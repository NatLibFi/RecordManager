<?php
/**
 * OAI-PMH Provider Front-End
 *
 * PHP version 7
 *
 * Copyright (C) Ere Maijala 2012.
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
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

/**
 * OAI-PMH Provider Front-End
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/RecordManager/Base/Autoloader.php';

/**
 * Main function
 *
 * @return void
 */
function main()
{
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

    $provider = new \RecordManager\Base\Controller\OaiPmhProvider(
        $basePath, $config
    );
    $provider->launch();
}

main();
