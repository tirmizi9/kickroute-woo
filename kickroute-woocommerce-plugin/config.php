<?php

add_filter('woocommerce_settings_tabs_array', 'add_kickroute_tab', 50);
function add_kickroute_tab($settings_tabs) {
  $settings_tabs['kickroute'] = __('Kickroute', 'kickroute-tab');
  return $settings_tabs;
}

add_action('woocommerce_settings_tabs_kickroute', 'kickroute_tab');
function kickroute_tab(){
  woocommerce_admin_fields(get_kickroute_settings());
}

function woocommerce_admin_settings_sanitize_option_wc_kickroute_key( $value, $option, $raw_value ) {
  $old_value = esc_attr( esc_attr( get_option( 'wc_kickroute_key' ) ) );
  if ( $value === $old_value ){
    return $value;
  }
  return kickroute_encrypt_key( $value );
};
add_filter( "woocommerce_admin_settings_sanitize_option_wc_kickroute_key", 'woocommerce_admin_settings_sanitize_option_wc_kickroute_key', 10, 3 );

add_action('woocommerce_update_options_kickroute', 'update_kickroute_settings');
function update_kickroute_settings() {
  woocommerce_update_options(get_kickroute_settings());
  $example_number = esc_attr( get_option( 'wc_kickroute_example_order_number' ) );
  $tracking_number_field_name = esc_attr( get_option( 'wc_kickroute_tracking_number_field_name' ) );
  $example_id = esc_attr( get_option( 'wc_kickroute_example_product_id' ) );
  $gtin_field_name = esc_attr( get_option( 'wc_kickroute_gtin_field_name' ) );
  $wholesale_price_field_name = esc_attr( get_option( 'wc_kickroute_dropshipping_price_field_name' ) );

  $tracking_number = get_post_meta( $example_number, $tracking_number_field_name, true );
  $gtin = get_post_meta( $example_id, $gtin_field_name, true );
  $wholesale_price = get_post_meta( $example_id, $wholesale_price_field_name, true );
  $code = kickroute_send_data( json_encode(
    array(
      'type' => 'connection.test',
      'body' => array(
        'webhook_url' => get_site_url() . '/wp-json/wc/v3/kickroute_callback_endpoint',
        'example_order' => array(
          'number' => $example_number,
          'tracking_number' => $tracking_number ? $tracking_number : '',
          'tracking_carrier' => esc_attr( get_option( 'wc_kickroute_tracking_carrier' ) ),
        ),
        'example_product' => array(
          'id' => $example_id,
          'gtin' => $gtin ? $gtin : '',
          'wholesale_price' => $wholesale_price ? $wholesale_price : '',
        ),
      )
    )
  ) );
}

