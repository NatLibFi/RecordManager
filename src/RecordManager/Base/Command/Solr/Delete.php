<?php
/**
 * Delete Records from Solr
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
namespace RecordManager\Base\Command\Solr;

use RecordManager\Base\Command\AbstractBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete Records from Solr
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Delete extends AbstractBase
{
    use CommandWithSolrUpdaterTrait;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Delete a data source from the Solr index')
            ->setHelp('Deletes all records of a data source from the Solr index')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Data source id'
            );
    }

    /**
     * Delete records of a single data source from the Solr index
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $sourceId = $input->getArgument('source');
        if (!empty($this->config['Solr']['merge_records'])) {
            $this->logger->logInfo(
                'deleteSolr',
                "Deleting data source '$sourceId' from merged records via Solr "
                    . 'update for merged records'
            );
            $this->solrUpdater->updateRecords('', $sourceId, '', false, true);
        }
        $this->logger->logInfo(
            'deleteSolr',
            "Deleting data source '$sourceId' directly from Solr"
        );
        $this->solrUpdater->setVerboseMode($this->verbose);
        $this->solrUpdater->deleteDataSource($sourceId);
        $this->logger->logInfo(
            'deleteSolr',
            "Deletion of '$sourceId' from Solr completed"
        );

        return Command::SUCCESS;
    }
}
