<?php

return array(
    'name' => 'Ya CMS Shop-Script 5',
    'description' => 'Набор модулей yandex',
    'vendor' => '98765',
    'version' => '1.0.0',
    'img' => '/img/logo.png',
    'frontend' => true,
    'shop_settings' => true,
    'handlers' => array(
        'frontend_footer' => 'frontendFoot',
        'frontend_checkout' => 'frontendSucc',
    ),
);
