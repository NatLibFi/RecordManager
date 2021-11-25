<?php
/**
 * Search Data Source Settings
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
namespace RecordManager\Base\Command\Sources;

use RecordManager\Base\Command\AbstractBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Search Data Source Settings
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Search extends AbstractBase
{
    /**
     * Separator character mappings
     *
     * @var array
     */
    protected $separatorMap = [
        '\t' => "\t",
        '\n' => "\n",
        '\r' => "\r",
    ];

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Search in data source configuration')
            ->addArgument(
                'regexp',
                InputArgument::REQUIRED,
                'Regular expression to search for'
            )->addOption(
                'separator',
                null,
                InputOption::VALUE_REQUIRED,
                "Output separator (use '\\t' for tab, '\\n' for LF or '\\r' for CR)",
                ','
            )->setHelp(
                'Search for a regular expression in data source configuration.'
                . ' Configuration is normalized to "setting=value" where boolean'
                . ' values are 0 (false) and 1 (true)'
            );
    }

    /**
     * Search for a regexp in data sources
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $regexp = $input->getArgument('regexp');
        $separator = $input->getOption('separator');

        $separator = str_replace(
            ['\t', '\n', '\r'],
            ["\t", "\n", "\r"],
            $separator
        );

        if (substr($regexp, 0, 1) !== '/') {
            $regexp = "/$regexp/";
        }
        $matches = [];
        foreach ($this->dataSourceConfig as $source => $settings) {
            foreach ($settings as $setting => $value) {
                foreach (is_array($value) ? $value : [$value] as $single) {
                    if (is_array($single)) {
                        continue;
                    }
                    if (is_bool($single)) {
                        $single = $single ? '1' : '0';
                    }
                    if (!is_object($single)
                        && preg_match($regexp, "$setting=$single")
                    ) {
                        $matches[] = $source;
                        break 2;
                    }
                }
            }
        }
        $output->writeln(implode($separator, $matches));

        return Command::SUCCESS;
    }
}
