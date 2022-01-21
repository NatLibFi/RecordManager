<?php
/**
 * Display a source configuration from data sources
 *
 * PHP version 7
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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display a source configuration from data sources
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class ShowConfig extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Show configuration for a data source')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source to display'
            );
    }

    /**
     * Show a data source
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $source = $input->getArgument('source');
        $fullPath = RECMAN_BASE_PATH . '/conf/datasources.ini';

        $contents = file($fullPath, FILE_IGNORE_NEW_LINES);
        if (false === $contents) {
            $output->writeln("<error>Could not open $fullPath for reading</error>");
            return Command::FAILURE;
        }

        $lines = [];
        $currentSource = '';
        foreach ($contents as $line) {
            $line = OutputFormatter::escape($line);
            [$commentless] = explode(';', $line, 2);
            $commentless = trim($commentless);
            if (strncmp($commentless, '[', 1) === 0
                && substr($commentless, -1) === ']'
                && strlen($commentless) > 2
            ) {
                $currentSource = substr($commentless, 1, -1);
            }
            if ($currentSource === $source) {
                $lines[] = $line;
            }
        }

        $output->write('<info>' . implode(PHP_EOL, $lines) . '</info>');

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
