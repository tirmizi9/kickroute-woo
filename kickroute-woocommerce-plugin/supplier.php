<?php

//add_filter( 'woocommerce_hide_invisible_variations', '__return_false', 10 );

add_action( 'woocommerce_new_product_variation', 'kickroute_mark_product_as_unimported', 10, 2 );
function kickroute_mark_product_as_unimported( $id, $variation ){
  update_post_meta( $variation->get_parent_id(), 'imported_to_kickroute', 'no' );
}


add_action( 'woocommerce_update_product', 'kickroute_send_product_data', 10, 2 );
function kickroute_send_product_data( $product_id, $product ){
  if ( !$product->is_type( 'simple' ) && !$product->is_type( 'variable' ) ) return;

  // if ( get_post_meta( $product_id, 'imported_to_kickroute', true ) !== 'yes' ){
  //   return;
  // }

  $updating_product = "kickroute_update_product_$product_id";
  if ( !get_transient( $updating_product ) ) {
    $gtin = kickroute_get_gtin( $product_id );
    if ( $gtin === '' || $gtin === NULL ){
      return;
    }
    $body = array(
      'gtin' => $gtin,
      'is_live' => $product->get_status() === 'publish',
      'name' => $product->get_name(),
    );

    if ( $product->is_type( 'simple' ) ){
      $body['regular_price'] = $product->get_regular_price();
      $body['is_in_stock'] = $product->is_in_stock();
      $body['stock_quantity'] = $product->get_stock_quantity();
    }
    $event = json_encode( array(
      'type' => 'item.updated',
      'body' => $body
    ) );
    $code = kickroute_send_data( $event );
    if ( $code !== 200 ){
      kickroute_source_event( 'item.updated', 'product', $product_id );
    }
    set_transient( $updating_product, $product_id, 1 );
  }
}


add_action( 'woocommerce_update_product_variation', 'kickroute_send_updated_product_variation_data', 10, 2 );
function kickroute_send_updated_product_variation_data( $variation_id, $variation ){
  $product_id = $variation->get_parent_id();
  $updating_product_variation = "kickroute_update_product_$product_id" . "_$variation_id";
  if ( !get_transient( $updating_product_variation ) ) {
    $gtin = kickroute_get_gtin( $variation_id );
    if ( $gtin === '' || $gtin === NULL ){
      return;
    }
    $event = array(
      'type' => 'item.updated',
      'body' => array(
        'gtin' => $gtin,
        'is_live' => $variation->is_purchasable(),
        'regular_price' => $variation->get_regular_price(),
        'is_in_stock' => $variation->is_in_stock(),
        'stock_quantity' => $variation->get_stock_quantity()
      )
    );
    $code = kickroute_send_data( json_encode( $event ) );
    if ( $code !== 200 ){
      kickroute_source_event( 'item.updated', 'product_variation', $variation_id );
    }
    set_transient( $updating_product_variation, $variation_id, 1 );
  }

}

add_action('added_post_meta', 'kickroute_send_wholesale_price_if_updated', 10, 4);
add_action('updated_post_meta', 'kickroute_send_wholesale_price_if_updated', 10, 4);
function kickroute_send_wholesale_price_if_updated( $meta_id, $post_id, $meta_key, $meta_value ){
  $field_name = esc_attr( get_option( 'wc_kickroute_dropshipping_price_field_name' ) );
  if ( $meta_key !== $field_name ) return;

  $post = get_post( $post_id );
  $parent_id = wp_get_post_parent_id( $post );

  $gtin = kickroute_get_gtin( $post_id );
  if ( $gtin === '' || $gtin === NULL ){
    return;
  }

  $event = array(
    'type' => 'item.updated',
    'body' => array(
      'gtin' => $gtin,
      'wholesale_price' => $meta_value ? $meta_value : null
    )
  );
  $code = kickroute_send_data( json_encode( $event ) );
  if ( $code !== 200 ){
    $post_type = $parent_id !== 0 ? 'product_variation' : 'product';
    kickroute_source_event( 'item.updated', $post_type, $post_id );
  }
}

add_action( 'trash_product', 'kickroute_on_remove_post', 10 );
add_action( 'untrashed_post', 'kickroute_on_untrash_post', 10 );
add_action( 'before_delete_post', 'kickroute_on_remove_post', 10 );
function kickroute_on_untrash_post( $post_id ){
  if ( 'product' === get_post_type( $post_id ) ){
    kickroute_send_new_tombstoned_value( $post_id, false );
  }
}
function kickroute_on_remove_post( $post_id ){
  $post_type = get_post_type( $post_id );
  if ( ( 'product' !== $post_type ) && ( 'product_variation' !== $post_type ) ){
    return;
  }
  kickroute_send_new_tombstoned_value( $post_id, true );
}

function kickroute_send_new_tombstoned_value( $post_id, $new_value ){
  $post = get_post( $post_id );
  $parent_id = wp_get_post_parent_id( $post );

  $gtin = kickroute_get_gtin( $post_id );
  if ( $gtin === '' || $gtin === NULL ){
    return;
  }
  $event = array(
    'type' => 'item.updated',
    'body' => array(
      'gtin' => $gtin,
      'is_live' => !$new_value
    )
  );

  $code = kickroute_send_data( json_encode( $event ) );
  if ( $code !== 200 ){
    $post_type = $parent_id !== 0 ? 'product_variation' : 'product';
    kickroute_source_event( 'item.updated', $post_type, $post_id );
  }
}


add_action('woocommerce_refund_created', 'kickroute_send_new_refund', 10, 2);
function kickroute_send_new_refund( $refund_id, $refund ){
  $event = kickroute_construct_refund_issued_event( $refund_id );
  $code = kickroute_send_data( json_encode( $event ) );
  if ( $code !== 200 ){
    kickroute_source_event( 'refund.issued', 'order_refund', $refund_id );
  }
}

// function __assert_product_or_variation($post_id){
//   $post_type = get_post_type($post_id);
//   return $post_type === 'product' || $post_type === 'product_variation';
// }

// // Process the bulk action from selected orders
// add_filter( 'handle_bulk_actions-edit-shop_product', 'decrease_meals_bulk_action_edit_shop_order', 10, 3 );
// function decrease_meals_bulk_action_edit_shop_order( $redirect_to, $action, $post_ids ) {
//     kickroute_debug_log("here");
//     kickroute_debug_log($post_ids);
//     return $redirect_to;
// }

// add_action('woocommerce_update_product', 'on_update_product', 10, 2);
// function on_update_product($product_id, $product){
//   kickroute_debug_log($product->get_available_variations());
//   remove_action('woocommerce_update_product', 'on_update_product', 10, 2);
// }
