<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

class WC_Errandlr_Delivery_API
{
    protected $env;

    public $token;

    public $request_url;

    protected $domain_name;

    public function __construct($settings = array())
    {
        $this->env = isset($settings['mode']) ? $settings['mode'] : 'sandbox';

        if ($this->env == 'live') {
            $this->token    = isset($settings['live_token']) ? $settings['live_token'] : '';

            $this->domain_name = 'commerce.errandlr.com';
            $this->request_url = 'https://commerce.errandlr.com/';
        } else {

            $this->token    = isset($settings['sandbox_token']) ? $settings['sandbox_token'] : '';
            $this->domain_name = 'commerce.errandlr.com';
            $this->request_url = 'https://commerce.errandlr.com/';
        }
    }

    public function create_delivery($params)
    {
        //waiting
    }

    public function calculate_pricing($params)
    {
        return $this->send_request('estimate', $params, 'get');
    }

    public function get_lat_lng($address)
    {
        $address = rawurlencode($address);
        $coord   = get_transient('Errandlr_delivery_geocode_' . $address);

        if (empty($coord)) {
            $url  = 'http://nominatim.openstreetmap.org/?format=json&addressdetails=1&q=' . $address . '&limit=1';
            $json = wp_remote_get($url);
            if (200 === (int) wp_remote_retrieve_response_code($json)) {
                $body = wp_remote_retrieve_body($json);
                $json = json_decode($body, true);
            }

            $coord['lat']  = $json[0]['lat'];
            $coord['long'] = $json[0]['lon'];
            set_transient('Errandlr_delivery_geocode_' . $address, $coord, DAY_IN_SECONDS * 90);
        }

        return $coord;
    }

    /**
     * Send HTTP Request
     * @param string $endpoint API request path
     * @param array $args API request arguments
     * @param string $method API request method
     * @return object|null JSON decoded transaction object. NULL on API error.
     */
    public function send_request(
        $endpoint,
        $args = array(),
        $method = 'post'
    ) {
        $uri = "{$this->request_url}{$endpoint}";

        $arg_array = array(
            'method'    => strtoupper($method),
            'headers'   => $this->get_headers()
        );

        //method
        if ($method == 'get') {
            $arg_array['body'] = array();
            //append uri
            $uri .= '?' . $args;
        } else {
            //json body
            $arg_array['body'] = json_encode($args);
        }

        $req = wp_remote_request($uri, $arg_array);
        if (is_wp_error($req)) {
            throw new \Exception(__('HTTP error connecting to Errandlr delivery. Try again'));
        } else {
            $res = wp_remote_retrieve_body($req);
            if (null !== ($json = json_decode($res, true))) {
                error_log(__METHOD__ . ' for ' . $uri . ' ' . print_r(compact('arg_array', 'json'), true));

                if (isset($json['code']) == 400) {
                    throw new Exception("There was an issue connecting to Errandlr delivery. Reason: {$json['message']}.");
                }

                return $json;
            } else { // Un-decipherable message
                throw new Exception(__('There was an issue connecting to Errandlr delivery. Try again later.'));
            }
        }

        return false;
    }

    //use curl
    public function send_request_curl($args)
    {
        $uri = "{$this->request_url}request";
        $response = wp_remote_post($uri, array(
            'headers'     => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $this->token
            ),
            'body'        => wp_json_encode($args),
            'method'      => 'POST',
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            throw new \Exception(__('HTTP error connecting to Errandlr delivery. Try again'));
        } else {
            $res = wp_remote_retrieve_body($response);
            if (null !== ($json = json_decode($res, true))) {
                if (isset($json['code']) == 400) {
                    throw new Exception("There was an issue connecting to Errandlr delivery. Reason: {$json['message']}.");
                }
                file_put_contents(__DIR__ . '/log2.txt', print_r($json, true));
                return $json;
            } else { // Un-decipherable message
                throw new Exception(__('There was an issue connecting to Errandlr delivery. Try again later.'));
            }
        }
    }

    /**
     * Generates the headers to pass to API request.
     */
    public function get_headers()
    {
        return array(
            'Accept' => 'application/json',
            //add bearer token
            'Authorization' => 'Bearer ' . $this->token,
        );
    }
}
