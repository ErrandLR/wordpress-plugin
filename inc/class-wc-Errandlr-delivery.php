<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Main Errandlr Delivery Class.
 *
 * @class  WC_Errandlr_Delivery
 */
class WC_Errandlr_Delivery
{
    /** @var \WC_Errandlr_Delivery_API api for this plugin */
    public $api;

    /** @var array settings value for this plugin */
    public $settings;

    /** @var array order status value for this plugin */
    public $statuses;

    /** @var \WC_Errandlr_Delivery single instance of this plugin */
    protected static $instance;

    /**
     * Loads functionality/admin classes and add auto schedule order hook.
     *
     * @since 1.0
     */
    public function __construct()
    {
        // get settings
        $this->settings = maybe_unserialize(get_option('woocommerce_errandlr_delivery_settings'));

        $this->init_plugin();

        $this->init_hooks();
    }

    /**
     * Initializes the plugin.
     *
     * @internal
     *
     * @since 2.4.0
     */
    public function init_plugin()
    {
        $this->includes();

        if (is_admin()) {
            $this->admin_includes();
        }

        //ajax errandlr_delivery_get_status
        add_action('wp_ajax_errandlr_delivery_get_status', array($this, 'getStatus'));
        add_action('wp_ajax_nopriv_errandlr_delivery_get_status', array($this, 'getStatus'));
    }

    /**
     * Includes the necessary files.
     *
     * @since 1.0.0
     */
    public function includes()
    {

        require_once __DIR__ . '/class-wc-el-api.php';

        require_once __DIR__ . '/class-wc-el-shipping-method.php';
    }

    public function admin_includes()
    {
        require_once __DIR__ . '/class-wc-el-orders.php';
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     */
    public function init_hooks()
    {
        /**
         * Actions
         */

        // create order when \WC_Order::payment_complete() is called
        add_action('woocommerce_thankyou', array($this, 'create_order_shipping_task'));


        add_action('woocommerce_shipping_init', array($this, 'load_shipping_method'));

        // cancel a Errandlr delivery task when an order is cancelled in WC
        add_action('woocommerce_order_status_cancelled', array($this, 'cancel_order_shipping_task'));

        // adds tracking button(s) to the View Order page
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_view_order_tracking'));

        /**
         * Filters
         */
        // Add shipping icon to the shipping label
        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'add_shipping_icon'), PHP_INT_MAX, 2);

        add_filter('woocommerce_checkout_fields', array($this, 'remove_address_2_checkout_fields'));

        add_filter('woocommerce_shipping_methods', array($this, 'add_shipping_method'));

        add_filter('woocommerce_shipping_calculator_enable_city', '__return_true');

