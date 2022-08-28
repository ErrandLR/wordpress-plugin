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
        );
    }

    function is_available($package)
    {
        if ($this->enabled === "no")
            return false;
        return apply_filters('woocommerce_shipping_' . $this->id . '_is_available', true);
    }


    /**
     * Calculate shipping by sending destination/items to Shipwire and parsing returned rates
     *
     * @since 1.0
     * @param array $package
     */
    public function calculate_shipping($package = array())
    {
        if ($this->get_option('enabled') == 'no') {
            return;
        }

        // country required for all shipments
        if ($package['destination']['country'] !== 'NG') {
            //add notice
            wc_add_notice(__('Errandlr delivery is only available for Nigeria'), 'notice');
            return;
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

        $name = $this->get_option('name');
        $email = $this->get_option('email');
        $pickup_country = $this->get_option('pickup_country');
        $pickup_state = $this->get_option('pickup_state');
        $pickup_city = $this->get_option('pickup_city');
        $pickup_address = $this->get_option('pickup_address');
        $phone = $this->get_option('phone');

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
        $costData = $api->calculate_pricing($parser);
        file_put_contents(__DIR__ . '/costData.txt', print_r($costData, true));
        $cost = wc_format_decimal($costData["estimate"]);
        $metadata = array(
            'errandlr_cost'    => $cost,
            'routes' => $costData['routes']["mapUrl"],
            'geoId' => $costData["geoId"],
            'dropoffLocationsID' => $costData["routes"]["dropoffLocations"][0]["order"],
        );
        //add rate
        $this->add_rate(array(
            'id'        => $this->id . $this->instance_id,
            'label'     => $this->title,
            'cost'      => $cost,
            'meta_data' => $metadata,
        ));
    }
}
