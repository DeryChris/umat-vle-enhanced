<?php return array(
    'root' => array(
        'name' => 'moodle/moodle',
        'pretty_version' => 'dev-main',
        'version' => 'dev-main',
        'reference' => 'ca1ad81803f44bee513b2a4930bf9988679d164d',
        'type' => 'moodle-core',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'moodle/lms' => array(
            'dev_requirement' => false,
            'provided' => array(
                0 => '5.1',
            ),
        ),
        'moodle/moodle' => array(
            'pretty_version' => 'dev-main',
            'version' => 'dev-main',
            'reference' => 'ca1ad81803f44bee513b2a4930bf9988679d164d',
            'type' => 'moodle-core',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
