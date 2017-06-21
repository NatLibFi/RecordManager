<?php
require_once 'classes/Autoloader.php';
echo "Bootstrap..\n";
$loader = Autoloader::getLoader();
$loader->addDirectory('tests');
$loader->addDirectory('tests/RecordDrivers');
