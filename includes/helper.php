<?php
require_once 'utils.php';

class MobbexHelper
{

    public function __construct()
    {
        // Init settings (Full List in WC_Gateway_Mobbex::init_form_fields)
        $option_key = 'woocommerce_' . MOBBEX_WC_GATEWAY_ID . '_settings';
        $settings = get_option($option_key, null) ?: [];
        foreach ($settings as $key => $value) {
            $key = str_replace('-', '_', $key);
            $this->$key = $value;
        }
    }

    public function isReady()
    {
        return ($this->enabled === 'yes' && !empty($this->api_key) && !empty($this->access_token));
    }

    /**
     * Get sources with common plans from mobbex.
     * @param integer|null $total
     */
    public function get_sources($total = null)
    {
        // If plugin is not ready
        if (!$this->isReady()) {
            return [];
        }

        $data = $total ? '?total=' . $total : null;

        $response = wp_remote_get(MOBBEX_SOURCES . $data, [

            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);
            $data = $response['data'];
            if ($data) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Get sources with advanced rule plans from mobbex.
     * @param string $rule
     */
    public function get_sources_advanced($rule = 'externalMatch')
    {
        if (!$this->isReady()) {
            return [];
        }
        $response = wp_remote_get(str_replace('{rule}', $rule, MOBBEX_ADVANCED_PLANS), [
            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],
        ]);
        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);
            $data = $response['data'];
            if ($data) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Return all active payment methods by tax id
     * @param $tax_id : integer  
     * @param $total : integer
     * @return Array
     */
    private function get_payment_methods($tax_id,$total){
        
        $url = str_replace('{total}', $total, MOBBEX_LIST_PLANS);
        $response = wp_remote_get(str_replace('{tax_id}', $tax_id, $url), [
            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],
        ]);
        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);
            $data = $response['data'];
            if ($data) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Return base 64 payment method image
     * @param $reference
     */
    private function get_payment_image($reference)
    {
        $response = wp_remote_get(str_replace('{reference}', $reference, MOBBEX_PAYMENT_IMAGE), [

            'headers' => [
                'cache-control' => 'no-cache',
                'content-type' => 'application/json',
                'x-api-key' => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

        ]);
        if (!is_wp_error($response)) {
            return $response['body'];
        }

        return [];
    }

    /**
     * Return the payment methods 
     * @param $tax_id : integer
     * @param $total : double
     * @param $product_id : integer
     * @param $method_id : integer
     * @return Array 
     */
    public function get_list_source($tax_id,$total,$product_id,$method_id=0)
    {
        $payment_methods_mobbex = $this->get_payment_methods($tax_id,$total);
        $payment_methods = array();

        if (!empty($payment_methods_mobbex)) {
            // installments view source
            $no_active_plans = $this->get_no_active_plans($product_id);       
            if($method_id != 0){
                $method = null;
                foreach($payment_methods_mobbex as $payment_method)
                {
                    if($payment_method['source']['id'] == $method_id){
                        $method = $payment_method;
                        break;
                    }
                }
                if($method != null){
                    $payment_methods[] = $payment_methods = $this->build_plan_array($method);
                }
            }else{
                foreach($payment_methods_mobbex as $payment_method){
                    $payment_methods[] = $this->build_plan_array($payment_method);
                }
            }
        }

        return $payment_methods;
    }

    /**
     * Return array with payment methods and their plans
     * @param $payment_method : array
     * @return Array 
     */
    private function build_plan_array($payment_method)
    {
        //only add if payment is enabled
        if($payment_method['installments']['enabled'])
        {
            $included_plans= array();
            foreach($payment_method['installments']['list'] as $installment)
            {
                $plan = array();
                //if it is a 'ahora' plan then use the reference 
                if(strpos($installment['reference'],'ahora')!== false){
                    if(!in_array($installment['reference'],$no_active_plans)){
                        $plan['name'] = $installment['name'];
                        $plan['amount'] = $installment['totals']['total'];    
                    }
                }else{
                    $plan['name'] = $installment['name'];
                    $plan['amount'] = $installment['totals']['total'];
                }
                if(!empty($plan)){
                    $included_plans[] = $plan;
                }
            }       
            if(!empty($included_plans)){
                $method = array();
                $method['id'] = $payment_method['source']['id'];
                $method['reference'] = $payment_method['source']['reference'];
                $method['name'] = $payment_method['source']['name'];
                $method['installments'] = $included_plans;
                $method['image'] = $this->get_payment_image($payment_method['source']['reference']);
                
            }
        }

        return $method;
    }

    /**
     * Return the plans that are not active in the product and categories
     * @param $product_id: integer
     * @return Array
     */
    private function get_no_active_plans($product_id)
    {
        $ahora = array(
            'ahora_3'  => 'Ahora 3',
            'ahora_6'  => 'Ahora 6',
            'ahora_12' => 'Ahora 12',
            'ahora_18' => 'Ahora 18',
        );
        $no_active_plans = array();
        
        // Check "Ahora" custom fields in categories
        $categories_ids = array();
        $categories = get_the_terms( $product_id, 'product_cat' );//retrieve categories
        foreach($categories as $category){
            array_push($categories_ids, $category->term_id);
        }
        
        foreach ($ahora as $key => $value) {
            //check if any of the product's categories have the plan selected
            //Have one or more categories
            foreach($categories_ids as $cat_id){
                if (get_term_meta($cat_id, $key, true) === 'yes') {
                    //Plan is checked in the category
                    $no_active_plans[] = $key;
                    unset($ahora[$key]);
                    break;
                }
            }
        }

        foreach ($ahora as $key => $value) 
        {
            if (get_post_meta($product_id, $key, true) === 'yes') {
                //the product have $ahora[$key] plan selected
                $no_active_plans[] = $key;
                unset($ahora[$key]);
            }
        }

        $checked_common_plans = unserialize(get_post_meta($product_id, 'common_plans', true));
        $checked_advanced_plans = unserialize(get_post_meta($product_id, 'advanced_plans', true));

        if (!empty($checked_common_plans)) 
        {
            foreach ($checked_common_plans as $key => $common_plan) {
                $no_active_plans[] = $common_plan;
                unset($checked_common_plans[$key]);
            }
        }

        if (!empty($checked_advanced_plans)) 
        {
            foreach ($checked_advanced_plans as $key => $advanced_plan) {
                $no_active_plans[] =$advanced_plan;
                unset($checked_advanced_plans[$key]);
            }
        }

        return $no_active_plans;
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

        foreach($products as $product)
            $categories = array_merge($categories, wp_get_post_terms($product, 'product_cat', ['fields' => 'ids']));

        // Remove duplicated IDs and return
        return array_unique($categories);
    }

    /**
     * Get payment mode.
     * 
     * @return string|null $payment_mode
     */
    public function get_payment_mode()
    {
        if (defined('MOBBEX_CHECKOUT_INTENT') && !empty(MOBBEX_CHECKOUT_INTENT)) {
            // Try to get from constant first
            return MOBBEX_CHECKOUT_INTENT;
        } else if (!empty($this->payment_mode) && $this->payment_mode === 'yes') {
            return 'payment.2-step';
        }
    }

    /**
     * Get Store configured by product/category using Multisite options.
     * 
     * @param WP_Order $order
     * 
     * @return array|null $store
     */
    public static function get_store($order)
    {
        $stores   = get_option('mbbx_stores');
        $products = self::get_product_ids($order);

        // Search store configured
        foreach ($products as $product_id) {
            $store_configured = self::get_store_from_product($product_id);

            if (!empty($store_configured) && !empty($stores[$store_configured]))
                return $stores[$store_configured];
        }
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
        // Get possible store from product
        $store = get_post_meta($product_id, 'mbbx_store', true);
        if (!empty($store))
            return $store;

        // Get possible stores from product categories
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
        foreach ($categories as $cat_id) {
            $store = get_term_meta($cat_id, 'mbbx_store', true);
            if (!empty($store))
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
        $response = wp_remote_post(str_replace('{id}', $payment_id, MOBBEX_CAPTURE_PAYMENT), [
            'headers' => [
                'cache-control'  => 'no-cache',
                'content-type'   => 'application/json',
                'x-api-key'      => $this->api_key,
                'x-access-token' => $this->access_token,
            ],

            'body'        => json_encode(compact('total')),
            'data_format' => 'body',
        ]);

        if (!is_wp_error($response)) {
            $response = json_decode($response['body'], true);

            if (!empty($response['result']))
                return true;
        }

        throw new Exception(__('An error occurred in the execution', 'mobbex-for-woocommerce'));
    }
}