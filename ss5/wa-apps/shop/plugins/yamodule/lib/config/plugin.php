<?php

return array(
    'name' => 'Y.CMS Shop-Script 5 (1.1.0beta',
    'description' => 'Набор модулей Яндекс (Яндекс.Деньги, Яндекс.Маркет, Яндекс.Метрика)',
    'vendor' => '98765',
    'version' => '1.1.0',
    'img' => '/img/logo.png',
    'frontend' => true,
    'shop_settings' => true,
    'handlers' => array(
        'frontend_footer' => 'frontendFoot',
        'frontend_checkout' => 'frontendSucc',
    ),
);
