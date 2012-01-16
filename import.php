<?php
/**
 * Command line interface for importing records
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012.
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
 */

require_once 'cmdline.php';

function main($argv)
{
    $params = parseArgs($argv);
    if (!isset($params['file']) || !isset($params['source'])) {
        echo "Usage: import --file=... --source=... [...]\n\n";
        echo "Parameters:\n\n";
        echo "--file              The file of records\n";
        echo "--source            Source ID\n";
        echo "--verbose           Enable verbose output\n\n";
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;

    $files = glob($params['file'], GLOB_NOSORT);
    $count = 0;
    foreach ($files as $file) {
        echo "Processing '$file'\n";
        $count += $manager->loadFromFile($params['source'], $file);
        echo "Total records processed: $count\n";
    }
}

main($argv);

