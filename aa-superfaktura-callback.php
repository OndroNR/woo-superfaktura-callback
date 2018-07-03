<?php
/*
Plugin Name: AA Superfaktura callback support
Plugin URI: https://www.akademiaalexandra.sk
Description: Sets WooCommerce order payment status on Superfaktura callback
Author: Ondrej Galbavy
Version: 1
Author URI: https://ondrej.galbavy.sk/
*/

function aa_superfaktura_callback_handler(WP_REST_Request $request) {
  if (get_option('aa_superfaktura_callback_enabled') != 'yes') {
    return '';
  }

  $status_from = get_option('aa_superfaktura_callback_order_status_before');
  $status_to = get_option('aa_superfaktura_callback_order_status_after');

  $client_secret_key = $request['secret_key'];
  $secret_key = get_option('aa_superfaktura_callback_secret_key');
  if ($client_secret_key !== $secret_key) {
    return new WP_Error('invalid_secret_key', 'Invalid secret key');
  }

  // $data = ['params' => $request->get_params()];

  $query_proforma = new WC_Order_Query(array('post_type' => 'shop_order', 'wc_sf_internal_proforma_id' => $request['invoice_id']));
  $orders_proforma = $query_proforma->get_orders();

  $query_regular = new WC_Order_Query(array('post_type' => 'shop_order', 'wc_sf_internal_regular_id' => $request['invoice_id']));
  $orders_regular = $query_regular->get_orders();

  $orders = array_merge($orders_proforma, $orders_regular);

  // status 'on-hold' -> 'processing'
  foreach($orders as $order) {
    if ($order->get_status() === $status_from) {
      $order->set_status($status_to);
      $order->save();
    }
  }

  // $data['orders'] = array_map(function($order) {return $order->get_id() . $order->get_status();}, $orders);

  $data = 'ok';

  $response = new WP_REST_Response( $data );
  return $response;
}

add_action('rest_api_init', function() {
  register_rest_route('aa_superfaktura_callback/v1', '/callback', array(
    'methods' => 'GET',
    'callback' => 'aa_superfaktura_callback_handler',
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
});

/**
 * Handle a custom 'customvar' query var to get orders with the 'customvar' meta.
 * @param array $query - Args for WP_Query.
 * @param array $query_vars - Query vars from WC_Order_Query.
 * @return array modified $query
 */
function aa_superfaktura_callback_handle_custom_query_var( $query, $query_vars ) {
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
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'aa_superfaktura_callback_handle_custom_query_var', 10, 2 );



function aa_superfaktura_callback_settings_hook($settings) {
        $settings[] = array(
            'title' => 'Superfaktura callback',
            'type' => 'title',
            'desc' => 'Callback settings',
            'id' => 'woocommerce_wi_invoice_title_callback'
        );

        $settings[] = array(
            'title' => 'Enabled',
            'id' => 'aa_superfaktura_callback_enabled',
            'type' => 'checkbox',
            'default' => 'no'
        );

        $settings[] = array(
            'title' => 'SECRET_KEY',
            'id' => 'aa_superfaktura_callback_secret_key',
            'desc' => 'Secret key',
            'type' => 'text',
        );

        $shop_order_status = aa_superfaktura_callback_get_order_statuses();

        $settings[] = array(
            'title' => 'Order status before',
            'id' => 'aa_superfaktura_callback_order_status_before',
            'desc' => 'Order status required before transition to payed status',
            'default' => 'on-hold',
            'type' => 'select',
            'class' => 'wc-enhanced-select',
            'options' => $shop_order_status
        );

        $settings[] = array(
            'title' => 'Order status after',
            'id' => 'aa_superfaktura_callback_order_status_after',
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
add_filter('woocommerce_wc_superfaktura_settings', 'aa_superfaktura_callback_settings_hook', 50, 1);

    function aa_superfaktura_callback_get_order_statuses()
    {
        if ( function_exists( 'wc_order_status_manager_get_order_status_posts' ) ) // plugin WooCommerce Order Status Manager
        {
            $wc_order_statuses = array_reduce(
                wc_order_status_manager_get_order_status_posts(),
                function($result, $item)
                {
                    $result[$item->post_name] = $item->post_title;
                    return $result;
                },
                array()
            );

            return $wc_order_statuses;
        }

        if ( function_exists( 'wc_get_order_statuses' ) )
        {
            $wc_get_order_statuses = wc_get_order_statuses();

            return aa_superfaktura_callback_alter_wc_statuses( $wc_get_order_statuses );
        }

        $order_status_terms = get_terms('shop_order_status','hide_empty=0');

        $shop_order_statuses = array();
        if ( ! is_wp_error( $order_status_terms ) )
        {
            foreach ( $order_status_terms as $term )
            {
                $shop_order_statuses[$term->slug] = $term->name;
            }
        }

        return $shop_order_statuses;
     }
    function aa_superfaktura_callback_alter_wc_statuses( $array )
    {
        $new_array = array();
        foreach ( $array as $key => $value )
        {
            $new_array[substr($key,3)] = $value;
        }

        return $new_array;
    }

