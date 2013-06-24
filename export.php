<?php
/**
 * Command line interface for exporting records
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2013.
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
    if (!isset($params['file'])) {
        echo "Usage: export --file=... [...]\n\n";
        echo "Parameters:\n\n";
        echo "--file              The file for records\n";
        echo "--deleted           The file for deleted record IDs\n";
        echo "--from              From date where to start the export\n";
        echo "--verbose           Enable verbose output\n";
        echo "--quiet             Quiet, no output apart from the data\n";
        echo "--skip              Skip x records to export only a \"representative\" subset\n";
        echo "--source            Export only the given source\n";
        echo "--single            Export single record with the given id\n";
        echo "--xpath             Export only records matching the XPath expression\n\n";
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;
    $manager->quiet = isset($params['quiet']) ? $params['quiet'] : false;

    $manager->exportRecords(
        $params['file'], 
        isset($params['deleted']) ? $params['deleted'] : '', 
        isset($params['from']) ? $params['from'] : '', 
        isset($params['skip']) ? $params['skip'] : 0, 
        isset($params['source']) ? $params['source'] : '', 
        isset($params['single']) ? $params['single'] : '',
        isset($params['xpath']) ? $params['xpath'] : ''
    );
}

main($argv);

