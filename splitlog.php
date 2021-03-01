<?php
/**
 * Command line program to split a harvesting debug log to a set of
 * importable files.
 *
 * PHP version 7
 *
 * Copyright (C) Ere Maijala 2012.
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
require_once __DIR__ . '/cmdline.php';

/**
 * Main function
 *
 * @param string[] $argv Program parameters
 *
 * @return void
 */
function main($argv)
{
    $params = parseArgs($argv);
    if (!isset($params['input']) || !isset($params['output'])) {
        echo "Usage: splitlog --input=... --output=...\n\n";
        echo "Parameters:\n\n";
        echo "--input             A harvest debug log file\n";
        echo "--output            A file mask for output (e.g. output%d.xml)\n";
        echo "--verbose           Enable verbose output\n\n";
        exit(1);
    }

    $fh = fopen($params['input'], 'r');
    $out = null;
    $count = 0;
    $inResponse = false;
    $emptyLines = 2;
    while (($line = fgets($fh))) {
        $line = chop($line);
        if (!$line) {
            ++$emptyLines;
            continue;
        }
        //echo "Empty: $emptyLines, $inResponse, line: '$line'\n";
        if ($emptyLines >= 2 && $line == 'Request:') {
            fgets($fh);
            fgets($fh);
            $inResponse = true;
            $emptyLines = 0;
            ++$count;
            $filename = sprintf($params['output'], $count);
            echo "Creating file '$filename'\n";
            if ($out) {
                fclose($out);
            }
            $out = fopen($filename, 'w');
            if ($out === false) {
                die("Could not open output file\n");
            }
            continue;
        }
        $emptyLines = 0;
        if ($inResponse && $out) {
            fputs($out, $line . "\n");
        }
    }
    if ($out) {
        fclose($out);
    }
    echo "Done.\n";
}

main($argv);
