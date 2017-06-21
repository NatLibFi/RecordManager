<?php
require_once 'src/RecordManager/Autoloader.php';
$loader = Autoloader::getLoader();
$loader->addDirectory('tests');
$loader->addDirectory('tests/RecordDrivers');