function get_kickroute_settings(){
  $settings = array(
      'section_title' => array(
          'name'     => __('Kickroute Optionen', 'woocommerce-kickroute'),
          'type'     => 'title',
          'desc'     => '',
          'id'       => 'wc_kickroute_section_title'
      ),
      'key' => array(
          'name'     => __('Schlüssel', 'woocommerce-kickroute'),
          'type'     => 'text',
          'desc'     => 'Bitte gebe hier den Schlüssel ein, welcher dir nach dem Erstellen deines Shops auf Kickroute angezeigt wurde.',
          'id'       => 'wc_kickroute_key'
      ),
      'gtin_field_name' => array(
          'name'     => __('EAN Feldname', 'woocommerce-kickroute'),
          'type'     => 'text',
          'desc'     => 'Bitte gebe hier den Namen des Feldes ein, in dem du die GTIN (EAN) bei jedem Produkt bzw. bei jeder Variation eingetragen hast. (Zur Dokumentation)',
          'id'       => 'wc_kickroute_gtin_field_name'
      ),
      'tracking_number_field_name' => array(
          'name'     => __('Tracking Nummer Feldname', 'woocommerce-kickroute'),
          'type'     => 'text',
          'desc'     => 'Bitte gebe hier den Namen des Feldes ein, in dem du Tracking Nummer bei Bestellungen speicherst (Lieferanten) bzw. erhalten möchtest (Einzelhändler)',
          'id'       => 'wc_kickroute_tracking_number_field_name'
      ),
      'tracking_carrier' => array(
          'name'     => __('Tracking Carrier Feldname', 'woocommerce-kickroute'),
          'type'     => 'select',
          'options' => array(
            'dhl'        => __( 'DHL', 'woocommerce' ),
            'ups'        => __( 'UPS', 'woocommerce' ),
            'gls'        => __( 'GLS', 'woocommerce' ),
            'hermes'        => __( 'Hermes', 'woocommerce' ),
            'dpd'        => __( 'DPD', 'woocommerce' ),
            'tnt'        => __( 'TNT', 'woocommerce' ),
            'other'       => __( '-- Sonstige --', 'woocommerce' ),
          ),
          'desc'     => 'Bitte wähle deinen Versanddienstleister aus (Nur erforderlich, wenn du Lieferant bist)',
          'id'       => 'wc_kickroute_tracking_carrier'
      ),
      'publish_products' => array(
          'name'     => __('Ich bin Lieferant', 'woocommerce-kickroute'),
          'type'     => 'checkbox',
          'desc'     => 'Bitte setzte den Haken, falls du Lieferant bist.',
          'id'       => 'wc_kickroute_publish_products'
      ),
      'dropshipping_price_field_name' => array(
          'name'     => __('Dropshipping Preis Feldname (nur von Lieferanten einzutragen)', 'woocommerce-kickroute'),
          'type'     => 'text',
          'desc'     => 'Bitte gebe hier den Namen des Feldes ein, in dem du den Dropshipping Preis für Kickroute Einzelhändler speicherst. (Zur Dokumentation)',
          'id'       => 'wc_kickroute_dropshipping_price_field_name'
      ),
      'example_order_number' => array(
          'name'     => __('Beispiel Bestellnummer (nur für Testzwecke)', 'woocommerce-kickroute'),
          'type'     => 'text',
          'desc'     => 'Bitte gebe eine Bestellnummer ein, um auf Kickroute prüfen zu können, ob die Verbindung korrekt angelegt wurde.',
          'id'       => 'wc_kickroute_example_order_number'
      ),
      'example_product_id' => array(
          'name'     => __('Beispiel Produkt-ID (nur für Testzwecke)', 'woocommerce-kickroute'),
          'type'     => 'text',
          'desc'     => 'Bitte gebe eine Produkt-ID ein, um auf Kickroute prüfen zu können, ob die Verbindung korrekt angelegt wurde.',
          'id'       => 'wc_kickroute_example_product_id'
      ),
      'section_end' => array(
         'type' => 'sectionend',
         'id' => 'wc_kickroute_section_end'
      )
  );
  return apply_filters('wc_kickroute_tab_settings', $settings);
}

// Plugin page settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'kickroute_settings_link');
function kickroute_settings_link($links){
  $settings_link = '<a href="admin.php?page=kickroute">Settings</a>';
  array_unshift($links, $settings_link);
  return $links;
}

add_filter('woocommerce_admin_settings_sanitize_option_wc_kickroute_publish_products', 'kickroute_publish_products_create_cron', 10);
function kickroute_publish_products_create_cron( $value ){
  if ( $value === 'yes' ){
    if ( !wp_next_scheduled( 'kickroute_send_products_batch_hook' ) ) {
      wp_schedule_event( time() + 60, '20min', 'kickroute_send_products_batch_hook' );
    }
  }
  return $value;
}

add_action( 'kickroute_send_products_batch_hook', 'kickroute_publish_products', 10 );
function kickroute_publish_products() {
  set_time_limit(0);
  $publish_products = esc_attr( get_option( 'wc_kickroute_publish_products' ) );
  if ( $publish_products !== 'yes' ){
    return;
  }

  $arr = kickroute_get_next_item_batch();
  if ( sizeof( $arr['payload'] ) === 0 ){
    return;
  }

  $event = json_encode( array(
    'type' => 'products.create',
    'body' => $arr['payload']
  ) );

  $code = kickroute_send_data( $event );
  if ( $code === 200 ){
    foreach( $arr['product_ids'] as $id ){
      update_post_meta( $id, 'imported_to_kickroute', 'yes' );
    }
  }
};

add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'kickroute_handling_custom_meta_query_keys', 10, 3 );
function kickroute_handling_custom_meta_query_keys( $wp_query_args, $query_vars, $data_store_cpt ) {
    $meta_key = 'imported_to_kickroute';
    if ( ! empty( $query_vars[$meta_key] ) ) {
      $val = esc_attr( $query_vars[$meta_key] );
      if ( $val === 'yes' ){
        $wp_query_args['meta_query'][] = array(
          'key' => $meta_key,
          'compare' => '=',
          'value' => 'yes',
        );
      }
      else if ( $val === 'no' ){
        $wp_query_args['meta_query'][] = array(
          'relation' => 'OR',
          array(
            'key' => $meta_key,
            'compare' => '=',
            'value' => 'no',
          ),
          array(
            'key' => $meta_key,
            'compare' => 'NOT EXISTS',
            'value' => '',
          )
        );
      }
    }
    return $wp_query_args;
}

