<?php
/**
 * Command line interface for importing records
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
 * @throws \Exception
 */
function main($argv)
{
    $params = parseArgs($argv);
    if (empty($params['file']) || empty($params['source'])) {
        echo <<<EOT
Usage: $argv[0] --file=... --source=... [...]

Parameters:

--file              The file or wildcard pattern of files of records
--source            Source ID
--delete            Mark the imported records deleted
--verbose           Enable verbose output
--config.section.name=value
                    Set configuration directive to given value overriding any
                    setting in recordmanager.ini
--lockfile=file     Use a lock file to avoid executing the command multiple times in
                    parallel (useful when running from crontab)
--basepath=path     Use path as the base directory for conf, mappings and
                    transformations directories. Normally automatically determined.


EOT;
        exit(1);
    }

    $app = bootstrap($params);
    $sm = $app->getServiceManager();

    $lockfile = $params['lockfile'] ?? '';
    $lockhandle = false;
    try {
        if (($lockhandle = acquireLock($lockfile)) === false) {
            die();
        }

        $import = $sm->get(\RecordManager\Base\Controller\Import::class);
        $import->launch(
            $params['source'],
            $params['file'],
            $params['delete'] ?? false
        );
    } catch (\Exception $e) {
        releaseLock($lockhandle);
        throw $e;
    }
    releaseLock($lockhandle);
}

main($argv);
