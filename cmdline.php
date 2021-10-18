<?php
/**
 * Command Line Utility Functions
 *
 * PHP version 7
 *
 * Copyright (C) Patrick Fisher 2009
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
 * Helper functions for command line utilities.
 */
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php';

// If profiling is requested, set it up now. Profiling can be enabled from the
// command line by providing XHProf location, e.g.
// RECMAN_PROFILE=http://localhost/xhprof php manage.php ...
if ($profilerBaseUrl = getenv('RECMAN_PROFILE')) {
    $profiler = new \RecordManager\Base\Utils\Profiler($profilerBaseUrl);
    $profiler->start();
}

/**
 * Bootstrap a command line application
 *
 * @param array $params Command line parameters
 *
 * @return \Laminas\Mvc\Application
 */
function bootstrap($params)
{
    define(
        'RECMAN_BASE_PATH',
        !empty($params['basepath']) ? $params['basepath'] : __DIR__
    );

    $app = \Laminas\Mvc\Application::init(
        include __DIR__ . '/conf/application.config.php'
    );
    $sm = $app->getServiceManager();
    $configReader = $sm->get(\RecordManager\Base\Settings\Ini::class);
    $configReader->addOverrides('recordmanager.ini', $params);

    return $app;
}

/**
 * Parse command line arguments
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
                $params[$key] = $params[$key] ?? true;
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
                    $params[$key] = $params[$key] ?? true;
                }
            }
        } else {
            $params[] = $arg;
        }
    }
    if ($params['verbose'] ?? false) {
        $params['config.Log.verbose'] = true;
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
 * @param resource|bool|null $handle Lock file handle or a falsy value
 *
 * @return void
 */
function releaseLock($handle)
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
