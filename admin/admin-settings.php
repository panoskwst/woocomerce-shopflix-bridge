<?php
add_action('admin_menu', 'plugin_menu');
require_once ABSPATH . 'wp-content/plugins/shopflix-bridge/classes/shopflix-bridge-class.php';

function plugin_menu() {
    add_menu_page('Shopflix bridge', 'Shopflix bridge', 'manage_options', 'shopflix-bridge', 'main_page');
}

function main_page() {
    // menu page content.
    ?>
    <div class="wrap">
        <h2>Shopflix Bridge Settings</h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('shopflix_bridge_settings_group');
            do_settings_sections('shopflix-bridge');
            submit_button();
            ?>			
			<h3>Shopflix Credentials</h3>
			<table class="form-table">
				<tr valign="top"> 
					<th scope="row">Shopflix Username</th>
					<td><input type="text" name="shopflix_username" value="<?php echo esc_attr(get_option('shopflix_username')); ?>" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">Shopflix Password</th>
					<td><input type="text" name="shopflix_password" value="<?php echo esc_attr(get_option('shopflix_password')); ?>" /></td>
				</tr>
				<tr valign="top">
                    <th scope="row"><label for="shopflix_product_unique_identifier"><?php _e('Unique Identifier','shopflix-bridge');?></label></th>
                    <td>
					<select id="shopflix_product_unique_identifier" name="shopflix_product_unique_identifier">
						<option value="sku" <?php echo get_option('shopflix_product_unique_identifier','sku')=='sku' ? 'selected' : '';?>><?php _e('SKU','shopflix-bridge');?></option>
                        <option value="id" <?php echo get_option('shopflix_product_unique_identifier','sku')=='id' ? 'selected' : '';?>><?php _e('ID','shopflix-bridge');?></option>
                    </select>
				 <tr valign="top">
                    <th scope="row"><label for="shopflix_prefered_shipping_method"><?php _e('Shipping Method','shopflix-bridge');?></label></th>
                    <td>
	                    <?php
	                    $methods=[];
	                    $zone = new \WC_Shipping_Zone( 0 );
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
	                    }?>
                        <select id="shopflix_prefered_shipping_method" name="shopflix_prefered_shipping_method">
                            <?php foreach ( $methods as $k=>$method ) { ?>
                                <option value="<?php echo $k;?>" <?php echo get_option('shopflix_prefered_shipping_method',null)==$k ? 'selected' : '';?>><?php echo $method;?></option>
                            <?php } ?>
                        </select>
                    </td>
                </tr>
			</table>
        </form>
		<table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="shopflix_bridge_fetch_order_id"><?php _e('Fetch order','shopflix-bridge');?></label></th>
                    <td><input type="text" placeholder="<?php _e('shopflix order id','shopflix-bridge');?>" id="shopflix_fetch_order_id" name="shopflix_fetch_order_id" value="" />
                    <input type="button" data-success="<?php _e('Order fetched with ID:','shopflix-bridge');?>" data-fail="<?php _e('Failed to fetch order','shopflix-bridge');?>" class="button" id="shopflix_fetch_order" name="shopflix_fetch_order" value="<?php _e('Fetch','shopflix-bridge');?>" />
                    </td>
                </tr>
            </table>
    </div>
    <?php
}

function shopflix_bridge_register_settings() {
	register_setting('shopflix_bridge_settings_group', 'shopflix_username');
    register_setting('shopflix_bridge_settings_group', 'shopflix_password');
	register_setting('shopflix_bridge_settings_group', 'shopflix_prefered_shipping_method');
	register_setting('shopflix_bridge_settings_group', 'shopflix_product_unique_identifier');
}

function shopflix_fetch_order_callback() {

    // Verify the nonce for security
    check_ajax_referer('shopflix_fetch_order_nonce', 'nonce');

    // Process the Ajax request
    $order_id = isset($_POST['order_id']) ? sanitize_text_field($_POST['order_id']) : '';

    
    $shopflix_order = new Shopflix_Bridge();
	// $shopflix_order->fetch_shopflix_order($order_id);
	
	$order_details = $shopflix_order->fetch_shopflix_order($order_id);

    // Return something to the Ajax call
    wp_send_json_success($order_details);

    wp_die(); // Terminate immediately and return a proper response
}

function shopflix_bridge_enqueue_admin_scripts() {
    // Register script
    wp_register_script('shopflix-bridge-script', plugins_url('/js/shopflix-bridge.js', __FILE__), array('jquery'), '1.0', true);

    // Enqueue script
    wp_enqueue_script('shopflix-bridge-script');

    // Localize script for AJAX
    wp_localize_script('shopflix-bridge-script', 'shopflixAjax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('shopflix_fetch_order_nonce'),
    ));
}

add_action('admin_enqueue_scripts', 'shopflix_bridge_enqueue_admin_scripts');
add_action('admin_init', 'shopflix_bridge_register_settings');
add_action('wp_ajax_shopflix_fetch_order', 'shopflix_fetch_order_callback');
?>