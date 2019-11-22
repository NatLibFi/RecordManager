<?php
/**
 * Metadata Preview Front-End
 *
 * PHP version 5
 *
 * Copyright (C) Eero Heikkinen 2013.
 * Copyright (C) The National Library of Finland 2013.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/RecordManager/Base/Autoloader.php';

$basePath = __DIR__;
$filename = $basePath . '/conf/recordmanager.ini';
$config = parse_ini_file($filename, true);
if (false === $config) {
    $error = error_get_last();
    $message = $error['message'] ?? 'unknown error occurred';
    throw new \Exception(
        "Could not load configuration from file '$filename': $message"
    );
}

$createPreview = new \RecordManager\Base\Controller\CreatePreview(
    $basePath, $config
);

$func = $_REQUEST['func'] ?? '';
if ($func === 'get_sources') {
    $record = $createPreview->getDataSources($_REQUEST['format'] ?? '');
} else {
    if (!isset($_REQUEST['source']) || !isset($_REQUEST['data'])) {
        http_response_code(400);
        echo json_encode(['error_message' => 'Missing parameters']);
        return;
    }
    $format = $_REQUEST['format'] ?? '';
    $source = $_REQUEST['source'] ?? '';

    if (!preg_match('/^[\w_]*$/', $format) || !preg_match('/^[\w_-]*$/', $source)) {
        http_response_code(400);
        echo json_encode(['error_message' => 'Invalid parameters']);
        return;
    }

    try {
        $record = $createPreview->launch($_REQUEST['data'], $format, $source);
    } catch (\Exception $e) {
        http_response_code(400);
        echo json_encode(['error_message' => $e->getMessage()]);
        return;
    }
}

header('Content-Type: application/json');
echo json_encode($record);
