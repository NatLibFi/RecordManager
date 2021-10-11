<?php

$modules = ['RecordManager\Base', 'Laminas\Router'];
if (defined('RECMAN_MODULES')) {
    $modules = array_merge(explode(';', RECMAN_MODULES));
}

return [
    'modules' => array_unique($modules),
    'module_listener_options' => [
        'config_glob_paths'    => [
            'config/autoload/{,*.}{global,local}.php',
        ],
        'config_cache_enabled' => false,
        'module_map_cache_enabled' => false,
        'check_dependencies' => defined('APPLICATION_ENV')
            && APPLICATION_ENV == 'development',
        'module_paths' => [
            './src/RecordManager',
            './vendor',
        ],
    ],
    'service_manager' => [
        'use_defaults' => true,
        'factories'    => [],
    ],
];
