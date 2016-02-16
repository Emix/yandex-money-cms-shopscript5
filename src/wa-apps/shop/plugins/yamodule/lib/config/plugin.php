<?php

return array(
    'name' => 'Y.CMS Shop-Script (1.2.3.1)',
    'description' => 'Набор модулей Яндекс (Яндекс.Деньги, Яндекс.Маркет, Яндекс.Метрика)',
    'vendor' => '98765',
    'version' => '1.2.3.2',
    'img' => '/img/logo.png',
    'frontend' => true,
    'shop_settings' => true,
    'handlers' => array(
        'frontend_footer' => 'frontendFoot',
        'frontend_checkout' => 'frontendSucc',
        'backend_order' => 'kassaOrderReturn',
        'order_action.process' => 'yaOrderProcess',
        'order_action.delete' => 'yaOrderDel',
        'order_action.ship' => 'yaOrderShip',
        'order_action.refund' => 'yaOrderRefund',
    ),
);
