<?php

/**
 * Solr Update
 *
 * PHP version 8
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

namespace RecordManager\Base\Command\Solr;

use RecordManager\Base\Command\AbstractBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Solr Update
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class UpdateIndex extends AbstractBase
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
            ->setDescription('Update Solr index')
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Process records updated since the given timestamp'
            )->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Process all records'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the specified record'
            )->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only a comma-separated list of data sources',
                '*'
            )->addOption(
                'no-commit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Disable commit requests',
                false
            )->addOption(
                'date-per-server',
                null,
                InputOption::VALUE_NONE,
                'Whether to track last update date per server address.'
                . ' Default is set by track_updates_per_update_url setting.'
            );
    }

    /**
     * Send updates to the Solr index
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        // Override the default verbosity:
        $this->verbose = $output->isVerbose();
        $this->solrUpdater->updateRecords(
            $input->getOption('all') ? '' : $input->getOption('from'),
            $input->getOption('source'),
            $input->getOption('single'),
            $input->getOption('no-commit') === null,
            false,
            '',
            $input->getOption('date-per-server')
        );

        return Command::SUCCESS;
    }
}
