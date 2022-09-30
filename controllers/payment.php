<?php

namespace Mobbex\Controller;

final class Payment
{
    /** @var \MobbexLogger */
    public $logger;

    /** @var \MobbexHelper */
    public $helper;

    public function __construct()
    {
        $this->logger = new \MobbexLogger();
        $this->helper = new \MobbexHelper();

        if (!$this->logger->error) 
            add_action('woocommerce_api_mobbex_return_url', [$this, 'mobbex_return_url']);

        //Add Mobbex Webhook hook 
        add_action('rest_api_init', function () {
            register_rest_route('mobbex/v1', '/webhook', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'mobbex_webhook'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Redirect to Mobbex checkout or cart page in error case.
     * 
     */
    public function mobbex_return_url()
    {
        $status = $_GET['status'];
        $id     = $_GET['mobbex_order_id'];
        $token  = $_GET['mobbex_token'];
        $error  = false;

        if (empty($status) || empty($id) || empty($token))
            $error = "No se pudo validar la transacción. Contacte con el administrador de su sitio";

        if (!$this->helper->valid_mobbex_token($token))
            $error = "Token de seguridad inválido.";

        if ($error)
            return $this->_redirect_to_cart_with_error($error);

        $order = wc_get_order($id);

        if ($status > 1 && $status < 400) {
            // Redirect
            $redirect = $order->get_checkout_order_received_url();
        } else {
            return $this->_redirect_to_cart_with_error('Transacción fallida. Reintente con otro método de pago.');
        }

        WC()->session->set('order_id', null);
        WC()->session->set('order_awaiting_payment', null);

        wp_safe_redirect($redirect);
    }

    private function _redirect_to_cart_with_error($error_msg)
    {
        wc_add_notice($error_msg, 'error');
        wp_redirect(wc_get_cart_url());

        return array('result' => 'error', 'redirect' => wc_get_cart_url());
    }

    /**
     * Process the Mobbex Webhook.
     * 
     * @param object $request
     * @return array
     */
    public function mobbex_webhook($request)
    {
        try {
            $this->logger->debug("REST API > Request", $request->get_params());
            
            $requestData = isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json' ? apply_filters('mobbex_order_webhook',  json_decode(file_get_contents('php://input'), true)) : apply_filters('mobbex_order_webhook', $request->get_params());
            $postData    = !empty($requestData['data']) ? $requestData['data'] : [];
            $id          = $request->get_param('mobbex_order_id');
            $token       = $request->get_param('mobbex_token');

            $this->logger->debug(
                'Mobbex Webhook Data: ',
                compact('id', 'token', 'postData')
            );

            //order webhook filter
            $webhookData = \MobbexHelper::format_webhook_data($id, $postData, $this->helper->multicard === 'yes', $this->helper->multivendor != 'no');
            
            // Save transaction
            global $wpdb;
            $wpdb->insert($wpdb->prefix.'mobbex_transaction', $webhookData, \MobbexHelper::db_column_format($webhookData));

            // Try to process webhook
            $result = $webhookData['parent'] === 'yes' ? $this->process_webhook($id, $token, $webhookData) : true;
            
            return [
                'result'   => $result,
                'platform' => [
                    'name'      => 'woocommerce',
                    'version'   => MOBBEX_VERSION,
                    'ecommerce' => [
                        'wordpress'   => get_bloginfo('version'),
                        'woocommerce' => WC_VERSION
                    ]
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->debug("REST API > Error", $e->getMessage());

            return [
                "result" => false,
            ];
        }
    }

    /**
     * Process & store the data of the Mobbex webhook.
     * 
     * @param string $id
     * @param string $token
     * @param array $data
     * 
     * @return bool 
     */
    public function process_webhook($id, $token, $data)
    {
        $status = $data['status_code'];
        
        $this->logger->debug("Mobbex API > Process Data", 
        [
            "id" => $id,
            "token" => $token,
            "status" => $status,
        ]);

        if (empty($status) || empty($id) || empty($token)) {
            $this->logger->debug('Missing status, id, or token.');

            return false;
        }

        if (!$this->helper->valid_mobbex_token($token)) {
            $this->logger->debug('Invalid mobbex token.');

            return false;
        }

        $order = wc_get_order($id);
        $order->update_meta_data('mobbex_webhook', json_decode($data['data'], true));
        $order->update_meta_data('mobbex_payment_id', $data['payment_id']);

        $source = json_decode($data['data'], true)['payment']['source'];
        $payment_method = $source['name'];

        // TODO: Check the Status and Make a better note here based on the last registered status
        $main_mobbex_note = 'ID de Operación Mobbex: ' . $data['payment_id'] . '. ';

        if (!empty($data['entity_uid'])) {
            $entity_uid = $data['entity_uid'];

            $mobbex_order_url = str_replace(['{entity.uid}', '{payment.id}'], [$entity_uid, $data['payment_id']], MOBBEX_COUPON);

            $order->update_meta_data('mobbex_coupon_url', $mobbex_order_url);
            $order->add_order_note('URL al Cupón: ' . $mobbex_order_url);
        }

        if (!empty($source['type']) && $source['type'] == 'card') {
            $mobbex_card_payment_info = $payment_method . ' ( ' . $source['number'] . ' )';
            $mobbex_card_plan = $source['installment']['description'] . '. ' . $source['installment']['count'] . ' Cuota/s' . ' de ' . $source['installment']['amount'];

            $order->update_meta_data('mobbex_card_info', $mobbex_card_payment_info);
            $order->update_meta_data('mobbex_plan', $mobbex_card_plan);

            $main_mobbex_note .= 'Pago realizado con ' . $mobbex_card_payment_info . '. ' . $mobbex_card_plan . '. ';
        } else {
            $main_mobbex_note .= 'Pago realizado con ' . $payment_method . '. ';
        }

        $order->add_order_note($main_mobbex_note);

        if ($data['risk_analysis'] > 0) {
            $order->add_order_note('El riesgo de la operación fue evaluado en: ' . $data['risk_analisys']);
            $order->update_meta_data('mobbex_risk_analysis', $data['risk_analisys']);
        }

        if (!empty($payment_method)) {
            $order->set_payment_method_title($payment_method . ' ' . __('a través de Mobbex'));
        }

        $order->save();

        $this->update_order_status($order, $data);
        $this->update_order_total($order, $data);
        
        // Set Total Paid
        $order->set_total($data['total']);

        //action with the checkout data
        do_action('mobbex_webhook_process', $id, json_decode($data['data'], true));

        return true;
    }

    /**
     * Update order status using webhook formatted data.
     * 
     * @param WC_Order $order
     * @param array $data
     */
    public function update_order_status($order, $data)
    {
        $helper = new \MobbexOrderHelper($order);

        $order->update_status(
            $helper->get_status_from_code($data['status_code']),
            $data['status_message']
        );

        // Complete payment only if it's approved
        if (in_array($data['status_code'], $helper->status_codes['approved']))
            $order->payment_complete($data['payment_id']);
    }

    /**
     * Update order total paid using webhook formatted data.
     * 
     * @param WC_Order $order
     * @param array $data
     */
    public function update_order_total($order, $data)
    {
        if ($order->get_total() == $data['total'] || $data['status_code'] == 605 || $order->get_meta('mbbx_updated'))
            return;

        // Add a fee item to order with the difference
        $item = new \WC_Order_Item_Fee;
        $item->set_props([
            'name'   => $data['total'] > $order->get_total() ? 'Cargo financiero' : 'Descuento',
            'amount' => $data['total'] - $order->get_total(),
            'total'  => $data['total'] - $order->get_total(),
        ]);
        $order->add_item($item);

        // Recalculate totals and add flag to not do it again
        $order->calculate_totals();
        $order->update_meta_data('mbbx_updated', 1);
    }
}
