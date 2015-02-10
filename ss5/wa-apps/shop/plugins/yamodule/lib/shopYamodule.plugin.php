<?php

class shopYamodulePlugin extends shopPlugin {

    public function sendSettings($post, $action)
	{
		$array = array(
			'cms' => 'shopscript5',
			'module' => $action,
			'adminemail' => 'test@mail.ru'
		);

		$post = array_merge($post, $array);
		$url = 'http://stat.ymwork.ru/index.php';
		$curlOpt = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLINFO_HEADER_OUT => 1,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 80,
			CURLOPT_PUT => 1,
			CURLOPT_BINARYTRANSFER => 1,
			CURLOPT_REFERER => $_SERVER['SERVER_NAME']
        );

		$headers[] = 'Content-Type: application/x-yametrika+json';
		$body = json_encode($post);
		$fp = fopen('php://temp/maxmemory:256000', 'w');
		fwrite($fp, $body);
		fseek($fp, 0);
		$curlOpt[CURLOPT_INFILE] = $fp; // file pointer
		$curlOpt[CURLOPT_INFILESIZE] = strlen($body);
        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOpt);
        $rbody = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
	}

	public function gocurl($type, $post)
	{
		$url = 'https://oauth.yandex.ru/token';
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_FAILONERROR, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_TIMEOUT, 9);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		$result = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$data = json_decode($result);
		if ($status == 200) {
			if (!empty($data->access_token))
			{
				$sm = new waAppSettingsModel();
				if($type == 'm')
					$sm->set('shop.yamodule' , 'ya_metrika_token', $data->access_token);
				elseif($type == 'p')
					$sm->set('shop.yamodule' , 'ya_pokupki_token', $data->access_token);
			}

			die(json_encode(array('token' => $data->access_token)));
		}
		else
			die($result);
	}
    public function saveSettings($settings = array())
	{
		$sm = new waAppSettingsModel();
		$data = $sm->get('shop.yamodule');
		$this->sendSettings($_POST, waRequest::request('mode'));
		foreach ($_POST as $k => $v)
		{
			if ($k == 'ya_pokupki_carrier' || $k == 'ya_pokupki_rate')
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
			'ya_metrika_login' => _w('Не заполнен логин yandex'),
			'ya_metrika_userpw' => _w('Не заполнен пароль yandex'),
			'ya_metrika_token' => _w('Не заполнен токен. Получите его'),
			'ya_market_name' => _w('Не заполнено имя магазина'),
			'ya_market_price' => _w('Не заполнена цена'),
			'ya_pokupki_atoken' => _w('Не заполнен токен. Получите его'),
			'ya_pokupki_url' => _w('Не заполнена ссылка'),
			'ya_pokupki_appid' => _w('Не заполнен id приложения'),
			'ya_pokupki_pwapp' => _w('Не заполнен пароль приложения'),
			'ya_pokupki_campaign' => _w('Не заполнен номер кампании'),
			'ya_pokupki_login' => _w('Не заполнен логин yandex'),
			'ya_pokupki_userpw' => _w('Не заполнен пароль yandex'),
			'ya_pokupki_token' => _w('Не заполнен токен. Получите его'),
		);

		$this->errors = array();
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

		$all_ok = _w('Все настройки верно заполнены!');
		$arr = array('p2p', 'kassa', 'market', 'pokupki', 'metrika');
		// waSystem::dieo($this->errors);
		if (waRequest::request('mode') == 'metrika')
		{
			require_once __DIR__.'/../api/metrika.php';
			$ymetrika = new YaMetrika();
			$ymetrika->initData($data['ya_metrika_token'], $data['ya_metrika_number']);
			$ymetrika->client_id = $data['ya_metrika_appid'];
			$ymetrika->client_secret = $data['ya_metrika_pwapp'];
			$ymetrika->username = $data['ya_metrika_login'];
			$ymetrika->password = $data['ya_metrika_userpw'];
			$ymetrika->processCounter();
			$this->errors['metrika'] = array_merge($this->errors['metrika'], $_SESSION['metrika_status']);
		}

		if (waRequest::post('action') == 'get_token')
		{
			$type = waRequest::post('type');
			if ($type == 'metrika')
				return $this->gocurl('m', 'grant_type=password&username='.$data['ya_metrika_login'].'&password='.$data['ya_metrika_userpw'].'&client_id='.$data['ya_metrika_appid'].'&client_secret='.$data['ya_metrika_pwapp']);
			if ($type == 'pokupki')
				return $this->gocurl('p', 'grant_type=password&username='.$data['ya_pokupki_login'].'&password='.$data['ya_pokupki_userpw'].'&client_id='.$data['ya_pokupki_appid'].'&client_secret='.$data['ya_pokupki_pwapp']);
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
