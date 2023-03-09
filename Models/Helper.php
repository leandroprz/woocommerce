<?php
namespace Mobbex\WP\Checkout\Models;


class Helper
{
    /**
     * All 'ahora' plans.
     */
    public static $ahora = ['ahora_3', 'ahora_6', 'ahora_12', 'ahora_18'];

    /** Module configuration settings */
    public $settings = [];

    /** @var Config */
    public $config;

    /** @var MobbexApi */
    public $api;

    /**
     * Load plugin settings.
     */
    public function __construct()
    {
        $this->config = new Config();
        $this->api    = new MobbexApi($this->config->api_key, $this->config->access_token);
        $this->settings = $this->config->settings;
    }

    public function isReady()
    {
        return ($this->config->enabled === 'yes' && !empty($this->config->api_key) && !empty($this->config->access_token));
    }

    /**
     * Returns a query param with the installments of the product.
     * @param int $total
     * @param array $installments
     * @return string $query
     */
    public function getInstallmentsQuery($total, $installments = [])
    {
        // Build query params and replace special chars
        return preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', http_build_query(compact('total', 'installments')));
    }

    /**
     * Get sources from Mobbex.
     * 
     * @param int|float|null $total Amount to calculate payment methods.
     * @param array|null $installments Use +uid:<uid> to include and -<reference> to exclude.
     * 
     * @return array 
     */
    public function get_sources($total = null, $installments = [])
    {
        $query = $this->getInstallmentsQuery($total, $installments);

        return $this->api->request([
            'method' => 'GET',
            'uri'    => "sources" . ($query ? "?$query" : ''),
        ]) ?: [];
    }

    /**
     * Get sources with advanced rule plans from mobbex.
     * 
     * @param string $rule
     * 
     * @return array
     */
    public function get_sources_advanced($rule = 'externalMatch')
    {
        return $this->api->request([
            'method' => 'GET',
            'uri'    => "sources/rules/$rule/installments",
        ]) ?: [];
    }

    /**
     * Retrieve installments checked on plans filter of each product.
     * 
     * @param array $products Array of products or their ids.
     * 
     * @return array
     */
    public function get_installments($products)
    {
        $installments = $inactive_plans = $active_plans = [];

        // Get plans from products
        foreach ($products as $product) {
            $id = $product instanceOf WC_Product ? $product->get_id() : $product;

            $inactive_plans = array_merge($inactive_plans, self::get_inactive_plans($id));
            $active_plans   = array_merge($active_plans, self::get_active_plans($id));
        }

        // Add inactive (common) plans to installments
        foreach ($inactive_plans as $plan)
            $installments[] = '-' . $plan;

        // Add active (advanced) plans to installments (only if the plan is active on all products)
        foreach (array_count_values($active_plans) as $plan => $reps) {
            if ($reps == count($products))
                $installments[] = '+uid:' . $plan;
        }

        // Remove duplicated plans and return
        return array_values(array_unique($installments));
    }

    /**
     * Retrive inactive common plans from a product and its categories.
     * 
     * @param int $product_id
     * 
     * @return array
     */
    public static function get_inactive_plans($product_id)
    {
        $categories     = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $inactive_plans = [];

        // Get inactive 'ahora' plans (previus save method)
        foreach (self::$ahora as $plan) {
            // Get from product
            if (get_post_meta($product_id, $plan, true) === 'yes') {
                $inactive_plans[] = $plan;
                continue;
            }

            // Get from product categories
            foreach ($categories as $cat_id) {
                if (get_term_meta($cat_id, $plan, true) === 'yes') {
                    $inactive_plans[] = $plan;
                    break;
                }
            }
        }

        // Get plans from product and product categories
        $inactive_plans = array_merge($inactive_plans, self::unserialize_array(get_post_meta($product_id, 'common_plans', true)));

        foreach ($categories as $cat_id)
            $inactive_plans = array_merge($inactive_plans, self::unserialize_array(get_term_meta($cat_id, 'common_plans', true)));

        // Remove duplicated and return
        return array_unique($inactive_plans);
    }

    /**
     * Retrive active advanced plans from a product and its categories.
     * 
     * @param int $product_id
     * 
     * @return array
     */
    public static function get_active_plans($product_id)
    {
        $categories     = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        $active_plans = [];

        // Get plans from product and product categories
        $active_plans = array_merge($active_plans, self::unserialize_array(get_post_meta($product_id, 'advanced_plans', true)));

        foreach ($categories as $cat_id)
            $active_plans = array_merge($active_plans, self::unserialize_array(get_term_meta($cat_id, 'advanced_plans', true)));

        // Remove duplicated and return
        return array_unique($active_plans);
    }

