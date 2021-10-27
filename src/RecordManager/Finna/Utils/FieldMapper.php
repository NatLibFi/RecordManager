<?php
/**
 * Field value mapper
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2018.
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
namespace RecordManager\Finna\Utils;

/**
 * Field value mapper
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class FieldMapper extends \RecordManager\Base\Utils\FieldMapper
{
    /**
     * Initialize the data source settings
     *
     * @param array $dataSourceConfig Data source configuration
     *
     * @return void
     */
    public function initdataSourceConfig(array $dataSourceConfig): void
    {
        parent::initdataSourceConfig($dataSourceConfig);

        foreach ($this->settings as &$settings) {
            if (empty($settings['mappingFiles']['format_ext_str_mv'])
                && !empty($settings['mappingFiles']['format'])
            ) {
                $settings['mappingFiles']['format_ext_str_mv']
                    = $settings['mappingFiles']['format'];
            }

            if (empty($settings['mappingFiles']['building_available_str_mv'])
                && !empty($settings['mappingFiles']['building'])
            ) {
                $settings['mappingFiles']['building_available_str_mv']
                    = $settings['mappingFiles']['building'];
                $mappings = &$settings['mappingFiles']['building_available_str_mv'];
                foreach ($mappings as &$mapping) {
                    if (isset($mapping['map']['##empty'])
                        || isset($mapping['map']['##emptyarray'])
                    ) {
                        // map is a reference to a shared cache object, break it
                        $map = $mapping['map'];
                        unset($mapping['map']);
                        $mapping['map'] = $map;
                        if (isset($mapping['map']['##empty'])) {
                            unset($mapping['map']['##empty']);
                        }
                        if (isset($mapping['map']['##emptyarray'])) {
                            unset($mapping['map']['##emptyarray']);
                        }
                    }
                }
            }
        }
    }
}
