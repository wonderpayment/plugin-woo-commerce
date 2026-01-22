<?php return array(
    'root' => array(
        'name' => 'wonder-payment/wonder-payment-for-woocommerce',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '7acc108f156d2fec6c2a68fba228335fa964be59',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'wonder-payment/wonder-payment-for-woocommerce' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '7acc108f156d2fec6c2a68fba228335fa964be59',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'wonderpayment/sdk' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'bf6e5661a788296016cf514dd63f7687b3ddbb14',
            'type' => 'library',
            'install_path' => __DIR__ . '/../wonderpayment/sdk',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