    /**
     * Get all product IDs from Order.
     * 
     * @param WP_Order $order
     * @return array $products
     */
    public static function get_product_ids($order)
    {
        $products = [];

        foreach ($order->get_items() as $item)
            $products[] = $item->get_product_id();

        return $products;
    }

    /**
     * Get all category IDs from Order.
     * Duplicates are removed.
     * 
     * @param WP_Order $order
     * @return array $categories
     */
    public static function get_category_ids($order)
    {
        $categories = [];

        // Get Products Ids
        $products = self::get_product_ids($order);

        foreach ($products as $product)
            $categories = array_merge($categories, wp_get_post_terms($product, 'product_cat', ['fields' => 'ids']));

        // Remove duplicated IDs and return
        return array_unique($categories);
    }

    /**
     * Get Store ID from product and its categories.
     * 
     * @param int|string $product_id
     * 
     * @return string|null $store_id
     */
    public static function get_store_from_product($product_id)
    {
        $stores     = get_option('mbbx_stores') ?: [];
        $store      = get_post_meta($product_id, 'mbbx_store', true);
        $ms_enabled = get_post_meta($product_id, 'mbbx_enable_multisite', true);

        if ($ms_enabled && !empty($store) && !empty($stores[$store]))
            return $store;

        // Get stores from product categories
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);

