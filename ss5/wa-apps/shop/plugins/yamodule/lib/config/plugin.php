<?php

return array(
    'name' => 'yamodule',
    'description' => 'Пак модулей yandex',
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
