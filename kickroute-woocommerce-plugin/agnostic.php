<?php

include 'sync_endpoint.php';

function debug_log($log) {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }

// Order status
add_action( 'woocommerce_order_status_changed', 'kickroute_on_send_new_status', 10, 3 );
add_action( 'kickroute_send_new_status_hook', 'do_kickroute_send_new_status', 10, 2 );
function kickroute_on_send_new_status( $order_id, $from, $to ){
  $event = kickroute_construct_updated_order_event( $order_id );

  if ( !kickroute_cron_is_usable() ){
    $code = kickroute_send_data( json_encode( $event ) );
    if ( $code !== 200 ){
      kickroute_source_event( 'order.updated', 'shop_order', $order_id );
    }
    return;
  }
  wp_schedule_single_event( time(), 'kickroute_send_new_status_hook', array( $event, $order_id ) );
}
function do_kickroute_send_new_status( $event, $order_id ){
  $code = kickroute_send_data( json_encode( $event ) );
  if ( $code !== 200 ){
    kickroute_source_event( 'order.updated', 'shop_order', $order_id );
  }
}

add_action('added_post_meta', 'kickroute_send_new_tracking', 10, 4);
add_action('updated_post_meta', 'kickroute_send_new_tracking', 10, 4);
function kickroute_send_new_tracking($meta_id, $post_id, $meta_key, $meta_value){
  $field_name = esc_attr( get_option( 'wc_kickroute_tracking_number_field_name' ) );
  if ( $meta_key === $field_name ){
    $number = get_post_meta( $post_id, $field_name, true );
    $carrier = esc_attr( get_option( 'wc_kickroute_tracking_carrier' ) );

    $kickroute_number = get_post_meta( $post_id, '_kickroute_order_number', true );

    if ( $kickroute_number === '' ){
      return;
    }

    $event = array(
      'type' => 'order.updated',
      'body' => array(
        'number' => $kickroute_number,
        'contains_kickroute_number' => true,
        'tracking' => array(
          'number' => $number,
          'carrier' => $carrier
        )
      )
    );

    $code = kickroute_send_data( json_encode( $event ) );
    if ( $code !== 200 ){
      kickroute_source_event(
        'order.updated',
        'shop_order',
        $post_id
      );
    }
  }
  // Advanced Shipment Tracking Plugin: _wc_shipment_tracking_items
}
