<?php
/**
 * Harvest
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
namespace RecordManager\Base\Controller;

use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Harvest\PluginManager as HarvesterPluginManager;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\Logger;

/**
 * Harvest
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Harvest extends AbstractBase
{
    use StoreRecordTrait;

    /**
     * Harvester plugin manager
     *
     * @var HarvesterPluginManager
     */
    protected $harvesterPluginManager;

    /**
     * Constructor
     *
     * @param array                  $config              Main configuration
     * @param array                  $datasourceConfig    Datasource configuration
     * @param Logger                 $logger              Logger
     * @param DatabaseInterface      $database            Database
     * @param RecordPluginManager    $recordPluginManager Record plugin manager
     * @param SplitterPluginManager  $splitterManager     Record splitter plugin
     *                                                    manager
     * @param DedupHandlerInterface  $dedupHandler        Deduplication handler
     * @param HarvesterPluginManager $harvesterManager    Harvester plugin manager
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Logger $logger,
        DatabaseInterface $database,
        RecordPluginManager $recordPluginManager,
        SplitterPluginManager $splitterManager,
        DedupHandlerInterface $dedupHandler,
        HarvesterPluginManager $harvesterManager
    ) {
        parent::__construct(
            $config,
            $datasourceConfig,
            $logger,
            $database,
            $recordPluginManager,
            $splitterManager,
            $dedupHandler
        );

        $this->harvesterPluginManager = $harvesterManager;
    }

    /**
     * Harvest records from a data source
     *
     * @param string      $repository           Source ID to harvest
     * @param string      $harvestFromDate      Override start date (otherwise
     *                                          harvesting is done from the previous
     *                                          harvest date)
     * @param string      $harvestUntilDate     Override end date (otherwise
     *                                          current date is used)
     * @param string      $startResumptionToken Override OAI-PMH resumptionToken to
     *                                          resume interrupted harvesting process
     *                                          (note that tokens may have a limited
     *                                          lifetime)
     * @param string      $exclude              Source ID's to exclude from
     *                                          harvesting
     * @param bool|string $reharvest            Whether to consider this a full
     *                                          reharvest where sets may have changed
     *                                          (deletes records not received during
     *                                          this harvesting)
     *
     * @return void
     * @throws \Exception
     */
    public function launch($repository = '', $harvestFromDate = null,
        $harvestUntilDate = null, $startResumptionToken = '', $exclude = null,
        $reharvest = false
    ) {
        if (empty($this->dataSourceSettings)) {
            $this->logger->logFatal(
                'harvest',
                'Please add data source settings to datasources.ini'
            );
            throw new \Exception('Data source settings missing in datasources.ini');
        }

        $this->initSourceSettings();

        if ($reharvest && !is_string($reharvest) && $startResumptionToken) {
            $this->logger->logFatal(
                'harvest',
                'Reharvest start date must be specified when used with the'
                    . ' resumption token override option'
            );
            throw new \Exception(
                'Reharvest start date must be specified when used with the'
                . ' resumption token override option'
            );
        }

        $excludedSources = isset($exclude) ? explode(',', $exclude) : [];

        // Loop through all the sources and perform harvests
        foreach ($this->dataSourceSettings as $source => $settings) {
            try {
                if ($repository && $repository != '*' && $source != $repository) {
                    continue;
                }
                if (in_array($source, $excludedSources)) {
                    continue;
                }
                if (empty($source) || empty($settings) || !isset($settings['url'])) {
                    continue;
                }
                $this->logger->logInfo(
                    'harvest',
                    "Harvesting from '$source'"
                    . ($reharvest ? ' (full reharvest)' : '')
                );

                $dateThreshold = null;
                if ($reharvest) {
                    if (is_string($reharvest)) {
                        $dateThreshold = strtotime($reharvest);
                    } else {
                        $dateThreshold = time();
                    }
                    $this->logger->logInfo(
                        'harvest',
                        'Reharvest date threshold: '
                        . gmdate('Y-m-d\TH:i:s\Z', $dateThreshold)
                    );
                }

                $type = ($settings['type'] ?? null) ?: 'OAI-PMH';
                $harvester = $this->harvesterPluginManager->get($type);
                $harvester->init($source, $this->verbose);

                if ($startResumptionToken) {
                    if (is_callable([$harvester, 'setInitialPosition'])) {
                        $harvester->setInitialPosition($startResumptionToken);
                    } else {
                        $this->logger->logWarning(
                            'harvest',
                            get_class($harvester) . ' does not support overriding'
                            . ' of start position'
                        );
                    }
                }
                if (isset($harvestFromDate)) {
                    $harvester->setStartDate(
                        $harvestFromDate == '-' ? null : $harvestFromDate
                    );
                }
                if (isset($harvestUntilDate)) {
                    $harvester->setEndDate($harvestUntilDate);
                }

                $harvester->harvest([$this, 'storeRecord']);

                if ($reharvest) {
                    if ($harvester->getHarvestedRecordCount() == 0) {
                        $this->logger->logFatal(
                            'harvest',
                            "No records received from '$source' during"
                                . ' reharvesting -- assuming an error and'
                                . ' skipping marking records deleted'
                        );
                    } else {
                        $this->logger->logInfo(
                            'harvest',
                            'Marking deleted all records not received during'
                            . ' the harvesting'
                        );
                        $count = 0;
                        $this->db->iterateRecords(
                            [
                                'source_id' => $source,
                                'deleted' => false,
                                'updated' => [
                                    '$lt' =>
                                        $this->db->getTimestamp($dateThreshold)
                                ]
                            ],
                            [],
                            function ($record) use (&$count) {
                                if ($this->verbose) {
                                    echo "Marking {$record['_id']} deleted\n";
                                }
                                $this->markRecordDeleted($record, true);
                                if (++$count % 1000 == 0) {
                                    $this->logger->logInfo(
                                        'harvest', "Deleted $count records"
                                    );
                                }
                            }
                        );
                        $this->logger
                            ->logInfo('harvest', "Deleted $count records");
                        // Deduplication will update timestamps from deferred
                        // update with markRecordDeleted, but handle non-dedup
                        // sources here to avoid need for deduplication:
                        if (empty($this->dataSourceSettings[$source]['dedup'])) {
                            $this->logger->logInfo(
                                'harvest',
                                'Updating timestamps for any host records of'
                                . ' records deleted'
                            );
                            $this->db->updateRecords(
                                [
                                    'source_id' => $source,
                                    'update_needed' => true
                                ],
                                [
                                    'updated' => $this->db->getTimestamp(),
                                    'update_needed' => false
                                ]
                            );
                        }
                    }
                }

                if (!$reharvest && isset($settings['deletions'])
                    && strncmp(
                        $settings['deletions'], 'ListIdentifiers', 15
                    ) == 0
                ) {
                    // The repository doesn't support reporting deletions, so
                    // list all identifiers and mark deleted records that were
                    // not found

                    if (!is_callable([$harvester, 'listIdentifiers'])) {
                        throw new \Exception(
                            get_class($harvester)
                            . ' does not support listing identifiers'
                        );
                    }

                    $processDeletions = true;
                    $interval = null;
                    $deletions = explode(':', $settings['deletions']);
                    if (isset($deletions[1])) {
                        $state = $this->db->getState(
                            "Last Deletion Processing Time $source"
                        );
                        if (null !== $state) {
                            $interval
                                = round((time() - $state['value']) / 3600 / 24);
                            if ($interval < $deletions[1]) {
                                $this->logger->logInfo(
                                    'harvest',
                                    "Not processing deletions, $interval days"
                                    . ' since last time'
                                );
                                $processDeletions = false;
                            }
                        }
                    }

                    if ($processDeletions) {
                        $this->logger->logInfo(
                            'harvest',
                            'Processing deletions' . (isset($interval)
                                ? " ($interval days since last time)" : '')
                        );

                        $this->logger->logInfo('harvest', 'Unmarking records');
                        $this->db->updateRecords(
                            ['source_id' => $source, 'deleted' => false],
                            [],
                            ['mark' => 1]
                        );

                        $this->logger
                            ->logInfo('harvest', 'Fetching identifiers');
                        // Reset any overridden initial position:
                        $harvester->setInitialPosition('');
                        $harvester->listIdentifiers([$this, 'markRecord']);

                        $this->logger
                            ->logInfo('harvest', 'Marking deleted records');

                        $count = 0;
                        $this->db->iterateRecords(
                            [
                                'source_id' => $source,
                                'deleted' => false,
                                'mark' => ['$exists' => false]
                            ],
                            [],
                            function ($record) use (&$count, $source) {
                                if (!empty($record['oai_id'])) {
                                    $this->storeRecord(
                                        $source, $record['oai_id'], true, ''
                                    );
                                } else {
                                    $this->markRecordDeleted($record);
                                }

                                if (++$count % 1000 == 0) {
                                    $this->logger->logInfo(
                                        'harvest', "Deleted $count records"
                                    );
                                }
                            }
                        );
                        $this->logger
                            ->logInfo('harvest', "Deleted $count records");

                        $state = [
                            '_id' => "Last Deletion Processing Time $source",
                            'value' => time()
                        ];
                        $this->db->saveState($state);
                    }
                }
                $this->logger->logInfo(
                    'harvest', "Harvesting from '$source' completed"
                );
            } catch (\Exception $e) {
                $this->logger->logFatal('harvest', 'Exception: ' . $e->getMessage());
            }
        }
    }

    /**
     * Mark a record "seen". Used by OAI-PMH harvesting when deletions are not
     * supported.
     *
     * @param string $sourceId Source ID
     * @param string $oaiId    ID of the record as received from OAI-PMH
     * @param bool   $deleted  Whether the record is to be deleted
     *
     * @throws \Exception
     * @return void
     */
    public function markRecord($sourceId, $oaiId, $deleted)
    {
        if ($deleted) {
            // Don't mark deleted records...
            return;
        }
        $this->db->updateRecords(
            ['source_id' => $sourceId, 'oai_id' => $oaiId],
            ['mark' => true]
        );
    }
}
