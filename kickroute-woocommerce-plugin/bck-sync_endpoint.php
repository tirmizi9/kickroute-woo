<?php
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');

function kickroute_handle_custom_query_var( $query, $query_vars ) {
	if ( ! empty( $query_vars['_kickroute_order_number'] ) ) {
		$query['meta_query'][] = array(
			'key' => '_kickroute_order_number',
			'value' => esc_attr( $query_vars['_kickroute_order_number'] ),
		);
	}

	return $query;
}
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'kickroute_handle_custom_query_var', 10, 2 );


function has_valid_kickroute_signature( $request ){
	$header_signature = $request->get_header( 'X-Kickroute-Signature' );
	$token = kickroute_decrypt_key( esc_attr( get_option( 'wc_kickroute_key' ) ) );


	if ( $token === '' || $header_signature === '' ){
		return false;
	}

	$signature = hash_hmac( 'sha256', $request->get_body(), $token );
	if ( !hash_equals( $signature, $header_signature ) ){
		return new WP_Error(
			'rest_no_route',
			'No route was found matching the URL and request method',
			array( 'status' => 404 )
		);
	}
	return true;
}

add_action( 'rest_api_init', 'kickroute_register_custom_endpoints' );
function kickroute_register_custom_endpoints(){
	register_rest_route( 'wc/v3', 'kickroute_callback_endpoint', array(
    'methods' => 'POST',
    'callback' => 'kickroute_react_to_webhook' /*,
	'permission_callback' => 'has_valid_kickroute_signature' */
  ) );
}

function kickroute_react_to_requested_order_number( $data ){
  $orders = wc_get_orders( array( '_kickroute_order_number' => $data['kickroute_order_number'] ) );
  if ( sizeof( $orders ) === 0 ){
    return new WP_Error(
      'rest_no_order_for_kickroute_order_number',
      'No order was found for the supplied kickroute order number.',
      array( 'status' => 404 )
    );
  }
  return array(
		'status' => 200,
		'number' => $orders[0]->get_order_number()
	);
}

add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'kickroute_handle_order_number_custom_query_var', 10, 2 );
function kickroute_handle_order_number_custom_query_var( $query, $query_vars ) {
    if ( ! empty( $query_vars['number'] ) ) {
        $query['meta_query'][] = array(
            'key' => '_order_number',
            'value' => esc_attr( $query_vars['number'] ),
        );
    }

    return $query;
}

function kickroute_react_to_webhook( $request ){
	try {
		$event = json_decode( $request->get_body(), true );
		switch( $event['type'] ){
			case 'item.updated':
				return kickroute_react_to_updated_item( $event['body'] );
			case 'item.updated_price':
				return kickroute_react_to_updated_price( $event['body'] );
			case 'order.updated':
				return kickroute_react_to_updated_order( $event['body'] );
			case 'order.placed':
				return kickroute_react_to_placed_order( $event['body'] );
			case 'order.requested_number':
				return kickroute_react_to_requested_order_number( $event['body'] );
			case 'products.import':
				return kickroute_react_to_product_import( $event['body'] );
		}
	} catch ( Exception $e ){
		return array(
			'code' => 500,
			'exception' => $e->getMessage()
		);
	}
}

function kickroute_react_to_updated_item( $body ){
	$item = kickroute_get_item_by_gtin( $body['gtin'] );

	if ( $item === NULL ){
		return array( 'status' => 200 );
	}

	if (
		array_key_exists( 'stock_quantity', $body ) && array_key_exists( 'is_in_stock', $body )
		&& get_post_meta( $item->get_id(), '_kickroute_stock_update_lock', true ) !== 'yes' ){
		$is_in_stock = $body['is_in_stock'];
		$stock_quantity = $body['stock_quantity'];
		$is_in_stock_mapper = array(
			false => 'outofstock',
			true => 'instock'
		);

		$item->set_manage_stock( $stock_quantity !== NULL );
		$item->set_stock_status( $is_in_stock_mapper[$is_in_stock] );
		$item->set_stock_quantity( $stock_quantity );
	}
	$item->save();

	return array( 'status' => 200 );
}


