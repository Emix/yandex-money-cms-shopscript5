<?php

class shopYamodulePlugin extends shopPlugin {

	public function sendStatistics()
	{
		global $wa;
		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		$sm = new waAppSettingsModel();
		$data = $sm->get('shop.yamodule');
		$data_shop = $sm->get('webasyst');
		$array = array(
			'url' => wa()->getUrl(true),
			'cms' => 'shop-script5',
			'version' => wa()->getVersion('webasyst'),
			'ver_mod' => $this->info['version'],
			'email' => $data_shop['email'],
			'shopid' => $data['ya_kassa_shopid'],
			'settings' => array(
				'kassa' => $data['ya_kassa_active'],
				'p2p' => $data['ya_p2p_active'],
				'metrika' => $data['ya_metrika_active'],
			)
		);

		$array_crypt = base64_encode(serialize($array));

		$url = 'https://statcms.yamoney.ru/v2/';
		$curlOpt = array(
			CURLOPT_HEADER => false,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLINFO_HEADER_OUT => true,
			CURLOPT_POST => true,
		);

		$curlOpt[CURLOPT_HTTPHEADER] = $headers;
		$curlOpt[CURLOPT_POSTFIELDS] = http_build_query(array('data' => $array_crypt, 'lbl'=>1));

		$curl = curl_init($url);
		curl_setopt_array($curl, $curlOpt);
		$rbody = curl_exec($curl);
		$errno = curl_errno($curl);
		$error = curl_error($curl);
		$rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close($curl);
		  
		$json=json_decode($rbody);
		if ($rcode==200 && isset($json->new_version)){
			return $json->new_version;
		}else{
			return false;
		}
	}

