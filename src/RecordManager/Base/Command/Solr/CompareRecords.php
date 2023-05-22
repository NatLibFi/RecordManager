<?php

/**
 * Command that compares records with the ones in the Solr index
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2022.
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

namespace RecordManager\Base\Command\Solr;

use RecordManager\Base\Command\AbstractBase;
use RecordManager\Base\Database\DatabaseInterface;
use RecordManager\Base\Deduplication\DedupHandlerInterface;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Solr\SolrComparer;
use RecordManager\Base\Splitter\PluginManager as SplitterPluginManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command that compares records with the ones in the Solr index
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class CompareRecords extends AbstractBase
{
    /**
     * Solr access
     *
     * @var SolrComparer
     */
    protected $solrComparer;

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
     * @param SolrComparer          $solrComparer        Solr comparer
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
        SolrComparer $solrComparer
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
        $this->solrComparer = $solrComparer;
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Compare records with records in Solr index')
            ->setHelp(
                'Compares records as they would be indexed with the existing ones in'
                . ' the Solr index.'
            )->addOption(
                'log',
                null,
                InputOption::VALUE_REQUIRED,
                'Log results to the given file'
            )->addOption(
                'fields',
                null,
                InputOption::VALUE_REQUIRED,
                'Compare only a comma-separated list of fields',
                ''
            )->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Process records updated since the given timestamp'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the specified record'
            )->addOption(
                'skip-missing',
                null,
                InputOption::VALUE_NONE,
                'Skip records missing from index'
            )->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only a comma-separated list of data sources',
                '*'
            );
    }

    /**
     * Compare records that would be updated with the existing records in the Solr
     * index.
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $this->solrComparer->compareRecords(
            $input->getOption('log'),
            $input->getOption('from'),
            $input->getOption('source'),
            $input->getOption('single'),
            $input->getOption('fields'),
            $input->getOption('skip-missing')
        );

        return Command::SUCCESS;
    }
}
