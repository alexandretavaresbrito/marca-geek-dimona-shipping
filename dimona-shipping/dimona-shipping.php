<?php
/**
 * Plugin Name: Dimona Shipping Method
 * Description: Método de entrega customizado WooCommerce que consulta a API Dimona em tempo real e exibe nome, prazo e valor.
 * Plugin URI:  https://example.com/
 * Version:     1.0.0
 * Author:      Desenvolvedor
 * Text Domain: dimona-shipping
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'woocommerce_shipping_init', 'dimona_shipping_method_init' );
add_filter( 'woocommerce_shipping_methods', 'dimona_shipping_add_method' );

function dimona_shipping_method_init() {
    if ( ! class_exists( 'WC_Shipping_Method' ) ) {
        return;
    }

    class WC_Dimona_Shipping_Method extends WC_Shipping_Method {
        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'dimona_shipping';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = __( 'Dimona Shipping', 'dimona-shipping' );
            $this->method_description = __( 'Consulta a API Dimona /api/v2/shipping e exibe cotações em tempo real.', 'dimona-shipping' );
            $this->supports           = array( 'shipping-zones', 'instance-settings' );

            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->title     = $this->get_option( 'title', __( 'Entrega Dimona', 'dimona-shipping' ) );
            $this->api_key   = $this->get_option( 'api_key' );
            $this->api_url   = $this->get_option( 'api_url', 'https://api.dimona.com/api/v2/shipping' );
            $this->cache_ttl = absint( $this->get_option( 'cache_ttl', 300 ) );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => __( 'Ativar/Desativar', 'dimona-shipping' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Ativar método de entrega Dimona', 'dimona-shipping' ),
                    'default'     => 'yes',
                    'description' => __( 'Habilita este método de entrega para a zona.', 'dimona-shipping' ),
                ),
                'title' => array(
                    'title'       => __( 'Título', 'dimona-shipping' ),
                    'type'        => 'text',
                    'description' => __( 'Nome exibido no checkout.', 'dimona-shipping' ),
                    'default'     => __( 'Entrega Dimona', 'dimona-shipping' ),
                ),
                'api_key' => array(
                    'title'       => __( 'API Key Dimona', 'dimona-shipping' ),
                    'type'        => 'text',
                    'description' => __( 'Informe a chave de API fornecida pela Dimona.', 'dimona-shipping' ),
                ),
                'api_url' => array(
                    'title'       => __( 'URL da API', 'dimona-shipping' ),
                    'type'        => 'text',
                    'description' => __( 'Endpoint da API da Dimona. Deixe como padrão se não souber.', 'dimona-shipping' ),
                    'default'     => 'https://api.dimona.com/api/v2/shipping',
                ),
                'cache_ttl' => array(
                    'title'       => __( 'Cache de cotações (segundos)', 'dimona-shipping' ),
                    'type'        => 'number',
                    'description' => __( 'Tempo em segundos para armazenar as cotações em cache.', 'dimona-shipping' ),
                    'default'     => 300,
                    'desc_tip'    => true,
                ),
            );
        }

        public function calculate_shipping( $package = array() ) {
            if ( empty( $this->api_key ) ) {
                return;
            }

            $rates = $this->get_dimona_rates( $package );
            if ( is_wp_error( $rates ) || empty( $rates ) ) {
                return;
            }

            foreach ( $rates as $rate ) {
                $rate_id = sanitize_title( $rate['name'] . '-' . $rate['delivery_time'] );
                $this->add_rate( array(
                    'id'       => $this->id . ':' . $rate_id,
                    'label'    => trim( $rate['name'] . ' - ' . $rate['delivery_time'] ),
                    'cost'     => wc_format_decimal( $rate['cost'], 2 ),
                    'package'  => $package,
                    'meta_data' => array(
                        array(
                            'key'   => 'dimona_delivery_time',
                            'value' => $rate['delivery_time'],
                        ),
                    ),
                ) );
            }
        }

        protected function get_dimona_rates( $package ) {
            $cache_key = 'dimona_shipping_' . md5( wp_json_encode( $package ) . $this->api_key . $this->api_url );
            $cached    = get_transient( $cache_key );

            if ( false !== $cached ) {
                return $cached;
            }

            $body = $this->build_request_body( $package );
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . sanitize_text_field( $this->api_key ),
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 20,
            );

            $response = wp_remote_post( esc_url_raw( $this->api_url ), $args );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( 200 !== absint( $code ) ) {
                return new WP_Error( 'dimona_api_error', sprintf( __( 'Erro ao consultar Dimona: %s', 'dimona-shipping' ), wp_strip_all_tags( $body ) ) );
            }

            $data = json_decode( $body, true );
            if ( ! is_array( $data ) || empty( $data['rates'] ) ) {
                return new WP_Error( 'dimona_invalid_response', __( 'Resposta inválida da API Dimona.', 'dimona-shipping' ) );
            }

            $rates = $this->normalize_rates( $data['rates'] );
            set_transient( $cache_key, $rates, $this->cache_ttl );
            return $rates;
        }

        protected function build_request_body( $package ) {
            $destination = isset( $package['destination'] ) ? $package['destination'] : array();
            $items = array();

            if ( ! empty( $package['contents'] ) ) {
                foreach ( $package['contents'] as $item ) {
                    if ( empty( $item['data'] ) || 0 === $item['quantity'] ) {
                        continue;
                    }

                    $product = $item['data'];
                    $items[] = array(
                        'name'     => $product->get_name(),
                        'sku'      => $product->get_sku(),
                        'quantity' => absint( $item['quantity'] ),
                        'weight'   => wc_get_weight( $product->get_weight(), 'kg' ),
                        'height'   => wc_get_dimension( $product->get_height(), 'cm' ),
                        'width'    => wc_get_dimension( $product->get_width(), 'cm' ),
                        'length'   => wc_get_dimension( $product->get_length(), 'cm' ),
                        'price'    => wc_get_price_to_display( $product, array( 'qty' => 1 ) ),
                    );
                }
            }

            return array(
                'destination' => array(
                    'country'  => ! empty( $destination['country'] ) ? $destination['country'] : '',
                    'state'    => ! empty( $destination['state'] ) ? $destination['state'] : '',
                    'postcode' => ! empty( $destination['postcode'] ) ? $destination['postcode'] : '',
                    'city'     => ! empty( $destination['city'] ) ? $destination['city'] : '',
                ),
                'items'     => $items,
                'currency'  => get_woocommerce_currency(),
                'total'     => isset( WC()->cart ) ? WC()->cart->get_cart_contents_total() : 0,
            );
        }

        protected function normalize_rates( $rates ) {
            $normalized = array();

            foreach ( $rates as $rate ) {
                if ( empty( $rate['name'] ) || ! isset( $rate['price'] ) ) {
                    continue;
                }

                $normalized[] = array(
                    'name'          => sanitize_text_field( wp_strip_all_tags( $rate['name'] ) ),
                    'delivery_time' => isset( $rate['delivery_time'] ) ? sanitize_text_field( wp_strip_all_tags( $rate['delivery_time'] ) ) : __( 'Prazo não informado', 'dimona-shipping' ),
                    'cost'          => wc_format_decimal( floatval( $rate['price'] ), 2 ),
                );
            }

            return $normalized;
        }
    }
}

function dimona_shipping_add_method( $methods ) {
    $methods['dimona_shipping'] = 'WC_Dimona_Shipping_Method';
    return $methods;
}
