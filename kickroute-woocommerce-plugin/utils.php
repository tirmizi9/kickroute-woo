<?php


function kickroute_get_item_by_gtin( $gtin ){
  $gtin_field_name = esc_attr( get_option( 'wc_kickroute_gtin_field_name' ) );

  $args = array(
    'post_type' => 'product',
    'meta_key' => $gtin_field_name,
    'meta_value' => $gtin
  );
  $query = new WP_Query( $args );
  $posts = $query->posts;

  if ( sizeof( $posts ) === 0 ){
    $args['post_type'] = 'product_variation';
    $query = new WP_Query( $args );
    $posts = $query->posts;

    if ( sizeof( $posts ) === 0 ){
      return NULL;
    }

    return new WC_Product_Variation( $posts[0]->ID );
  }

  return wc_get_product( $posts[0]->ID );
}


function kickroute_get_gtin( $post_id ){
  $field_name = esc_attr( get_option( 'wc_kickroute_gtin_field_name' ) );
  if ( $field_name === '' ){
    return '';
  }
  return get_post_meta( $post_id, $field_name, true );
}

function kickroute_get_dropshipping_price( $post_id ){
  $field_name = esc_attr( get_option( 'wc_kickroute_dropshipping_price_field_name' ) );
  if ( $field_name === '' ){
    return '';
  }
  return get_post_meta( $post_id, $field_name, true );
}


function kickroute_send_data( $json_data, $token=NULL ){
  if ( $token === NULL ){
    $token = kickroute_decrypt_key( esc_attr( get_option( 'wc_kickroute_key' ) ) );
  }
  if ( $json_data === NULL ){
    return;
  }

  $resp = wp_remote_post(
    "https://api-dev.kickroute.com",
    array(
      "method" => "POST",
      "timeout" => 10,
      "headers" => array(
          'Content-Type' => 'application/json',
          'x-api-key' => $token,
      ),
      "body" => $json_data
    )
  );
  if ( is_wp_error( $resp ) ){
    return 0;
  }
  if ( is_array( $resp ) ){
    return $resp['response']['code'];
  }
  return 0;
}

add_action( 'kickroute_send_events_batch_hook', 'kickroute_send_events_batch', 10 );
function kickroute_send_events_batch(){

  $token = kickroute_decrypt_key( esc_attr( get_option( 'wc_kickroute_key' ) ) );
  if ( !$token ){
    return;
  }

  global $wpdb;
  $table_name = $wpdb->base_prefix . 'kickroute_events';

  $changes = $wpdb->get_results( "SELECT * FROM $table_name LIMIT 20;" );

  // $wpdb->get_results returns all ints as strings, but they need to be ints
  // for our backend validation to pass, so we cast them.
  // Also, we store the body as json, but we want to return it as an array
  $events = array();
  for ( $i = 0; $i < sizeof( $changes ); $i++ ){
    $type = $changes[$i]->type;
    if ( $type === 'item.updated' ){
      // post_identifier = item id

      // get the product/variation ID
      $dropshipping_price_field_name = esc_attr( get_option( 'wc_kickroute_dropshipping_price_field_name' ) );
      if ( $changes[$i]->post_type === 'product' ){
        $item = new WC_Product( $changes[$i]->post_identifier );
        $name = $item->get_name();
      }
      else if ( $changes[$i]->post_type === 'product_variation' ){
        $item = new WC_Product_Variation( $changes[$i]->post_identifier );
        $name = NULL;
      }
      $item_id = $item->get_id();
      $gtin = kickroute_get_gtin( $item_id );
      $wholesale_price = get_post_meta( $item_id, $dropshipping_price_field_name, true );

      if ( $dropshipping_price_field_name === '' ){
        $wholesale_price = NULL;
      }

      $event = array(
        'type' => 'item.updated',
        'body' => array(
          'gtin' => $gtin,
          'stock_quantity' => $item->get_stock_quantity(),
          'wholesale_price' => $wholesale_price,
          'is_live' => $item->get_status() === 'publish',
          'name' => $name
        )
      );
      array_push( $events, $event );
    }
    else if ( $type === 'order.placed' ){
      // post_identifier = order number
      $event = kickroute_construct_placed_order_event( $changes[$i]->post_identifier );
      if ( $event === NULL ){
        continue;
      }
      array_push( $events, $event );
    }
    else if ( $type === 'order.updated' ){
      // post_identifier = order number
      $event = kickroute_construct_updated_order_event( $changes[$i]->post_identifier );
      array_push( $events, $event );
    }
    else if ( $type === 'refund.issued' ){
      // post_identifier = refund id
      $event = kickroute_construct_refund_issued_event( $changes[$i]->post_identifier );
      array_push( $events, $event );
    }
    else if ( $type === 'item.updated_connection' ){
      // post_identifier = refund id
      $event = kickroute_construct_updated_item_connection_event( $changes[$i]->post_identifier );
      array_push( $events, $event );
    }
  }

  if ( sizeof( $events ) === 0 ){
    return;
  }

  $event = json_encode( array(
    'type' => 'events.batch.process',
    'body' => $events
  ) );

  $code = kickroute_send_data( $event );
  if ( $code === 200 ) {
    $wpdb->query( "DELETE FROM $table_name LIMIT 20;" );
  };
}


