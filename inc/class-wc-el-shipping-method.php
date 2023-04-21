<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Errandlr Delivery Shipping Method Class
 *
 * Provides real-time shipping rates from Errandlr delivery and handle order requests
 *
 * @since 1.0
 * 
 * @extends \WC_Shipping_Method
 */
class WC_Errandlr_Delivery_Shipping_Method extends WC_Shipping_Method
{
    /**
     * Constructor.
     *
     * @since 1.0.0
     */
    public function __construct($instance_id = 0)
    {
        $this->id                 = 'errandlr_delivery';
        $this->instance_id           = absint($instance_id);
        $this->method_title       = __('Errandlr Delivery');
        $this->method_description = __('Get your parcels delivered better, cheaper and quicker via Errandlr Delivery');

        $this->supports  = array(
            'settings',
            'shipping-zones',
        );

        $this->init();

        $this->title = 'Errandlr Delivery';

        $this->enabled = $this->get_option('enabled');
    }

    /**
     * Init.
     *
     * Initialize Errandlr delivery shipping method.
     *
     * @since 1.0.0
     */
    public function init()
    {
        $this->init_form_fields();
        $this->init_settings();

        // Save settings in admin if you have any defined
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Init fields.
     *
     * Add fields to the Errandlr delivery settings page.
     *
     * @since 1.0.0
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'     => __('Enable/Disable'),
                'type'         => 'checkbox',
                'label'     => __('Enable this shipping method'),
                'default'     => 'no',
            ),
            'mode' => array(
                'title'       =>     __('Mode'),
                'type'        =>     'select',
                'description' =>     __('Default is (Sandbox), choose (Live) when your ready to start processing orders via  Errandlr delivery'),
                'default'     =>     'sandbox',
                'options'     =>     array('sandbox' => 'Sandbox', 'live' => 'Live'),
            ),
            'live_token' => array(
                'title'       =>     __('Live Token'),
                'type'        =>     'text',
                'description' =>     sprintf(__('Enter your live token. This can be found in your %sErrandlr delivery account%s.', 'woocommerce'), '<a href="https://errandlr.com" target="_blank">', '</a>'),
                'default'     =>     __(''),
            ),
            'sandbox_token' => array(
                'title'       =>     __('Sandbox Token'),
                'type'        =>     'text',
                'description' =>     __('Your sandbox token', 'errandlr'),
                'default'     =>     __('')
            ),
            'name' => array(
                'title'       =>     __('Your name'),
                'type'        =>     'text',
                'description' =>     __('Your name', 'errandlr'),
                'default'     =>     __('')
            ),
            'email' => array(
                'title'       =>     __('Your email'),
                'type'        =>     'email',
                'description' =>     __('Your email', 'errandlr'),
                'default'     =>     __('')
            ),
            'pickup_country' => array(
                'title'       =>     __('Pickup Country'),
                'type'        =>     'text',
                'description' =>     __('Errandlr delivery/pickup is only available for Nigeria'),
                'default'     =>     __('Nigeria')
            ),
            'pickup_state' => array(
                'title'        =>    __('Pickup State'),
                'type'         =>    'text',
                'description'  =>    __('Errandlr delivery/pickup state.'),
                'default'      =>    __('Lagos'),
            ),
            'pickup_city' => array(
                'title'       =>     __('Pickup City'),
                'type'        =>     'text',
                'description' =>     __('The local area where the parcel will be picked up.'),
                'default'     =>     __('Lagos')
            ),
            'pickup_address' => array(
                'title'       =>     __('Pickup Address'),
                'type'        =>     'text',
                'description' =>     __('The address where the parcel will be picked up.'),
                'default'     =>     __(''),
            ),
            'phone' => array(
                'title'       =>     __('Phone Number'),
                'type'        =>     'text',
                'description' =>     __('Used to coordinate pickup if the Errandlr rider is outside attempting delivery. Must be a valid phone number'),
                'default'     =>     __('')
            ),
            //add discount amount
            'discount_amount' => array(
                'title'       =>     __('Discount Amount for premium'),
                'type'        =>     'number',
                'description' =>     __('Add discount amount to shipping cost for premium delivery. <br> <b>Note: Adding a discount amount takes precedence over fixed amount.</b>'),
                'default'     =>     __('0')
            ),
            //add discount for economy
            'discount_amount_economy' => array(
                'title'       =>     __('Discount Amount for economy'),
                'type'        =>     'number',
                'description' =>     __('Add discount amount to shipping cost for economy delivery. <br> <b>Note: Adding a discount amount takes precedence over fixed amount.</b>'),
                'default'     =>     __('0')
            ),
            //fixed amount
            'fixed_amount' => array(
                'title'       =>     __('Fixed Amount for premium'),
                'type'        =>     'number',
                'description' =>     'Add fixed amount to shipping cost. <br> <b>Note: Adding a fixed shipping cost takes precedence over discount amount.</b>',
                'default'     =>     __('0')
            ),
            //fixed amount
            'fixed_amount_economy' => array(
                'title'       =>     __('Fixed Amount for economy'),
                'type'        =>     'number',
                'description' =>     'Add fixed amount to shipping cost. <br> <b>Note: Adding a fixed shipping cost takes precedence over discount amount.</b>',
                'default'     =>     __('0')
            ),
        );
    }

    function is_available($package)
    {
        if ($this->enabled === "no")
            return false;
        return apply_filters('woocommerce_shipping_' . $this->id . '_is_available', true);
    }


    /**
     * Calculate shipping by sending destination/items to errandlr and parsing returned rates
     *
     * @since 1.0
     * @param array $package
     */
    public function calculate_shipping($package = array())
    {
        if ($this->get_option('enabled') == 'no') {
            return;
        }

        // // country required for all shipments
        if ($package['destination']['country'] !== 'NG') {
            //add notice
            wc_add_notice(__('Errandlr delivery is only available for Nigeria'), 'notice');
            return;
        }

        //check if session is started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        //if session is set
        if (isset($_SESSION['errandlr_shipping_info'])) {
            //get session
            $errandlr_shipping_info = $_SESSION['errandlr_shipping_info'];
            // file_put_contents(__DIR__ . '/loggin.txt', print_r($errandlr_shipping_info, true));
        } else {
            $errandlr_shipping_info = [];
        }

        //if session is set
        if (isset($_SESSION['errandlr_shipping_cost'])) {
            //get session
            $errandlr_cost = $_SESSION['errandlr_shipping_cost'];
        } else {
            $errandlr_cost = 0;
        }

        $delivery_country_code = $package['destination']['country'];
        $delivery_state_code = $package['destination']['state'];
        $delivery_city = $package['destination']['city'];
        $delivery_base_address = $package['destination']['address'];

        $delivery_state = WC()->countries->get_states($delivery_country_code)[$delivery_state_code];
        $delivery_country = WC()->countries->get_countries()[$delivery_country_code];

        //full address 
        $delivery_address = $package['destination']['address'] . ', ' . $package['destination']['city'] . ', ' . $delivery_state . ', ' . $delivery_country;

        if ('Lagos' !== $delivery_state) {
            wc_add_notice('Errandlr Delivery only available within Lagos', 'notice');
            return;
        }

        //check if $errandlr_cost is available
        if (!empty($errandlr_cost)) {
            //check if $errandlr_cost is a string 
            if (is_string($errandlr_cost)) {
                //convert to float
                $errandlr_cost = intval($errandlr_cost);
            }

            //title
            if ($errandlr_shipping_info['premium'] == "true") {
                $this->title = 'Premium Errandlr Delivery';
            } else {
                $this->title = 'Economy Errandlr Delivery';
            }

            //check if $errandlr_shipping_info is not empty
            if (!empty($errandlr_shipping_info)) {
                //add rate
                $this->add_rate(array(
                    'id'        => $this->id . $this->instance_id,
                    'label'     => $this->title,
                    'cost'      => $errandlr_cost,
                    'meta_data' => [
                        'routes' => $errandlr_shipping_info['routes'],
                        'geoId' => $errandlr_shipping_info['geoId'],
                        'dropoffLocationsID' => $errandlr_shipping_info['dropoffLocationsID'],
                        'premium' => $errandlr_shipping_info['premium'],
                        'currency' => $errandlr_shipping_info['currency'],
                        'economy_cost' => $errandlr_shipping_info['economy_cost'],
                        'premium_cost' => $errandlr_shipping_info['premium_cost'],
                    ],
                ));
                return;
            }
        }

        //add rate
        $this->add_rate(array(
            'id'        => $this->id . $this->instance_id,
            'label'     => $this->title,
            'cost'      => 0,
            'meta_data' => [],
        ));
    }
}
