<?php

// Forward orders
add_action( 'woocommerce_rest_insert_shop_order_object', 'kickroute_on_woocommerce_rest_insert_shop_order_object', 10, 3 );
function kickroute_on_woocommerce_rest_insert_shop_order_object( $object, $request, $is_creating ){
  if ( !$is_creating || ( get_post_meta( $object, '_kickroute_order_number' ) !== '' ) ) {
    return;
  }

  do_kickroute_on_new_order( $object->get_id() );
}
add_action( 'woocommerce_checkout_order_processed', 'kickroute_on_new_order', 100 );
add_action( 'woocommerce_new_order', 'kickroute_on_new_order', 100 );
add_action( 'kickroute_on_new_order_hook', 'do_kickroute_on_new_order', 10 );
function kickroute_on_new_order( $order_id ){
  kickroute_debug_log($order_id);
  if ( !kickroute_cron_is_usable() ){
    do_kickroute_on_new_order( $order_id );
    return;
  }
  wp_schedule_single_event( time(), 'kickroute_on_new_order_hook', array( $order_id ) );
}

function do_kickroute_on_new_order( $order_id ){
  $event = kickroute_construct_placed_order_event( $order_id );
  if ( $event === NULL ){
    return;
  }
  $code = kickroute_send_data( json_encode( $event ) );
  if ( $code !== 200 ){
    kickroute_source_event( 'order.placed', 'shop_order', $order_id );
  }
}

add_action( 'updated_post_meta', 'kickroute_reset_item_stock_lock', 10, 4 );
function kickroute_reset_item_stock_lock( $meta_id, $post_id, $meta_key ){
  if ( $meta_key !== '_regular_price' ) return;

  if ( get_post_meta( $post_id, '_kickroute_stock_update_lock', true ) !== 'yes' ){
    return;
  }

  update_post_meta( $post_id, '_kickroute_stock_update_lock', 'no' );
}

add_action( 'woocommerce_product_options_general_product_data', 'kickroute_add_category_select_input' );
function kickroute_add_category_select_input() {
  $args = array(
    'label' => 'Kickroute Kategorie',
    'id' => '_wc_kickroute_category',
    'options' => array(
      'none' => '-- Keine --',
      'vaping' => 'Vaping',
      'vaping-e-cigarettes' => '– E-Zigaretten',
      'vaping-liquids' => '– Liquids',
      'vaping-flavors' => '– Aromen',
      'vaping-cbd' => '– CBD',
      'vaping-pod-starter-sets' => '– Pod-Starter Sets',
      'vaping-refill-pods' => '– Nachfüll-Pods',
      'vaping-batteries' => '– Akkus',
      'vaping-mods' => '– Akkuträger',
      'vaping-atomizer' => '– Verdampfer',
      'vaping-vaporizer' => '– Vaporizer',
      'vaping-coils' => '– Verdampferköpfe',
      'vaping-nicotine-shots' => '– Nikotin Shots',
      'vaping-base' => '– Basen',
    ),
    'desc_tip' => true,
    'description' => 'Bitte wähle die Produktkategorie der Kickroute Plattform aus. Achtung: Diese Kategorien sind unabhängig von denen in deinem WooCommerce Shop.'
  );
  woocommerce_wp_select( $args );
}

add_action( 'woocommerce_product_options_general_product_data', 'kickroute_add_supplier_number_field' );
function kickroute_add_supplier_number_field() {
  $args = array(
    'label' => 'Kickroute Lieferant',
    'id' => '_wc_kickroute_supplier_number',
    'desc_tip' => true,
    'description' => 'Für Einzelhändler: Das Kürzel deines Lieferanten'
  );
  woocommerce_wp_text_input( $args );
}


function kickroute_construct_updated_item_connection_event( $product_id ){
  $gtin_field_name = esc_attr( get_option( 'wc_kickroute_gtin_field_name' ) );
  $product = wc_get_product( $product_id );

  $gtin = get_post_meta( $product_id, $gtin_field_name, true );
  if ( !kickroute_valid_gtin( $gtin ) ){
    return;
  }

  return array(
    'type' => 'item.updated_connection',
    'body' => array(
      'gtin' => $gtin,
      'supplier_number' => get_post_meta( $product_id, '_wc_kickroute_supplier_number', true )
    )
  );
}

