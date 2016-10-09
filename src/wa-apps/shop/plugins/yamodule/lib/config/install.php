<?php
$model = new waModel();
$sql = 'CREATE TABLE IF NOT EXISTS `shop_pokupki_orders` (
		  `id_order` int(10) NOT NULL,
		  `id_market_order` varchar(100) NOT NULL,
		  `currency` varchar(100) NOT NULL,
		  `ptype` varchar(100) NOT NULL,
		  `home` varchar(100) NOT NULL,
		  `pmethod` varchar(100) NOT NULL,
		  `outlet` varchar(100) NOT NULL,
		  PRIMARY KEY (`id_order`,`id_market_order`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

$sql_mws = 'CREATE TABLE IF NOT EXISTS `shop_mws_return`
		(
			`id_return` int(10) NOT NULL AUTO_INCREMENT,
			`invoice_id` varchar(128) NOT NULL,
			`cause` varchar(256) NOT NULL,
			`amount` DECIMAL(10,2) NOT NULL,
			`request` varchar(1024) NOT NULL,
			`response` varchar(1024) NOT NULL,
			`status` varchar(1024) NOT NULL,
			`error` varchar(1024) NOT NULL,
			`date` datetime NOT NULL,
			PRIMARY KEY  (`id_return`,`invoice_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci';

$s = $model->query($sql);
$s_mws = $model->query($sql_mws);

$plugin_id = array('shop', 'yamodule');
$app_settings_model = new waAppSettingsModel();
$data_db = array(
	'action' => 'kassa',
	'yamodule_mws_csr_sign' => '',
	'yamodule_mws_csr' => '',
	'yamodule_mws_pkey' => '',
	'yamodule_mws_cert' => '',
	'ya_kassa_max' => '5000',
	'ya_kassa_active' => '0',
	'ya_kassa_inside' => '1',
	'ya_kassa_paylogo' => '1',
	'ya_kassa_log' => '1',
	'ya_kassa_qp' => '1',
	'ya_kassa_qw' => '1',
	'ya_kassa_pb' => '1',
	'ya_kassa_ma' => '1',
	'ya_kassa_alfa' => '1',
	'ya_kassa_sber' => '1',
	'ya_kassa_wm' => '1',
	'ya_kassa_sms' => '1',
	'ya_p2p_active' => '1',
	'ya_kassa_terminal' => '1',
	'ya_kassa_card' => '1',
	'ya_kassa_wallet' => '1',
	'ya_kassa_pw' => '',
	'ya_kassa_scid' => '',
	'ya_kassa_shopid' => '',
	'status' => 'you',
	'update_time' => '1',
	'ya_kassa_test' => '1',
	'ya_p2p_test' => '1',
	'ya_p2p_number' => '',
	'ya_p2p_appid' => '',
	'ya_p2p_skey' => '',
	'ya_p2p_log' => '1',
	'ya_metrika_active' => '1',
	'ya_metrika_number' => '',
	'ya_metrika_appid' => '',
	'ya_metrika_pwapp' => '',
	'ya_metrika_login' => '',
	'ya_metrika_userpw' => '',
	'ya_metrika_token' => '',
	'ya_metrika_ww' => '1',
	'ya_metrika_map' => '1',
	'ya_metrika_out' => '1',
	'ya_metrika_refused' => '1',
	'ya_metrika_hash' => '1',
	'ya_metrika_cart' => '1',
	'ya_metrika_order' => '1',
	'ya_metrika_log' => '1',
	'ya_market_simpleyml' => '0',
	'ya_market_selected' => '1',
	'ya_market_set_available' => '1',
	'ya_market_name' => '',
	'ya_market_price' => '',
	'ya_market_available' => '1',
	'ya_market_home' => '0',
	'ya_market_comb' => '1',
	'ya_market_fea' => '1',
	'ya_market_dm' => '1',
	'ya_market_currencies' => '1',
	'ya_market_store' => '',
	'ya_market_delivery' => '',
	'ya_market_pickup' => '',
	'ya_market_log' => '1',
	'ya_pokupki_atoken' => '',
	'ya_pokupki_url' => 'https://api.partner.market.yandex.ru/v2/',
	'ya_pokupki_campaign' => '',
	'ya_pokupki_login' => '',
	'ya_pokupki_userpw' => '',
	'ya_pokupki_appid' => '',
	'ya_pokupki_pwapp' => '',
	'ya_pokupki_token' => '',
	'ya_pokupki_pickup' => '',
	'ya_pokupki_yandex' => '1',
	'ya_pokupki_sprepaid' => '',
	'ya_pokupki_cash' => '1',
	'ya_pokupki_card' => '1',
	'ya_pokupki_log' => '1',
	'ya_metrika_code' => ' ',
	'ya_metrika_informer' => '1',
	'ya_pokupki_carrier' => '',
	'ya_market_vendor' => '',
	'ya_pokupki_rate' => '',
	'ya_market_currency' => 'RUB',
	'ya_market_categories' => '',
	'ya_plugin_contact' => '',
	'type' => 'metrika',
);

if ($s)
	foreach ($data_db as $k => $val)
		$app_settings_model->set($plugin_id, $k, $val);

$contact = new waContactEmailsModel();
$contact_id = $contact->getContactIdByEmail('yandex@buy.rux');

if (!$contact_id)
{
	$user = new waContact();
	$user['firstname'] = 'Yandex';
	$user['lastname'] = 'Buy';
	$user['email'] = 'yandex@buy.rux';
	$user['create_datetime'] = date('Y-m-d H:i:s');
	$user['create_app_id'] = 'shop';
	$user['password'] = base64_decode('000000');
	$errors_c = $user->save();

	$app_settings_model->set($plugin_id, 'ya_plugin_contact', $user->getId());
}
else
	$app_settings_model->set($plugin_id, 'ya_plugin_contact', $contact_id);