<?php
/**
 * Legacy command Line Utility Functions
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

/**
 * Command Line Utility Functions
 *
 * Helper functions for legacy command line utilities.
 */

/**
 * Convert legacy command line parameters to console parameters
 *
 * @param array $mappings Option mappings
 *
 * @return void
 */
function convertOptions(array $mappings): void
{
    $mappings['verbose'] = [
        'rawOpt' => '-vvv'
    ];
    $args = $_SERVER['argv'];
    $executable = array_shift($args);
    $command = '';
    $options = [];
    $arguments = [];
    foreach ($args as $option) {
        $mapped = false;
        foreach ($mappings as $src => $dst) {
            if (preg_match("/^--$src=(.+)/", $option, $matches)) {
                $value = $matches[1];
                if (isset($dst['valueMap'][$value])) {
                    $value = $dst['valueMap'][$value];
                }
                if ($src === 'func') {
                    $command = $value;
                } else {
                    if (isset($dst['command'])) {
                        $command = $dst['command'];
                    }
                    if (isset($dst['arg'])) {
                        $arguments[$dst['arg']] = $value;
                    };
                    if (isset($dst['opt'])) {
                        $options[] = '--' . $dst['opt'] . '=' . $value;
                    }
                }
                $mapped = true;
                break;
            } elseif (preg_match("/^--$src$/", $option)) {
                if (isset($dst['opt'])) {
                    $options[] = '--' . $dst['opt'];
                } elseif (isset($dst['rawOpt'])) {
                    $options[] = $dst['rawOpt'];
                }
                $mapped = true;
                break;
            }
        }
        if (!$mapped) {
            $options[] = $option;
        }
    }
    ksort($arguments);
    $_SERVER['argv'] = array_merge([$executable, $command], $options, $arguments);
    $_SERVER['argc'] = count($_SERVER['argv']);
}