function kickroute_react_to_updated_order( $body ){
	$order = wc_get_order( $body['number'] );

	if ( $order === NULL ){
		return new WP_Error(
			'rest_no_item_found',
			'No item was found for the supplied GTIN.',
			array(
				'status' => 404,
				'number' => $body['number']
			)
		);
	}

	if ( array_key_exists( 'tracking', $body ) ){
		if ( $body['tracking']['number'] !== '' && $body['tracking']['carrier'] !== '' ){
			$id = $order->get_id();
			$field_name = esc_attr( get_option( 'wc_kickroute_tracking_number_field_name' ) );
			update_post_meta( $id, $field_name, esc_attr( $body['tracking']['number'] ) );
			update_post_meta( $id, 'kickroute_tracking_carrier', esc_attr( $body['tracking']['carrier'] ) );
		}
	}

	if ( array_key_exists( 'status', $body ) ){
		if ( $body['status'] !== '' ){
			$status_mapping = array(
				'pending-payment' => 'pending',
				'on-hold' => 'on-hold',
				'awaiting-fulfillment' => 'processing',
				'completed' => 'completed',
				'refunded' => 'refunded',
				'cancelled' => 'cancelled'
			);
			$new_status = $status_mapping[$body['status']];

			// Redundancy: in case a mistaken webhook is received (for whatever
			// reason), this prevents it from being computed.
			$allowed_after_completed = array( 'refunded', 'cancelled' );
			if (
				( $order->get_status() === 'completed' || $order->get_status() === 'refunded' || $order->get_status() === 'cancelled' )
				&&
				( !in_array( $new_status, $allowed_after_completed ) ) ){
				return array( 'status' => 200 );
			}

			$order->update_status( $new_status );
		}
	}

	return array( 'status' => 200 );
}

function kickroute_react_to_placed_order( $body ){
	// Redundancy: for reliability purposes we check if an order with the same
	// kickroute order number was already created. If so, we return.
	// We wouldn't ever want to double-place an order for the same order.
	$orders = wc_get_orders( array(
	  'limit'        => -1, // Query all orders
	  'meta_key'     => '_kickroute_order_number', // The postmeta key field
		'meta_value'   => $body['kickroute_order_number'],
	));

	if ( sizeof( $orders ) > 0 ){
		return array( 'status' => 200 );
	}

	$status_mapping = array(
		'pending-payment' => 'pending',
		'on-hold' => 'on-hold',
		'awaiting-fulfillment' => 'processing',
		'completed' => 'completed',
		'refunded' => 'refunded',
		'cancelled' => 'cancelled'
	);
	$new_status = $status_mapping[$body['status']];

	$shipping = $body['shipping'];
	$shipping_address = array(
	  'first_name' => $shipping['first_name'],
	  'last_name' => $shipping['last_name'],
	  'company' => $shipping['company'],
	  'address_1' => $shipping['address_1'],
	  'address_2' => $shipping['address_2'],
	  'city'  => $shipping['city'],
	  'state' => $shipping['state'],
	  'postcode' => $shipping['postcode'],
	  'country' => $shipping['country_code']
  );

	$billing = $body['billing'];
	$billing_address = array(
	  'first_name' => $billing['first_name'],
	  'last_name' => $billing['last_name'],
	  'company' => $billing['company'],
	  'email' => $billing['email'],
	  'phone' => $billing['phone'],
	  'address_1' => $billing['address_1'],
	  'city'  => $billing['city'],
	  'state' => $billing['state'],
	  'postcode' => $billing['postcode'],
	  'country' => $billing['country_code']
  );

  // Now we create the order
  $order = new WC_Order();

	$user = get_user_by( 'email', $billing['email'] );
	if ( $user ){
		$order->set_customer_id( $user->ID );
	}

	$order->set_address( $shipping_address, 'shipping' );
	$order->set_address( $billing_address, 'billing' );

	foreach ( $body['items'] as $item ){
		$item_obj = kickroute_get_item_by_gtin( $item['gtin'] );
		if ( $item_obj === NULL ){
			return new WP_Error(
	      'rest_no_item_found',
	      'No item was found for the supplied GTIN.',
	      array(
					'status' => 404,
					'gtin' => $item['gtin']
				)
	    );
		}
		if ( !kickroute_has_enough_stock( $item_obj, $item['quantity'] ) ){
			return new WP_Error(
				'rest_not_enough_stock',
				'The item does not have enough stock.',
				array(
					'status' => 428,
					'gtin' => $item['gtin']
				)
			);
		}
		$order->add_product( $item_obj, $item['quantity'], array(
			'subtotal' => $item['net_total'],
			'total' => $item['net_total']
		) );
	}

	$item = new WC_Order_Item_Shipping();
	$item->set_method_title( 'Flat rate' );
	$item->set_total( $body['shipping_net_total'] );
	$order->add_item( $item );

	$order->calculate_totals();

	$order->update_meta_data( '_kickroute_order_number', $body['kickroute_order_number'] );
	$order->add_order_note( 'Kickroute Dropshipping Bestellung' );
	$order->update_status( $new_status );

	$order->save();

	$order_id = $order->get_id();
	do_action( 'woocommerce_new_order', $order_id );
	return array(
		'status' => 201,
		'number' => $order->get_order_number()
	);
}

