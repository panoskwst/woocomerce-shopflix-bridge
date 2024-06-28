<?php

require_once plugin_dir_path(__FILE__) . '../shopflix-bridge.php';

class Shopflix_Bridge {

	private $shopflix_username;
    private $shopflix_password;
	
	public function __construct() {
		
		// Shopflix API credentials
		
		$this->shopflix_username = get_option('shopflix_username');
		$this->shopflix_password = get_option('shopflix_password');
		
	}
	
	public function curl_request($url,$method = 'GET',$shopflix_status = null){
		
		// $url = 'https://staging.wellcomm.store/api/courier/' . $shipment_id; // correct url
		writeToLogFile('The Url: ' . $url);
		$curl = curl_init();
		$curlOptions = array (
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			// CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1)',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_HTTPHEADER => array(
				'Authorization: Basic ' . base64_encode( $this->shopflix_username .':'. $this->shopflix_password ),
				"Content-Type: application/json",
			),
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_HTTPAUTH => CURLAUTH_BASIC
		);
		
		// handle order acceptance
		if ($method == 'PUT') {
			
			$curlOptions[CURLOPT_POSTFIELDS] = '{"status":"'. $shopflix_status . '"}';
		}
		
		curl_setopt_array($curl, $curlOptions);

		$response = curl_exec($curl);
		$info = curl_getinfo($curl);
		curl_close($curl);
		return array ('response' => $response, 'info' => $info);
	}
	
	public function check_cancelled_shopflix_orders(){
		writeToLogFile('We are now checking for canceled orders');

		$page = 1;
		do{
			$url = 'https://staging.wellcomm.store/api/orders/?status=I&page='. $page; 
			$result = $this->curl_request($url);
			$response = $result['response'];
			$info = $result['info'];
			$cancelled_orders = json_decode($response,true);
			$existing_order_keys = $this->get_existing_order_keys_from_woocommerce();
			if (is_wp_error($existing_order_keys)) {

				writeToLogFile('Error fetching existing order keys from WooCommerce: ' . $existing_order_keys->get_error_message());
				return;
			}
			if (isset($cancelled_orders['orders']) && is_array($cancelled_orders['orders'])) {
				$order_ids = array(); // Initialize an array to store order_ids

				foreach ($cancelled_orders['orders'] as $order) {
					// Check if "order_id" key exists in the order
					if (isset($order['order_id'])) {
						$order_id = $order['order_id'];
						writeToLogFile('Order id of the caceled order to check for the key: '. $order_id);
						$order_ids[] = $order_id; // Store order_id in the array
						if (!in_array($order_id, $existing_order_keys)) {
							// If it doesn't exist all good or all very bad
							writeToLogFile('The order_key was not found in another order, check if something is wrong with the order');

						} else {
							writeToLogFile('The order_key was found in another order. We will continue with the change of status');
							$this->cancel_shopflix_order($order_id);
							// continue;
						}
					} else {
						writeToLogFile('The order_key was not found in the shopflix order.');
						continue;
					}

				}
			} else {

				writeToLogFile("Error: 'orders' key not found or not an array in the response.");
				return;
			}

			$page++;	
		} while ($info['http_code'] == 200 && count($cancelled_orders['orders']) == 20);

	}
	
	// update woocomerce cancelled orders
	public function cancel_shopflix_order($order_key){

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
				writeToLogFile('The order ' . $cancelled_order_id . ' cancelled successfully!');
			} elseif($created === 'Shopflix-Bridge' && $status === 'cancelled') {
				writeToLogFile('The order ' . $cancelled_order_id . ' is already cancelled!');
			}

		} else {
			// Handle the case where no order is found with the specified order key
			writeToLogFile('No order found with the specified order key: ' . $order_key);
		}

	}

	
	public function check_shopflix_orders(){
		$page = 1;
		do {
			// Make an HTTP request to an API to get shopflix order data
			$api_url = 'https://staging.wellcomm.store/api/orders/?status=O&page='. $page; 
			writeToLogFile('Before making the API request');
			$result = $this->curl_request($api_url);
			$response = $result['response'];
			$info = $result['info'];
			// debuging check
			writeToLogFile('After making the API request. Response:' . json_encode($response). 'and:' . json_encode($info));	
			$shopflix_orders = json_decode($response,true);
			$existing_order_keys = $this->get_existing_order_keys_from_woocommerce();		
			if (is_wp_error($existing_order_keys)) {

				writeToLogFile('Error fetching existing order keys from WooCommerce: ' . $existing_order_keys->get_error_message());
				return;
			}
			// Check if "orders"  exists and it's an array
			if (isset($shopflix_orders['orders']) && is_array($shopflix_orders['orders'])) {
				$order_ids = array(); // Initialize an array to store order_ids

				foreach ($shopflix_orders['orders'] as $order) {
					// Check if "order_id" key exists in the order
					if (isset($order['order_id'])) {
						$order_id = $order['order_id'];
						writeToLogFile('Order id to become key: '. $order_id);
						$order_ids[] = $order_id; // Store order_id in the array
						if (!in_array($order_id, $existing_order_keys)) {
							// If it doesn't exist create the order
							$this->fetch_shopflix_order($order_id);
						} else {
							writeToLogFile('The order_key was found in another order.');
							continue;
						}
					} else {
						writeToLogFile('The order_key was found in the shopflix order.');
						continue;
					}

				}
			} else {

				writeToLogFile("Error: 'orders' key not found or not an array in the response.");
				return;
			}

			$page++;	
		} while ($info['http_code'] == 200 && count($shopflix_orders['orders']) == 20);

	}


	// Function to create an order
	public function fetch_shopflix_order($order_id) {
		$plugin_dir = dirname(plugin_dir_path( __FILE__ ) . '/Shopflix-Bridge');
		wp_mkdir_p($plugin_dir ."locked-orders");
		$lock_file = $plugin_dir . "/locked-orders/{$order_id}.lock";

		if (file_exists($lock_file)) {
			// Lock file exists, an order creation is in progress
			writeToLogFiles('A Order with th id: ' . $order_id . ' is getting creating so i will stop here');
			return;
		}

		global $wp_filesystem;
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		WP_Filesystem();

		// Create a lock file
		try {

			$wp_filesystem->put_contents($lock_file, 'locked');

		} catch (Exception $e) {

			writeToLogFiles('A Order with th id: ' . $order_id . ' is getting creating so i will stop here');

			return;
		}
		$order_api_url = 'https://staging.wellcomm.store/api/orders/' . $order_id;
		$result = $this->curl_request($order_api_url);
		$order_response = $result['response'];
		$info = $result['info'];


		if ($order_response === false) {
			writeToLogFile('cURL error: ' . curl_error($order_curl));
			// delete the lock file
			$wp_filesystem->delete($lock_file);
			return;
		}

		// debuging check
		writeToLogFile('Shopflix Order API Response: ' . $order_response);

		$shopflix_order = json_decode($order_response, true);
		writeToLogFile('Decoded Shopflix Order Data: ' . json_encode($order_response));
		$invalid_order_types = ["G", "H", "J", "E", "L", "K", "D", "I", "C"];

		if (in_array($shopflix_order['order_type'],$invalid_order_types)) {

			writeToLogFile('Invalid Shopflix Order Type: ' . $shopflix_order['order_type']);
			// delete the lock file
			$wp_filesystem->delete($lock_file);
			return; 
		}
		if (empty($shopflix_order['order_id'])) {
			writeToLogFile('Invalid Shopflix Order ID.');
			// delete the lock file
			$wp_filesystem->delete($lock_file);
			return;
		}
		try {
			// pass the shipping_method_id
			$shopflix_shipping_method = get_option('shopflix_prefered_shipping_method');

			// create the order object
			$order = wc_create_order();

			// Set order data
			$order->set_date_created($shopflix_order['timestamp']);
			$order->set_date_paid($shopflix_order['timestamp']);
			$order->set_date_modified($shopflix_order['timestamp']);
			$order->set_created_via('Shopflix-Bridge');
			$order->set_currency(get_woocommerce_currency());
			$order->set_customer_id(0);
			$order->set_payment_method('shopflix');
			$order->set_payment_method_title('shopflix');
			$order->set_customer_note($shopflix_order['notes'] . ' ' . $shopflix_order['fields']['141']);
			$order->set_order_key($shopflix_order['order_id']);
			$order->set_billing_first_name($shopflix_order['b_firstname']);
			$order->set_billing_last_name($shopflix_order['b_lastname']);
			$order->set_billing_address_1($shopflix_order['b_address']);
			$order->set_billing_address_2($shopflix_order['b_address_2']);
			$order->set_billing_city($shopflix_order['b_city']);
			$order->set_billing_state($shopflix_order['b_state']);
			$order->set_billing_postcode($shopflix_order['b_zipcode']);
			$order->set_billing_country($shopflix_order['b_country']);
			$order->set_billing_email($shopflix_order['email']);
			$order->set_billing_phone($shopflix_order['b_phone']);
			$order->set_shipping_first_name($shopflix_order['s_firstname']);
			$order->set_shipping_last_name($shopflix_order['s_lastname']);
			$order->set_shipping_company($shopflix_order['company']);
			$order->set_shipping_address_1($shopflix_order['s_address']);
			$order->set_shipping_address_2($shopflix_order['s_address_2']);
			$order->set_shipping_city($shopflix_order['s_city']);
			$order->set_shipping_state($shopflix_order['s_state']);
			$order->set_shipping_postcode($shopflix_order['s_zipcode']);
			$order->set_shipping_country($shopflix_order['s_country']);
			$order->set_shipping_phone(!empty($shopflix_order['s_phone']) ? $shopflix_order['s_phone'] : $shopflix_order['b_phone'] );
			$pickup_date = $shopflix_order['product_groups'][0]['pickup_date'];
			writeToLogFile('Pick Up Date: ' . $pickup_date);
			$order->update_meta_data('_Courier_pickup_date',$pickup_date);
			$delivery_date = $shopflix_order['product_groups'][0]['delivery_date'];
			writeToLogFile('Delivery Date: ' . $delivery_date);
			$order->update_meta_data('_Courier_delivery_date',$delivery_date);
			$order->set_billing_company($shopflix_order['fields']['116']);
			if($shopflix_order['fields']['115'] === "Y"){
				writeToLogFile('This order needs an invoice ');
				$order->update_meta_data('_billing_invoice','y');
				$order->update_meta_data('_billing_activity',$shopflix_order['fields']['156']);
				$order->update_meta_data('_billing_vat_id',$shopflix_order['fields']['119']);
				$order->update_meta_data('_billing_tax_office',$shopflix_order['fields']['120'] . ' ' .$shopflix_order['fields']['118']);
			} 
			// Initialize an array to store shipment IDs
			$shipment_ids = array();

			// Add line items from products
			if (isset($shopflix_order['products']) && is_array($shopflix_order['products'])) {
				foreach ($shopflix_order['products'] as $product_key => $product) {
					$shipment_id = $product['shipment_id'];
					writeToLogFile('Shipment Id: ' . $shipment_id);
					$product_unique_identifier = get_option('shopflix_product_unique_identifier');
					if ($product_unique_identifier == 'sku'){

						$product_sku = $product['product_code'];
						writeToLogFile('WooCommerce Order product sku: ' . json_encode($product_sku));
						$product_id = wc_get_product_id_by_sku($product_sku);

					} else if($product_unique_identifier == 'id'){

						$product_id = $product['product_code'];

					}


					writeToLogFile('WooCommerce Order product id: ' . json_encode($product_id));
					if ($product_id) {
						// Fetch the product using the product ID
						$wc_product = wc_get_product($product_id);
						if ($wc_product) {
							writeToLogFile('WooCommerce Product Data: ' . json_encode($wc_product));
						} else {
							writeToLogFile('Product not found for ID: ' . $product_id);
							writeToLogFile('WooCommerce Product Data: ' . var_export($wc_product, true));
							
							//delete the lock file
							$wp_filesystem->delete($lock_file);
							return;
						}

						// Add the product to the order
						$quantity = (int) $product['amount'];
						$price = $product['price'];
						// Calculate the taxes
						$taxe_rate = $shopflix_order['taxes'][6]['rate_value'];
						if(!empty($taxe_rate)){
							$taxe = (float) $taxe_rate;
							writeToLogFile('The taxes are: '. $taxe);
							$price_excluded_tax = $price / (1 + $taxe /100);
						}
						else{
							writeToLogFile('The taxes of the order are null i will asume that it is 24%');
							$price_excluded_tax = $price / (1 + 24 /100);

						}


						$order->add_product($wc_product, $quantity, array(
							'total' => $price_excluded_tax * $quantity,
						));
						$shipment_ids[] = $shipment_id;

					} else {
						// Handle the case where product ID is not found based on SKU
						writeToLogFile('Product not found for SKU: ' . $product_sku);
					}
				}
			}


			// maybe change the logic
			$first_shipment_id = !empty($shipment_ids) ? $shipment_ids[0] : null;
			$order->update_meta_data('Shopflix_shipment_id', $first_shipment_id);
			// $order->update_meta_data('Shopflix_shipment_id',$shipment_id);
			$testing = $order->get_meta_data('Shopflix_shipment_id');
			// writeToLogFile('$first_shipment_id: ' . $first_shipment_id);
			// writeToLogFile('Shipment meta Id: ' . $testing);

			$shipping_items    = (array) $order->get_items('shipping');
			if ( sizeof( $shipping_items ) == 0 ) {
				// check if it works
				$item = new WC_Order_Item_Shipping();
				// $item->set_method_title('Δωρεάν Μεταφορικά');
				$separate=explode(":",$shopflix_shipping_method);
				$item = new WC_Order_Item_Shipping();

				$methods=[];
				$zone = new \WC_Shipping_Zone( 0 );
				// to fetch the shipping method
				foreach ( $zone->get_shipping_methods() as $shipping_method ) {
					$id=$shipping_method->id;
					$id.=!empty($shipping_method->get_instance_id()) ? ':'.$shipping_method->get_instance_id() : '';
					$methods[$id]=$shipping_method->get_method_title();
				}

				$zones = WC_Shipping_Zones::get_zones();
				foreach ( $zones as $zone ) {
					$zone = new \WC_Shipping_Zone( $zone['id'] );
					foreach ( $zone->get_shipping_methods() as $shipping_method ) {
						$id=$shipping_method->id;
						$id.=!empty($shipping_method->get_instance_id()) ? ':'.$shipping_method->get_instance_id() : '';
						$methods[$id]=$shipping_method->get_title();
					}
				}

				if (is_array($separate) && sizeof($separate)==2) {
					$item->set_method_id( $separate[0] );
					$item->set_instance_id( $separate[1] );
					writeToLogFile('shipping item method_id is: ' . $separate[0]);
					writeToLogFile('shipping item instance_id is: ' . $separate[1]);
				}else {
					$item->set_method_id( $shopflix_shipping_method );
					writeToLogFile('shipping item method_id is the shopflix shipping method:' . $shopflix_shipping_method);
				}
				$item->set_method_title($methods[$shopflix_shipping_method]);
				writeToLogFile('item method Title: ' . $methods[$shopflix_shipping_method]);

				$order->add_item( $item );
				$order->calculate_totals();
			}
			$order->set_status('on-hold');
			$order->save();
			// delete the lock file
			$wp_filesystem->delete($lock_file);
			$order_id = $order->get_id();
			if (defined('DOING_AJAX') && DOING_AJAX) {
				$doing_ajax = true;
			}
			  if ($order_id) {
				  writeToLogFile('Doing Ajax?');
                if (defined('DOING_AJAX') && DOING_AJAX) {
                	writeToLogFile('Doing Ajax');
                    wp_send_json_success(array('order_id' => $order_id, 'message' => 'Order fetched successfully.'));
                    
                }
            } else {
				if (defined('DOING_AJAX') && DOING_AJAX) {
					
					wp_send_json_error(array(
						'message' => 'Failed to process the order.'
					));
					
				}
				 
			}

			writeToLogFile('Order created successfully. Order ID: ' . $order_id);
			writeToLogFile('WooCommerce Order: ' . json_encode($order));
			writeToLogFile('WooCommerce Order Data: ' . json_encode($order->get_data()));
			writeToLogFile('WooCommerce Order Items: ' . json_encode($order->get_items()));
			writeToLogFile('WooCommerce Order Items: ' . json_encode($order->get_status()));
		}catch (Exception $e) {
			writeToLogFile('Exception: ' . $e->getMessage());
			$wp_filesystem->delete($lock_file);
		}
	}
	
	public function get_existing_order_keys_from_woocommerce() {
		try {
			// Get orders with order keys
			$orders = wc_get_orders(array(
				// 'status'      => 'on-hold',
				'limit'       => -1,    // Fetch all orders
				'return'      => 'ids',
				'orderby'     => 'date',
				'order'       => 'DESC',
			));

			// Extract order keys
			$order_keys = array_map(function ($order_id) {
				return get_post_meta($order_id, '_order_key', true);
			}, $orders);

			return $order_keys;
		} catch (Exception $e) {
			// Log the exception message or take appropriate action
			writeToLogFile('Error fetching existing order keys from WooCommerce: ' . $e->getMessage());
			return [];
		}
	}
	
	public function create_voucher($order_shipping_id,$decoded_voucher){
		global $wp_filesystem;

		WP_Filesystem();

		$voucher_dir = plugin_dir_path( __FILE__ ) . '../';

		wp_mkdir_p($voucher_dir . 'vouchers');

		$voucher_file = $voucher_dir . '/vouchers/' . $order_shipping_id . '.pdf';

		if ($wp_filesystem->put_contents($voucher_file, $decoded_voucher)) {

			writeToLogFile('The voucher is created.');
			$this->set_voucher_link($order_shipping_id);

		} else {

			writeToLogFile('Failed to create the voucher.');

		}

	}
	
	public function print_shopflix_voucher($order_shipping_id){
		$print_voucher_url = 'https://staging.wellcomm.store/api/courier/' . $order_shipping_id;
		writeToLogFile('print_voucher_url: ' . $print_voucher_url);

		$request = $this->curl_request($print_voucher_url);
		$response = $request['response'];
		$print_voucher_response = json_decode($response, true);
		writeToLogFile('voucher response: ' . json_encode($print_voucher_response));
		
		// check if this call returned that the voucher is already created 
		if ($print_voucher_response['status'] == 500) {
			writeToLogFile('The voucher is already created canot access the shipmentNumber in order to continue the proccess. ');
			return;
			
		}

		$shipment_number = $print_voucher_response['voucher']['ShipmentNumber'];
		writeToLogFile('ShipmentNumber Number: ' . $shipment_number);

		$get_voucher_url = 'https://staging.wellcomm.store/api/courier/?print=' . $shipment_number . '&labelFormat=thermiko';
		// hard coded get coucher url
		// $get_voucher_url = 'https://staging.wellcomm.store/api/courier/?print=013672734944&labelFormat=thermiko';
		writeToLogFile('Voucher Url: ' . $get_voucher_url);

		$request = $this->curl_request($get_voucher_url);
		$response = $request['response'];
		writeToLogFile('Voucher response: ' . $response);
		
		$raw_voucher = json_decode($response, true);
		// $voucher_status = $raw_voucher[];
		$voucher =  $raw_voucher['Voucher'];
		writeToLogFile('Voucher is: ' . $voucher);
		if ($voucher) {
			$decoded_voucher = base64_decode($voucher);
			writeToLogFile('the voucher: ' . $decoded_voucher . "\n");
			$this->create_voucher($order_shipping_id,$decoded_voucher);
		}


	}

	public function set_voucher_link($order_shipping_id) {
		$this->voucher_link =  plugins_url('vouchers/' . $order_shipping_id . '.pdf', __FILE__);
	}

	public function get_voucher_link() {
		return $this->voucher_link;
	}
}
?>