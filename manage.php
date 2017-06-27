<?php
/**
 * Command line interface for Record Manager
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * @throws Exception
 */
function main($argv)
{
    global $configArray;

    $params = parseArgs($argv);
    applyConfigOverrides($params);
    if (empty($params['func']) || !is_string($params['func'])) {
        echo <<<EOT
Usage: $argv[0] --func=... [...]

Parameters:

--func             renormalize|deduplicate|updatesolr|dump|dumpsolr|markdeleted
                   |deletesource|deletesolr|optimizesolr|count|checkdedup|comparesolr
                   |purgedeleted|markdedup
--source           Source ID to process (separate multiple sources with commas)
--all              Process all records regardless of their state (deduplicate,
                   markdedup)
                   or date (updatesolr)
--from             Override the date from which to run the update (updatesolr)
--single           Process only the given record id (deduplicate, updatesolr, dump)
--nocommit         Don't ask Solr to commit the changes (updatesolr)
--field            Field to analyze (count)
--force            Force deletesource to proceed even if deduplication is enabled for
                   the source
--verbose          Enable verbose output for debugging
--config.section.name=value
                   Set configuration directive to given value overriding any setting
                   in recordmanager.ini
--lockfile=file    Use a lock file to avoid executing the command multiple times in
                   parallel (useful when running from crontab)
--comparelog       Record comparison output file. N.B. The file will be overwritten
                   (comparesolr)
--dumpprefix       File name prefix to use when dumping records (dumpsolr). Default
                   is "dumpsolr".
--mapped           If set, use values only after any mapping files are processed when
                   counting records (count)
--daystokeep=days  How many last days to keep when purging deleted records
                   (purgedeleted)


EOT;
        exit(1);
    }

    $lockfile = isset($params['lockfile']) ? $params['lockfile'] : '';
    $lockhandle = false;
    $verbose = isset($params['verbose']) ? $params['verbose'] : false;
    try {
        if (($lockhandle = acquireLock($lockfile)) === false) {
            die();
        }

        $manager = new \RecordManager\Base\Controller\RecordManager(
            $configArray, true, $verbose
        );

        $sources = isset($params['source']) ? $params['source'] : '';
        $single = isset($params['single']) ? $params['single'] : '';
        $noCommit = isset($params['nocommit']) ? $params['nocommit'] : false;

        // Solr update, compare and dump can handle multiple sources at once
        if ($params['func'] == 'updatesolr') {
            $date = isset($params['all'])
                ? '' : (isset($params['from']) ? $params['from'] : null);

            $solrUpdate = new \RecordManager\Base\Controller\SolrUpdate(
                $configArray, true, $verbose
            );
            $solrUpdate->launch($date, $sources, $single, $noCommit);
        } elseif ($params['func'] == 'comparesolr') {
            $date = isset($params['all'])
                ? '' : (isset($params['from']) ? $params['from'] : null);
            $log = isset($params['comparelog']) ? $params['comparelog'] : '-';

            $solrCompare = new \RecordManager\Base\Controller\SolrCompare(
                $configArray, true, $verbose
            );
            $solrCompare->launch($log, $date, $sources, $single);
        } elseif ($params['func'] == 'dumpsolr') {
            $date = isset($params['all'])
                ? '' : (isset($params['from']) ? $params['from'] : null);
            $dumpPrefix = isset($params['dumpprefix'])
                ? $params['dumpprefix'] : 'dumpsolr';

            $solrDump = new \RecordManager\Base\Controller\SolrDump(
                $configArray, true, $verbose
            );
            $solrDump->launch($dumpPrefix, $date, $sources, $single);
        } else {
            foreach (explode(',', $sources) as $source) {
                switch ($params['func']) {
                case 'renormalize':
                    $renormalize = new \RecordManager\Base\Controller\Renormalize(
                        $configArray, true, $verbose
                    );
                    $renormalize->launch($source, $single);
                    break;
                case 'deduplicate':
                case 'markdedup':
                    $deduplicate = new \RecordManager\Base\Controller\Deduplicate(
                        $configArray, true, $verbose
                    );
                    $deduplicate->launch(
                        $source, isset($params['all']) ? true : false, $single,
                        $params['func'] == 'markdedup'
                    );
                    break;
                case 'dump':
                    $manager->dumpRecord($single);
                    break;
                case 'deletesource':
                    $manager->deleteRecords(
                        $source, isset($params['force']) ? $params['force'] : false
                    );
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
                    $manager->countValues(
                        $source,
                        isset($params['field']) ? $params['field'] : null,
                        isset($params['mapped']) ? $params['mapped'] : false
                    );
                    break;
                case 'checkdedup':
                    $manager->checkDedupRecords();
                    break;
                case 'purgedeleted':
                    if (!isset($params['force']) || !$params['force']) {
                        echo <<<EOT
Purging of deleted records means that any further Solr updates don't include these
deletions. Use the --force parameter to indicate that this is ok. No records have
been purged.

EOT;
                        exit(1);
                    }
                    $purge = new \RecordManager\Base\Controller\PurgeDeleted(
                        $configArray, true, $verbose
                    );
                    $purge->launch(
                        isset($params['daystokeep']) ? intval($params['daystokeep'])
                        : 0,
                        $source
                    );
                    break;
                default:
                    echo 'Unknown func: ' . $params['func'] . "\n";
                    exit(1);
                }
            }
        }
    } catch (\Exception $e) {
        releaseLock($lockhandle);
        throw $e;
    }
    releaseLock($lockhandle);
}

main($argv);
