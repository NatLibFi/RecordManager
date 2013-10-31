<?php
/**
 * Command line interface for harvesting records
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
    applyConfigOverrides($params);
    if (!isset($params['source'])) {
        echo <<<EOT
Usage: $argv[0] --source=... [...]

Parameters:
            
--source            Repository id ('*' for all, separate multiple sources with commas)
--exclude           Repository id's to exclude when using '*' for source (separate multiple sources with commas)
--from              Override harvesting start date
--until             Override harvesting end date
--all               Harvest from beginning (overrides --from)
--verbose           Enable verbose output
--override          Override initial resumption token (e.g. to resume failed connection)
--reharvest[=date]  This is a full reharvest, delete all records that were not received during the harvesting (or were modified before [date]). Implies --all.
--config.section.name=value 
                    Set configuration directive to given value overriding any setting in recordmanager.ini


EOT;
        exit(1);
    }

    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;
    $from = isset($params['from']) ? $params['from'] : null;
    if (isset($params['all']) || isset($params['reharvest'])) {
        $from = '-';
    }
    foreach (explode(',', $params['source']) as $source) {
        $manager->harvest(
            $source, 
            $from, 
            isset($params['until']) ? $params['until'] : null, 
            isset($params['override']) ? urldecode($params['override']) : '',
            isset($params['exclude']) ? $params['exclude'] : null,
            isset($params['reharvest']) ? $params['reharvest'] : ''
        );
    }
}

main($argv);
