<?php
/**
 * Extended MARC-in-JSON serializer with support for RecordManager legacy formats
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2022.
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
namespace RecordManager\Base\Marc\Serialization;

/**
 * Extended MARC-in-JSON serializer with support for RecordManager legacy formats
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcInJson extends \VuFind\Marc\Serialization\MarcInJson
{
    /**
     * Subfield indicator character in legacy v1 format (from ISO 2709)
     *
     * @var string
     */
    public const SUBFIELD_INDICATOR = "\x1F";

    /**
     * Parse MARC-in-JSON or any of RecordManager's JSON versions
     *
     * @param string $marc JSON
     *
     * @throws \Exception
     * @return array<array>
     */
    public static function fromString(string $marc): array
    {
        $json = json_decode($marc, true);
        if (isset($json['leader']) && isset($json['fields'])) {
            // Proper MARC-in-JSON
            return $json;
        }

        $result = [
            'leader' => [],
            'fields' => [],
        ];
        if (!isset($json['v'])) {
            // Legacy v1 format
            foreach ($json as $tag => $field) {
                foreach ($field as $data) {
                    if (strstr($data, self::SUBFIELD_INDICATOR)) {
                        $newField = [
                            'ind1' => mb_substr($data . ' ', 0, 1, 'UTF-8'),
                            'ind2' => mb_substr($data . ' ', 1, 1, 'UTF-8'),
                            'subfields' => [],
                        ];
                        $subfields = explode(
                            self::SUBFIELD_INDICATOR,
                            substr($data, 3)
                        );
                        foreach ($subfields as $subfield) {
                            $newField['subfields'][] = [
                                $subfield[0] => substr($subfield, 1)
                            ];
                        }
                        $result['fields'][] = [$tag => $newField];
                    } elseif ('000' === $tag) {
                        $result['leader'] = $data;
                    } else {
                        $result['fields'][] = [$tag => $data];
                    }
                }
            }
        } elseif ($json['v'] == 2) {
            // Legacy v2 format
            foreach ($json['f'] as $code => $codeFields) {
                if (!is_array($codeFields)) {
                    // 000
                    $result['leader'] = $codeFields;
                    continue;
                }
                foreach ($codeFields as $field) {
                    if (is_array($field)) {
                        $newField = [
                            'ind1' => $field['i1'],
                            'ind2' => $field['i2'],
                            'subfields' => [],
                        ];
                        if (isset($field['s'])) {
                            foreach ($field['s'] as $subfield) {
                                $newField['subfields'][] = [
                                    $subfield['c'] => $subfield['v']
                                ];
                            }
                        }
                        $result['fields'][] = [$code => $newField];
                    } else {
                        $result['fields'][] = [$code => $field];
                    }
                }
            }
        } elseif ($json['v'] == 3) {
            // Legacy v3 format
            foreach ($json['f'] as $code => $codeFields) {
                if ('000' === $code) {
                    $result['leader'] = is_array($codeFields)
                        ? reset($codeFields) : $codeFields;
                    continue;
                }
                foreach ($codeFields as $field) {
                    if (is_array($field)) {
                        $newField = [
                            'ind1' => $field['i1'],
                            'ind2' => $field['i2'],
                            'subfields' => [],
                        ];
                        if (isset($field['s'])) {
                            foreach ($field['s'] as $subfield) {
                                $newField['subfields'][] = [
                                    (string)key($subfield) => current($subfield)
                                ];
                            }
                        }
                        $result['fields'][] = [$code => $newField];
                    } else {
                        $result['fields'][] = [$code => $field];
                    }
                }
            }
        } else {
            throw new \Exception("Unrecognized MARC JSON format: $marc");
        }

        return $result;
    }
}
