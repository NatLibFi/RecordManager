<?php
/**
 * Harvest
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
namespace RecordManager\Base\Command\Records;

use RecordManager\Base\Command\AbstractBase;
use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Harvest\PluginManager as HarvesterPluginManager;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
    use \RecordManager\Base\Command\StoreRecordTrait;

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
     * @param MetadataUtils          $metadataUtils       Metadata utilities
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
        MetadataUtils $metadataUtils,
        HarvesterPluginManager $harvesterManager
    ) {
        parent::__construct(
            $config,
            $datasourceConfig,
            $logger,
            $database,
            $recordPluginManager,
            $splitterManager,
            $dedupHandler,
            $metadataUtils
        );

        $this->harvesterPluginManager = $harvesterManager;
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Harvest records')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of sources to process (* = all)',
                '*'
            )->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of sources to exclude'
            )->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Harvesting start date (overrides date stored in database)'
            )->addOption(
                'until',
                null,
                InputOption::VALUE_REQUIRED,
                'Harvesting end date (not needed unless harvesting a specific'
                . ' range)'
            )->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                "Harvest from the beginning (without start date, overrides 'from')"
            )->addOption(
                'start-position',
                null,
                InputOption::VALUE_REQUIRED,
                'Specify start position (e.g. a resumption token) to continue'
                . ' an interrupted harvesting process'
            )->addOption(
                'reharvest',
                null,
                InputOption::VALUE_OPTIONAL,
                'Harvest all records (implies --all) and mark deleted the ones that'
                . ' were not received during harvesting. Value defines a threshold'
                . ' for deletions and can be specified when continuing an'
                . ' interrupted harvesting process',
                false
            );
    }

    /**
     * Harvest records from a data source
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        if (empty($this->dataSourceConfig)) {
            $this->logger->logFatal(
                'harvest',
                'Please add data source settings to datasources.ini'
            );
            throw new \Exception('Data source settings missing in datasources.ini');
        }

        $repository = $input->getOption('source');
        $harvestFromDate = $input->getOption('from');
        $harvestUntilDate = $input->getOption('until');
        $startPosition = $input->getOption('start-position');
        $exclude = $input->getOption('exclude');
        $reharvest = $input->getOption('reharvest');
        // Default is false, so null means reharvest with no value:
        if (null === $reharvest) {
            $reharvest = true;
        }
        if ($reharvest || $input->getOption('all')) {
            $harvestFromDate = '-';
        }

        if ($reharvest && !is_string($reharvest) && $startPosition) {
            $this->logger->logFatal(
                'harvest',
                'Reharvest start date must be specified when used with the'
                    . ' --start-position option'
            );
            throw new \Exception(
                'Reharvest start date must be specified when used with the'
                . ' --start-position option'
            );
        }

        $excludedSources = isset($exclude) ? explode(',', $exclude) : [];

        $returnCode = Command::SUCCESS;

        // Loop through all the sources and perform harvests
        foreach ($this->dataSourceConfig as $source => $settings) {
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
                $harvester->init($source, $this->verbose, $reharvest ? true : false);

                if ($startPosition) {
                    if (is_callable([$harvester, 'setInitialPosition'])) {
                        $harvester->setInitialPosition($startPosition);
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
                        $this->markUnseenRecordsDeleted($source, $dateThreshold);

                        // Deduplication will update timestamps from deferred
                        // update with markRecordDeleted, but handle non-dedup
                        // sources here to avoid need for deduplication:
                        if (empty($this->dataSourceConfig[$source]['dedup'])) {
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
                        $settings['deletions'],
                        'ListIdentifiers',
                        15
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

                        $this->logger
                            ->logInfo('harvest', 'Fetching identifiers');
                        // Reset any overridden initial position:
                        $harvester->setInitialPosition('');

                        $dateThreshold = time();
                        $harvester->listIdentifiers([$this, 'markRecordSeen']);

                        $this->markUnseenRecordsDeleted($source, $dateThreshold);

                        $state = [
                            '_id' => "Last Deletion Processing Time $source",
                            'value' => time()
                        ];
                        $this->db->saveState($state);
                    }
                }
                $this->logger->logInfo(
                    'harvest',
                    "Harvesting from '$source' completed"
                );
            } catch (\Exception $e) {
                $this->logger->logFatal('harvest', 'Exception: ' . $e->getMessage());
                $returnCode = Command::FAILURE;
            }
        }
        return $returnCode;
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
    public function markRecordSeen($sourceId, $oaiId, $deleted)
    {
        if ($deleted) {
            // Don't mark deleted records...
            return;
        }
        $this->db->updateRecords(
            ['source_id' => $sourceId, 'oai_id' => $oaiId],
            ['date' => $this->db->getTimestamp()]
        );
    }

    /**
     * Set deleted all records that were not "seen" during harvest
     *
     * Uses the 'date' field that only gets updated when a record is received.
     *
     * @param string $source        Record source
     * @param int    $dateThreshold Date threshold for deletion
     *
     * @return void
     */
    protected function markUnseenRecordsDeleted(
        string $source,
        int $dateThreshold
    ): void {
        $this->logger->logInfo('harvest', 'Marking unseen records deleted');

        $count = 0;
        $this->db->iterateRecords(
            [
                'source_id' => $source,
                'deleted' => false,
                'date' => [
                    '$lt' =>
                        $this->db->getTimestamp($dateThreshold)
                ]
            ],
            [],
            function ($record) use (&$count, $source) {
                if (!empty($record['oai_id'])) {
                    $this->storeRecord(
                        $source,
                        $record['oai_id'],
                        true,
                        ''
                    );
                } else {
                    $this->markRecordDeleted($record);
                }

                if (++$count % 1000 == 0) {
                    $this->logger->logInfo(
                        'harvest',
                        "Deleted $count records"
                    );
                }
            }
        );
        $this->logger->logInfo('harvest', "Deleted $count records");
    }
}
