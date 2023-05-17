<?php

/**
 * Get last Solr update timestamp
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2022.
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

namespace RecordManager\Base\Command\State;

use RecordManager\Base\Command\AbstractBase;
use RecordManager\Base\Command\Solr\CommandWithSolrUpdaterTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Get last Solr update timestamp
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class GetLastSolrUpdateTimestamp extends AbstractBase
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
            ->setDescription('Get timestamp of last Solr update')
            ->addOption(
                'date-per-server',
                null,
                InputOption::VALUE_NONE,
                'Whether to track last update date per server address.'
                . ' Default is set by track_updates_per_update_url setting.'
            );
    }

    /**
     * Get the timestamp
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $key = $this->solrUpdater->getLastUpdateStateKey(
            $input->getOption('date-per-server')
                ?: !empty($this->config['Solr']['track_updates_per_update_url'])
        );
        if ($output->isVerbose()) {
            $output->writeln($key);
        }
        $timestamp = $this->solrUpdater->getLastUpdateDate($key);

        $output->writeln(
            null === $timestamp ? 'not stored' : gmdate('Y-m-d H:i:s\Z', $timestamp)
        );

        return Command::SUCCESS;
    }
}
