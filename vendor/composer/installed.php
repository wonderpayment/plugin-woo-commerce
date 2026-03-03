<?php return array(
    'root' => array(
        'name' => 'wonder-payment/wonder-payment-for-woocommerce',
        'pretty_version' => 'dev-master',
        'version' => 'dev-master',
        'reference' => '448860db28e182d86e57b9ca0e426b1e249cfc81',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'wonder-payment/wonder-payment-for-woocommerce' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => '448860db28e182d86e57b9ca0e426b1e249cfc81',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'wonderpayment/sdk' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'eb02d746ed2677fc34ac6c106d4fa8f051b45de7',
            'type' => 'library',
            'install_path' => __DIR__ . '/../wonderpayment/sdk',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
