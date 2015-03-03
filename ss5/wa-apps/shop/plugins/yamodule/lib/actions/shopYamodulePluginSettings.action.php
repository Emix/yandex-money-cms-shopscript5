<?php

class shopYamodulePluginSettingsAction extends waViewAction {

    protected $plugin_id = array('shop', 'yamodule');

    public function execute() {
        $sm = new waAppSettingsModel();
        $settings = $sm->get($this->plugin_id);
		$settings['ya_pokupki_carrier'] = unserialize($settings['ya_pokupki_carrier']);
		$settings['ya_pokupki_rate'] = unserialize($settings['ya_pokupki_rate']);
		$settings['ya_market_categories'] = unserialize($settings['ya_market_categories']);
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
        $this->view->assign('treeCat', $this->treeCat());
        $this->view->assign($settings);
    }

	public final function getRelayUrl($force_https = null)
    {
        $url = wa()->getRootUrl(true).'payments.php/yamodulepay/';
        if ($force_https) {
            $url = preg_replace('@^http://@', 'https://', $url);
        } elseif ($force_https === false) {
            $url = preg_replace('@^https://@', 'http://', $url);
        }
        return $url;
    }

	public function treeItem($id, $name)
	{
		$html = '<li class="tree-item">
						<span class="tree-item-name">
							<input type="checkbox" name="ya_market_categories[]" value="'.$id.'">
							<i class="tree-dot"></i>
							<label class="">'.$name.'</label>
						</span>
					</li>';
		return $html;
	}

	public function treeFolder($id, $name)
	{
		$html = '<li class="tree-folder">
					<span class="tree-folder-name">
						<input type="checkbox" name="ya_market_categories[]" value="'.$id.'">
						<i class="icon-folder-open"></i>
						<label class="tree-toggler">'.$name.'</label>
					</span>
					<ul class="tree" style="display: block;">'.$this->treeCat($id).'</ul>
				</li>';
		return $html;
	}

	public function treeCat($id_cat = 0)
	{
		$html = '';
		$categories = $this->getCategories($id_cat);
		foreach ($categories as $category)
		{
			$children = $this->getCategories($category['id']);
			if (count($children))
			{
				$html .= $this->treeFolder($category['id'], $category['name']);
			}
			else
			{
				$html .= $this->treeItem($category['id'], $category['name']);
			}
		}

		return $html;
	}

	public function getCategories($parent_id = 0) {
		$cat = new shopCategoryModel();
		$sql = "SELECT c.* FROM `shop_category` c";
        $where = "`parent_id` = i:parent";
		$where .= " AND status = 1";
        $sql .= ' WHERE '.$where;
        $sql .= " ORDER BY `id`";
		$array = $cat->query($sql, array('parent' => $parent_id))->fetchAll();
		return $array;
	}
}