function kickroute_react_to_updated_price( $body ){
	$item = kickroute_get_item_by_gtin( $body['gtin'] );

	if ( $item === NULL ){
		return array( 'status' => 404 );
	}

	if ( !array_key_exists( 'new_price', $body ) ){
		return array( 'status' => 400 );
	}

	if ( (float)$body['new_price'] >= wc_get_price_excluding_tax( $item ) ){
		update_post_meta( $item->get_id(), '_kickroute_stock_update_lock', 'yes' );
		$item->set_stock_quantity(0);
		$item->save();
	}
	else {
		update_post_meta( $item->get_id(), '_kickroute_stock_update_lock', 'no' );
		$item->set_stock_quantity( $body['stock_quantity'] );
		$item->save();
	}

	return array( 'status' => 200 );
}

function kickroute_has_enough_stock( $item, $quantity ){
	if ( $item->managing_stock() ){
		return $item->get_stock_quantity() >= $quantity;
	}
	return $item->is_in_stock();
}


function kickroute_react_to_product_import( $body ){
	$gtin_field_name = esc_attr( get_option( 'wc_kickroute_gtin_field_name' ) );
	if ( $gtin_field_name === '' || $gtin_field_name === NULL ){
		return;
	}

	foreach( $body as $product_data ){

		$post = array(
			'post_title'  => $product_data['name'],
			'post_parent' => '',
			'post_status' => 'publish',
			'post_type'   => 'product',
		);
		$product_id = wp_insert_post( $post );

		if ( isset( $product_data['gtin'] ) ){
			update_post_meta( $product_id, $gtin_field_name, $product_data['gtin'] );
		}

		if ( isset( $product_data['tax_rate'] ) ){
			$mapping = array(
				'standard' => '',
				'reduced' => 'reduced-rate'
			);
			update_post_meta( $product_id, '_tax_class', $mapping[$product_data['tax_rate']] );
		}

		if ( isset( $product_data['regular_price'] ) ){
			update_post_meta( $product_id, '_price', $product_data['regular_price'] );
			update_post_meta( $product_id, '_regular_price', $product_data['regular_price'] );
		}

		if ( isset( $product_data['is_in_stock'] ) ){
			$is_in_stock_mapper = array(
				false => 'outofstock',
				true => 'instock',
			);
			update_post_meta( $product_id, '_stock_status', $is_in_stock_mapper[$product_data['is_in_stock']] );
		}

		if ( isset( $product_data['stock_quantity'] ) ){
			update_post_meta( $product_id, '_manage_stock', $product_data['stock_quantity'] !== NULL );
			update_post_meta( $product_id, '_stock', $product_data['stock_quantity'] );
		}

		if ( isset( $product_data['supplier_number'] ) ){
			update_post_meta( $product_id, '_wc_kickroute_supplier_number', $product_data['supplier_number'] );
		}

		if ( isset( $product_data['image_url'] ) ){
			$attachment_id = media_sideload_image( $product_data['image_url'], $product_id, '', 'id' );
			set_post_thumbnail( $product_id, $attachment_id );
		}

		if ( isset ( $product_data['variations'] ) ){
			if ( sizeof( $product_data['variations'] ) > 0 ){
				wp_set_object_terms( $product_id, 'variable', 'product_type', false );
				kickroute_create_product_attributes( $product_id, $product_data['variations'] );
				kickroute_create_product_variation( $product_id, $product_data['variations'], $product_data['supplier_number'] );
			}
		}
	}

	return array( 'status' => 200 );
}