function kickroute_get_next_item_batch(){
  if ( esc_attr( get_option( 'wc_kickroute_offer_page' ) ) === NULL ){
    add_option( 'wc_kickroute_offer_page', 1 );
  }
  $page = esc_attr( get_option( 'wc_kickroute_offer_page' ) );

  $query = wc_get_products( array(
    'status' => 'publish',
    'downloadable' => false,
    //'imported_to_kickroute' => 'no',
    'orderby' => 'date',
    'order' => 'ASC',
    'paginate' => true,
    'page' => $page,
    'limit' => 200,
  ) );

  $products = $query->products;
  if ( $page >= $query->max_num_pages ){
    update_option( 'wc_kickroute_offer_page', 1 );
  }
  else {
    update_option( 'wc_kickroute_offer_page', intval(esc_attr( get_option( 'wc_kickroute_offer_page' ) )) + 1 );
  }

  $payload = array();
  $tax_rate_mapper = array(
    '' => 'standard',
    'reduced-rate' => 'reduced',
    'zero-rate' => 'zero'
  );
  $product_ids = array();
  foreach ( $products as $product ){
    $type = $product->get_type();
    $id = $product->get_id();

    if ( $type === 'simple' ){
      $product_gtin = kickroute_get_gtin( $id );
      $dropshipping_price = kickroute_get_dropshipping_price( $id );
      $category = get_post_meta( $id, '_wc_kickroute_category', true );
      if ( !kickroute_valid_gtin( $product_gtin ) || $dropshipping_price === '' || $dropshipping_price === NULL || !kickroute_valid_category( $category ) ){
        continue;
      }
      $image_url = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
      $obj = array(
        'gtin' => $product_gtin,
        'name' => $product->get_name(),
        'description' => $product->get_description(),
        'is_in_stock' => $product->is_in_stock(),
        'stock_quantity' => $product->get_stock_quantity(),
        'regular_price' => $product->get_regular_price(),
        'wholesale_price' => $dropshipping_price,
        'is_live' => true,
        'tax_rate' => $tax_rate_mapper[$product->get_tax_class()],
        'image_url' => $image_url ? $image_url : '',
        'category' => $category
      );

      array_push( $payload, $obj );
      array_push( $product_ids, $id );
    }
    else if ( $type === 'variable' ){
      $variations = array();
      $category = get_post_meta( $id, '_wc_kickroute_category', true );

      if ( !kickroute_valid_category( $category ) ){
        continue;
      }

      foreach ( $product->get_available_variations() as $variation ){
        $var_gtin = kickroute_get_gtin( $variation['variation_id'] );
        $dropshipping_price = kickroute_get_dropshipping_price( $variation['variation_id'] );
        if ( !kickroute_valid_gtin( $var_gtin ) || $dropshipping_price === '' || $dropshipping_price === NULL ){
          continue;
        }
        $var_product = wc_get_product( $variation['variation_id'] );
        $image_url = wp_get_attachment_image_url( $var_product->get_image_id(), 'full' );
        $var_obj = array(
          'gtin' => $var_gtin,
          'description' => $var_product->get_description(),
          'is_in_stock' => $var_product->is_in_stock(),
          'stock_quantity' => $var_product->get_stock_quantity(),
          'regular_price' => $var_product->get_regular_price(),
          'wholesale_price' => $dropshipping_price,
          'is_live' => true,
          'attributes' => $var_product->get_attributes(),
          'image_url' => $image_url ? $image_url : ''
        );
        array_push( $variations, $var_obj );
      }

      if ( sizeof( $variations ) === 0 ){
        continue;
      }

      $image_url = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
      $obj = array(
        'sku' => $product->get_sku(),
        'name' => $product->get_name(),
        'description' => $product->get_description(),
        'is_live' => true,
        'variations' => $variations,
        'category' => $category,
        'image_url' => $image_url ? $image_url : '',
        'tax_rate' => $tax_rate_mapper[$product->get_tax_class()]
      );

      array_push( $payload, $obj );
      array_push( $product_ids, $id );
    }
  }

  return array(
    'payload' => $payload,
    'product_ids' => $product_ids
  );
}

function kickroute_valid_category( $category ){
  return !( $category === '' || $category === NULL || $category === 'none' );
}

function kickroute_valid_gtin( $product_gtin ){
  return preg_match( '/^[0-9]{12,13}$/', $product_gtin );
}
