<?php

/**
 * Field value mapper
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2012-2023.
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

namespace RecordManager\Base\Utils;

/**
 * Field value mapper
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class FieldMapper
{
    /**
     * Base path
     *
     * @var string
     */
    protected $basePath;

    /**
     * Mapping file cache
     *
     * @var array
     */
    protected static $mapCache = [];

    /**
     * Default (not specific to data source configuration) mappings
     *
     * @var array
     */
    protected $defaultMappings = [];

    /**
     * Settings for all data sources
     *
     * @var array
     */
    protected $settings = [];

    /**
     * Constructor
     *
     * @param string $basePath         Base path for configuration files
     * @param array  $defaultMappings  Default mappings for all data sources
     * @param array  $dataSourceConfig Data source settings
     */
    public function __construct(
        string $basePath,
        array $defaultMappings,
        array $dataSourceConfig
    ) {
        $this->basePath = $basePath;
        $this->defaultMappings = $defaultMappings;
        $this->initDataSourceConfig($dataSourceConfig);
    }

    /**
     * Initialize the data source settings
     *
     * @param array $dataSourceConfig Data source configuration
     *
     * @return void
     */
    public function initDataSourceConfig(array $dataSourceConfig): void
    {
        foreach ($dataSourceConfig as $source => $settings) {
            $this->settings[$source]['mappingFiles'] = [];

            // Use default mappings as the basis
            $allMappings = $this->defaultMappings;

            // Apply data source specific overrides
            foreach ($settings as $key => $value) {
                if (substr($key, -8, 8) == '_mapping') {
                    $field = substr($key, 0, -8);
                    if (empty($value)) {
                        unset($allMappings[$field]);
                    } else {
                        $allMappings[$field] = $value;
                    }
                }
            }

            foreach ($allMappings as $field => $values) {
                foreach ((array)$values as $value) {
                    $parts = explode(',', $value, 2);
                    $filename = $parts[0];
                    $type = $parts[1] ?? 'normal';
                    if (!isset(self::$mapCache[$filename])) {
                        self::$mapCache[$filename] = $this->readMappingFile(
                            $this->basePath . '/mappings/' . $filename
                        );
                    }
                    $this->settings[$source]['mappingFiles'][$field][] = [
                        'type' => $type,
                        'map' => &self::$mapCache[$filename],
                    ];
                }
            }
        }
    }

    /**
     * Map source format to Solr format
     *
     * @param string $source Source ID
     * @param array  $format Format(s)
     *
     * @return array Mapped format(s)
     */
    public function mapFormat(string $source, array $format): array
    {
        $settings = $this->settings[$source];

        if (isset($settings['mappingFiles']['format'])) {
            $mappingFile = $settings['mappingFiles']['format'];
            $map = $mappingFile[0]['map'];
            if (!empty($format)) {
                $format = $this->mapValue($format, $mappingFile);
                return (array)$format;
            } elseif (isset($map['##empty'])) {
                return (array)$map['##empty'];
            } elseif (isset($map['##emptyarray'])) {
                return (array)$map['##emptyarray'];
            }
        }
        return $format;
    }

    /**
     * Map all fields in an array
     *
     * @param string                                   $source Source ID
     * @param array<string, string|array<int, string>> $data   Fields to process
     *
     * @return array
     */
    public function mapValues($source, &$data)
    {
        $settings = $this->settings[$source];
        foreach ($settings['mappingFiles'] as $field => $mappingFile) {
            if (isset($data[$field]) && !empty($data[$field])) {
                if (is_array($data[$field])) {
                    $newValues = [];
                    foreach ($data[$field] as $value) {
                        $replacement = $this->mapValue($value, $mappingFile);
                        if (is_array($replacement)) {
                            $newValues = [...$newValues, ...$replacement];
                        } else {
                            $newValues[] = $replacement;
                        }
                    }
                    $data[$field] = array_values(
                        array_filter(
                            array_unique($newValues),
                            function ($s) {
                                return '' !== $s;
                            }
                        )
                    );
                } else {
                    $data[$field] = $this->mapValue($data[$field], $mappingFile);
                }
                if ([] === $data[$field] || '' === $data[$field]) {
                    if (isset($mappingFile[0]['map']['##mappedempty'])) {
                        $data[$field] = $mappingFile[0]['map']['##mappedempty'];
                    } elseif (isset($mappingFile[0]['map']['##mappedemptyarray'])) {
                        $data[$field]
                            = [$mappingFile[0]['map']['##mappedemptyarray']];
                    }
                }
            } elseif (isset($mappingFile[0]['map']['##empty'])) {
                $data[$field] = $mappingFile[0]['map']['##empty'];
            } elseif (isset($mappingFile[0]['map']['##emptyarray'])) {
                $data[$field] = [$mappingFile[0]['map']['##emptyarray']];
            }
        }
        return $data;
    }

    /**
     * Map a value using a mapping file
     *
     * @param mixed $value       Value to map
     * @param array $mappingFile Mapping file
     * @param int   $index       Mapping index for sub-entry mappings
     *
     * @return string|array<int, string>
     */
    protected function mapValue($value, $mappingFile, $index = 0)
    {
        if (is_array($value)) {
            // Map array parts (predefined hierarchy) separately
            $newValue = [];
            foreach ($value as $i => $v) {
                $v = $this->mapValue($v, $mappingFile, $i);
                if ('' === $v) {//  || [] === $v) {
                    // If we get an empty string or array from any level, stop here
                    break;
                }
                if (is_array($v)) {
                    $newValue = [...$newValue, ...$v];
                } else {
                    $newValue[] = $v;
                }
            }
            return implode('/', $newValue);
        }
        $map = $mappingFile[$index]['map']
            ?? $mappingFile[0]['map'];
        $type = $mappingFile[$index]['type'] ?? $mappingFile[0]['type'];
        if ('regexp' === $type || 'regexp-multi' === $type) {
            $newValues = [];
            $all = 'regexp-multi' === $type;
            foreach ($map as $pattern => $replacement) {
                $pattern = addcslashes($pattern, '/');
                if (is_array($replacement)) {
                    $matches = false;
                    foreach ($replacement as $current) {
                        $newValue = preg_replace(
                            "/$pattern/us",
                            $current,
                            $value,
                            -1,
                            $count
                        );
                        if ($count > 0) {
                            $newValues[] = $newValue;
                            $matches = true;
                        } else {
                            // No matches, stop the loop
                            break;
                        }
                    }
                    if (!$all && $matches) {
                        return $newValues;
                    }
                } else {
                    $newValue = preg_replace(
                        "/$pattern/us",
                        $replacement,
                        $value,
                        -1,
                        $count
                    );
                    if ($count > 0) {
                        if (!$all) {
                            return $newValue;
                        }
                        $newValues[] = $newValue;
                    }
                }
            }
            if ($newValues) {
                return $newValues;
            }
            return $map['##default'] ?? $value;
        }
        $replacement = $value;
        if (isset($map[$value])) {
            $replacement = $map[$value];
        } elseif (isset($map['##default'])) {
            $replacement = $map['##default'];
        }
        return $replacement;
    }

    /**
     * Read a mapping file (two strings separated by ' = ' per line)
     *
     * @param string $filename Mapping file name
     *
     * @throws \Exception
     * @return array Mappings
     */
    protected function readMappingFile($filename)
    {
        $mappings = [];
        $handle = fopen($filename, 'r');
        if (!$handle) {
            throw new \Exception("Could not open mapping file '$filename'");
        }
        $lineno = 0;
        while (($line = fgets($handle))) {
            ++$lineno;
            $line = rtrim($line);
            if (!$line || $line[0] == ';') {
                continue;
            }
            $parts = explode(' = ', $line, 2);
            if (!isset($parts[1])) {
                $parts = explode('=', $line, 2);
            }
            if (!isset($parts[1])) {
                fclose($handle);
                throw new \Exception(
                    "Unable to parse mapping file '$filename' line "
                    . "(no ' = ' found): ($lineno) $line"
                );
            }
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if (substr($key, -2) == '[]') {
                $mappings[substr($key, 0, -2)][] = $value;
            } else {
                $mappings[$key] = $value;
            }
        }
        fclose($handle);
        return $mappings;
    }
}
