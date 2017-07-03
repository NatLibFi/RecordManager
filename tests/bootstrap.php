<?php
require_once 'src/RecordManager/Base/Autoloader.php';
$loader = Autoloader::getLoader();
$loader->addDirectory('tests');
$loader->addDirectory('tests/RecordDrivers');