function kickroute_source_event( $type, $post_type, $post_identifier ){
  global $wpdb;

  $table_name = $wpdb->base_prefix . 'kickroute_events';

  $wpdb->replace( $table_name, array(
    'type' => $type,
    'post_type' => $post_type,
    'post_identifier' => $post_identifier
  ) );
}


function kickroute_cron_is_usable(){
  $rest_api_call = ( strpos( wp_debug_backtrace_summary(), 'WP_REST_Server->serve_request' ) !== false );
  $wp_cron_disabled = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );

  return ( (!$rest_api_call) && (!$wp_cron_disabled) );
}


function kickroute_debug_log($message) {
    if (WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}


function kickroute_construct_updated_order_event( $order_id ){
  $order = new WC_Order( $order_id );

  $status_mapping = array(
    'pending' => 'pending-payment',
    'on-hold' => 'on-hold',
    'processing' => 'awaiting-fulfillment',
    'completed' => 'completed',
    'refunded' => 'refunded',
    'cancelled' => 'cancelled'
  );
  $new_status = $order->get_status();

  if ( !array_key_exists( $new_status, $status_mapping ) ){
    return;
  }

  $kickroute_number = get_post_meta( $order_id, '_kickroute_order_number', true );
  $translated_status = $status_mapping[$new_status];

  $tracking_number = get_post_meta( $order_id, esc_attr( get_option( 'wc_kickroute_tracking_number_field_name' ) ), true );
  $carrier = esc_attr( get_option( 'wc_kickroute_tracking_carrier' ) );

  return array(
    'type' => 'order.updated',
    'body' => array(
      'number' => ( $kickroute_number !== '' ) ? $kickroute_number : $order->get_order_number(),
      'tracking' => array(
        'number' => $tracking_number,
        'carrier' => $carrier,
      ),
      'contains_kickroute_number' => $kickroute_number !== '',
      'status' => $translated_status,
    )
  );
}


function kickroute_construct_placed_order_event( $order_id ){
  $order = wc_get_order( $order_id );

  if ( $order === NULL ){
    return;
  }

  $order_items = array();
  foreach ( $order->get_items() as $item ){
    $var_id = $item->get_variation_id();
    $item_id = ( $var_id !== 0 ) ? $var_id : $item->get_product_id();
    $supplier_number = get_post_meta( $item_id, '_wc_kickroute_supplier_number', true );
    if ( $supplier_number === '' ){
      continue;
    }
    $item_arr = array(
      'gtin' => kickroute_get_gtin( $item_id ),
      'supplier_number' => $supplier_number,
      'net_total' => wc_format_decimal( $item->get_total(), 2 ),
      'total_tax' => wc_format_decimal( $item->get_total_tax(), 2 ),
      'quantity' => $item->get_quantity()
    );
    array_push( $order_items, $item_arr );
  }

  if ( empty( $order_items ) ){
    return NULL;
  }

  $status_mapping = array(
    'pending' => 'pending-payment',
    'on-hold' => 'on-hold',
    'processing' => 'awaiting-fulfillment',
    'completed' => 'completed',
    'refunded' => 'refunded'
  );
  $shipping = $order->get_address( 'shipping' );
  $shipping['country_code'] = $shipping['country'];
  unset( $shipping['country'] );

  return array(
    'type' => 'order.placed',
    'body' => array(
      'number' => $order->get_order_number(),
      'status' => $status_mapping[$order->get_status()],
      'items' => $order_items,
      'shipping' => $shipping
    )
  );
}

function kickroute_construct_refund_issued_event( $refund_id ){
  $refund = new WC_Order_Refund( $refund_id );
  $order_id = $refund->get_parent_id();
  $kickroute_number = get_post_meta( $order_id, '_kickroute_order_number', true );
  if ( $kickroute_number === '' || $kickroute_number === NULL ){
    return;
  }
  $body = array(
    'number' => $kickroute_number,
    'total' => $refund->get_amount(),
    'items' => array(),
    'deduplication_id' => $refund_id
  );
  foreach ( $refund->get_items() as $item_id => $item ){
    $var_id = $item->get_variation_id();
    $item_id = ( $var_id !== 0 ) ? $var_id : $item->get_product_id();
    array_push( $body['items'], array(
      'gtin' => kickroute_get_gtin( $item_id ),
      'quantity' => abs( $item->get_quantity() ),
      'net_total' => abs( $item->get_total() ),
      'total_tax' => abs( $item->get_total_tax() ),
    ) );
  }

  return array(
    'type' => 'refund.issued',
    'body' => $body
  );
}

function kickroute_get_encryption_key_and_salt(){
  $password = '';
  if ( !defined( 'LOGGED_IN_KEY' ) ){
    return;
  }
  if ( LOGGED_IN_KEY === '' ){
    return;
  }
  $password = LOGGED_IN_KEY;

  $salt = '';
  if ( !defined( 'LOGGED_IN_SALT' ) ){
    return;
  }
  if ( LOGGED_IN_SALT === '' ){
    return;
  }
  $salt = LOGGED_IN_SALT;
  return array( 'password' => $password, 'salt' => $salt );
}

function kickroute_encrypt_key( $raw_value ) {
    if ( !extension_loaded( 'openssl' ) ){
      return $raw_value;
    }

    $encryption_strings = kickroute_get_encryption_key_and_salt();
    if ( $encryption_strings === NULL ){
      return $raw_value;
    }
    $password = $encryption_strings['password'];
    $salt = $encryption_strings['salt'];

    $method = "AES-256-CTR";
    $iv = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );

    $encrypted = openssl_encrypt( $raw_value . $salt, $method, $password, 0, $iv );
    if ( !$encrypted ){
      return false;
    }

    return base64_encode( $iv . $encrypted );
}

function kickroute_decrypt_key( $encrypted_key ) {
    if ( !extension_loaded( 'openssl' ) ){
      return $encrypted_key;
    }

    $encryption_strings = kickroute_get_encryption_key_and_salt();
    if ( $encryption_strings === NULL ){
      return $encrypted_key;
    }
    $password = $encryption_strings['password'];
    $salt = $encryption_strings['salt'];

    $encrypted_key = base64_decode( $encrypted_key );

    $method = "AES-256-CTR";
    $ivlen = openssl_cipher_iv_length( $method );
    $iv = substr( $encrypted_key, 0, $ivlen );

    $encrypted_key = substr( $encrypted_key, $ivlen );
    $decrypted_key = openssl_decrypt( $encrypted_key, $method, $password, 0, $iv );

    if ( !$decrypted_key || substr( $decrypted_key, -strlen( $salt ) ) != $salt ){
      return false;
    }

    return substr( $decrypted_key, 0, -strlen( $salt ) );
}
