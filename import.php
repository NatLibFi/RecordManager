<?php
/**
 * Command line interface for importing records
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
    if (!isset($params['file']) || !isset($params['source'])) {
        echo "Usage: import --file=... --source=... [...]\n\n";
        echo "Parameters:\n\n";
        echo "--file              The file or wildcard pattern of files of records\n";
        echo "--source            Source ID\n";
        echo "--verbose           Enable verbose output\n\n";
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;

    $manager->loadFromFile($params['source'], $params['file']);
}

main($argv);

