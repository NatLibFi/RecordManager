<?php

// Prepare service manager:
$applicationConfig = require __DIR__ . '/application.config.php';
$serviceManager = new \Laminas\ServiceManager\ServiceManager();
$serviceManager->configure($applicationConfig['service_manager'] ?? []);
$serviceManager->setService('ApplicationConfig', $applicationConfig);

// Load modules:
$moduleManager = $serviceManager->get(\RecordManager\Base\ModuleManager\ModuleManager::class);
$moduleManager->initialize();

// Return service manager:
return $serviceManager;
