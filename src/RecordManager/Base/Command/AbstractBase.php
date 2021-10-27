<?php
/**
 * RecordManager command base class
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
namespace RecordManager\Base\Command;

use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use RecordManager\Base\Utils\XslTransformation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * RecordManager command base class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractBase extends \Symfony\Component\Console\Command\Command
{
    use \RecordManager\Base\Record\CreateRecordTrait;
    use \Symfony\Component\Console\Command\LockableTrait;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Verbose mode
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Database
     *
     * @var DatabaseInterface
     */
    protected $db;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceSettings;

    /**
     * Record plugin manager
     *
     * @var RecordPluginManager
     */
    protected $recordPluginManager;

    /**
     * Record splitter plugin manager
     *
     * @var SplitterPluginManager
     */
    protected $splitterPluginManager;

    /**
     * Deduplication handler
     *
     * @var DedupHandlerInterface
     */
    protected $dedupHandler;

    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Constructor
     *
     * @param array                 $config              Main configuration
     * @param array                 $datasourceConfig    Datasource configuration
     * @param Logger                $logger              Logger
     * @param DatabaseInterface     $database            Database
     * @param RecordPluginManager   $recordPluginManager Record plugin manager
     * @param SplitterPluginManager $splitterManager     Record splitter plugin
     *                                                   manager
     * @param DedupHandlerInterface $dedupHandler        Deduplication handler
     * @param MetadataUtils         $metadataUtils       Metadata utilities
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Logger $logger,
        DatabaseInterface $database,
        RecordPluginManager $recordPluginManager,
        SplitterPluginManager $splitterManager,
        DedupHandlerInterface $dedupHandler,
        MetadataUtils $metadataUtils
    ) {
        $name = null;
        if (empty($this->defaultName)) {
            $className = get_class($this);
            $parts = explode('\\', $className);
            $name = strtolower(
                preg_replace(
                    '/(?<=[a-z])([A-Z])/',
                    '-$1',
                    array_pop($parts)
                )
            );
            $name = strtolower(array_pop($parts)) . ":$name";
        }
        parent::__construct($name);
        date_default_timezone_set($config['Site']['timezone']);

        $this->config = $config;
        $this->verbose = $config['Log']['verbose'] ?? false;
        $this->logger = $logger;
        $this->db = $database;
        $this->logger->setDatabase($this->db);
        $this->dataSourceSettings = $datasourceConfig;
        $this->recordPluginManager = $recordPluginManager;
        $this->splitterPluginManager = $splitterManager;
        $this->dedupHandler = $dedupHandler;
        $this->metadataUtils = $metadataUtils;
    }

    /**
     * Initialize the execution and call the actual command's doExecute
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initSourceSettings();

        $lock = $input->getOption('lock');
        if (false !== $lock) {
            if (!is_string($lock)) {
                $lock = RECMAN_BASE_PATH . '/' . $this->getName();
            }
            if (!$this->lock($lock)) {
                if ($output->isVerbose()) {
                    echo "Another process has this task locked. Exiting..\n";
                }
                return Command::SUCCESS;
            }
        }
        $result = $this->doExecute($input, $output);
        if ($lock) {
            $this->release();
        }
        return $result;
    }

    /**
     * Process the actual command
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        throw new \Exception(get_class($this) . ': doExecute not defined');
    }

    /**
     * Initialize the data source settings and XSL transformations
     *
     * @throws \Exception
     * @return void
     */
    protected function initSourceSettings()
    {
        foreach ($this->dataSourceSettings as $source => &$settings) {
            if (!isset($settings['institution'])) {
                $this->logger->logFatal(
                    'initSourceSettings',
                    "institution not set for $source"
                );
                throw new \Exception("Error: institution not set for $source\n");
            }
            if (!isset($settings['format'])) {
                $this->logger->logFatal(
                    'initSourceSettings',
                    "format not set for $source"
                );
                throw new \Exception("Error: format not set for $source\n");
            }
            if (empty($settings['idPrefix'])) {
                $settings['idPrefix'] = $source;
            }
            if (!isset($settings['recordXPath'])) {
                $settings['recordXPath'] = '//record';
            }
            if (!isset($settings['oaiIDXPath'])) {
                $settings['oaiIDXPath'] = '';
            }
            if (!isset($settings['dedup'])) {
                $settings['dedup'] = false;
            }
            if (empty($settings['componentParts'])) {
                $settings['componentParts'] = 'as_is';
            }
            if (!isset($settings['preTransformation'])) {
                $settings['preTransformation'] = '';
            }
            if (!isset($settings['indexMergedParts'])) {
                $settings['indexMergedParts'] = true;
            }
            if (!isset($settings['type'])) {
                $settings['type'] = '';
            }
            if (!isset($settings['non_inherited_fields'])) {
                $settings['non_inherited_fields'] = [];
            }
            if (!isset($settings['keepMissingHierarchyMembers'])) {
                $settings['keepMissingHierarchyMembers'] = false;
            }

            $params = [
                'source_id' => $source,
                'institution' => $settings['institution'],
                'format' => $settings['format'],
                'id_prefix' => $settings['idPrefix']
            ];
            $settings['normalizationXSLT'] = !empty($settings['normalization'])
                ? new XslTransformation(
                    RECMAN_BASE_PATH . '/transformations',
                    $settings['normalization'],
                    $params
                ) : null;
            $settings['solrTransformationXSLT']
                = !empty($settings['solrTransformation'])
                ? new XslTransformation(
                    RECMAN_BASE_PATH . '/transformations',
                    $settings['solrTransformation'],
                    $params
                ) : null;

            if (!empty($settings['recordSplitterClass'])) {
                $splitterClass = ltrim($settings['recordSplitterClass'], '\\');
                $settings['recordSplitter'] = $this->splitterPluginManager
                    ->get($splitterClass);
            } elseif (!empty($settings['recordSplitter'])) {
                $style = new \DOMDocument();
                $xslFile = RECMAN_BASE_PATH . '/transformations/'
                    . $settings['recordSplitter'];
                if ($style->load($xslFile) === false) {
                    throw new \Exception(
                        "Could not load $xslFile for source $source"
                    );
                }
                $settings['recordSplitter'] = new \XSLTProcessor();
                $settings['recordSplitter']->importStylesheet($style);
            } else {
                $settings['recordSplitter'] = null;
            }
        }
    }

    /**
     * Read and initalize the data source settings
     *
     * @param string $filename Ini file
     *
     * @return array
     */
    protected function readDataSourceSettings($filename)
    {
        $settings = parse_ini_file($filename, true);
        if (false === $settings) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown error occurred';
            throw new \Exception(
                "Could not load data source settings from file '$filename': $message"
            );
        }

        // Check for linked data sources and store information to the linked sources
        // too
        foreach ($settings as $sourceId => $sourceSettings) {
            if (!empty($sourceSettings['componentPartSourceId'])) {
                foreach ($sourceSettings['componentPartSourceId'] as $linked) {
                    if (!isset($settings[$linked]['__hostRecordSourceId'])) {
                        $settings[$linked]['__hostRecordSourceId'] = [$linked];
                    }
                    $settings[$linked]['__hostRecordSourceId'][] = $sourceId;
                }
            }
        }

        return $settings;
    }
}
