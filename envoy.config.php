<?php

/**
 * Project Enviroment Setting
 */
$env = getenv('ENVOY_ENV');
$path = getenv('ENVOY_PATH');
$subset = getenv('ENVOY_SUBSET');

// Support for json on base64 encode or a file
$connections = getenv('ENVOY_CONNECTIONS');
if (is_file($connections)) {
    $connections = file_get_contents($connections);
} else {
    $connections = base64_decode($connections);
}

/**
 * server settings
 * @var JSON
 * @example row set: {"server":{"conn":"foo@bar","option":"-o StrictHostKeyChecking=no"},}
 * @example row set: {"server":"root@foo.bar"},
 */
$server_connections = json_decode($connections, true);

/**
 * Slack notification configuration on deployment.
 */
$slack = [
    /**
     * Slack URL for notifications.
     */
    'url' => getenv('SLACK_WEBHOOK'),

    /**
     * Slack channel where to send notifications.
     */
    'channel' => getenv('SLACK_CHANNEL'),
];

/**
 * number of releases keep on remote
 */
$release_keep_count = 2;

/**
 * Release package name
 */
$source_name = 'source';

/**
 * shared sub-directories name , eg: storage
 */
$shared_subdirs = [
    'bootstrap/cache',
    'storage',
    'public/files',
    'public/static',
];

/**
 * Misc. Settings
 */
$settings = [
    // default env set
    'env_default' => 'production',
    // @example 'www-data'
	'service_owner_default'=>'www-data',
	// depends install components settings.
    'deps_install_component'=> [
        'composer'=>true,
        'npm'=>true,
        'bower'=>true,
        'gulp'=>true,
    ],
    'deps_install_command'=> [
        'composer'=>'composer install --prefer-dist --no-scripts --no-interaction --quiet',
        'npm'=>'npm install --cache-min 999999 --quiet',
        'bower'=>'bower install',
        'gulp'=>'gulp',
    ],
    'runtime_optimize_component'=> [
        'composer'=>false,
        'artisan'=> [
            'optimize'=>true,
            'config_cache'=>true,
            'route_cache'=>false,
        ],
    ],
    'runtime_optimize_command'=> [
        'composer'=>'composer dump-autoload --optimize',
        'artisan'=> [
            'optimize'=>'php artisan clear-compiled && php artisan optimize',
            'config_cache'=>'php artisan config:clear && php artisan config:cache',
            'route_cache'=>'php artisan route:clear && php artisan route:cache',
        ],
    ],
];
