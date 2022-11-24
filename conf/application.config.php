<?php

// Default modules (specify local modules in modules.config.php):
$modules = ['RecordManager\\Base', 'Laminas\\Router'];

if (file_exists(__DIR__ . '/modules.config.php')) {
    /**
     * @psalm-suppress MissingFile
     */
    $modules = [...$modules, ...include __DIR__ . '/modules.config.php'];
}

return [
    'modules' => array_unique($modules),
    'module_listener_options' => [
        'config_glob_paths'    => [
            'config/autoload/{,*.}{global,local}.php',
        ],
        'config_cache_enabled' => false,
        'module_map_cache_enabled' => false,
        'check_dependencies' => getenv('APPLICATION_ENV') == 'development',
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