add_action('added_post_meta', 'kickroute_update_product_connection', 10, 4);
add_action('updated_post_meta', 'kickroute_update_product_connection', 10, 4);
function kickroute_update_product_connection($meta_id, $post_id, $meta_key, $meta_value){
  if ( $meta_key === '_wc_kickroute_supplier_number' ){
    $event = kickroute_construct_updated_item_connection_event( $post_id );
    $code = kickroute_send_data( json_encode( $event ) );

    if ( $code !== 200 ){
      kickroute_source_event(
        'item.updated_connection',
        'product',
        $post_id
      );
    }
  }
}

// New supplier number field for variation
function kickroute_variation_settings_fields( $loop, $variation_data, $variation ) {
  woocommerce_wp_text_input(
      array(
          'id'          => '_wc_kickroute_supplier_number[' . $variation->ID . ']',
          'label'       => __( 'Kickroute Lieferant', 'texdomain' ),
          'placeholder' => '',
          'desc_tip'    => 'true',
          'description' => __( 'Kickroute Lieferant', 'texdomain' ),
          'value'       => get_post_meta( $variation->ID, '_wc_kickroute_supplier_number', true )
      )
  );
}
add_action( 'woocommerce_product_after_variable_attributes', 'kickroute_variation_settings_fields', 10, 3 );

function kickroute_save_variation_settings_fields( $post_id ) {
  $supplier_number_value = sanitize_text_field( $_POST['_wc_kickroute_supplier_number'][ $post_id ] );
  if( ! empty( $supplier_number_value ) ) {
      update_post_meta( $post_id, '_wc_kickroute_supplier_number', esc_attr( $supplier_number_value ) );
  }
}
add_action( 'woocommerce_save_product_variation', 'kickroute_save_variation_settings_fields', 10, 2 );
//

add_action( 'woocommerce_product_bulk_edit_start', 'kickroute_custom_field_bulk_edit_input' );
function kickroute_custom_field_bulk_edit_input() {
    ?>
    <div class="inline-edit-group">
      <label class="alignleft">
         <span class="title"><?php _e( 'Kickroute Kategorie', 'woocommerce' ); ?></span>
         <span class="input-text-wrap">
            <select class="select" name="_wc_kickroute_category">
              <option value="none">-- Keine --</option>
              <option value="vaping">Vaping</option>
              <option value="vaping-e-cigarettes">– E-Zigaretten</option>
              <option value="vaping-liquids">– Liquids</option>
              <option value="vaping-flavors">– Aromen</option>
              <option value="vaping-cbd">– CBD</option>
              <option value="vaping-pod-starter-sets">– Pod-Starter Sets</option>
              <option value="vaping-refill-pods">– Nachfüll-Pods</option>
              <option value="vaping-batteries">– Akkus</option>
              <option value="vaping-mods">– Akkuträger</option>
              <option value="vaping-atomizer">– Verdampfer</option>
              <option value="vaping-vaporizer">– Vaporizer</option>
              <option value="vaping-coils">– Verdampferköpfe</option>
              <option value="vaping-nicotine-shots">– Nikotin Shots</option>
              <option value="vaping-base">– Basen</option>
            </select>
         </span>
        </label>
    </div>
    <?php
}

add_action( 'woocommerce_product_bulk_edit_save', 'kickroute_save_bulk_edit_category' );
function kickroute_save_bulk_edit_category( $product ) {
  $post_id = $product->get_id();
  if ( isset( $_REQUEST['_wc_kickroute_category'] ) ) {
    $custom_field = sanitize_text_field( $_REQUEST['_wc_kickroute_category'] );
    update_post_meta( $post_id, '_wc_kickroute_category', wc_clean( $custom_field ) );
  }
}

add_action( 'woocommerce_process_product_meta', 'kickroute_save_custom_fields', 10, 1 );
function kickroute_save_custom_fields( $product_id ) {
  if ( isset( $_REQUEST['_wc_kickroute_category'] ) ) {
    $custom_field = sanitize_text_field( $_REQUEST['_wc_kickroute_category'] );
    update_post_meta( $product_id, '_wc_kickroute_category', wc_clean( $custom_field ) );
  }
  if ( isset( $_REQUEST['_wc_kickroute_supplier_number'] ) ) {
    $custom_field = sanitize_text_field( $_REQUEST['_wc_kickroute_supplier_number'] );
    update_post_meta( $product_id, '_wc_kickroute_supplier_number', wc_clean( $custom_field ) );
  }
}
