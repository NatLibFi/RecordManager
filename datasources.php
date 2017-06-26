<?php
/**
 * Command line interface for managing data sources
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
require_once 'cmdline.php';

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
    applyConfigOverrides($params);
    if (empty($params['search'])) {
        echo <<<EOT
Usage: $argv[0] --search=...

Parameters:

--search=[regexp]   Search for a string in data sources and list the data source id's


EOT;
        exit(1);
    }

    $manager = new \RecordManager\Base\Controller\RecordManager(
        true, isset($params['verbose']) ? $params['verbose'] : false
    );
    if (!empty($params['search'])) {
        $manager->searchDataSources($params['search']);
    }

}

main($argv);

