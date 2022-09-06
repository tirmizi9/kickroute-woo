<?php

add_filter( 'pr_shipping_dhl_label_args', 'kickroute_pr_shipping_dhl_label_args_custom', 50, 2 );
function kickroute_pr_shipping_dhl_label_args_custom( $args, $order_id ){
	$order = new WC_Order( $order_id );
	 $kickroute_order_number = $order->get_meta( '_kickroute_order_number' );
	if( isset( $kickroute_order_number ) && $kickroute_order_number != '' ){
		$args['dhl_settings']['shipper_name'] = $order->billing_first_name . " " . $order->billing_last_name;
		$args['dhl_settings']['shipper_company'] = $order->billing_company;
		$args['dhl_settings']['shipper_address'] = $order->billing_address_1;
		$args['dhl_settings']['shipper_address_no'] = ( $order->billing_address_2 != '' ) ? $order->billing_address_2 : ',';
		$args['dhl_settings']['shipper_address_city'] = $order->billing_city;
		$args['dhl_settings']['shipper_address_state'] = $order->billing_state;
		$args['dhl_settings']['shipper_address_zip'] = $order->billing_postcode;
		$args['dhl_settings']['shipper_phone'] = $order->billing_phone;
		$args['dhl_settings']['shipper_email'] = $order->billing_email;
	}
	$args['shipping_address']['phone'] = '';
	return $args;
}

add_filter( 'woocommerce_gzd_dhl_label_api_shipper_name1', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_name1', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_name2', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_name2', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_street_name', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_street_name', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_street_number', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_street_number', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_zip', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_zip', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_city', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_city', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_state', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_state', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_country', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_country', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_phone', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_phone', 50, 2 );
add_filter( 'woocommerce_gzd_dhl_label_api_shipper_email', 'kickroute_woocommerce_gzd_dhl_label_api_shipper_email', 50, 2 );
function kickroute_woocommerce_gzd_dhl_label_api_shipper_name1( $shipper_name, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$shipper_name = $order->billing_company;
	}
	return $shipper_name;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_name2( $shipper_name, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$shipper_name =  $order->billing_first_name." ".$order->billing_last_name;;
	}
	return $shipper_name;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_street_name( $street_name, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$street_name = $order->billing_address_1;
	}
	return $street_name;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_street_number( $street_number, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$street_number =($order->billing_address_2 != '') ? $order->billing_address_2 : ',';
	}
	return $street_number;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_zip( $zip, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$zip = $order->billing_postcode;
	}
	return $zip;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_city( $city, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$city = $order->billing_city;
	}
	return $city;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_state( $state, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$state = $order->billing_state;
	}
	return $state;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_country( $country, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$country = $order->billing_country;
	}
	return $country;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_phone( $phone, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$phone = $order->billing_phone;
	}
	return $phone;
}

function kickroute_woocommerce_gzd_dhl_label_api_shipper_email( $email, $label ){
	$shipment = $label->get_shipment();
	$order =  $shipment ? $shipment->get_order() : '';
	$kickroute_order_number = $order->get_meta('_kickroute_order_number');
	if(isset($kickroute_order_number) && $kickroute_order_number != ''){
		$email = $order->billing_email;
	}
	return $email;
}
