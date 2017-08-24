<?php

/**
 *
 * @author Webasyst
 * @name YandexMoney
 * @description YandexMoney pament module
 * @property-read string $integration_type
 * @property-read string $TESTMODE
 * @property-read string $shopPassword
 * @property-read string $ShopID
 * @property-read string $scid
 * @property-read string $payment_mode
 * @property-read array $paymentType
 *
 * @see https://money.yandex.ru/doc.xml?id=526537
 */
class yamodulepayPayment extends waPayment implements waIPayment
{
    /** @var int Success */
    const XML_SUCCESS = 0;

    /** @var int Authorization failed */
    const XML_AUTH_FAILED = 1;

    /** @var int Payment refused by shop */
    const XML_PAYMENT_REFUSED = 100;

    /** @var int Bad request */
    const XML_BAD_REQUEST = 200;

    /** @var int Temporary technical problems */
    const XML_TEMPORAL_PROBLEMS = 1000;

    private $version = '1.3.2';
    private $order_id;
    private $request;

    public function allowedCurrency()
    {
        return 'RUB';
    }

    private function extendItems(&$order)
    {
        $items = $order->items;
        $product_model = new shopProductModel();
        $discount = $order->discount;
        foreach ($items as & $item) {
            $data = $product_model->getById($item['product_id']);
            $item['tax_id'] = ifset($data['tax_id']);
            $item['currency'] = $order->currency;
            if (!empty($item['total_discount'])) {
                $discount -= $item['total_discount'];
                $item['total'] -= $item['total_discount'];
                $item['price'] -= $item['total_discount'] / $item['quantity'];
            }
        }

        unset($item);

        $discount_rate = $order->total ? ($order->discount / ($order->total + $order->discount - $order->tax - $order->shipping)) : 0;

        $taxes_params = array(
            'billing'  => $order->billing_address,
            'shipping' => $order->shipping_address,
            'discount_rate' => $discount_rate
        );
        shopTaxes::apply($items, $taxes_params, $order->currency);

        if ($discount) {
            $k = 1 - $discount_rate;

            foreach ($items as & $item) {
                if ($item['tax_included']) {
                    $item['tax'] = round($k * $item['tax'], 4);
                }

                $item['price'] = round($k * $item['price'], 4);
                $item['total'] = round($k * $item['total'], 4);
            }

            unset($item);
        }

        $order->items = $items;
        return $items;
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order_data = waOrder::factory($order_data);
        if ($order_data['currency_id'] != 'RUB') {
            return array(
                'type' => 'error',
                'data' => _w('Оплата на сайте Яндекс.Денег производится в только в рублях (RUB) и в данный момент невозможна, так как эта валюта не определена в настройках.'),
            );
        }

        require_once dirname(__FILE__).'/../api/yamoney.php';

        $app_m = new waAppSettingsModel();
        $yclass = new YandexMoney();
        $data = $app_m->get('shop.yamodule');

        $_SESSION['order_data'] = array();
        $p2p = (bool)$data['ya_p2p_active'];
        $_SESSION['order_data'][$order_data['order_id']] = array(
            $this->app_id,
            $this->merchant_id,
        );

        $view = wa()->getView();
        if ((bool)$data['ya_kassa_active']) {
            $this->assignKassaVariables($order_data, $data, $yclass, $view);
        }
        if ((bool)$data['ya_p2p_active']) {
            $view->assign('form_url', $this->getRelayUrl());
        }
        if ((bool)$data['ya_billing_active']) {
            $this->assignBillingVariables($order_data, $data, $yclass, $view);
            return $view->fetch($this->path.'/templates/billing_payment.html');
        }
        $view->assign('p2p', $p2p);
        $view->assign('auto_submit', false);
        return $view->fetch($this->path.'/templates/payment.html');
    }


    protected function callbackInit($request)
    {
        if (!empty($_POST['orderNumber']) && isset($_POST['action']) && $_POST['action'] == 'paymentAviso')
        {
            $match = explode('/', $_POST['orderNumber']);
            $this->app_id = $match[0];
            $this->merchant_id = $match[1];
            $this->order_id = $match[2];
        }

        $doit = waRequest::get('doit');
        $id = waRequest::get('id_order');
        if (($doit == 'wallet' || $doit == 'card') && isset($_SESSION['order_data'][$id]))
        {
            $this->order_id = $id;
            $this->app_id = $_SESSION['order_data'][$id][0];
            $this->merchant_id = $_SESSION['order_data'][$id][1];
        }

        return parent::callbackInit($request);
    }

