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
    /**
     *
     * Success
     * @var int
     */
    const XML_SUCCESS = 0;

    /**
     *
     * Authorization failed
     * @var int
     */
    const XML_AUTH_FAILED = 1;

    /**
     *
     * Payment refused by shop
     * @var int
     */
    const XML_PAYMENT_REFUSED = 100;

    /**
     *
     * Bad request
     * @var int
     */
    const XML_BAD_REQUEST = 200;

    /**
     *
     * Temporary technical problems
     * @var int
     */
    const XML_TEMPORAL_PROBLEMS = 1000;

    private $version = '1.3';
    private $order_id;
    private $request;

    public function allowedCurrency()
    {
        return 'RUB';
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

		require_once __DIR__.'/../api/yamoney.php';
        $view = wa()->getView();
		$_SESSION['order_data'] = array();
		$app_m = new waAppSettingsModel();
		$yclass = new YandexMoney();
		$data = $app_m->get('shop.yamodule');
		$p2p = (bool)$data['ya_p2p_active'];
		$kassa = (bool)$data['ya_kassa_active'];
		$_SESSION['order_data'][$order_data['order_id']] = array(
			$this->app_id,
			$this->merchant_id,
		);

		if ($kassa)
		{
			$hidden_fields = array(
				'scid' => $data['ya_kassa_scid'],
				'ShopID' => $data['ya_kassa_shopid'],
				'CustomerNumber' => $order_data['customer_contact_id'],
				'customerNumber' => $order_data['customer_contact_id'],
				'orderNumber' => $this->app_id.'_'.$this->merchant_id.'_'.$order_data['order_id'],
				'Sum' => number_format($order_data['amount'], 2, '.', ''),
			);

			if ($data['ya_kassa_test'])
				$yclass->test = true;
			$view->assign('form_url', $yclass->getEndpointUrl());
			$view->assign('alfa', $data['ya_kassa_alfa']);
			$view->assign('wm', $data['ya_kassa_wm']);
			$view->assign('sber', $data['ya_kassa_sber']);
			$view->assign('sms', $data['ya_kassa_sms']);
			$view->assign('terminal', $data['ya_kassa_terminal']);
			$view->assign('card', $data['ya_kassa_card']);
			$view->assign('wallet', $data['ya_kassa_wallet']);
			$view->assign('hidden_fields', $hidden_fields);
		}

		if ($p2p)
		{
			$view->assign('form_url', $this->getRelayUrl());
		}

		$view->assign('p2p', $p2p);
		$view->assign('kassa', $kassa);
		$view->assign('auto_submit', false);
        return $view->fetch($this->path.'/templates/payment.html');
    }


    protected function callbackInit($request)
    {
        if (!empty($_POST['orderNumber']) && $_POST['action'] == 'paymentAviso')
		{
			$match = explode('_', $_POST['orderNumber']);
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
		require_once __DIR__.'/../api/yamoney.php';
		require_once __DIR__.'/../../../../wa-apps/shop/lib/model/shopOrder.model.php';
		$order_model = new shopOrderModel();
		$order = $order_model->getById($this->order_id ? $this->order_id : wa()->getStorage()->get('shop/order_id'));
		$action = waRequest::post('action');
		$doit = waRequest::get('doit');
		$app_m = new waAppSettingsModel();
		$yclass = new YandexMoney();
		$data = $app_m->get('shop.yamodule');

		if (waRequest::request('orderNumber'))
		{
			$yclass->password = $data['ya_kassa_pw'];
			$yclass->shopid = $data['ya_kassa_shopid'];
			if (waRequest::get('result') || waRequest::request('action') == 'PaymentFail' || waRequest::request('action') == 'PaymentSuccess')
			{
				$match = explode('_', waRequest::request('orderNumber'));
				if (waRequest::request('action') == 'PaymentFail')
					$red = wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true).'?order_id='.$match[2];
				else
					$red = wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'success'), true).'?order_id='.$match[2];

				return array(
					'redirect' => $red
				);
			}
			else
			{
				if ($_POST['action'] == 'checkOrder')
				{
					if ($data['ya_kassa_log'])
						$this->log_save('callback:  checkOrder');
					$code = $yclass->checkOrder($_POST);
					$yclass->sendCode($_POST, $code);
				}

				if ($_POST['action'] == 'paymentAviso'){
					if ($this->order_id > 0)
					{
						if ($data['ya_kassa_log'])
							$this->log_save('Payment by kassa for order '.$this->order_id.' success');
						$_SESSION['order_data'] = array();
						$transaction_data['merchant_id'] = $this->merchant_id;
						$transaction_data['order_id'] = $this->order_id;
						$transaction_data['type'] = self::OPERATION_CAPTURE;
						$transaction_data['state'] = self::STATE_CAPTURED;
						$transaction_data['plugin'] = 'yandex_p2p_card';
						$transaction_data['amount'] = $order['total'];
						if ($data['ya_kassa_log'])
						{
							$this->log_save('callback:  Aviso');
							$this->log_save('order_id '.$this->order_id);
						}

						$result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
						$yclass->checkOrder($_POST, true, true);
					}
				}
			}
		}

		if ($action == 'p2p')
		{
			$payment_type = waRequest::post('payment-type');
			if ($payment_type == 'wallet')
			{
				$scope = array(
					"payment.to-account(\"".$data['ya_p2p_number']."\",\"account\").limit(,".number_format($order['total'], 2, '.', '').")",
					"money-source(\"wallet\",\"card\")"
				);

				$auth_url = $yclass->buildObtainTokenUrl($data['ya_p2p_appid'], $this->getRelayUrl().'wallet?id_order='.$order['id'], $scope);
				if ($data['ya_p2p_log'])
					$this->log_save('Redirect to '.$auth_url);
				wa()->getResponse()->redirect($auth_url);
			}
			
			if ($payment_type == 'card')
			{
				$instance = $yclass->sendRequest('/api/instance-id', array('client_id' => $data['ya_p2p_appid']));
				if($instance->status == 'success')
				{
					$instance_id = $instance->instance_id;
					$message = 'payment to order #'.$_SESSION['shop/order_id'];
					if ($data['ya_p2p_log'])
						$this->log_save('payment by card to order #'.$_SESSION['shop/order_id']);
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
					if($response->status == 'success')
					{
						$this->error = false;
						$request_id = $response->request_id;
						$_SESSION['ya_encrypt_CRequestId'] = urlencode(base64_encode($request_id));

						do{
							$process_options = array(
								"request_id" => $request_id,
								'instance_id' => $instance_id,
								'ext_auth_success_uri' => $this->getRelayUrl().'card?id_order='.$order['id'],
								'ext_auth_fail_uri' => wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true).'order_id='.$order['id']
							);	

							$result = $yclass->sendRequest('/api/process-external-payment', $process_options);					
							if ($result->status == "in_progress")
							{
								if ($data['ya_p2p_log'])
									$this->log_save('Payment card in progress');
								sleep(1);
							}

						}while ($result->status == "in_progress");

						if($result->status == 'success')
						{
							if ($data['ya_p2p_log'])
								$this->log_save('Payment by card success');
							die('success');
							wa()->getResponse()->redirect($this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS));
						}
						elseif($result->status == 'ext_auth_required')
						{
							$_SESSION['cps_context_id'] = $result->acs_params->cps_context_id;
							$url = sprintf("%s?%s", $result->acs_uri, http_build_query($result->acs_params));
							if ($data['ya_p2p_log'])
								$this->log_save('Redirect to (card) '.$url);
							wa()->getResponse()->redirect($url);
							exit;
						}
						elseif($result->status == 'refused')
						{
							if ($data['ya_p2p_log'])
								$this->log_save('Refused '.$result->error);
							die($yclass->descriptionError($result->error) ? $yclass->descriptionError($result->error) : $result->error);
						}
					}
				}
			}
		}

		if ($doit == 'card')
		{
			if ($_SESSION['cps_context_id'] == waRequest::get('cps_context_id'))
			{
				if ($data['ya_p2p_log'])
					$this->log_save('Payment by card for order '.$this->order_id.' success');
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
}