        add_filter('woocommerce_shipping_calculator_enable_postcode', '__return_false');
        //enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), PHP_INT_MAX, 1);
        //ajax errandlr_validate_checkout
        add_action('wp_ajax_errandlr_validate_checkout', array($this, 'errandlr_validate_checkout'));
        add_action('wp_ajax_nopriv_errandlr_validate_checkout', array($this, 'errandlr_validate_checkout'));
        //ajax errandlr_africa_save_shipping_info
        add_action('wp_ajax_errandlr_africa_save_shipping_info', array($this, 'errandlr_africa_save_shipping_info'));
        add_action('wp_ajax_nopriv_errandlr_africa_save_shipping_info', array($this, 'errandlr_africa_save_shipping_info'));
        //checkout_update_refresh_shipping_methods
        add_action('woocommerce_checkout_update_order_review', array($this, 'checkout_update_refresh_shipping_methods'), PHP_INT_MAX, 1);
        //cart action
        add_action('woocommerce_add_to_cart', array($this, 'remove_wc_session_on_cart_action'), 10, 6);
        //wc new order
        add_action('woocommerce_checkout_order_processed', array($this, 'wc_new_order'), 10, 3);
    }

    //wc_new_order
    public function wc_new_order()
    {
        //check if session is started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        //remove session
        if (isset($_SESSION['errandlr_shipping_info'])) {
            unset($_SESSION['errandlr_shipping_info']);
        }

        //remove session
        if (isset($_SESSION['errandlr_shipping_cost'])) {
            unset($_SESSION['errandlr_shipping_cost']);
        }
    }

    //remove_wc_session_on_cart_action
    public function remove_wc_session_on_cart_action($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        //check if session is started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        //remove session
        if (isset($_SESSION['errandlr_shipping_info'])) {
            unset($_SESSION['errandlr_shipping_info']);
        }

        //remove session
        if (isset($_SESSION['errandlr_shipping_cost'])) {
            unset($_SESSION['errandlr_shipping_cost']);
        }
    }

    public function checkout_update_refresh_shipping_methods($post_data)
    {
        //update shipping pricing realtime
        $packages = WC()->cart->get_shipping_packages();
        foreach ($packages as $package_key => $package) {
            WC()->session->set('shipping_for_package_' . $package_key, false); // Or true
        }
    }

    //enqueue_scripts
    public function enqueue_scripts()
    {
        //style
        wp_enqueue_style('errandlr-delivery-css', plugins_url('assets/css/style.css', WC_ERRAN_DL_MAIN_FILE), array(), time());
        //enqueue scripts
        wp_enqueue_script('errandlr-delivery-js', plugins_url('assets/js/errandlr.js', WC_ERRAN_DL_MAIN_FILE), array('jquery', 'jquery-blockui'), time(), true);
        wp_localize_script('errandlr-delivery-js', 'errandlr_delivery', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('errandlr_delivery_nonce')
        ));
    }

    //errandlr_validate_checkout
    public function errandlr_validate_checkout()
    {
        //verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'errandlr_delivery_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        $data = $_POST['data'];
        //parse form data
        parse_str($data, $form_data);

        //calculate_shipment
        $calculate_shipment = $this->calculate_shipment($form_data);
        if ($calculate_shipment === false) {
            wp_send_json([
                'code' => 400,
                'message' => 'Unable to calculate errandlr shipment'
            ]);
        }

        wp_send_json([
            'code' => 200,
            'message' => 'Shipment calculated successfully',
            'shipment_info' => $calculate_shipment
        ]);
    }

    //errandlr_africa_save_shipping_info
    public function errandlr_africa_save_shipping_info()
    {
        //verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'errandlr_delivery_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
        }

        //premium
        $premium = sanitize_text_field($_POST['premium']);
        $shipping_info = $this->sanitize_array($_POST['shipping_info']);
        //apply shipping 
        $apply_shipping = $this->apply_shipping($shipping_info, $premium);
        if ($apply_shipping) {
            wp_send_json([
                'code' => 200,
                'message' => 'Shipping applied successfully'
            ]);
        }

        wp_send_json([
            'code' => 400,
            'message' => 'Unable to apply shipping'
        ]);
    }

    //sanitize_array
    public function sanitize_array($array)
    {
        //check if array is not empty
        if (!empty($array)) {
            //loop through array
            foreach ($array as $key => $value) {
                //check if value is array
                if (is_array($array)) {
                    //sanitize array
                    $array[$key] = is_array($value) ? $this->sanitize_array($value) : $this->sanitizeDynamic($value);
                } else {
                    //check if $array is object
                    if (is_object($array)) {
                        //sanitize object
                        $array->$key = $this->sanitizeDynamic($value);
                    } else {
                        //sanitize mixed
                        $array[$key] = $this->sanitizeDynamic($value);
                    }
                }
            }
        }
        //return array
        return $array;
    }

    //sanitize_object
    public function sanitize_object($object)
    {
        //check if object is not empty
        if (!empty($object)) {
            //loop through object
            foreach ($object as $key => $value) {
                //check if value is array
                if (is_array($value)) {
                    //sanitize array
                    $object->$key = $this->sanitize_array($value);
                } else {
                    //sanitize mixed
                    $object->$key = $this->sanitizeDynamic($value);
                }
            }
        }
        //return object
        return $object;
    }

    //dynamic sanitize
    public function sanitizeDynamic($data)
    {
        $type = gettype($data);
        switch ($type) {
            case 'array':
                return $this->sanitize_array($data);
                break;
            case 'object':
                return $this->sanitize_object($data);
                break;
            default:
                return sanitize_text_field($data);
                break;
        }
    }

    //calculate shipment
    public function calculate_shipment($form_data)
    {
        $errandshipment = new WC_Errandlr_Delivery_Shipping_Method;

        if ($errandshipment->get_option('enabled') == 'no') {
            return false;
        }

        // country required for all shipments
        if ($form_data['billing_country'] !== 'NG') {
            //add notice
            wc_add_notice(__('Errandlr delivery is only available for Nigeria'), 'notice');
            return false;
        }

        $delivery_country_code = $form_data['billing_country'];
        $delivery_state_code = $form_data['billing_state'];

        $delivery_state = WC()->countries->get_states($delivery_country_code)[$delivery_state_code];
        $delivery_country = WC()->countries->get_countries()[$delivery_country_code];

        //if $form_data['billing_address_1'] is empty return false
        if (empty($form_data['billing_address_1'])) {
            wc_add_notice('Please enter a valid address', 'notice');
            return false;
        }

        //full address 
        $delivery_address = $form_data['billing_address_1'] . ', ' . $form_data['billing_city'] . ', ' . $delivery_state . ', ' . $delivery_country;

        if ('Lagos' !== $delivery_state) {
            wc_add_notice('Errandlr Delivery only available within Lagos', 'notice');
            return false;
        }

        $name = $errandshipment->get_option('name');
        $email = $errandshipment->get_option('email');
        $pickup_country = $errandshipment->get_option('pickup_country');
        $pickup_state = $errandshipment->get_option('pickup_state');
        $pickup_city = $errandshipment->get_option('pickup_city');
        $pickup_address = $errandshipment->get_option('pickup_address');
        $phone = $errandshipment->get_option('phone');
        $discount_amount_premium = $errandshipment->get_option('discount_amount');
        $discount_amount_economy = $errandshipment->get_option('discount_amount_economy');
        $fixed_amount_premium = $errandshipment->get_option('fixed_amount');
        $fixed_amount_economy = $errandshipment->get_option('fixed_amount_economy');

        $api = wc_Errandlr_delivery()->get_api();
        $args = [
            "dropoffLocations" => json_encode([
                [
                    "id" => $delivery_address,
                    "label" => $delivery_address
                ]
            ]),
            "optimize" => 'false',
            "pickupLocation" => json_encode(
                [
                    "id" => $pickup_address,
                    "label" => $pickup_address
                ]
            ),
        ];
        $parser = 'dropoffLocations=' . $args['dropoffLocations'] . '&optimize=' . $args['optimize'] . '&pickupLocation=' . $args['pickupLocation'];
        try {
            $costData = $api->calculate_pricing($parser);
            // file_put_contents(__DIR__ . '/costData.txt', print_r($costData, true));
            $cost = wc_format_decimal($costData["estimate"]);
            $batchEstimate = wc_format_decimal($costData["batchEstimate"]);

            //check if discount amount premium is not empty
            if (!empty($discount_amount_premium)) {
                //check if discount is a string
                if (is_string($discount_amount_premium)) {
                    //convert to int
                    $discount_amount_premium = (int) $discount_amount_premium;
                }
                //check if discount amount is greater than cost
                if ($discount_amount_premium > $cost) {
                    $cost = 0;
                } else {
                    $cost = $cost - $discount_amount_premium;
                }
            }

            //check if discount amount premium is not empty
            if (!empty($discount_amount_economy)) {
                //check if discount is a string
                if (is_string($discount_amount_economy)) {
                    //convert to int
                    $discount_amount_economy = (int) $discount_amount_economy;
                }
                //check if discount amount is greater than cost
                if ($discount_amount_economy > $batchEstimate) {
                    $batchEstimate = 0;
                } else {
                    $batchEstimate = $batchEstimate - $discount_amount_economy;
                }
            }

            //check if fixed amount premium is not empty
            if (!empty($fixed_amount_premium)) {
                //check if fixed amount is a string
                if (is_string($fixed_amount_premium)) {
                    //convert to int
                    $fixed_amount_premium = (int) $fixed_amount_premium;
                }
                $cost = $fixed_amount_premium;
            }

            //check if fixed amount economy is not empty
            if (!empty($fixed_amount_economy)) {
                //check if fixed amount is a string
                if (is_string($fixed_amount_economy)) {
                    //convert to int
                    $fixed_amount_economy = (int) $fixed_amount_economy;
                }
                $batchEstimate = $fixed_amount_economy;
            }

            $metadata = array(
                'errandlr_cost'    => $cost,
                'economy_cost' => $batchEstimate,
                'premium_cost' => $cost,
                'currency' => get_woocommerce_currency(),
                'routes' => $costData['routes']["mapUrl"],
                'geoId' => $costData["geoId"],
                'dropoffLocationsID' => $costData["routes"]["dropoffLocations"][0]["order"],
            );
            //return
            return $metadata;
        } catch (\Throwable $th) {
            throw $th;
        }
    }

    //apply_shipping
    public function apply_shipping($shipping_info, $premium)
    {
        $errandshipment = new WC_Errandlr_Delivery_Shipping_Method;
        $discount_amount = $errandshipment->get_option('discount_amount');
        $discount_amount_economy = $errandshipment->get_option('discount_amount_economy');
        $fixed_amount = $errandshipment->get_option('fixed_amount');
        $fixed_amount_economy = $errandshipment->get_option('fixed_amount_economy');
        //check if $premium is 'true'
        if ($premium == 'true') {
            $cost = $shipping_info['premium_cost'];

            //check if discount amount premium is not empty
            if (!empty($discount_amount)) {
                //check if discount is a string
                if (is_string($discount_amount)) {
                    //convert to int
                    $discount_amount = (int) $discount_amount;
                }
                //check if discount amount is greater than cost
                if ($discount_amount > $cost) {
                    $cost = 0;
                } else {
                    $cost = $cost - $discount_amount;
                }
            }

            //check if fixed amount premium is not empty

            if (!empty($fixed_amount)) {
                //check if fixed amount is a string
                if (is_string($fixed_amount)) {
                    //convert to int
                    $fixed_amount = (int) $fixed_amount;
                }
                $cost = $fixed_amount;
            }
        } else {
            $cost = $shipping_info['economy_cost'];

            //check if discount amount economy is not empty
            if (!empty($discount_amount_economy)) {
                //check if discount is a string
                if (is_string($discount_amount_economy)) {
                    //convert to int
                    $discount_amount_economy = (int) $discount_amount_economy;
                }
                //check if discount amount is greater than cost
                if ($discount_amount_economy > $cost) {
                    $cost = 0;
                } else {
                    $cost = $cost - $discount_amount_economy;
                }
            }

            //check if fixed amount economy is not empty

            if (!empty($fixed_amount_economy)) {
                //check if fixed amount is a string
                if (is_string($fixed_amount_economy)) {
                    //convert to int
                    $fixed_amount_economy = (int) $fixed_amount_economy;
                }
                $cost = $fixed_amount_economy;
            }
        }

        $shipping_info['premium'] = $premium;
        //update shipping info
        $updateShippingInfo = $shipping_info;

        //check if session is started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        //set session
        $_SESSION['errandlr_shipping_info'] = $updateShippingInfo;
        //set session cost
        $_SESSION['errandlr_shipping_cost'] = $cost;

        return true;
    }

    /**
     * shipping_icon.
     *
     * @since   1.0.0
     */
    function add_shipping_icon($label, $method)
    {
        if ($method->method_id == 'errandlr_delivery') {
            $plugin_path = WC_ERRAN_DL_MAIN_FILE;
            $logo_title = 'Errandlr Delivery';
            $icon_url = plugins_url('assets/img/errandlr-logo.png', $plugin_path);
            $img = '<img class="Errandlr-delivery-logo"' .
                ' alt="' . $logo_title . '"' .
                ' title="' . $logo_title . '"' .
                ' style="width: 16px;
                height: 13px;
                display: inline;
                object-fit: contain;"' .
                ' src="' . $icon_url . '"' .
                '>';
            $label = $img . ' ' . $label;
        }

        return $label;
    }

    public function create_order_shipping_task($order_id)
    {
        if (get_post_meta($order_id, 'errandlr_reference', true)) {
            return;
        }

        $order = wc_get_order($order_id);
        // $order_status    = $order->get_status();
        $shipping_method = @array_shift($order->get_shipping_methods());

        if (strpos($shipping_method->get_method_id(), 'errandlr_delivery') !== false) {

            $receiver_name      = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
            $receiver_email     = $order->get_billing_email();
            $receiver_phone     = $order->get_billing_phone();
            $delivery_base_address  = $order->get_billing_address_1();
            $delivery_city      = $order->get_billing_city();
            $delivery_state_code    = $order->get_billing_state();
            $delivery_postcode    = $order->get_billing_postcode();
            //get subtotal
            $subtotal = $order->get_subtotal();
            //get note
            $note = $order->get_customer_note() ?: 'null';

            $delivery_country_code  = $order->get_billing_country();
            $delivery_state = WC()->countries->get_states($delivery_country_code)[$delivery_state_code];
            $delivery_country = WC()->countries->get_countries()[$delivery_country_code];
            $payment_method = $order->get_payment_method();

            $name         = $this->settings['name'];
            $email        = $this->settings['email'];
            $pickup_address = $this->settings['pickup_address'];
            $pickup_city         = $this->settings['pickup_city'];
            $pickup_state        = $this->settings['pickup_state'];
            $pickup_country      = $this->settings['pickup_country'];
            $phone      = $this->settings['phone'];
            if (trim($pickup_country) == '') {
                $pickup_country = 'NG';
            }

            //full address 
            $delivery_address = $delivery_base_address . ", " . $delivery_city . ", " . $delivery_state . ", " . $delivery_country;

            $api = $this->get_api();
            //check if $receiver_phone does not start with +
            if (strpos($receiver_phone, '+') !== 0) {
                //remove the first 0
                $receiver_phone = substr($receiver_phone, 1);
                //add +234
                $receiver_phone = '+234' . $receiver_phone;
            }

            //check if $phone does not start with +
            if (strpos($phone, '+') !== 0) {
                //remove the first 0
                $phone = substr($phone, 1);
                //add +234
                $phone = '+234' . $phone;
            }
            //metadata
            $senddata = [
                "geoId" => $shipping_method->get_meta('geoId'),
                "name" => $name,
                "email" => $email,
                "phone" => $phone,
                "deliverToInformation" => [
                    [
                        "order" => $shipping_method->get_meta('dropoffLocationsID'),
                        "name" => $receiver_name,
                        "phone" => $receiver_phone,
                        "packageValue" => strval($subtotal),
                        "packageType" => "sum",
                        "packageDetail" => "ecommerce",
                        //not more than 100 characters
                        "deliveryNotes" => (strlen($note) > 100) ? substr($note, 0, 100) : $note
                    ]
                ],
                "state" => $pickup_state,
                "country" => $pickup_country,
                "city" => $pickup_city,
                "localGovt" => $pickup_city,
                "batch" => $shipping_method->get_meta('premium')
            ];

            $order->add_order_note("Errandlr Delivery: " . "Creating shipping task for order " . $order_id);
            //send request
            $response = $api->send_request_curl($senddata);
            if (isset($response["trackingId"]) && $response["trackingId"] != '') {
                //add post meta
                if (isset($response["request"])) {
                    update_post_meta($order_id, 'errandlr_request', $response["request"]);
                }
                update_post_meta($order_id, 'errandlr_reference', $response["trackingId"]);
                //created noted
                $order->add_order_note(
                    "Errandlr Delivery: " . "Shipping task created for order " . $order_id . " with reference " . $response["trackingId"]
                );
            } else {
                $order->add_order_note(
                    "Errandlr Delivery: " . "Shipping task creation failed for order " . $order_id . " with error"
                );
            }
        }
    }

    public function getStatus()
    {
        $reference = sanitize_text_field($_GET["reference"]);
        $api = $this->get_api();
        //wp remote get
        $response = wp_remote_get($api->request_url . "order-status?id=" . $reference, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api->token,
                'Content-Type' => 'application/json'
            ]
        ]);
        //check if response is not a wp error
        if (!is_wp_error($response)) {
            //get body
            $body = wp_remote_retrieve_body($response);
            //decode body
            $body = json_decode($body, true);
            //check if body is not null
            wp_send_json($body);
        } else {
            wp_send_json([
                "code" => 401,
                "message" => "Invalid API Key"
            ]);
        }
    }

    /**
     * Adds the tracking information to the View Order page.
     *
     * @internal
     *
     * @since 2.0.0
     *
     * @param int|\WC_Order $order the order object
     */
    public function add_view_order_tracking($order)
    {
        $order = wc_get_order($order);

        $errandlr_reference = get_post_meta($order->get_id(), 'errandlr_reference', true);

        if (isset($$reference)) {
?>
            <table id="wc_Errandlr_delivery_order_meta_box">
                <tr>
                    <th><strong><?php esc_html_e('Unique Refrence ID') ?> : </strong></th>
                    <td><?php echo esc_html((empty($errandlr_reference)) ? __('N/A') : $errandlr_reference); ?></td>
                </tr>

                <tr>
                    <th><strong><?php esc_html_e('Delivery Status') ?> : </strong></th>
                    <td>
                        <p id="errand_status">
                            ....
                        </p>
                    </td>
                </tr>
            </table>
            <script>
                jQuery(document).ready(function($) {
                    $.get("<?php echo admin_url('admin-ajax.php'); ?>", {
                        action: 'errandlr_delivery_get_status',
                        reference: '<?php echo esc_html($errandlr_reference); ?>'
                    }, function(data) {
                        $('#errand_status').html(data.status);
                    });
                });
            </script>

<?php
        }
    }

    public function remove_address_2_checkout_fields($fields)
    {
        unset($fields['billing']['billing_address_2']);
        unset($fields['shipping']['shipping_address_2']);

        return $fields;
    }

    /**
     * Load Shipping method.
     *
     * Load the WooCommerce shipping method class.
     *
     * @since 1.0.0
     */
    public function load_shipping_method()
    {
        $this->shipping_method = new WC_Errandlr_Delivery_Shipping_Method;
    }

    /**
     * Add shipping method.
     *
     * Add shipping method to the list of available shipping method..
     *
     * @since 1.0.0
     */
    public function add_shipping_method($methods)
    {
        if (class_exists('WC_Errandlr_Delivery_Shipping_Method')) :
            $methods['errandlr_delivery'] = 'WC_Errandlr_Delivery_Shipping_Method';
        endif;

        return $methods;
    }

    /**
     * Initializes the and returns Errandlr Delivery API object.
     *
     * @since 1.0
     *
     * @return \WC_Errandlr_Delivery_API instance
     */
    public function get_api()
    {
        // return API object if already instantiated
        if (is_object($this->api)) {
            return $this->api;
        }

        $Errandlr_delivery_settings = $this->settings;

        // instantiate API
        return $this->api = new \WC_Errandlr_Delivery_API($Errandlr_delivery_settings);
    }

    public function get_plugin_path()
    {
        return plugin_dir_path(__FILE__);
    }

    /**
     * Returns the main Errandlr Delivery Instance.
     *
     * Ensures only one instance is/can be loaded.
     *
     * @since 1.0.0
     *
     * @return \WC_Errandlr_Delivery
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}


/**
 * Returns the One True Instance of WooCommerce ErrandlrDelivery.
 *
 * @since 1.0.0
 *
 * @return \WC_Errandlr_Delivery
 */
function wc_Errandlr_delivery()
{
    return \WC_Errandlr_Delivery::instance();
}
