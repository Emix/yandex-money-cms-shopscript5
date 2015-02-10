<?php

class shopYamodulePluginSettingsAction extends waViewAction {

    protected $plugin_id = array('shop', 'yamodule');

    public function execute() {
        $sm = new waAppSettingsModel();
        $settings = $sm->get($this->plugin_id);

		$settings['ya_pokupki_carrier'] = unserialize($settings['ya_pokupki_carrier']);
		$settings['ya_pokupki_rate'] = unserialize($settings['ya_pokupki_rate']);
		$plugin_model = new shopPluginModel();
		$methods = $plugin_model->listPlugins('shipping');
		$allowed = array('RUR', 'RUB', 'UAH', 'USD', 'BYR', 'KZT', 'EUR');
		$currency_model = new shopCurrencyModel();
		$currencies = $currency_model->getCurrencies();
			foreach ($currencies as $k => $currency)
				if (!in_array($currency['code'], $allowed))
					unset($currencies[$k]);
		$ya_features = array();
		$ff = new shopFeatureModel();
		$ya_features = $ff->getAll();
		
		// waSystem::dieo($ya_features);
        $this->view->assign('ya_features', $ya_features);
        $this->view->assign('ya_kassa_methods', $methods);
        $this->view->assign('ya_kassa_check', $this->getRelayUrl(true));
        $this->view->assign('ya_kassa_aviso', $this->getRelayUrl(true));
        $this->view->assign('ya_kassa_fail', $this->getRelayUrl().'?result=fail');
        $this->view->assign('ya_kassa_success', $this->getRelayUrl().'?result=success');
        $this->view->assign('ya_p2p_callback', $this->getRelayUrl(true));
        $this->view->assign('ya_pokupki_callback', '---');
        $this->view->assign('ya_metrika_callback', '---');
        $this->view->assign('ya_market_yml', wa()->getRootUrl(true).'yamodule/price.xml');
        $this->view->assign('ya_pokupki_link', wa()->getRootUrl(true).'pokupki');
        $this->view->assign('ya_currencies', $currencies);
        $this->view->assign($settings);
    }

	public final function getRelayUrl($force_https = null)
    {
        $url = wa()->getRootUrl(true).'payments.php/yamodulepay/';
        //TODO detect - is allowed https
        if ($force_https) {
            $url = preg_replace('@^http://@', 'https://', $url);
        } elseif ($force_https === false) {
            $url = preg_replace('@^https://@', 'http://', $url);
        }
        return $url;
    }
}
