<?php
/**
 * Command Line Utility Functions
 *
 * PHP version 5
 *
 * Copyright (C) Patrick Fisher 2009
 * Copyright (C) The National Library of Finland 2011-2013.
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
 */
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/RecordManager/Base/Autoloader.php';

/**
 * Apply any configuration overrides defined on command line
 *
 * @param array $params Command line parameters
 * @param array $config Configuration
 *
 * @return array
 */
function applyConfigOverrides($params, $config)
{
    foreach ($params as $key => $value) {
        $setting = explode('.', $key);
        if ($setting[0] == 'config') {
            $config[$setting[1]][$setting[2]] = $value;
        }
    }
    return $config;
}

/**
 * Command Line Interface (CLI) utility function.
 *
 * @param array $argv Arguments
 *
 * @return array Parsed keys and values
 * @usage  $args = parseArgs($_SERVER['argv']);
 * @author Patrick Fisher <patrick@pwfisher.com>
 * @source https://github.com/pwfisher/CommandLine.php
 */
function parseArgs($argv)
{
    array_shift($argv);
    $params = [];
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
        } elseif (substr($arg, 0, 1) == '-') {
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

/**
 * Try to acquire a lock on a lock file
 *
 * @param string $lockfile Lock file
 *
 * @return resource|bool|null Returns file handle on success, null if no lock was
 * required or false on failure
 */
function acquireLock($lockfile)
{
    if (empty($lockfile)) {
        return null;
    }
    $handle = fopen($lockfile, 'c+');
    if (!is_resource($handle)) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

/**
 * Release a lock on a lock file
 *
 * @param resource $handle Lock file handle
 *
 * @return void
 */
function releaseLock($handle)
{
    if ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
