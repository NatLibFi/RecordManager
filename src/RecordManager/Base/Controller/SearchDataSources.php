<?php
/**
 * Search Data Source Settings
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
namespace RecordManager\Base\Controller;

/**
 * Search Data Source Settings
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class SearchDataSources extends AbstractBase
{
    /**
     * Search for $regexp in data sources
     *
     * @param string $regexp Regular expression
     *
     * @return void
     */
    public function launch($regexp)
    {
        if (substr($regexp, 0, 1) !== '/') {
            $regexp = "/$regexp/";
        }
        $matches = [];
        foreach ($this->dataSourceSettings as $source => $settings) {
            foreach ($settings as $setting => $value) {
                foreach (is_array($value) ? $value : [$value] as $single) {
                    if (is_array($single)) {
                        continue;
                    }
                    if (is_bool($single)) {
                        $single = $single ? '1' : '0';
                    }
                    if (preg_match($regexp, "$setting=$single")) {
                        $matches[] = $source;
                        break 2;
                    }
                }
            }
        }
        echo implode(',', $matches) . "\n";
    }
}