    public function saveSettings($settings = array())
	{
		require_once dirname(__FILE__).'/../api/mws.php';
		require_once dirname(__FILE__).'/../api/metrika.php';

		if (waRequest::request('mode') == 'cert_upload')
		{
			return Mws::upload();
		}

		if (waRequest::request('mode') == 'output_csr')
		{
			Mws::outputCsr();
			exit();
		}

		if (waRequest::request('mode') == 'generate_cert')
		{
			Mws::generateCsr();
			return array('errors' => $this->errors);
		}

		$sm = new waAppSettingsModel();
		$data = $sm->get('shop.yamodule');

		if (waRequest::request('mode') == 'make_return')
		{
			$errors = array();
			$id_order = waRequest::request('id_order');
			if ($id_order > 0)
			{
				$order_model = new shopOrderModel();
				$order = $order_model->getById($id_order);

				$mws = New Mws();
				$mws->demo = $data['ya_kassa_test'];
				$mws->shopId = $data['ya_kassa_shopid'];
				$mws->PkeyPem = isset($data['yamodule_mws_pkey']) ? $data['yamodule_mws_pkey'] : '';
				$mws->CertPem = isset($data['yamodule_mws_cert']) ? $data['yamodule_mws_cert'] : '';

				$model = new shopPluginModel();
				$loaded_plugin = $model->getByField('plugin', 'yamodulepay');
				$mws_payment = $mws->request('listOrders', array('orderNumber' => implode('/', array('shop', $loaded_plugin['id'], $order['id']))), false, false);

				if (!isset($mws_payment['invoiceId']) || !$mws_payment['invoiceId'])
					$errors[] = 'Проблема с сертификатом, отсутствием оплаты по данному заказу или указан ошибочный идентификатор магазина';

				if (empty($errors) && isset($_POST['return_sum'])) {
					$cause = waRequest::request('return_cause') ? waRequest::request('return_cause') : '';
					$amount = waRequest::request('return_sum');
					$amount = $amount ? $amount : 0;
					$amount = number_format((float)$amount, 2, '.', '');

					if (strlen($cause) > 100 || strlen($cause) < 3)
						$errors[] = 'Причина возврата не может быть пустой или превышать длину в 100 символов';
					if ($amount > $mws_payment['orderSumAmount'])
						$errors[] = 'Сумма возврата не может превышать сумму платежа';

					if (!count($errors)) {
						$mws_return = $mws->request('returnPayment', array('invoiceId' => $mws_payment['invoiceId'], 'amount' => $amount, 'cause' => $cause));

						if (isset($mws_return['status'])){
							$mws->addReturn(array(
								'amount' => $amount,
								'cause' => $cause,
								'request' => $mws->txt_request || 'NULL',
								'response' => $mws->txt_respond || 'NULL',
								'status' => $mws_return['status'],
								'error' => $mws_return['error'],
								'invoice_id' => $mws_payment['invoiceId'],
								'date' => date('Y-m-d H:i:s')
							));

							if($mws_return['status'] == 0) {
								$success = true;
							} else {
								$errors[] = $mws->getErr($mws_return['error']);
							}
						}
					}
				}
			}

			return array('mws_return' => isset($mws_return) ? $mws_return : '', 'errors' => $errors);
		}

		foreach ($_POST as $k => $v)
		{
			if ($k == 'ya_pokupki_carrier' || $k == 'ya_pokupki_rate' || $k == 'ya_market_categories')
				$v = serialize($v);

			$sm->set('shop.yamodule' , $k, $v);
		}

		$array_fields = array(
			'ya_kassa_shopid' => _w('Не заполнен Shop Id'),
			'ya_kassa_scid' => _w('Не заполнен SCID'),
			'ya_kassa_pw' => _w('Не заполнен Пароль'),
			'ya_p2p_number' => _w('Не заполнен номер кошелька'),
			'ya_p2p_appid' => _w('Не заполнен id приложения'),
			'ya_p2p_skey' => _w('Не заполнен секретный ключ'),
			'ya_metrika_number' => _w('Не заполнен номер счётчика'),
			'ya_metrika_appid' => _w('Не заполнен id приложения'),
			'ya_metrika_pwapp' => _w('Не заполнен пароль приложения'),
			'ya_metrika_token' => _w('Не заполнен токен. Получите его'),
			'ya_market_name' => _w('Не заполнено имя магазина'),
			'ya_market_price' => _w('Не заполнена цена'),
			'ya_pokupki_atoken' => _w('Не заполнен токен. Получите его'),
			'ya_pokupki_url' => _w('Не заполнена ссылка'),
			'ya_pokupki_appid' => _w('Не заполнен id приложения'),
			'ya_pokupki_pwapp' => _w('Не заполнен пароль приложения'),
			'ya_pokupki_campaign' => _w('Не заполнен номер кампании'),
			'ya_pokupki_token' => _w('Не заполнен токен. Получите его'),
		);
		$this->errors = array();
		$update_status = $this->sendStatistics();
		if ($update_status != false) $this->errors['update'][] = '<div class="alert alert-danger">У вас неактуальная версия модуля. Вы можете <a target="_blank" href="https://github.com/yandex-money/yandex-money-cms-shopscript5/releases">загрузить и установить</a> новую ('.$update_status.')</div>';
		
		$this->errors['metrika'] = array();
		$data = $sm->get('shop.yamodule');
		$keys = array_keys($array_fields);
		foreach ($keys as $key)
		{
			if (empty($data[$key]))
			{
				$d = explode('_', $key);
				$this->errors[$d[1]][] = $this->errors_alert($array_fields[$key]);
			}
		}

		if (waRequest::request('action') == 'kassa')
		{
			if (waRequest::request('ya_kassa_active')
				&& (!isset($data['yamodule_mws_csr_sign']) || empty($data['yamodule_mws_csr_sign']) || ($data['ya_kassa_shopid'] != $_POST['ya_kassa_shopid']))
			) {
				Mws::generateCsr();
			}
		}

		$all_ok = _w('Все настройки верно заполнены!');
		$arr = array('p2p', 'kassa', 'market', 'pokupki', 'metrika');
		if (waRequest::request('mode') == 'metrika')
		{
			$ymetrika = new YaMetrika();
			$ymetrika->initData($data['ya_metrika_token'], $data['ya_metrika_number']);
			$ymetrika->client_id = $data['ya_metrika_appid'];
			$ymetrika->client_secret = $data['ya_metrika_pwapp'];
			$ymetrika->processCounter();
			$this->errors['metrika'] = array_merge($this->errors['metrika'], $_SESSION['metrika_status']);
		}

		foreach ($arr as $a)
		{
			if (!isset($this->errors[$a]) || !count($this->errors[$a]))
				$this->errors[$a][] = $this->success_alert($all_ok);
		}

		return array('errors' => $this->errors);
	}