/**
* @since 1.0.3
* Create product attributes
*/
function kickroute_create_product_attributes( $post_id, $variations ) {
	$attr = array();
	if( $variations ){
		foreach ( $variations as $single_variation ){
			foreach ( $single_variation['attributes'] as $sing_att_arr ) {
				$attr[ strtolower( $sing_att_arr['name'] ) ][] = $sing_att_arr['value'];
			}
		}
	}

	$attrs      = array();
	$attributes = wc_get_attribute_taxonomies();
	foreach ( $attributes as $key => $value ) {
		array_push( $attrs, $attributes[ $key ]->attribute_name );
	}

	if ( $attr ) {
		foreach ( $attr as $key => $real_attr ) {
			// If taxonomy doesn't exists we create it.
			if ( ! in_array( $key, $attrs ) ) {
				$keyslug = str_replace(' ', '-', strtolower($key));
				$args = array(
					'id'           => '',
					'slug'         => $keyslug,
					'name'         => ucfirst( $key ),
					'type'         => 'select',
					'orderby'      => 'menu_order',
					'has_archives' => false,
					'limit'        => 1,
					'is_in_stock'  => 1,
				);
				wc_create_attribute( $args );
				register_taxonomy(
					'pa_' . $keyslug,
					'product',
					array(
						'label' => ucfirst(  $key ),
						'rewrite' => array( 'slug' => $keyslug ),
						'hierarchical' => true,
					)
				);
			}
			$len = count($real_attr) ; $real_attr_arr = array();
			for($r=0;$r<$len;$r++){
				$real_attr_arr[$r] = str_replace(' ', '-', strtolower($real_attr[$r]));
			}
			wp_set_object_terms( $post_id, $real_attr, 'pa_' . $keyslug );
		}
	}

	$product_attributes_data = array();
	if ( $attr ) {
		foreach ( $attr as $attribute => $attr_value ) {
			$attributee = str_replace(' ', '-', strtolower($attribute));
			$product_attributes_data[ 'pa_' . $attributee ] = array(
				'name'         => 'pa_' . $attributee,
				'value'        => '',
				'is_visible'   => '1',
				'is_variation' => '1',
				'is_taxonomy'  => '1',
			);
		}
	}
	update_post_meta( $post_id, '_product_attributes', $product_attributes_data );
}


function kickroute_create_product_variation( $product_id, $variation_data, $supplier_number ){
  // Get the Variable product object (parent)
  $product = wc_get_product( $product_id );
	if( $variation_data ){
		foreach( $variation_data as $single_variation ){
			$variation_post = array(
			  'post_title' => $product->get_name(),
			  'post_name' => 'product-'.$product_id.'-variation',
			  'post_status' => 'publish',
			  'post_parent' => $product_id,
			  'post_type' => 'product_variation',
			);

			$variation_id = wp_insert_post( $variation_post );
			//Insert attribures as variation
			foreach ( $single_variation['attributes'] as $sing_att_arr ) {
				$valuee = str_replace(' ', '-', strtolower($sing_att_arr['value']));
				$namee = str_replace(' ', '-', strtolower($sing_att_arr['name']));
				$attribute_term = get_term_by( 'name', $sing_att_arr['value'], 'pa_' . $namee );
				$attr_slug = isset( $attribute_term->slug ) ? $attribute_term->slug : $sing_att_arr['value'];
				update_post_meta( $variation_id, 'attribute_pa_' . $namee, $attr_slug);
			}
			update_post_meta( $variation_id, '_wc_kickroute_supplier_number', $supplier_number );

			$variation = new WC_Product_Variation( $variation_id );

			## Set/save all other data

			if( isset( $single_variation['sku'] ) )
			  $variation->set_sku( $single_variation['sku'] );

			if( isset( $single_variation['regular_price'] ) ){
					$variation->set_price( $single_variation['regular_price'] );
					$variation->set_regular_price( $single_variation['regular_price'] );
			}

			if( isset( $single_variation['stock_quantity' ]) ){
				//$variation->set_manage_stock( $single_variation['stock_quantity'] !== NULL );
				//$variation->set_stock_quantity( $single_variation['stock_quantity'] );
				update_post_meta( $variation_id, '_manage_stock', $single_variation['stock_quantity'] !== null );
				update_post_meta( $variation_id, '_stock', $single_variation['stock_quantity'] );
			} else {
			  $variation->set_manage_stock( false );
			}

			if ( isset( $single_variation['gtin'] ) ){
				$gtin_field_name = esc_attr( get_option( 'wc_kickroute_gtin_field_name' ) );
				update_post_meta( $variation_id, $gtin_field_name, $single_variation['gtin'] );
			}

			if ( isset( $single_variation['supplier_number'] ) ){
				update_post_meta( $variation_id, '_wc_kickroute_supplier_number', $single_variation['supplier_number'] );
			}

			if ( isset( $single_variation['is_in_stock'] ) ){
				$stock_quantity_stock = $single_variation['stock_quantity'] == 0 ? false : $single_variation['is_in_stock'];
				$is_in_stock_mapper = array(
					false => 'outofstock',
					true => 'instock'
				);
				//$variation->set_stock_status( $is_in_stock_mapper[$single_variation['is_in_stock']] );
				update_post_meta( $variation_id, '_stock_status', $is_in_stock_mapper[ $stock_quantity_stock ] );
			}

			if ( isset( $single_variation['image_url'] ) ){
				$attachment_id = media_sideload_image( $single_variation['image_url'], $single_variation, '', 'id' );
				set_post_thumbnail( $variation_id, $attachment_id );
			}

			$variation->save(); // Save the data
		}
	}
}
