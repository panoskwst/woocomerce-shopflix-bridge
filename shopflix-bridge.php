<?php
/*
Plugin Name: shopflix-bridge
Description: A plugin to synchronize orders in WooCommerce and Shopflix.
Version: 0.1.1
Author: Panagiotis Kostolias
Author URI:	https://github.com/panoskwst
*/

require_once(plugin_dir_path(__FILE__) . 'admin/admin-settings.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(plugin_dir_path(__FILE__) . 'classes/shopflix-bridge-class.php');

// Activation hook
register_activation_hook( __FILE__, 'shopflix_bridge_activate' );

// Deactivation hook
register_deactivation_hook(__FILE__, 'shopflix_bridge_deactivate');

// actions

add_action('init', 'shopflix_register_rewrite_rule');
add_action('template_redirect', 'handle_shopflix_cron_endpoint');
add_action('add_meta_boxes','shopflix_order_metabox', 10, 1);

add_action('woocommerce_order_status_changed', 'shopflix_order_status_change_hook', 10,3); 

// endpoint for the cron job
function shopflix_bridge_activate() {
	writeToLogFiles('i got created');
	// a rewrite rule to handle the endpoint
    shopflix_register_rewrite_rule();
    
	flush_rewrite_rules();
}

function shopflix_register_rewrite_rule(){
	
	add_rewrite_rule('^shopflix-crons$', 'index.php?shopflix_cron=1', 'top');
	 // Add a filter to register the 'shopflix_cron' query variable
    add_filter('query_vars', 'shopflix_query_var');
}

function shopflix_query_var($vars) {
	$vars[] = 'shopflix_cron';
	return $vars;
}


function shopflix_bridge_deactivate() {
    writeToLogFiles('i got deleted');
	// Remove the url hook
    flush_rewrite_rules(); 
}

// log files creation

 function writeToLogFiles($lektiko, $prefix = 'order_') {
   $filename = date('YmdHi');
  $logFile = fopen(__DIR__ . '/logs/' . $prefix . $filename . '.log', "a+");
   
  if ($logFile) {
       $logEntry = date('Y-m-d H:i:s') . ' - ' . $lektiko . PHP_EOL;
      fwrite($logFile, $logEntry);
       fclose($logFile);
   } else {
       die('Unable to open or create log file.');
   }
}

function handle_shopflix_cron_endpoint() {

	if (get_query_var('shopflix_cron')) {
		writeToLogFiles('The cron job is triggered');

		$Shopflix_Bridge = new Shopflix_Bridge(); 
        // functions to execute when the endpoint is accessed
        $Shopflix_Bridge->check_shopflix_orders();
		$Shopflix_Bridge->check_cancelled_shopflix_orders();

		writeToLogFiles('The cron job executed with success');
        // send a response to the server cron
        die('shopflix cron job executed.');
		
    }
}


function shopflix_order_metabox($post_type) {
    writeToLogFiles('Executing shopflix_order_metabox function');
	$current_screen = get_current_screen();
	
	if($post_type === 'shop_order' && $current_screen->id === 'shop_order'){
		global $post;
    	$order = wc_get_order($post->ID);
		if ($order && $order->get_created_via() === 'Shopflix-Bridge') {
			add_meta_box(
				'shopflix_metabox', // Meta box ID
            	'Shopflix Bridge', // Meta box Title
            	'shopflix_order_meta_box_html', // Callback
            	'shop_order', // Screen to add the meta box
            	'side' // Context

			);
		}
	}
}

function shopflix_order_meta_box_html() {
		global $post;
		$order = wc_get_order($post->ID);
		$shipment_id = $order->get_meta('Shopflix_shipment_id');
		if (empty($shipment_id)){
		writeToLogFiles('the shipment id is empty inside shopflix_order_meta_box_html');
		}
		writeToLogFiles('shipment Id inside the order: ' . $shipment_id);
		$shopflix_order_id = $order->get_order_key();
		$shopflix_pickup_date = $order->get_meta('_Courier_pickup_date');
		$shopflix_delivery_date = $order->get_meta('_Courier_delivery_date');
		$shopflix_voucher_link = $order->get_meta('_Shopflix_voucher_link');
		$vat = $order->get_meta('_billing_vat_id');
		writeToLogFiles('$shopflix_voucher_link inside the order: ' . $shopflix_voucher_link);
		render_shopflix_order_meta_box_html($shipment_id,$shopflix_order_id,$shopflix_pickup_date,$shopflix_delivery_date,$shopflix_voucher_link,$vat);
}

function render_shopflix_order_meta_box_html($shipment_id,$shopflix_order_id,$shopflix_pickup_date,$shopflix_delivery_date,$shopflix_voucher_link,$vat) {
    // Output the metadata
    echo '<h2>Shopflix Order Details</h2>';
	echo '<p><strong>Shopflix Shipment Id:</strong> ' . esc_html($shipment_id) . '</p>';
	if (empty($shipment_id)){
		writeToLogFiles('the shipment id is empty inside render_shopflix_order_meta_box_html');
	}
	writeToLogFiles('The shipment id inside render_shopflix_order_meta_box_html is: '. $shipment_id);
	echo '<p><strong>Shopflix Order Id:</strong> ' . esc_html($shopflix_order_id) . '</p>';
	echo '<p><strong>Shopflix Courier pickup Date:</strong> ' . esc_html($shopflix_pickup_date) . '</p>';
	echo '<p><strong>Shopflix Delivery Date:</strong> ' . esc_html($shopflix_delivery_date) . '</p>';
	if (!empty($shopflix_voucher_link)) {
		
		// echo '<p><strong>Shopflix Voucher Link:</strong> ' . esc_html($shopflix_voucher_link) . '</p>';
		
		echo '<p><a href="' . esc_url($shopflix_voucher_link) . '" class="button" target="_blank">Download Voucher</a></p>';
	}
	writeToLogFiles('The voucher inside render_shopflix_order_meta_box_html is: '. $shopflix_voucher_link);
	if(!empty($vat)){
		echo '<p><strong>VAT:</strong> ' . esc_html($vat) . '</p>';
	}

}


// update woocomerce cancelled orders
function cancel_shopflix_order($order_key){

	$cancelled_order_id = wc_get_order_id_by_order_key($order_key);

	if ($cancelled_order_id) {
		$cancelled_order = wc_get_order($cancelled_order_id);
		$created = $cancelled_order->get_created_via();
		$status = $cancelled_order->get_status();
	if($created === 'Shopflix-Bridge' && $status != 'cancelled' ){
		// Update meta data
		$cancelled_order->update_meta_data('_Shopflix_cancelled', 'Yes');
		$cancelled_order->save();

		// Update order status to 'cancelled'
		$cancelled_order->update_status('cancelled');
		writeToLogFiles('The order ' . $cancelled_order_id . ' cancelled successfully!');
	} elseif($created === 'Shopflix-Bridge' && $status === 'cancelled') {
		writeToLogFiles('The order ' . $cancelled_order_id . ' is already cancelled!');
	}

	} else {
		// Handle the case where no order is found with the specified order key
		writeToLogFiles('No order found with the specified order key: ' . $order_key);
	}

}


// Add a action hook to listen for order status changes
function shopflix_order_status_change_hook($order_id, $old_status, $new_status) {
	$shopflix_order_updater = new Shopflix_Bridge();
	$order = wc_get_order($order_id);
	writeToLogFiles('Order Id: ' . $order_id);
    $order_key = $order->get_order_key();
	writeToLogFiles('Order Key: ' . $order_key);

	$order_payment_method = $order->get_payment_method();
	$order_customer_canselled = $order->get_meta('_Shopflix_cancelled');
	writeToLogFiles('$order_customer_cancelled is set to: '. $order_customer_canselled);
	writeToLogFiles('Payment method: '. $order_payment_method . 'Is cancelled by the customer');
	$status_transitions = array(
		'processing' => 'G',
		'cancelled' => 'D',
	);
	
	if ($order_payment_method === 'shopflix' && isset($status_transitions[$new_status]) && $order_customer_canselled != 'Yes' ) {

		$shopflix_status = $status_transitions[$new_status]; // Change to Shopflix order identifier
		$url = 'https://staging.wellcomm.store/api/orders/' . $order_key; // correct url
		writeToLogFiles('The Url: ' . $url);
		$shopflix_order_updater->curl_request($url,'PUT',$shopflix_status);
	}

	if ($order_payment_method === 'shopflix' && $new_status === 'completed') {
		writeToLogFiles('We have catch a new status update to complete');
		$order_shipping_ids = $order->get_meta('Shopflix_shipment_id');
		writeToLogFiles($order_shipping_ids);
		// writeToLogFiles('The order shipping id exists and it is: ' . $order_shipping_id );
		$shopflix_order_updater->print_shopflix_voucher($order_shipping_ids);
		$voucher_link = $shopflix_order_updater->get_voucher_link();
		// $voucher_link = plugin_dir_path(__FILE__) . '/vauchers/' . $order_shipping_ids . '.pdf'; 
		// $voucher_link = 'https://testing.tsampashop.gr/wp-content/plugins/shopflix-bridge/vauchers/' . $order_shipping_ids . '.pdf';
		$order->update_meta_data('_Shopflix_voucher_link',$voucher_link);
		$order->save();
		
	}

}

// email filter

add_filter('woocommerce_email_recipient_customer_new_order', 'exclude_email_for_shopflix_orders', 10, 2);
add_filter('woocommerce_email_recipient_customer_on_hold_order', 'exclude_email_for_shopflix_orders', 10, 2);
add_filter('woocommerce_email_recipient_customer_processing_order', 'exclude_email_for_shopflix_orders', 10, 2);
add_filter('woocommerce_email_recipient_customer_completed_order', 'exclude_email_for_shopflix_orders', 10, 2);

function exclude_email_for_shopflix_orders($recipient, $order) {
	
	if( ! is_a($order, 'WC_Order') ){
		
		return $recipient;
	}
	
	$created_via = $order->get_created_via();

    // Check if the 'created_via' field has the value
    if ($created_via === 'Shopflix-Bridge') {
        
        return '';
    }

    // If it doesn't match, return the original recipient
    return $recipient;
}



// woocomerce search fields

add_action('restrict_manage_posts', 'shopflix_orders_filter_dropdown');

function shopflix_orders_filter_dropdown() {
    global $typenow;

    if ($typenow == 'shop_order') {
        $selected = isset($_GET['_created_via']) ? $_GET['_created_via'] : '';
        ?>
        <select name="_created_via">
            <option value=""><?php _e('Show all orders', 'shopflix-bridge'); ?></option>
            <option value="Shopflix-Bridge" <?php selected($selected, 'Shopflix-Bridge'); ?>><?php _e('shopflix-orders', 'shopflix-bridge'); ?></option>
        </select>
        <?php
    }
}

add_filter('woocommerce_shop_order_search_fields', 'add_created_via_to_order_search');

function add_created_via_to_order_search($search_fields) {
    $search_fields[] = '_created_via';
    return $search_fields;
}

add_action('woocommerce_shop_order_query', 'filter_orders_by_created_via', 10, 1);

function filter_orders_by_created_via($query) {
    if (!is_admin()) return;

    $value = isset($_GET['_created_via']) ? $_GET['_created_via'] : '';

    if (!empty($value) && $value === 'Shopflix-Bridge') {
        $query->set('meta_key', '_created_via');
        $query->set('meta_value', 'Shopflix-Bridge');
    }
}

// add shopflix orders images 

add_filter( 'manage_shop_order_posts_custom_column', 'shopflix_order_tracking_list',1 );
function shopflix_order_tracking_list( $column ) {

	global $post, $the_order;

	if ( empty( $the_order ) || $the_order->get_id() !== $post->ID ) {
		$the_order = wc_get_order( $post->ID );
	}

	if ( empty( $the_order ) ) {
		return;
	}

	$is_shopflix = false;
	if (strpos($the_order->get_created_via(), 'Shopflix-Bridge') !== false) {
		$is_shopflix = true;
	}

    if ( !empty($is_shopflix) && $column == 'order_number' ) {
    	echo '<img width=20 src="' . rtrim(plugin_dir_url(__FILE__),'/') . '/img/shopflix-logo.png'.'" style="position: absolute;transform: translate(-10px, -10px);">';
        $transform=25;
    }

}