	public function errors_alert($text)
	{
		$html = '<div class="alert alert-danger">
						<i class="fa fa-exclamation-circle"></i> '.$text.'
					</div>';
		return $html;
	}

	public function success_alert($text)
	{
		$html = ' <div class="alert alert-success">
					<i class="fa fa-check-circle"></i> '.$text.'
					</div>';
		return $html;
	}

	public static function settingsPaymentOptions($type)
    {
		$tp = array(
            'PC' => 'Оплата из кошелька в Яндекс.Деньгах',
            'AC' => 'Оплата с произвольной банковской карты',
            'GP' => 'Оплата наличными через кассы и терминалы',
            'MC' => 'Оплата со счета мобильного телефона',
            'WM' => 'Оплата из кошелька в системе WebMoney',
            'SB' => 'Оплата через Сбербанк: оплата по SMS или Сбербанк Онлайн',
            'AB' => 'Оплата через Альфа-Клик',
            'MC' => 'Платеж со счета мобильного телефона',
            'MA' => 'Оплата через MasterPass',
            'PB' => 'Оплата через Промсвязьбанк',
            'QW' => 'Оплата через QIWI Wallet',
            'QP' => 'Оплата через доверительный платеж (Куппи.ру)',
        );

        return isset($tp[$type]) ? $tp[$type] : $type;
    }

    public function yaOrderShip($data) {
		require_once dirname(__FILE__).'/../api/pokupki.php';
		$dbm = new waModel();
		$order = $dbm->query("SELECT * FROM `shop_pokupki_orders` WHERE id_order = ".(int)$data['order_id'])->fetchRow();
		$order_id = isset($order[1]) ? $order[1] : 0;
		if ($order_id)
		{
			$pokupki = new YaPokupki();
			$pokupki->makeData();
			$status = $pokupki->sendOrder('DELIVERY', $order_id);
		}
	}

    public function yaOrderRefund($data) {
		return $this->yaOrderDel($data);
	}

    public function yaOrderDel($data) {
		require_once dirname(__FILE__).'/../api/pokupki.php';
		$dbm = new waModel();
		$order = $dbm->query("SELECT * FROM `shop_pokupki_orders` WHERE id_order = ".(int)$data['order_id'])->fetchRow();
		$order_id = isset($order[1]) ? $order[1] : 0;
		if ($order_id)
		{
			$pokupki = new YaPokupki();
			$pokupki->makeData();
			$status = $pokupki->sendOrder('CANCELLED', $order_id);
		}
	}

    public function yaOrderProcess($data) {
		require_once dirname(__FILE__).'/../api/pokupki.php';
		$dbm = new waModel();
		$order = $dbm->query("SELECT * FROM `shop_pokupki_orders` WHERE id_order = ".(int)$data['order_id'])->fetchRow();
		$order_id = isset($order[1]) ? $order[1] : 0;
		if ($order_id)
		{
			$pokupki = new YaPokupki();
			$pokupki->makeData();
			$status = $pokupki->sendOrder('PROCESSING', $order_id);
		}
	}

	public static function log_save($logtext)
	{
		$real_log_file = './ya_logs/pokupki_'.date('Y-m-d').'.log';
		$h = fopen($real_log_file , 'ab');
		fwrite($h, date('Y-m-d H:i:s ') . '[' . addslashes($_SERVER['REMOTE_ADDR']) . '] ' . $logtext . "\n");
		fclose($h);
	}

