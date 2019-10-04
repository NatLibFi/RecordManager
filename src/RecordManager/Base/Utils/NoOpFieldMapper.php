<?php
/**
 * Field value mapper that does no mapping at all
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2019.
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
namespace RecordManager\Base\Utils;

/**
 * Field value mapper that does no mapping at all
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NoOpFieldMapper extends FieldMapper
{
    /**
     * Map source format to Solr format
     *
     * @param string $source Source ID
     * @param string $format Format
     *
     * @return string Mapped format string
     */
    public function mapFormat($source, $format)
    {
        return $format;
    }

    /**
     * Map all fields in an array
     *
     * @param string $source Source ID
     * @param array  $data   Fields to process
     *
     * @return array
     */
    public function mapValues($source, $data)
    {
        return $data;
    }

    /**
     * Map a value using a mapping file
     *
     * @param mixed $value       Value to map
     * @param array $mappingFile Mapping file
     * @param int   $index       Mapping index for sub-entry mappings
     *
     * @return mixed
     */
    protected function mapValue($value, $mappingFile, $index = 0)
    {
        return $value;
    }
}
