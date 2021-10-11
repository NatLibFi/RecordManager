<?php
/**
 * Command line interface for Record Manager
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2020.
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

use Laminas\ServiceManager\ServiceManager;

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
    if (empty($params['func']) || !is_string($params['func'])) {
        echo <<<EOT
Usage: $argv[0] --func=... [...]

Parameters:

--func              renormalize|deduplicate|updatesolr|dump|dumpsolr|markdeleted
                    |deletesource|deletesolr|optimizesolr|count|checkdedup|checksolr
                    |comparesolr|purgedeleted|markdedup|markforupdate|suppress
                    |unsuppress
--source            Source ID to process (separate multiple sources with commas)
--all               Process all records regardless of their state (deduplicate,
                    markdedup)
                    or date (updatesolr)
--from              Override the date from which to run the update (updatesolr)
--single            Process only the given record id (deduplicate, updatesolr, dump,
                    markdeleted, markforupdate, checkdedup, suppress, unsuppress)
--nocommit          Don't ask Solr to commit the changes (updatesolr)
--field             Field to analyze (count)
--force             Force deletesource to proceed even if deduplication is enabled
                    for the source
--verbose           Enable verbose output for debugging
--config.section.name=value
                    Set configuration directive to given value overriding any setting
                    in recordmanager.ini
--lockfile=file      Use a lock file to avoid executing the command multiple times in
                    parallel (useful when running from crontab)
--comparelog        Record comparison output file. N.B. The file will be overwritten
                    (comparesolr)
--dumpprefix        File name prefix to use when dumping records (dumpsolr). Default
                    is "dumpsolr".
--mapped            If set, use values only after any mapping files are processed
                    when counting records (count). If set to false, disable mappings
                    when dumping records (dumpsolr).
--daystokeep=days   How many last days to keep when purging deleted records
                    (purgedeleted)
--basepath=path     Use path as the base directory for conf, mappings and
                    transformations directories. Normally automatically determined.
--dateperserver     Track last update date per Solr server address. Allows updating
                    multiple servers with their own intervals. (updatesolr)


EOT;
        exit(1);
    }

    define(
        'RECMAN_BASE_PATH',
        !empty($params['basepath']) ? $params['basepath'] : __DIR__
    );

    $app = Laminas\Mvc\Application::init(require 'conf/application.config.php');
    $sm = $app->getServiceManager();
    $configReader = $sm->get(\RecordManager\Base\Settings\Ini::class);
    $configReader->addOverrides('recordmanager.ini', $params);

    $lockfile = $params['lockfile'] ?? '';
    $lockhandle = false;
    try {
        if (($lockhandle = acquireLock($lockfile)) === false) {
            die();
        }

        $sources = $params['source'] ?? '';
        $single = $params['single'] ?? '';
        $noCommit = $params['nocommit'] ?? false;

        // Solr update, compare and dump can handle multiple sources at once
        if ($params['func'] == 'updatesolr') {
            $date = isset($params['all']) ? '' : ($params['from'] ?? null);
            $datePerServer = !empty($params['dateperserver']);

            $solrUpdate = $sm->get(\RecordManager\Base\Controller\SolrUpdate::class);
            $solrUpdate->launch($date, $sources, $single, $noCommit, $datePerServer);
        } elseif ($params['func'] == 'comparesolr') {
            $date = isset($params['all']) ? '' : ($params['from'] ?? null);
            $log = $params['comparelog'] ?? '';

            $solrCompare = new \RecordManager\Base\Controller\SolrCompare(
                $basePath, $config, true, $verbose
            );
            $solrCompare->launch($log, $date, $sources, $single);
        } elseif ($params['func'] == 'dumpsolr') {
            $date = isset($params['all']) ? '' : ($params['from'] ?? null);
            $dumpPrefix = $params['dumpprefix'] ?? 'dumpsolr';
            $mapped = isset($params['mapped'])
                ? ('false' !== $params['mapped']) : true;

            $solrDump = new \RecordManager\Base\Controller\SolrDump(
                $basePath, $config, true, $verbose
            );
            $solrDump->launch($dumpPrefix, $date, $sources, $single, $mapped);
        } elseif ($params['func'] == 'checksolr') {
            $solrCheck = new \RecordManager\Base\Controller\SolrCheck(
                $basePath, $config, true, $verbose
            );
            $solrCheck->launch();
        } else {
            foreach (explode(',', $sources) as $source) {
                switch ($params['func']) {
                case 'renormalize':
                    $renormalize = new \RecordManager\Base\Controller\Renormalize(
                        $basePath, $config, true, $verbose
                    );
                    $renormalize->launch($source, $single);
                    break;
                case 'deduplicate':
                case 'markdedup':
                    $deduplicate = new \RecordManager\Base\Controller\Deduplicate(
                        $basePath, $config, true, $verbose
                    );
                    $deduplicate->launch(
                        $source, isset($params['all']) ? true : false, $single,
                        $params['func'] == 'markdedup'
                    );
                    break;
                case 'dump':
                    $dump = new \RecordManager\Base\Controller\Dump(
                        $basePath, $config, true, $verbose
                    );
                    $dump->launch($single);
                    break;
                case 'deletesource':
                    $deleteRecords
                        = new \RecordManager\Base\Controller\DeleteRecords(
                            $basePath, $config, true, $verbose
                        );
                    $deleteRecords->launch($source, !empty($params['force']));
                    break;
                case 'markdeleted':
                    $markDeleted = new \RecordManager\Base\Controller\MarkDeleted(
                        $basePath, $config, true, $verbose
                    );
                    $markDeleted->launch($source, $single);
                    break;
                case 'deletesolr':
                    $deleteSolr
                        = new \RecordManager\Base\Controller\DeleteSolrRecords(
                            $basePath, $config, true, $verbose
                        );
                    $deleteSolr->launch($source);
                    break;
                case 'optimizesolr':
                    $solrOptimize = new \RecordManager\Base\Controller\SolrOptimize(
                        $basePath, $config, true, $verbose
                    );
                    $solrOptimize->launch();
                    break;
                case 'count':
                    if (empty($params['field'])) {
                        echo "--field must be specified\n";
                        exit(1);
                    }
                    $countValues = new \RecordManager\Base\Controller\CountValues(
                        $basePath, $config, true, $verbose
                    );
                    $countValues->launch(
                        $source,
                        $params['field'],
                        $params['mapped'] ?? false
                    );
                    break;
                case 'checkdedup':
                    $checkDedup = new \RecordManager\Base\Controller\CheckDedup(
                        $basePath, $config, true, $verbose
                    );
                    $checkDedup->launch($single);
                    break;
                case 'purgedeleted':
                    if (!isset($params['force']) || !$params['force']) {
                        echo <<<EOT
Purging of deleted records means that RecordManager no longer has any knowledge of
them. They cannot be included in e.g. Solr updates or OAI-PMH responses.
Use the --force parameter to indicate that this is ok.

No records have been purged.

EOT;
                        exit(1);
                    }
                    $purge = new \RecordManager\Base\Controller\PurgeDeleted(
                        $basePath, $config, true, $verbose
                    );
                    $purge->launch(
                        isset($params['daystokeep']) ? intval($params['daystokeep'])
                        : 0,
                        $source
                    );
                    break;
                case 'markforupdate':
                    $markForUpdate
                        = new \RecordManager\Base\Controller\MarkForUpdate(
                            $basePath, $config, true, $verbose
                        );
                    $markForUpdate->launch($source, $single);
                    break;
                case 'suppress':
                    $suppress = new \RecordManager\Base\Controller\Suppress(
                        $basePath, $config, true, $verbose
                    );
                    $suppress->launch($source, $single);
                    break;
                case 'unsuppress':
                    $unsuppress = new \RecordManager\Base\Controller\Unsuppress(
                        $basePath, $config, true, $verbose
                    );
                    $unsuppress->launch($source, $single);
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