    public function kassaOrderReturn() {
		require_once dirname(__FILE__).'/../api/mws.php';
		$view = wa()->getView();
		$ri = $errors = $order = array();
		$id_order = isset($_GET['id']) ? (int)$_GET['id'] : 0;

		if ($id_order)
		{
			$order_model = new shopOrderModel();
			$order = $order_model->getById($id_order);

            $order_params_model = new shopOrderParamsModel();
            $payment_plugin = $order_params_model->getOne($order['id'], 'payment_plugin');
            if ($payment_plugin != 'yamodulepay') return false;

			$sm = new waAppSettingsModel();
			$data = $sm->get('shop.yamodule');

			$mws = New Mws();
			$mws->demo = $data['ya_kassa_test'];
			$mws->shopId = $data['ya_kassa_shopid'];
			$mws->PkeyPem = isset($data['yamodule_mws_pkey']) ? $data['yamodule_mws_pkey'] : '';
			$mws->CertPem = isset($data['yamodule_mws_cert']) ? $data['yamodule_mws_cert'] : '';

            if ($mws->CertPem == '' || $mws->PkeyPem == '') return false;

			$model = new shopPluginModel();
			$loaded_plugin = $model->getByField('plugin', 'yamodulepay');
			$mws_payment = ($mws->CertPem!='')?$mws->request('listOrders', array('orderNumber' => implode('/', array('shop', $loaded_plugin['id'], $order['id']))), false, false):array();

			if (!isset($mws_payment['invoiceId']) || !$mws_payment['invoiceId'])
				$errors[] = _w('Проблема с сертификатом, отсутствием оплаты по данному заказу или указан ошибочный идентификатор магазина');

		}

		$inv = (isset($mws_payment['invoiceId'])) ? $mws_payment['invoiceId'] : 0;
		$inv_sum = (isset($mws_payment['orderSumAmount'])) ? $mws_payment['orderSumAmount'] : 0;
		$inv_type = (isset($mws_payment['paymentType'])) ? $mws_payment['paymentType'] : "none";
		$ri = $mws->getSuccessReturns($inv);
		$sum_returned = $mws->sum;

		$view->assign(array(
			'return_total' => ($sum_returned),
			'return_sum' => ($inv_sum - $sum_returned),
			'invoiceId' => $inv,
			'return_items' => $ri,
			'payment_method' => $this->settingsPaymentOptions($inv_type),
			'return_errors' => $errors,
			'total' => $inv_sum,
			'id_order' => $id_order,
			'test' => 1,
			'pym' => $inv
		));

		$html = '';
		if (isset($mws_payment['orderNumber']) && $mws_payment['orderNumber']){
            $html['info_section'] = $view->fetch($this->path.'/templates/actions/settings/tabs_return.html');
        }else{
            $html['info_section'] = _w("Error MWS: ").$mws->txt_error;
        }
		return $html;
	}

    public function frontendFoot() {
        if ($this->getSettings('ya_metrika_code') && $this->getSettings('ya_metrika_active'))
		{
			$html = '<script type="text/javascript" src="'.wa()->getAppStaticUrl().'plugins/yamodule/js/front.js"></script>';
            $html .= $this->getSettings('ya_metrika_code');

            return $html;
        }
    }

	public function frontendSucc() {
		$order_id = wa()->getStorage()->get('shop/order_id');
        if ($this->getSettings('ya_metrika_active') && $order_id)
		{
			$order_model = new shopOrderModel();
			$currency_model = new shopCurrencyModel();
			$order = $order_model->getById($order_id);
			$currency = $currency_model->getById($order['currency']);
			$order_items_model = new shopOrderItemsModel();
			$items = $order_items_model->getByField('order_id', $order_id, true);

			$html = '<script type="text/javascript">
			$(document).ready(function(){
					var yaParams_'.$order['id'].' = {
						order_id: "'.$order['id'].'",
						order_price: '.$order['total'].', 
						currency: "'.($order['currency'] == 'RUB' ? 'RUR' : $order['currency']).'",
						exchange_rate: '.$currency['rate'].',
						goods:[';
							foreach ($items as $item)
								$html .= '{id: '.$item['product_id'].', name: "'.$item['name'].'", price: '.$item['price'].', quantity: '.$item['quantity'].'},';
						$html .= ']
					};
					
					console.log(yaParams_'.$order['id'].');
					metrikaReach("metrikaOrder", yaParams_'.$order['id'].');
			});
					</script>';

            return $html;
        }
    }

}
