<?php
/**
 * Command line interface for Record Manager
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
    if (!isset($params['func'])) {
        echo <<<EOT
Usage: $argv[0] --func=... [...]
            
Parameters:
            
--func             renormalize|deduplicate|updatesolr|dump|markdeleted|deletesource|deletesolr|optimizesolr|count|updategeocoding|resimplifygeocoding|checkdedup
--source           Source ID to process (separate multiple sources with commas)
--all              Process all records regardless of their state (deduplicate)
                   or date (updatesolr)
--from             Override the date from which to run the update (updatesolr)
--single           Process only the given record id (deduplicate, updatesolr, dump)
--nocommit         Don't ask Solr to commit the changes (updatesolr)
--field            Field to analyze (count)
--file             File containing places to geocode (updategeocoding)
--verbose          Enable verbose output for debugging
--config.section.name=value 
                   Set configuration directive to given value overriding any setting in recordmanager.ini


EOT;
        exit(1);
    }
    
    $manager = new RecordManager(true);
    $manager->verbose = isset($params['verbose']) ? $params['verbose'] : false;

    $sources = isset($params['source']) ? $params['source'] : '';
    $single = isset($params['single']) ? $params['single'] : '';
    $noCommit = isset($params['nocommit']) ? $params['nocommit'] : false;

    // Solr update can handle multiple sources at once
    if ($params['func'] == 'updatesolr') {
        $date = isset($params['all']) ? '' : (isset($params['from']) ? $params['from'] : null);
        $manager->updateSolrIndex($date, $sources, $single, $noCommit); 
    } else {
        foreach (explode(',', $sources) as $source) {
            switch ($params['func'])
            {
            case 'renormalize': 
                $manager->renormalize($source, $single); 
                break;
            case 'deduplicate': 
                $manager->deduplicate($source, isset($params['all']) ? true : false, $single); 
                break;
            case 'dump': 
                $manager->dumpRecord($single);
                break;
            case 'deletesource':
                $manager->deleteRecords($source);
                break;
            case 'markdeleted':
                $manager->markDeleted($source);
                break;
            case 'deletesolr':
                $manager->deleteSolrRecords($source);
                break;
            case 'optimizesolr':
                $manager->optimizeSolr();
                break;
            case 'count':
                $manager->countValues($source, isset($params['field']) ? $params['field'] : null);
                break;
            case 'updategeocoding':
                $manager->updateGeocodingTable(isset($params['file']) ? $params['file'] : null);
                break;
            case 'resimplifygeocoding':
                $manager->resimplifyGeocodingTable();
                break;
            case 'checkdedup':
                $manager->checkDedupRecords();
                break;
            default: 
                echo 'Unknown func: ' . $params['func'] . "\n"; 
                exit(1);
            }
        }
    }
}

main($argv);

