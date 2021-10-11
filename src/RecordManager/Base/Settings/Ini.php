<?php
/**
 * Ini file reader
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
namespace RecordManager\Base\Settings;

/**
 * Ini file reader
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

class Ini
{
    /**
     * Configuration overrides
     *
     * @var array
     */
    protected $overrides = [];

    /**
     * Add configuration file overrides e.g. from command line
     *
     * @param string $filename Ini file
     * @param array  $settings Settings (associative array)
     */
    public function addOverrides(string $filename, array $settings)
    {
        $this->overrides[$filename] = $settings;
    }

    /**
     * Parse an ini file to an array
     *
     * @param string $filename Ini file
     *
     * @return array
     * @throws \Exception
     */
    public function get(string $filename): array
    {
        $fullPath = RECMAN_BASE_PATH . "/conf/$filename";
        $result = parse_ini_file($fullPath, true);
        if (false === $result) {
            $error = error_get_last();
            $message = $error['message'] ?? 'unknown error occurred';
            throw new \Exception(
                "Could not load configuration from file '$fullPath': $message"
            );
        }
        return $this->applyOverrides($result, $this->overrides[$filename] ?? []);
    }

    /**
     * Apply any overrides to the configuration
     *
     * @param array $config    Configuration
     * @param array $overrides Overrides to apply
     *
     * @return array
     */
    function applyOverrides($config, $overrides)
    {
        foreach ($overrides as $key => $value) {
            $setting = explode('.', $key);
            if ($setting[0] == 'config' && isset($setting[2])) {
                $config[$setting[1]][$setting[2]] = $value;
            }
        }
        return $config;
    }
}
