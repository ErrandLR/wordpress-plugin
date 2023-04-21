<?php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Errandlr Delivery Orders Class
 *
 * Adds order admin page customizations
 *
 * @since 1.0
 */
class WC_Errandlr_Delivery_Orders
{
    /** @var \WC_Errandlr_Delivery_Orders single instance of this class */
    private static $instance;

    /**
     * Add various admin hooks/filters
     *
     * @since  1.0
     */
    public function __construct()
    {
        // add 'Errandlr Delivery Information' order meta box
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));

        // process order meta box order actions
        add_action('woocommerce_order_action_wc_Errandlr_delivery_update_status', array($this, 'process_order_meta_box_actions'));
    }


    /**
     * Add 'Errandlr Delivery Information' meta-box to 'Edit Order' page
     *
     * @since 1.0
     */
    public function add_order_meta_box()
    {
        add_meta_box(
            'wc_Errandlr_delivery_order_meta_box',
            __('Errandlr Delivery'),
            array($this, 'render_order_meta_box'),
            'shop_order',
            'side'
        );
    }

    public function render_order_meta_box($post)
    {
        $order = wc_get_order($post->ID);
        //get_meta_data
        $errandlr_reference = get_post_meta($order->get_id(), 'errandlr_reference', true);
        if (empty($errandlr_reference)) {
            echo '<p>' . __('No Errandlr Delivery information available for this order.') . '</p>';
            return;
        }
?>

        <table id="wc_Errandlr_delivery_order_meta_box">
            <tr>
                <th><strong><?php esc_html_e('Tracking ID') ?> : </strong></th>
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

    /**
     * Gets the main loader instance.
     *
     * Ensures only one instance can be loaded.
     *
     *
     * @return \WC_Errandlr_Delivery_Loader
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}

// fire it up!
return WC_Errandlr_Delivery_Orders::instance();
