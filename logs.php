<?php
/**
 * Command line interface for managing stored logs
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2021.
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
require_once __DIR__ . '/cmdline.php';

/**
 * Main function
 *
 * @param string[] $argv Program parameters
 *
 * @return void
 */
function main($argv)
{
    $params = parseArgs($argv);
    $basePath = !empty($params['basepath']) ? $params['basepath'] : __DIR__;
    $config = applyConfigOverrides($params, loadMainConfig($basePath));

    if (empty($params['func'])) {
        echo <<<EOT
Usage: $argv[0] --func=...

Parameters:

--func              sendlogs
--email=address     Recipient email address (sendlogs)
--basepath=path     Use path as the base directory for conf, mappings and
                    transformations directories. Normally automatically determined.


EOT;
        exit(1);
    }

    if ('sendlogs' === $params['func']) {
        if (empty($params['email'])) {
            echo "Email address is required.\n";
            exit(1);
        }

        $sendLogs = new \RecordManager\Base\Controller\SendLogs(
            $basePath,
            $config,
            true,
            $params['verbose'] ?? false
        );
        $sendLogs->launch($params['email']);
    }
}

main($argv);
