<?php

/**
 * Delete a source from data sources
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

namespace RecordManager\Base\Command\Sources;

use RecordManager\Base\Command\AbstractBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete a source from data sources
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Delete extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Remove a source from data source configuration')
            ->addOption(
                'highlight',
                null,
                InputOption::VALUE_OPTIONAL,
                'Highlight changes in output',
                false
            )->addOption(
                'keep-comments',
                null,
                InputOption::VALUE_OPTIONAL,
                'Retain comment lines adjacent to the deleted source sections.'
                . ' Possible options: (empty) = keep all, leading = keep leading'
                . ' comments, trailing = keep trailing comments (default),'
                . ' none = keep nothing.',
                'trailing'
            )->addOption(
                'write',
                null,
                InputOption::VALUE_OPTIONAL,
                'Write the changes to datasources.ini (default is to just output the'
                    . ' modified configuration). <options=bold>Make sure to have an'
                    . ' up-to-date backup before using this option.</>',
                false
            )->addArgument(
                'sources',
                InputArgument::REQUIRED,
                'A comma-separated list of data source sections to remove'
            )->setHelp('Remove a source or sources from datasources.ini.');
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
        $sources = explode(',', $input->getArgument('sources'));
        $highlight = $input->getOption('highlight') !== false;
        $keepComments = $input->getOption('keep-comments');
        $writeChanges = $input->getOption('write') === null;
        if ($highlight && $writeChanges) {
            $output->writeln(
                '<error>--highlight cannot be used with --write</error>'
            );
            return Command::INVALID;
        }

        // Check for existing records in any of the given sources:
        $recordsExist = false;
        foreach ($sources as $source) {
            if ($this->db->findRecord(['source_id' => $source])) {
                $output->writeln(
                    "<error>Data source '$source' contains records and cannot be"
                    . ' deleted</error>'
                );
                $recordsExist = true;
            }
        }
        if ($recordsExist) {
            return Command::FAILURE;
        }

        $fullPath = RECMAN_BASE_PATH . '/conf/datasources.ini';

        $contents = file($fullPath, FILE_IGNORE_NEW_LINES);
        if (false === $contents) {
            $output->writeln("<error>Could not open $fullPath for reading</error>");
            return Command::FAILURE;
        }
        $currentSource = '';

        $sections = [];
        $lines = [];
        foreach ($contents as $line) {
            if (!$writeChanges) {
                $line = OutputFormatter::escape($line);
            }
            [$commentless] = explode(';', $line, 2);
            $commentless = trim($commentless);
            if (
                strncmp($commentless, '[', 1) === 0
                && substr($commentless, -1) === ']'
                && strlen($commentless) > 2
            ) {
                if ($lines) {
                    $sections[] = [
                        'name' => $currentSource,
                        'lines' => $lines,
                        'deleted' => in_array($currentSource, $sources),
                    ];
                }
                $currentSource = substr($commentless, 1, -1);
                $lines = [];
            }
            $lines[] = $line;
        }
        if ($lines) {
            $sections[] = [
                'name' => $currentSource,
                'lines' => $lines,
                'deleted' => in_array($currentSource, $sources),
            ];
        }

        foreach ($sections as $sectionIdx => &$section) {
            if (!$section['deleted']) {
                continue;
            }

            if (null === $keepComments || 'trailing' === $keepComments) {
                if ($sectionIdx < count($sections) - 1) {
                    // Check for trailing comment lines to move to the next section:
                    $comments = [];
                    for ($i = count($section['lines']) - 1; $i >= 0; $i--) {
                        $line = $section['lines'][$i];
                        if ('' === trim($line) || !$this->isCommentLine($line)) {
                            if ($comments) {
                                $sections[$sectionIdx + 1]['lines'] = array_merge(
                                    $comments,
                                    $sections[$sectionIdx + 1]['lines']
                                );
                                $section['lines'] = array_slice(
                                    $section['lines'],
                                    0,
                                    -count($comments)
                                );
                            }
                            break;
                        }
                        $comments[] = $line;
                    }
                }
            }
            if (null !== $keepComments && 'leading' !== $keepComments) {
                if ($sectionIdx > 0 && !$sections[$sectionIdx - 1]['deleted']) {
                    // Check for adjacent comment lines to remove from the previous
                    // section:
                    $prevSection = &$sections[$sectionIdx - 1];
                    for ($i = count($prevSection['lines']) - 1; $i >= 0; $i--) {
                        $line = $prevSection['lines'][$i];
                        if ('' === trim($line) || !$this->isCommentLine($line)) {
                            break;
                        }
                        if ($highlight) {
                            $prevSection['lines'][$i] = "<fg=red>{$line}</>";
                        } else {
                            unset($prevSection['lines'][$i]);
                        }
                    }
                    unset($prevSection);
                }
            }
        }
        unset($section);

        if (!$highlight) {
            $sections = array_filter(
                $sections,
                function ($s) {
                    return !$s['deleted'];
                }
            );
        }

        $sections = array_map(
            function ($s) {
                if ($s['deleted']) {
                    foreach ($s['lines'] as &$line) {
                        $line = "<fg=red>$line</>";
                    }
                    unset($line);
                }
                $s['lines'] = implode(PHP_EOL, $s['lines']);
                return $s;
            },
            $sections
        );

        $result = implode(PHP_EOL, array_column($sections, 'lines')) . PHP_EOL;
        if ($writeChanges) {
            file_put_contents($fullPath, $result);
            $output->writeln("<info>$fullPath updated</info>");
        } else {
            $output->write($result);
        }

        return Command::SUCCESS;
    }

    /**
     * Check if a line is a comment line (contains a comment and nothing else)
     *
     * @param string $line Line to check
     *
     * @return bool
     */
    protected function isCommentLine(string $line): bool
    {
        $line = trim($line);
        return strncmp($line, ';', 1) === 0;
    }
}