        foreach ($categories as $cat_id) {
            $store      = get_term_meta($cat_id, 'mbbx_store', true);
            $ms_enabled = get_term_meta($cat_id, 'mbbx_enable_multisite', true);

            if ($ms_enabled && !empty($store) && !empty($stores[$store]))
                return $store;
        }
    }

    /**
     * Capture 'authorized' payment using Mobbex API.
     * 
     * @param string|int $payment_id
     * @param string|int $total
     * 
     * @return bool $result
     */
    public function capture_payment($payment_id, $total)
    {
        if (!$this->isReady())
            throw new Exception(__('Plugin is not ready', 'mobbex-for-woocommerce'));

        if (empty($payment_id) || empty($total))
            throw new Exception(__('Empty Payment UID or params', 'mobbex-for-woocommerce'));

        // Capture payment
        return $this->api->request([
            'method' => 'POST',
            'uri'    => "operations/$payment_id/capture",
            'body'   => compact('total'),
        ]);
    }

    /**
     * Try unserialize a string forcing an array as return.
     * 
     * @param mixed $var
     * 
     * @return array
     */
    public static function unserialize_array($var)
    {
        if (is_string($var))
            $var = unserialize($var);

        return is_array($var) ? $var : [];
    }
    

    /* WEBHOOK METHODS */

    /**
     * Format the webhook data in an array.
     * 
     * @param int $order_id
     * @param array $res
     * @return array $data
     * 
     */
    public static function format_webhook_data($order_id, $res)
    {
        $data = [
            'order_id'           => $order_id,
            'parent'             => isset($res['payment']['id']) ? (self::is_parent_webhook($res['payment']['id']) ? 'yes' : 'no') : null,
            'childs'             => isset($res['childs']) ? json_encode($res['childs']) : '',
            'operation_type'     => isset($res['payment']['operation']['type']) ? $res['payment']['operation']['type'] : '',
            'payment_id'         => isset($res['payment']['id']) ? $res['payment']['id'] : '',
            'description'        => isset($res['payment']['description']) ? $res['payment']['description'] : '',
            'status_code'        => isset($res['payment']['status']['code']) ? $res['payment']['status']['code'] : '',
            'status_message'     => isset($res['payment']['status']['message']) ? $res['payment']['status']['message'] : '',
            'source_name'        => isset($res['payment']['source']['name']) ? $res['payment']['source']['name'] : 'Mobbex',
            'source_type'        => isset($res['payment']['source']['type']) ? $res['payment']['source']['type'] : 'Mobbex',
            'source_reference'   => isset($res['payment']['source']['reference']) ? $res['payment']['source']['reference'] : '',
            'source_number'      => isset($res['payment']['source']['number']) ? $res['payment']['source']['number'] : '',
            'source_expiration'  => isset($res['payment']['source']['expiration']) ? json_encode($res['payment']['source']['expiration']) : '',
            'source_installment' => isset($res['payment']['source']['installment']) ? json_encode($res['payment']['source']['installment']) : '',
            'installment_name'   => isset($res['payment']['source']['installment']['description']) ? json_encode($res['payment']['source']['installment']['description']) : '',
            'installment_amount' => isset($res['payment']['source']['installment']['amount']) ? $res['payment']['source']['installment']['amount'] : '',
            'installment_count'  => isset($res['payment']['source']['installment']['count'] ) ? $res['payment']['source']['installment']['count']  : '',
            'source_url'         => isset($res['payment']['source']['url']) ? json_encode($res['payment']['source']['url']) : '',
            'cardholder'         => isset($res['payment']['source']['cardholder']) ? json_encode(($res['payment']['source']['cardholder'])) : '',
            'entity_name'        => isset($res['entity']['name']) ? $res['entity']['name'] : '',
            'entity_uid'         => isset($res['entity']['uid']) ? $res['entity']['uid'] : '',
            'customer'           => isset($res['customer']) ? json_encode($res['customer']) : '',
            'checkout_uid'       => isset($res['checkout']['uid']) ? $res['checkout']['uid'] : '',
            'total'              => isset($res['payment']['total']) ? $res['payment']['total'] : '',
            'currency'           => isset($res['checkout']['currency']) ? $res['checkout']['currency'] : '',
            'risk_analysis'      => isset($res['payment']['riskAnalysis']['level']) ? $res['payment']['riskAnalysis']['level'] : '',
            'data'               => isset($res) ? json_encode($res) : '',
            'created'            => isset($res['payment']['created']) ? $res['payment']['created'] : '',
            'updated'            => isset($res['payment']['updated']) ? $res['payment']['created'] : '',
        ];
        return $data;
    }

    /**
     * Check if webhook is parent type using him payment id.
     * 
     * @param string $payment_id
     * 
     * @return bool
     */
    public static function is_parent_webhook($payment_id)
    {
        return strpos($payment_id, 'CHD-') !== 0;
    }

    /**
     * Receives an array and returns an array with the data format for the 'insert' method
     * 
     * @param array $array
     * @return array $format
     * 
     */
    public static function db_column_format($array)
    {
        $format = [];

        foreach ($array as $value) {
            switch (gettype($value)) {
                case "bolean":
                    $format[] = '%s';
                    break;
                case "integer":
                    $format[] = '%d';
                    break;
                case "double":
                    $format[] = '%f';
                    break;
                case "string":
                    $format[] = '%s';
                    break;
                case "array":
                    $format[] = '%s';
                    break;
                case "object":
                    $format[] = '%s';
                    break;
                case "resource":
                    $format[] = '%s';
                    break;
                case "NULL":
                    $format[] = '%s';
                    break;
                case "unknown type":
                    $format[] = '%s';
                    break;
                case "bolean":
                    $format[] = '%s';
                    break;
            }
        }
        return $format;
    }

    public function get_api_endpoint($endpoint, $order_id)
    {
        $query = [
            'mobbex_token' => $this->generate_token(),
            'platform' => "woocommerce",
            "version" => MOBBEX_VERSION,
        ];

        if ($order_id)
            $query['mobbex_order_id'] = $order_id;
    
        if ($endpoint === 'mobbex_webhook') {
            if ($this->config->debug_mode != 'no')
                $query['XDEBUG_SESSION_START'] = 'PHPSTORM';
            return add_query_arg($query, get_rest_url(null, 'mobbex/v1/webhook'));
        } else 
            $query['wc-api'] = $endpoint;
        return add_query_arg($query, home_url('/'));
    }

    public function valid_mobbex_token($token)
    {
        return $token == $this->generate_token();
    }

    public function generate_token()
    {
        return md5($this->config->api_key . '|' . $this->config->access_token);
    }

    public function get_product_image($product_id)
    {
        $product = wc_get_product($product_id);

        if (!$product)
            return;

        $image   = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
        $default = wc_placeholder_img_src('thumbnail');

        return $image ?: $default;
    }

    /**
     * Retrieve a checkout created from current Cart|Order as appropriate.
     * 
     * @uses Only to show payment options.
     * 
     * @return array|null
     */
    public function get_context_checkout()
    {
        $order = wc_get_order(get_query_var('order-pay'));
        $cart  = WC()->cart;

        $helper = $order ? new \Mobbex\WP\Checkout\Helper\MobbexOrderHelper($order) : new \Mobbex\WP\Checkout\Helper\MobbexCartHelper($cart);

        // If is pending order page create checkout from order and return
        if ($order)
            return $helper->create_checkout();

        // Try to get previous cart checkout data
        $cart_checkout = WC()->session->get('mobbex_cart_checkout');
        $cart_hash     = $cart->get_cart_hash();

        $response = isset($cart_checkout[$cart_hash]) ? $cart_checkout[$cart_hash] : $helper->create_checkout();

        if ($response)
            WC()->session->set('mobbex_cart_checkout', [$cart_hash => $response]);

        return $response;
    }

}