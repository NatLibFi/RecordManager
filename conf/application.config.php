<?php

// Default modules (specify local modules in modules.config.php):
$modules = ['RecordManager\\Base'];

if (file_exists(__DIR__ . '/modules.config.php')) {
    /**
     * @psalm-suppress MissingFile
     */
    $modules = [...$modules, ...include __DIR__ . '/modules.config.php'];
}

return [
    'modules' => array_unique($modules),
    'service_manager' => [
        'use_defaults' => true,
        'factories'    => [
            // This needs to be available before module initialization:
            \RecordManager\Base\ModuleManager\ModuleManager::class
                => \RecordManager\Base\ModuleManager\ModuleManagerFactory::class,
            'Config' => \RecordManager\Base\ModuleManager\MergedConfigFactory::class,
        ],
    ],
];
