<?php
/**
 * Record Manager
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
namespace RecordManager\Base\Controller;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Solr\SolrUpdater;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\PerformanceCounter;
use RecordManager\Base\Utils\XslTransformation;

require_once 'PEAR.php';
require_once 'HTTP/Request2.php';

/**
 * RecordManager Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class RecordManager extends AbstractBase
{
    use StoreRecordTrait;

    /**
     * Dedup Handler
     *
     * @var DedupHandler
     */
    protected $dedupHandler = null;

    /**
     * Harvested MetaLib Records
     *
     * @var array
     */
    protected $metaLibRecords = [];

    // TODO: refactor data source setting handling
    protected $harvestType = '';
    protected $format = '';
    protected $idPrefix = '';
    protected $sourceId = '';
    protected $institution = '';
    protected $recordXPath = '';
    protected $oaiIDXPath = '';
    protected $componentParts = '';
    protected $dedup = false;
    protected $normalizationXSLT = null;
    protected $solrTransformationXSLT = null;
    protected $recordSplitter = null;
    protected $keepMissingHierarchyMembers = false;
    protected $pretransformation = '';
    protected $indexMergedParts = true;
    protected $nonInheritedFields = [];
    protected $prependParentTitleWithUnitId = null;
    protected $previousId = '[none]';

    /**
     * Constructor
     *
     * @param boolean $console Specify whether RecordManager is executed on the
     * console so that log output is also output to the console.
     * @param boolean $verbose Whether verbose output is enabled
     */
    public function __construct($console = false, $verbose = false)
    {
        parent::__construct($console, $verbose);

        $dedupClass = isset($configArray['Site']['dedup_handler'])
            ? $configArray['Site']['dedup_handler']
            : '\RecordManager\Base\Deduplication\DedupHandler';
        $this->dedupHandler = new $dedupClass(
            $this->db, $this->logger, $this->verbose, $this->basePath, $this->config,
            $this->dataSourceSettings
        );
    }

    /**
     * Catch the SIGINT signal and signal the main thread to terminate
     *
     * @param int $signal Signal ID
     *
     * @return void
     */
    public function sigIntHandler($signal)
    {
        $this->terminate = true;
        echo "Termination requested\n";
    }

    /**
     * Count distinct values in the specified field (that would be added to the
     * Solr index)
     *
     * @param string $sourceId Source ID
     * @param string $field    Field name
     * @param bool   $mapped   Whether to count values after any mapping files are
     *                         are processed
     *
     * @return void
     */
    public function countValues($sourceId, $field, $mapped)
    {
        if (!$field) {
            echo "Field must be specified\n";
            exit;
        }
        $updater = new SolrUpdater(
            $this->db, $this->basePath, $this->logger, $this->verbose
        );
        $updater->countValues($sourceId, $field, $mapped);
    }

    /**
     * Verify consistency of dedup records links with actual records
     *
     * @return void
     */
    public function checkDedupRecords()
    {
        $this->logger->log('checkDedupRecords', "Checking dedup record consistency");

        $dedupRecords = $this->db->findDedups([]);
        $count = 0;
        $fixed = 0;
        $pc = new PerformanceCounter();
        foreach ($dedupRecords as $dedupRecord) {
            $results = $this->dedupHandler->checkDedupRecord($dedupRecord);
            if ($results) {
                $fixed += count($results);
                foreach ($results as $result) {
                    $this->logger->log('checkDedupRecords', $result);
                }
            }
            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->log(
                    'checkDedupRecords',
                    "$count records checked with $fixed links fixed, "
                    . "$avg records/sec"
                );
            }
        }
        $this->logger->log(
            'checkDedupRecords',
            "Completed with $count records checked with $fixed links fixed"
        );
    }

    /**
     * Search for $regexp in data sources
     *
     * @param string $regexp Regular expression
     *
     * @return void
     */
    public function searchDataSources($regexp)
    {
        if (substr($regexp, 0, 1) !== '/') {
            $regexp = "/$regexp/";
        }
        $matches = '';
        foreach ($this->dataSourceSettings as $source => $settings) {
            foreach ($settings as $setting => $value) {
                foreach (is_array($value) ? $value : [$value] as $single) {
                    if (!is_string($single)) {
                        continue;
                    }
                    if (preg_match($regexp, "$setting=$single")) {
                        if ($matches) {
                            $matches .= ',';
                        }
                        $matches .= $source;
                        break 2;
                    }
                }
            }
        }
        echo "$matches\n";
    }

}