    public static function log_save($logtext)
    {
        $real_log_file = './ya_logs/'.date('Y-m-d').'.log';
        $h = fopen($real_log_file , 'ab');
        fwrite($h, date('Y-m-d H:i:s ') . '[' . addslashes($_SERVER['REMOTE_ADDR']) . '] ' . $logtext . "\n");
        fclose($h);
    }

    /**
     *
     * @param array $request - get from gateway
     * @throws waPaymentException
     * @return mixed
     */
    protected function callbackHandler($request)
    {
        require_once dirname(__FILE__).'/../api/yamoney.php';
        require_once dirname(__FILE__).'/../../../../wa-apps/shop/lib/model/shopOrder.model.php';
        $order_model = new shopOrderModel();
        $order = $order_model->getById($this->order_id ? $this->order_id : wa()->getStorage()->get('shop/order_id'));
        $action = waRequest::post('action');
        $doit = waRequest::get('doit');
        $app_m = new waAppSettingsModel();
        $yclass = new YandexMoney();
        $data = $app_m->get('shop.yamodule');

        if (waRequest::request('orderNumber')) {
            $yclass->password = $data['ya_kassa_pw'];
            $yclass->shopid = $data['ya_kassa_shopid'];
            if (waRequest::get('result') || waRequest::request('action') == 'PaymentFail' || waRequest::request('action') == 'PaymentSuccess') {
                $match = explode('/', waRequest::request('orderNumber'));
                if (waRequest::request('action') == 'PaymentFail') {
                    $red = wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true) . '?order_id=' . $match[2];
                } else {
                    $red = wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'success'), true) . '?order_id=' . $match[2];
                }
                return array(
                    'redirect' => $red
                );
            } else {
                if (isset($_POST['action']) && $_POST['action'] == 'checkOrder') {
                    if ($data['ya_kassa_log']) {
                        $this->log_save('callback:  checkOrder');
                    }
                    $code = $yclass->checkOrder($_POST);
                    $yclass->sendCode($_POST, $code);
                }

                if (isset($_POST['action']) && $_POST['action'] == 'paymentAviso') {
                    if ($this->order_id > 0) {
                        if ($data['ya_kassa_log']) {
                            $this->log_save('Payment by kassa for order ' . $this->order_id . ' success');
                        }
                        $_SESSION['order_data'] = array();
                        $transaction_data['merchant_id'] = $this->merchant_id;
                        $transaction_data['order_id'] = $this->order_id;
                        $transaction_data['currency_id'] = 1;
                        $transaction_data['type'] = self::OPERATION_CAPTURE;
                        $transaction_data['state'] = self::STATE_CAPTURED;
                        $transaction_data['plugin'] = 'yandex_p2p_card';
                        $transaction_data['amount'] = $order['total'];
                        if ($data['ya_kassa_log']) {
                            $this->log_save('callback:  Aviso');
                            $this->log_save('order_id '.$this->order_id);
                        }

                        $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
                        $yclass->checkOrder($_POST, true, true);
                    }
                }
            }
        }

        if ($action == 'p2p') {
            $payment_type = waRequest::post('payment-type');
            if ($payment_type == 'wallet') {
                $scope = array(
                    "payment.to-account(\"".$data['ya_p2p_number']."\",\"account\").limit(,".number_format($order['total'], 2, '.', '').")",
                    "money-source(\"wallet\",\"card\")"
                );
                $auth_url = $yclass->buildObtainTokenUrl($data['ya_p2p_appid'], $this->getRelayUrl().'wallet?id_order='.$order['id'], $scope);
                if ($data['ya_p2p_log']) {
                    $this->log_save('Redirect to ' . $auth_url);
                }
                wa()->getResponse()->redirect($auth_url);
            }

            if ($payment_type == 'card') {
                $instance = $yclass->sendRequest('/api/instance-id', array('client_id' => $data['ya_p2p_appid']));
                if($instance->status == 'success') {
                    $instance_id = $instance->instance_id;
                    $message = 'payment to order #'.$_SESSION['shop/order_id'];
                    if ($data['ya_p2p_log']) {
                        $this->log_save('payment by card to order #' . $_SESSION['shop/order_id']);
                    }
                    $payment_options = array(
                        'pattern_id' => 'p2p',
                        'to' => $data['ya_p2p_number'],
                        'amount_due' => number_format($order['total'], 2, '.', ''),
                        'comment' => trim($message),
                        'message' => trim($message),
                        'instance_id' => $instance_id,
                        'label' => $_SESSION['shop/order_id']
                    );

                    $response = $yclass->sendRequest('/api/request-external-payment', $payment_options);
                    if($response->status == 'success') {
                        $this->error = false;
                        $request_id = $response->request_id;
                        $_SESSION['ya_encrypt_CRequestId'] = urlencode(base64_encode($request_id));

                        do {
                            $process_options = array(
                                "request_id" => $request_id,
                                'instance_id' => $instance_id,
                                'ext_auth_success_uri' => $this->getRelayUrl().'card?id_order='.$order['id'],
                                'ext_auth_fail_uri' => wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true)
                            );
                            $result = $yclass->sendRequest('/api/process-external-payment', $process_options);
                            if ($result->status == "in_progress") {
                                if ($data['ya_p2p_log']) {
                                    $this->log_save('Payment card in progress');
                                }
                                sleep(1);
                            }
                        } while ($result->status == "in_progress");

                        if($result->status == 'success') {
                            if ($data['ya_p2p_log'])
                                $this->log_save('Payment by card success');
                            die('success');
                            wa()->getResponse()->redirect($this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS));
                        } elseif($result->status == 'ext_auth_required') {
                            $_SESSION['cps_context_id'] = $result->acs_params->cps_context_id;
                            $url = sprintf("%s?%s", $result->acs_uri, http_build_query($result->acs_params));
                            if ($data['ya_p2p_log'])
                                $this->log_save('Redirect to (card) '.$url);
                            wa()->getResponse()->redirect($url);
                            exit;
                        } elseif($result->status == 'refused') {
                            if ($data['ya_p2p_log'])
                                $this->log_save('Refused '.$result->error);
                            die($yclass->descriptionError($result->error) ? $yclass->descriptionError($result->error) : $result->error);
                        }
                    }
                }
            }
        }

        if ($doit == 'card') {
            if ($_SESSION['cps_context_id'] == waRequest::get('cps_context_id')) {
                if ($data['ya_p2p_log']) {
                    $this->log_save('Payment by card for order ' . $this->order_id . ' success');
                }
                $_SESSION['cps_context_id'] = '';
                $transaction_data['merchant_id'] = $this->merchant_id;
                $transaction_data['order_id'] = $this->order_id;
                $transaction_data['type'] = self::OPERATION_CAPTURE;
                $transaction_data['state'] = self::STATE_CAPTURED;
                $transaction_data['currency_id'] = 1;
                $transaction_data['plugin'] = 'yandex_p2p_card';
                $transaction_data['amount'] = $order['total'];
                $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
                wa()->getResponse()->redirect(wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'success'), true).'?order_id='.$this->order_id);
            }
        }

        if ($doit == 'wallet')
        {
            $code = waRequest::get('code');
            if (empty($code))
            {
                if ($data['ya_p2p_log'])
                    $this->log_save('Empty code');
                wa()->getResponse()->redirect(wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true));
            }
            else
            {
                $response = $yclass->getAccessToken($data['ya_p2p_appid'], $code, $this->getRelayUrl().'wallet', $data['ya_p2p_skey']);
                $token = '';
                if (isset($response->access_token))
                    $token = $response->access_token;
                else
                {
                    if ($data['ya_p2p_log'])
                        $this->log_save('Error '.$response->error);
                    wa()->getResponse()->redirect(wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true));
                }

                if (!empty($token))
                {
                    $message = 'payment to order #'.$_SESSION['shop/order_id'];
                    if ($data['ya_p2p_log'])
                        $this->log_save('payment to order #'.$_SESSION['shop/order_id']);
                    $rarray = array(
                        'pattern_id' => 'p2p',
                        'to' => $data['ya_p2p_number'],
                        'amount_due' => number_format($order['total'], 2, '.', ''),
                        'comment' => trim($message),
                        'message' => trim($message),
                        'label' => $_SESSION['shop/order_id']
                    );

                    $request_payment = $yclass->sendRequest('/api/request-payment', $rarray, $token);
                    switch($request_payment->status)
                    {
                        case 'success':
                            $_SESSION['ya_encrypt_token'] = urlencode(base64_encode($token));
                            $_SESSION['ya_encrypt_RequestId'] = urlencode(base64_encode($request_payment->request_id));

                            do{
                                $array_p = array("request_id" => $request_payment->request_id);
                                $process_payment = $yclass->sendRequest("/api/process-payment", $array_p, $token);

                                if($process_payment->status == "in_progress") {
                                    sleep(1);
                                }
                            }while ($process_payment->status == "in_progress");

                            if ($process_payment->status == 'success')
                            {
                                if ($data['ya_p2p_log'])
                                    $this->log_save('Payment p2p wallet for order '.$this->order_id.' success');
                                $_SESSION['ya_encrypt_token'] = '';
                                $_SESSION['ya_encrypt_RequestId'] = '';
                                $transaction_data['merchant_id'] = $this->merchant_id;
                                $transaction_data['order_id'] = $this->order_id;
                                $transaction_data['type'] = self::OPERATION_CAPTURE;
                                $transaction_data['state'] = self::STATE_CAPTURED;
                                $transaction_data['currency_id'] = 1;
                                $transaction_data['plugin'] = 'yandex_p2p_wallet';
                                $transaction_data['amount'] = $order['total'];
                                $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
                                wa()->getResponse()->redirect(wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'success'), true).'?order_id='.$this->order_id);
                            }
                            break;
                        case 'refused':
                            if ($data['ya_p2p_log'])
                                    $this->log_save($request_payment->error.' : '.$request_payment->error_description);
                            wa()->getResponse()->redirect(wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true));
                            break;
                    }
                }
            }
        }
    }

    /**
     * @param waOrder $orderData
     * @param array $paymentInfo
     * @param YandexMoney $plugin
     * @param waSmarty3View $view
     */
    private function assignKassaVariables($orderData, $paymentInfo, $plugin, $view)
    {
        $customer_contact = new waContact($orderData['customer_contact_id']);
        $phone = $customer_contact->get('phone');
        $email = $customer_contact->get('email');
        $hidden_fields = array(
            'scid' => $paymentInfo['ya_kassa_scid'],
            'shopid' => $paymentInfo['ya_kassa_shopid'],
            'customerNumber' => $orderData['customer_contact_id'],
            'orderNumber' => $this->app_id.'/'.$this->merchant_id.'/'.$orderData['order_id'],
            'Sum' => number_format($orderData['amount'], 2, '.', ''),
            'cps_email' => isset($email[0]) ? $email[0]['value'] : '',
            'cps_phone' => isset($phone[0]) ? $phone[0]['value'] : '',
            'shopSuccessUrl' => wa()->getRootUrl(true).'payments.php/yamodulepay/?result=success',
            'shopFailUrl' => wa()->getRootUrl(true).'payments.php/yamodulepay/?result=fail',
            'cms_name' => 'ya_webasyst'
        );

        if (isset($paymentInfo['ya_kassa_send_check']) && $paymentInfo['ya_kassa_send_check']) {
            $taxValues = array();
            $order_model = new shopOrderModel();
            $order = $order_model->getById($orderData['order_id']);

            $items = $this->extendItems($orderData);
            $model = new waContactEmailsModel();
            $emails = $model->getEmails($order['contact_id']);

            $email = '';
            if (count($emails)) {
                foreach ($emails as $erow) {
                    if (!empty($erow['value'])) {
                        $email = $erow['value'];
                        break;
                    }
                }
            }

            if (isset($paymentInfo['taxValues'])) {
                @$val = unserialize($paymentInfo['taxValues']);
                if (is_array($val)) {
                    $taxValues = $val;
                }
            }

            require_once dirname(__FILE__) . '/YandexMoneyReceipt.php';
            $receipt = new YandexMoneyReceipt(1, 'RUB');
            $receipt->setCustomerContact($email);
            foreach ($items as $product) {
                $taxId = 'ya_kassa_tax_'.$product['tax_id'];
                $price = $product['price'] + ($product['tax'] / $product['quantity']);
                if (isset($taxValues[$taxId])) {
                    $receipt->addItem($product['name'], $price, $product['quantity'], $taxValues[$taxId]);
                } else {
                    $receipt->addItem($product['name'], $price, $product['quantity']);
                }
            }

            if ($orderData['shipping'] > 0) {
                $receipt->addShipping($orderData['shipping_name'], $orderData['shipping'], 1);
            }
            $view->assign('ym_merchant_receipt', $receipt->normalize($orderData['amount'])->getJson());
        } else {
            $view->assign('ym_merchant_receipt', null);
        }

        if ($paymentInfo['ya_kassa_test']) {
            $plugin->test = true;
        }
        $view->assign('form_url_kassa', $plugin->getEndpointUrl());
        $view->assign('inside', $paymentInfo['ya_kassa_inside']);
        $view->assign('paylogo', $paymentInfo['ya_kassa_paylogo']);
        $view->assign('alfa', $paymentInfo['ya_kassa_alfa']);
        $view->assign('wm', $paymentInfo['ya_kassa_wm']);
        $view->assign('sber', $paymentInfo['ya_kassa_sber']);
        $view->assign('sms', $paymentInfo['ya_kassa_sms']);
        $view->assign('terminal', $paymentInfo['ya_kassa_terminal']);
        $view->assign('card', $paymentInfo['ya_kassa_card']);
        $view->assign('wallet', $paymentInfo['ya_kassa_wallet']);
        $view->assign('pb', $paymentInfo['ya_kassa_pb']);
        $view->assign('ma', $paymentInfo['ya_kassa_ma']);
        $view->assign('qw', $paymentInfo['ya_kassa_qw']);
        $view->assign('qp', $paymentInfo['ya_kassa_qp']);
        $view->assign('kassa', true);
        $view->assign('hidden_fields', $hidden_fields);
    }

    /**
     * @param waOrder $orderData
     * @param array $paymentInfo
     * @param YandexMoney $plugin
     * @param waSmarty3View $view
     */
    private function assignBillingVariables($orderData, $paymentInfo, $plugin, $view)
    {
        $fio = array();
        $customer = new waContact($orderData['customer_contact_id']);
        foreach (array('lastname', 'firstname', 'middlename') as $field) {
            $name = $customer->get($field);
            if (!empty($name)) {
                $fio[] = $name;
            }
        }
        $purpose = $this->parsePlaceholders($paymentInfo['ya_billing_purpose'], $orderData);
        $view->assign('formId', $paymentInfo['ya_billing_id']);
        $view->assign('narrative', $purpose);
        $view->assign('amount', number_format($orderData['total'], 2, '.', ''));
        $view->assign('fio', implode(' ', $fio));
        $view->assign('formUrl', 'https://money.yandex.ru/fastpay/confirm');

        if (!empty($paymentInfo['ya_billing_status'])) {
            $this->setOrderState($paymentInfo['ya_billing_status'], $orderData, $purpose);
        }
        $view->assign('logs', $this->logs);
    }

    /**
     * @param string $template
     * @param waOrder $order
     * @return string
     */
    private function parsePlaceholders($template, $order)
    {
        $replace = array(
            '%order_id%' => $order->id,
        );
        return strtr($template, $replace);
    }

    /**
     * @param string $stateId
     * @param waOrder $orderData
     * @param string $purpose
     */
    private function setOrderState($stateId, $orderData, $purpose)
    {
        $stateConfig = shopWorkflow::getConfig();
        if (!array_key_exists($stateId, $stateConfig['states'])) {
            return;
        }

        $previousStateId = $orderData['state_id'];
        if (empty($previousStateId)) {
            $previousStateId = 'new';
        }
        if ($stateId != $previousStateId) {
            $orderModel = new shopOrderModel();
            $orderModel->updateById($orderData->id, array('state_id' => $stateId));

            $logModel = new shopOrderLogModel();
            $logModel->add(array(
                'order_id'        => $orderData->id,
                'contact_id'      => wa()->getUser()->getId(),
                'before_state_id' => $previousStateId,
                'after_state_id'  => $stateId,
                'text'            => $purpose,
                'action_id'       => '',
            ));
        }
    }
}