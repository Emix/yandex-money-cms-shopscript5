<?php

class shopYamodulePluginSettingsAction extends waViewAction {

    protected $plugin_id = array('shop', 'yamodule');

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
            return $data->access_token;
            //die(json_encode(array('token' => $data->access_token)));
        }
        else
            return false;
    }

    private function getServerIp()
    {
        $url = 'http://ipv4.internet.yandex.net/internet/api/v0/ip';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9);
        curl_setopt($ch, CURLOPT_POST, 0);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status == 200) {
            $data = json_decode($result);
            if (is_string($data)) {
                return $data;
            }
        }
        return 'Не удалось определить IP адрес';
    }

    public function execute()
    {
        $sm = new waAppSettingsModel();
        $settings = $sm->get($this->plugin_id);

        if (waRequest::request('code') && waRequest::request('genToken')) {
            if (waRequest::request('type') == 'metrika'){
                $token = $this->gocurl('m', 'grant_type=authorization_code&code='.waRequest::request('code').'&client_id='.$settings['ya_metrika_appid'].'&client_secret='.$settings['ya_metrika_pwapp']);
            }else{
                $token = $this->gocurl('p', 'grant_type=authorization_code&code='.waRequest::request('code').'&client_id='.$settings['ya_pokupki_appid'].'&client_secret='.$settings['ya_pokupki_pwapp']);
            }

            exit(json_encode(array('token' => $token, 'url' => wa()->getRootUrl(true).'webasyst/shop/?action=plugins#/yamodule/')));
        }

        $settings['ya_pokupki_carrier'] = unserialize($settings['ya_pokupki_carrier']);
        $settings['ya_pokupki_rate'] = unserialize($settings['ya_pokupki_rate']);
        $settings['ya_market_categories'] = unserialize($settings['ya_market_categories']);
        $plugin_model = new shopPluginModel();
        $methods = $plugin_model->listPlugins('shipping');
        $allowed = array('RUR', 'RUB', 'UAH', 'USD', 'BYR', 'KZT', 'EUR');
        $currency_model = new shopCurrencyModel();
        $currencies = $currency_model->getCurrencies();
        foreach ($currencies as $k => $currency) {
            if (!in_array($currency['code'], $allowed)) {
                unset($currencies[$k]);
            }
        }
        $ya_features = array();
        $ff = new shopFeatureModel();
        $ya_features = $ff->getAll();

        $taxModel = new shopTaxModel();
        $taxes = $taxModel->getAll();
        $this->view->assign('taxes', $taxes);

        if (isset($settings['taxValues'])) {
            @$val = unserialize($settings['taxValues']);
            if (is_array($val)) {
                $this->view->assign($val);
            }
        }

        $newIpAddress = $this->getServerIp();
        if (!isset($settings['mws_ip_address'])) {
            $settings['mws_ip_address'] = $newIpAddress;
            $sm->set($this->plugin_id, 'mws_ip_address', $newIpAddress);
            unset($_SESSION['ya_mws_ip_counter']);
        } else {
            $oldIpAddress = $settings['mws_ip_address'];
            if ($oldIpAddress !== $newIpAddress) {
                if (!isset($_SESSION['ya_mws_ip_counter'])) {
                    $_SESSION['ya_mws_ip_counter'] = 1;
                } else {
                    $_SESSION['ya_mws_ip_counter'] += 1;
                    if ($_SESSION['ya_mws_ip_counter'] > 2) {
                        $settings['mws_ip_address'] = $newIpAddress;
                        $sm->set($this->plugin_id, 'mws_ip_address', $newIpAddress);
                        unset($_SESSION['ya_mws_ip_counter']);
                    }
                }
            }
        }

        $this->view->assign('ya_features', $ya_features);
        $this->view->assign('ya_kassa_methods', $methods);
        $this->view->assign('ya_kassa_check', $this->getRelayUrl(true));
        $this->view->assign('ya_kassa_aviso', $this->getRelayUrl(true));
        $this->view->assign('ya_kassa_fail', $this->getRelayUrl().'?result=fail');
        $this->view->assign('ya_kassa_success', $this->getRelayUrl().'?result=success');
        $this->view->assign('ya_p2p_callback', $this->getRelayUrl(true));
        $this->view->assign('ya_pokupki_callback', wa()->getRootUrl(true).'webasyst/shop/?action=plugins#/yamodule/');
        $this->view->assign('ya_metrika_callback', wa()->getRootUrl(true).'webasyst/shop/?action=plugins#/yamodule/');
        $this->view->assign('ya_market_yml', wa()->getRouteUrl('shop/frontend', array('module' => 'yamodule', 'action'=>'market'), true));
        $this->view->assign('ya_pokupki_link', wa()->getRouteUrl('shop/frontend',array(), true).'yamodule/pokupki');
        $this->view->assign('ya_currencies', $currencies);
        $this->view->assign('treeCat', $this->treeCat());

        $this->view->assign('mws_status', '');
        $this->view->assign('mws_cn', '/business/ss5/yacms-'.$settings['ya_kassa_shopid']);
        $this->view->assign('mws_sign', isset($settings['yamodule_mws_csr_sign']) ? $settings['yamodule_mws_csr_sign'] : '');
        $this->view->assign('mws_cert', isset($settings['yamodule_mws_cert']) && !empty($settings['yamodule_mws_cert']) ? 1 : 0);
        $this->view->assign('mws_server_ip', $newIpAddress);
        $this->view->assign('mws_server_old_ip', $settings['mws_ip_address']);

        $this->view->assign('ya_billing_active', empty($settings['ya_billing_active']) ? false : true);
        $this->view->assign('ya_billing_id', empty($settings['ya_billing_id']) ? '' : $settings['ya_billing_id']);
        $this->view->assign('ya_billing_purpose', empty($settings['ya_billing_purpose']) ? 'Номер заказа %order_id% Оплата через Яндекс.Платежку' : $settings['ya_billing_purpose']);
        $this->view->assign('ya_billing_status', empty($settings['ya_billing_status']) ? 'created' : $settings['ya_billing_status']);

        $this->view->assign('ya_kassa_send_check', empty($settings['ya_kassa_send_check']) ? false : true);

        $workflow = new shopWorkflow();
        $states = $workflow->getAllStates();
        $this->view->assign('ya_billing_statuses', $states);

        $this->view->assign($settings);
    }

    public final function getRelayUrl($force_https = true)
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
        foreach ($categories as $category) {
            $children = $this->getCategories($category['id']);
            if (count($children)) {
                $html .= $this->treeFolder($category['id'], $category['name']);
            } else {
                $html .= $this->treeItem($category['id'], $category['name']);
            }
        }
        return $html;
    }

    public function getCategories($parent_id = 0)
    {
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
