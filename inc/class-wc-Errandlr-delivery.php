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
            $icon_url = plugins_url('assets/img/logo.svg', $plugin_path);
            $img = '<img class="Errandlr-delivery-logo"' .
                ' alt="' . $logo_title . '"' .
                ' title="' . $logo_title . '"' .
                ' style="width:25px; height:25px; display:inline;    width: 18px;
    height: 11px;
    display: inline;
    background: black;"' .
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

            $receiver_name      = $order->get_shipping_first_name() . " " . $order->get_shipping_last_name();
            $receiver_email     = $order->get_billing_email();
            $receiver_phone     = $order->get_billing_phone();
            $delivery_base_address  = $order->get_shipping_address_1();
            $delivery_city      = $order->get_shipping_city();
            $delivery_state_code    = $order->get_shipping_state();
            $delivery_postcode    = $order->get_shipping_postcode();
            //get subtotal
            $subtotal = $order->get_subtotal();
            //get note
            $note = $order->get_customer_note() ?: 'null';

            $delivery_country_code  = $order->get_shipping_country();
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
                        "deliveryNotes" => $note
                    ]
                ],
                "state" => $pickup_state,
                "country" => $pickup_country,
                "city" => $pickup_city,
                "localGovt" => $pickup_city
            ];

            $order->add_order_note("Errandlr Delivery: " . "Creating shipping task for order " . $order_id);
            //send request
            $response = $api->send_request_curl($senddata);
            if (isset($response["request"])) {
                //add post meta
                update_post_meta($order_id, 'errandlr_request', $response["request"]);
                update_post_meta($order_id, 'errandlr_reference', $response["reference"]);
                //created noted
                $order->add_order_note(
                    "Errandlr Delivery: " . "Shipping task created for order " . $order_id . " with reference " . $response["reference"]
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
        $reference = $_GET["reference"];
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
                        reference: '<?php echo $errandlr_reference; ?>'
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
