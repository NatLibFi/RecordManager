<?php
/**
 * Count Field Values
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Count Field Values
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class CountValues extends AbstractBase
{
    use \RecordManager\Base\Command\Solr\CommandWithSolrUpdaterTrait;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Count field values')
            ->setHelp(
                'Counts the occurrences of the given field'
            )->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Data source to process'
            )->addOption(
                'mapped',
                null,
                InputOption::VALUE_NONE,
                'Whether to count values after mapping'
            )->addArgument(
                'field',
                InputArgument::REQUIRED,
                'Field to count'
            );
    }

    /**
     * Count distinct values in the specified field (that would be added to the
     * Solr index)
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $this->solrUpdater->setVerboseMode($this->verbose);
        $this->solrUpdater->countValues(
            $input->getOption('source'),
            $input->getArgument('field'),
            $input->getOption('mapped')
        );

        return Command::SUCCESS;
    }
}
