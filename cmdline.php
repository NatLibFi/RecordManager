<?php
/**
 * Command Line Utility Functions
 *
 * PHP version 5
 *
 * Copyright (C) Patrick Fisher 2009 
 * Copyright (C) The National Library of Finland 2011-2012.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */

/**
 * Command Line Utility Functions
 *
 * Helper functions for command line utilities.
 *
 */

require_once 'PEAR.php';

ini_set('display_errors', '1');

// Initialize command line environment
$basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
require_once 'classes/RecordManager.php';
$configArray = parse_ini_file($basePath . '/conf/recordmanager.ini', true);

PEAR::setErrorHandling(PEAR_ERROR_CALLBACK, 'pearHandleError');

/**
 * PEAR error handler
 * 
 * @param object $error PEAR error
 * 
 * @return void
 */
function pearHandleError($error)
{
    echo $error->toString() . "\n";
}

/**
 * parseArgs Command Line Interface (CLI) utility function.
 * 
 * @param string[] $argv Arguments
 * 
 * @return string[] Parsed keys and values 
 * @usage               $args = parseArgs($_SERVER['argv']);
 * @author              Patrick Fisher <patrick@pwfisher.com>
 * @source              https://github.com/pwfisher/CommandLine.php
 */
function parseArgs($argv)
{
    array_shift($argv);
    $params = array();
    foreach ($argv as $arg) {
        if (substr($arg, 0, 2) == '--') {
            $eqPos = strpos($arg, '=');
            if ($eqPos === false) {
                $key = substr($arg, 2);
                $params[$key] = isset($params[$key]) ? $params[$key] : true;
            } else {
                $key = substr($arg, 2, $eqPos - 2);
                $params[$key] = substr($arg, $eqPos + 1);
            }
        } else if (substr($arg, 0, 1) == '-') {
            if (substr($arg, 2, 1) == '=') {
                $key = substr($arg, 1, 1);
                $params[$key] = substr($arg, 3);
            } else {
                $chars = str_split(substr($arg, 1));
                foreach ($chars as $char) {
                    $key = $char;
                    $params[$key] = isset($params[$key]) ? $params[$key] : true;
                }
            }
        } else {
            $params[] = $arg;
        }
    }
    return $params;
}

