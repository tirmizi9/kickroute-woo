<?php
/*
Plugin Name: Dropshipping by Kickroute
Plugin URI: https://kickroute.com
Description: The official connector for WooCommerce Dropshipping through the Kickroute Platform
Version: 1.0.5
Author: Kickroute GmbH
License: GNU Public License v3
*/
defined( 'ABSPATH' ) || exit;

include 'config.php';
include 'utils.php';
include 'agnostic.php';
include 'merchant.php';
include 'supplier.php';
include 'shipping.php';

function kickroute_cron_schedules( $schedules ){
  if( !isset( $schedules["5min"] ) ){
    $schedules["5min"] = array(
      'interval' => 5 * 60,
      'display' => __( 'Once every 5 minutes' )
    );
  }
  if( !isset( $schedules["20min"] ) ){
    $schedules["20min"] = array(
      'interval' => 20 * 60,
      'display' => __( 'Once every 20 minutes' )
    );
  }
  return $schedules;
}
add_filter( 'cron_schedules', 'kickroute_cron_schedules' );

register_activation_hook( __FILE__, 'kickroute_activate' );
function kickroute_activate(){
  global $wpdb;
  $charset_collate = $wpdb->get_charset_collate();

  $table_name = $wpdb->base_prefix . 'kickroute_events';
  $sql = "CREATE TABLE `$table_name` (
    type varchar(50) NOT NULL,
    post_type varchar(20) NOT NULL,
    post_identifier varchar(30) NOT NULL,
    UNIQUE KEY (type, post_type, post_identifier)
  ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  $res = dbDelta( $sql );

  if ( !wp_next_scheduled( 'kickroute_send_events_batch_hook' ) ) {
    wp_schedule_event( time(), '5min', 'kickroute_send_events_batch_hook' );
  }

  if ( esc_attr( get_option( 'wc_kickroute_publish_products' ) ) === 'yes' ){
    if ( !wp_next_scheduled( 'kickroute_send_products_batch_hook' ) ) {
      wp_schedule_event( time() + 60, '20min', 'kickroute_send_products_batch_hook' );
    }
  }
}

register_deactivation_hook( __FILE__, 'kickroute_deactivate' );
function kickroute_deactivate(){
  global $wpdb;
  $table_name = $wpdb->prefix . 'kickroute_events';
  $wpdb->query( "DROP TABLE IF EXISTS $table_name" );

  $timestamp = wp_next_scheduled( 'kickroute_send_events_batch_hook' );
  wp_unschedule_event( $timestamp, 'kickroute_send_events_batch_hook' );

  $timestamp = wp_next_scheduled( 'kickroute_send_products_batch_hook' );
  wp_unschedule_event( $timestamp, 'kickroute_send_products_batch_hook' );
}
