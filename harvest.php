<?php
/**
 * Command line interface for harvesting records
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
    if (!isset($params['source'])) {
        echo "Usage: harvest --source=... [...]\n\n";
        echo "Parameters:\n\n";
        echo "--source            Repository id ('*' for all)\n";
        echo "--from              Override harvesting start date (use - to start from beginning)\n";
        echo "--until             Override harvesting end date\n";
        echo "--verbose           Enable verbose output\n";
        echo "--override          Override initial resumption token (e.g. to resume failed connection)\n\n";
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;
    $manager->harvest(
        $params['source'], 
        isset($params['from']) ? $params['from'] : null, 
        isset($params['until']) ? $params['until'] : null, 
        isset($params['override']) ? $params['override'] : ''
    );
}

main($argv);
