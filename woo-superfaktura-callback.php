<?php
/*
Plugin Name: WooCommerce SuperFaktura callback support
Plugin URI: https://ondrej.galbavy.sk/
Description: Sets WooCommerce order payment status on Superfaktura callback
Author: Ondrej Galbavy
Version: 0.1
Author URI: https://ondrej.galbavy.sk/
*/

if ( ! defined( 'WPINC' ) )
{
  die;
}

class WooSuperFakturaCallback {
  protected $callback_rest_namespace = 'woo_superfaktura_callback/v1';
  protected $callback_rest_path = 'callback';

  public function __construct() {
    add_action('admin_init', [$this, 'generate_secret_key']);
    add_action('rest_api_init', [$this, 'generate_secret_key']);
    add_action('rest_api_init', [$this, 'define_rest_routes']);
    add_filter('woocommerce_order_data_store_cpt_get_orders_query', [$this, 'handle_custom_query_var'], 10, 2);
    add_filter('woocommerce_wc_superfaktura_settings', [$this, 'sf_settings_hook'], 50, 1);
  }

  function generate_secret_key() {
    $secret_key = get_option('woo_superfaktura_callback_secret_key');
    if (empty($secret_key)) {
      update_option('woo_superfaktura_callback_secret_key', $this->randomString(32));
    }
  }

  function define_rest_routes() {
    register_rest_route($this->callback_rest_namespace, $this->callback_rest_path, array(
      'methods' => 'GET',
      'callback' => [$this, 'callback_handler'],
      'show_in_index' => false,
      'args' => array(
        'invoice_id' => array(
          'required' => true,
          'validate_callback' => function($param, $request, $key) {
            return is_numeric( $param ) && $param > 0;
          }
        ),
        'secret_key' => array(
          'required' => true,
          'validate_callback' => function($param, $request, $key) {
            return is_string( $param ) && !empty($param);
          }
        )
      )
    ));  
  }

  function callback_handler(WP_REST_Request $request) {
    if (get_option('woo_superfaktura_callback_enabled') != 'yes') {
      return '';
    }

    // verify SECRET_KEY
    $client_secret_key = $request['secret_key'];
    $secret_key = get_option('woo_superfaktura_callback_secret_key');
    if ($client_secret_key !== $secret_key) {
      return new WP_Error('invalid_secret_key', 'Invalid secret key');
    }

    // query orders by proforma and regular invoice SuperFaktura IDs
    $query_proforma = new WC_Order_Query(array('post_type' => 'shop_order', 'wc_sf_internal_proforma_id' => $request['invoice_id']));
    $orders_proforma = $query_proforma->get_orders();

    $query_regular = new WC_Order_Query(array('post_type' => 'shop_order', 'wc_sf_internal_regular_id' => $request['invoice_id']));
    $orders_regular = $query_regular->get_orders();

    $orders = array_merge($orders_proforma, $orders_regular);

    // e.g. transition only status 'on-hold' to 'processing'
    $status_from = get_option('woo_superfaktura_callback_order_status_before');
    $status_to = get_option('woo_superfaktura_callback_order_status_after');

    foreach($orders as $order) {
      if ($order->get_status() === $status_from) {
        $order->set_status($status_to, 'SuperFaktura callback: ');
        $order->save();
      }
    }

    return 'ok';
  }

  /**
   * Handle a custom 'wc_sf_internal_proforma_id' and 'wc_sf_internal_regular_id' query var to get orders with the those meta.
   * @param array $query - Args for WP_Query.
   * @param array $query_vars - Query vars from WC_Order_Query.
   * @return array modified $query
   */
  function handle_custom_query_var( $query, $query_vars ) {
    if ( ! empty( $query_vars['wc_sf_internal_proforma_id'] ) ) {
      $query['meta_query'][] = array(
        'key' => 'wc_sf_internal_proforma_id',
        'value' => esc_attr( $query_vars['wc_sf_internal_proforma_id'] ),
      );
    }

    if ( ! empty( $query_vars['wc_sf_internal_regular_id'] ) ) {
      $query['meta_query'][] = array(
        'key' => 'wc_sf_internal_regular_id',
        'value' => esc_attr( $query_vars['wc_sf_internal_regular_id'] ),
      );
    }

    return $query;
  }

  // Extend WooCommerce Superfaktura settings page
  function sf_settings_hook($settings) {
    $settings[] = array(
      'title' => 'Superfaktura callback settings',
      'type' => 'title',
      'desc' => 'Callback URL: ' . rest_url("$this->callback_rest_namespace/$this->callback_rest_path"),
      'id' => 'woocommerce_wi_invoice_title_callback'
    );

    $settings[] = array(
      'title' => 'Enabled',
      'id' => 'woo_superfaktura_callback_enabled',
      'type' => 'checkbox',
      'default' => 'no'
    );

    $settings[] = array(
      'title' => 'SECRET_KEY',
      'id' => 'woo_superfaktura_callback_secret_key',
      'desc' => 'Keep it secret, long and random',
      'type' => 'text',
    );

    $shop_order_status = WC_SuperFaktura::get_instance()->get_order_statuses();

    $settings[] = array(
      'title' => 'Order status before',
      'id' => 'woo_superfaktura_callback_order_status_before',
      'desc' => 'Order status required before transition to payed status',
      'default' => 'on-hold',
      'type' => 'select',
      'class' => 'wc-enhanced-select',
      'options' => $shop_order_status
    );

    $settings[] = array(
      'title' => 'Order status after',
      'id' => 'woo_superfaktura_callback_order_status_after',
      'desc' => 'Order status to set after payment',
      'default' => 'processing',
      'type' => 'select',
      'class' => 'wc-enhanced-select',
      'options' => $shop_order_status
    );

    $settings[] = array(
      'type' => 'sectionend',
      'id' => 'woocommerce_wi_invoice_title_callback'
    );
    return $settings;
  }

  private function randomString($length) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
  }

}

function run_woo_superfaktura_callback() {
  $wsc = new WooSuperFakturaCallback();
}
run_woo_superfaktura_callback();

