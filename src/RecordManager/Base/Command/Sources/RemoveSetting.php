<?php

/**
 * Remove a setting from data sources
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021-2023.
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
use RecordManager\Base\Command\Util\IniFileTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function in_array;

/**
 * Remove a setting from data sources
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class RemoveSetting extends AbstractBase
{
    use IniFileTrait;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Remove a setting from data source configuration')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Remove the setting only from a comma-separated list of data'
                    . ' sources',
                '*'
            )->addOption(
                'highlight',
                null,
                InputOption::VALUE_OPTIONAL,
                'Highlight changes in output',
                false
            )->addOption(
                'write',
                null,
                InputOption::VALUE_OPTIONAL,
                'Write the changes to datasources.ini (default is to just output the'
                    . ' modified configuration). <options=bold>Make sure to have an'
                    . ' up-to-date backup before using this option.</>',
                false
            )
            ->addArgument(
                'setting',
                InputArgument::REQUIRED,
                'The setting line to remove'
            )->setHelp(
                'Remove a setting from datasources.ini for the sources indicated by'
                    . ' the source option.'
            );
    }

    /**
     * Remove a setting from data sources
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $sources = $input->getOption('source');
        $sources = $sources === '*' ? null : explode(',', $sources);
        $highlight = $input->getOption('highlight') !== false;
        $writeChanges = $input->getOption('write') !== false;
        if ($highlight && $writeChanges) {
            $output->writeln(
                '<error>--highlight cannot be used with --write</error>'
            );
            return Command::INVALID;
        }
        $setting = $input->getArgument('setting');
        $analyzed = @parse_ini_string($setting);
        if (false === $analyzed) {
            $output->writeln(
                '<error>The setting to remove is not valid:</error> ' . $setting
            );
            return Command::INVALID;
        }
        if ($highlight) {
            $setting = "<info>$setting</info>";
        }

        $fullPath = RECMAN_BASE_PATH . '/conf/datasources.ini';

        $contents = file($fullPath, FILE_IGNORE_NEW_LINES);
        if (false === $contents) {
            $output->writeln("<error>Could not open $fullPath for reading</error>");
            return Command::FAILURE;
        }
        $modified = [];
        $currentSource = null;
        $modifyCurrentSource = false;

        $count = 0;
        foreach ($contents as $line) {
            if (!$writeChanges) {
                // Escape the line for outputting:
                $line = OutputFormatter::escape($line);
            }
            ++$count;
            [$commentless] = explode(';', $line, 2);
            $commentless = trim($commentless);

            $modifyCurrentSource = $currentSource
                && (!$sources || in_array($currentSource, $sources));
            if ($sectionName = $this->getSectionFromLine($commentless)) {
                $currentSource = $sectionName;
                $modified[] = $line;
                continue;
            }

            // Skip rest if current source is not one to be modified:
            if (!$modifyCurrentSource) {
                $modified[] = $line;
                continue;
            }

            // Check for existing setting:
            $lineAnalyzed = parse_ini_string($line);
            if (false === $lineAnalyzed) {
                $output->writeln(
                    "<error>Could not parse line $count:"
                    . ' ' . OutputFormatter::escape($line)
                    . '</error>'
                );
                return Command::INVALID;
            }
            if ($analyzed !== $lineAnalyzed) {
                $modified[] = $line;
            } elseif ($highlight) {
                $modified[] = "<fg=red>$line</>";
            }
        }

        $result = implode(PHP_EOL, $modified) . PHP_EOL;
        if ($writeChanges) {
            file_put_contents($fullPath, $result);
            $output->writeln("<info>$fullPath updated</info>");
        } else {
            $output->write($result);
        }

        return Command::SUCCESS;
    }
}
