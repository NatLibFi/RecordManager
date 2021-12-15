<?php
/**
 * Metadata Preview Front-End
 *
 * PHP version 7
 *
 * Copyright (C) Eero Heikkinen 2013.
 * Copyright (C) The National Library of Finland 2013-2021.
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
 * @link     https://github.com/NatLibFi/RecordManager
 */
require_once __DIR__ . '/vendor/autoload.php';

try {
    ob_start();

    define('RECMAN_BASE_PATH', getenv('RECMAN_BASE_PATH') ?: __DIR__);
    $app = \Laminas\Mvc\Application::init(
        include RECMAN_BASE_PATH . '/conf/application.config.php'
    );
    $sm = $app->getServiceManager();

    $configReader = $sm->get(\RecordManager\Base\Settings\Ini::class);
    $dataSourceConfig = $configReader->get('datasources.ini');
    if (!isset($dataSourceConfig['_preview'])) {
        $configReader->addOverrides(
            'datasources.ini',
            [
                '_preview' => [
                    'institution' => '_preview',
                    'componentParts' => null,
                    'format' => '_preview',
                    'preTransformation' => 'strip_namespaces.xsl',
                    'extraFields' => [],
                    'mappingFiles' => []
                ]
            ]
        );
    }
    if (!isset($dataSourceConfig['_marc_preview'])) {
        $configReader->addOverrides(
            'datasources.ini',
            [
                '_marc_preview' => [
                    'institution' => '_preview',
                    'componentParts' => null,
                    'format' => 'marc',
                    'extraFields' => [],
                    'mappingFiles' => []
                ]
            ]
        );
    }

    $createPreview = $sm->get(\RecordManager\Base\Controller\CreatePreview::class);

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

        $record = $createPreview->launch($_REQUEST['data'], $format, $source);
        if (false === $record) {
            throw new \Exception('A record could not be created');
        }
    }
    header('Content-Type: application/json');
    echo json_encode($record);
} catch (\Exception $e) {
    $res = ob_clean();
    http_response_code(400);
    echo json_encode(['error_message' => $e->getMessage()]);
    return;
}

