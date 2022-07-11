<?php return array(
    'root' => array(
        'name' => 'epayco/module-epaycopayment',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => NULL,
        'type' => 'magento2-module',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'epayco/epayco-php' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'reference' => 'b9c72c4e6586045553bb194cd549e8929fb13c2c',
            'type' => 'sdk',
            'install_path' => __DIR__ . '/../epayco/epayco-php',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
        'epayco/module-epaycopayment' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => NULL,
            'type' => 'magento2-module',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'rmccue/requests' => array(
            'pretty_version' => 'dev-develop',
            'version' => 'dev-develop',
            'reference' => 'fc36dd62d79a51a992a77594b2bd3e2764441b9a',
            'type' => 'library',
            'install_path' => __DIR__ . '/../rmccue/requests',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'dev_requirement' => false,
        ),
    ),
);
