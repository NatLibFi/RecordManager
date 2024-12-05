<?php

/**
 * MARC record handler class
 *
 * PHP version 8
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

namespace RecordManager\Base\Marc;

use function in_array;
use function is_string;

/**
 * MARC record handler class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Marc extends \VuFind\Marc\MarcReader
{
    /**
     * Get normal script fields only
     *
     * @var int
     */
    public const GET_NORMAL = 0;

    /**
     * Get alternate script fields only
     *
     * @var int
     */
    public const GET_ALT = 1;

    /**
     * Get normal and alternate script fields
     *
     * @var int
     */
    public const GET_BOTH = 2;

    /**
     * A record-specific transient cache for results from methods that may get called
     * multiple times with same parameters e.g. during deduplication.
     *
     * @var array
     */
    protected $resultCache = [];

    /**
     * Constructor
     *
     * @param string|array $data MARC record in one of the supported formats, or an
     *                           associative array with 'leader' and 'fields' in the
     *                           internal format
     */
    public function __construct($data)
    {
        // Override JSON serializer:
        $this->serializations['JSON'] = Serialization\MarcInJson::class;

        parent::__construct($data);
    }

    /**
     * Return an array of fields according to the fieldspecs.
     *
     * Format of fieldspecs:
     * [
     *   type (e.g. self::GET_BOTH),
     *   field code (e.g. '245'),
     *   subfields (e.g. ['a', 'b', 'c']),
     *   required subfields (e.g. ['t'])
     * ]
     *
     * @param array   $fieldspecs     Fields to get
     * @param boolean $firstOnly      Return only first matching field
     * @param boolean $splitSubfields Whether to split subfields to separate array
     *                                items
     *
     * @return array Subfields
     *
     * @psalm-suppress InvalidOperand
     */
    public function getFieldsSubfieldsBySpecs(
        array $fieldspecs,
        bool $firstOnly = false,
        bool $splitSubfields = false
    ): array {
        $key = __METHOD__ . '-' . json_encode($fieldspecs) . '-'
            . ($firstOnly ? '1' : '0')
            . ($splitSubfields ? '1' : '0');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $data = [];
        foreach ($fieldspecs as $fieldspec) {
            $type = $fieldspec[0];
            $tag = $fieldspec[1];
            $codes = $fieldspec[2];

            foreach ($this->getInternalFields($tag) as $field) {
                if (empty($field['subfields'])) {
                    $this->storeWarning("missing subfields in $tag");
                    continue;
                }

                // Check for required subfields
                if (isset($fieldspec[3])) {
                    $existing = [];
                    foreach ($field['subfields'] as $subfield) {
                        $existing[] = key($subfield);
                    }
                    if (array_diff($fieldspec[3], $existing)) {
                        continue;
                    }
                }

                if ($type != self::GET_ALT) {
                    // Handle normal field
                    if ($codes) {
                        if ($splitSubfields) {
                            foreach ($field['subfields'] as $subfield) {
                                // Cast to string so that in_array works properly
                                // with PHP 7.4:
                                $code = (string)key($subfield);
                                if (in_array($code, $codes)) {
                                    $data[] = current($subfield);
                                }
                            }
                        } else {
                            $fieldContents = '';
                            foreach ($field['subfields'] as $subfield) {
                                // Cast to string so that in_array works properly
                                // with PHP 7.4:
                                $code = (string)key($subfield);
                                if (in_array($code, $codes)) {
                                    if ($fieldContents) {
                                        $fieldContents .= ' ';
                                    }
                                    $fieldContents .= current($subfield);
                                }
                            }
                            if ($fieldContents) {
                                $data[] = $fieldContents;
                            }
                        }
                    } else {
                        $fieldContents = [];
                        array_walk(
                            $field['subfields'],
                            function ($s) use (&$fieldContents) {
                                $fieldContents[] = current($s);
                            }
                        );
                        if ($splitSubfields) {
                            $data = [...$data, ...$fieldContents];
                        } else {
                            $data[] = implode(' ', $fieldContents);
                        }
                    }
                }
                if (($type == self::GET_ALT || $type == self::GET_BOTH)) {
                    // phpcs:ignore
                    /** @psalm-var list<string> */
                    $linkedFields = $this->getLinkedSubfieldsFrom880(
                        $tag,
                        $this->getInternalSubfield($field, '6'),
                        $codes,
                        $splitSubfields ? null : ' '
                    );
                    if ($linkedFields) {
                        $data = [...$data, ...$linkedFields];
                    }
                }
                if ($firstOnly) {
                    break 2;
                }
            }
        }
        $this->resultCache[$key] = $data;
        return $data;
    }

    /**
     * Get linked fields in 880 for a field
     *
     * @param string $tag      Original field tag
     * @param string $linkData Original field's linkage subfield contents
     *
     * @return array Fields
     */
    public function getLinkedFieldsFrom880(
        string $tag,
        string $linkData
    ): array {
        if (!$linkData) {
            return [];
        }

        $link = $this->parseLinkageField($linkData);
        $result = [];
        foreach ($this->getLinkedFields('880', $tag) as $linkedField) {
            if (
                $link['occurrence'] === $linkedField['link']['occurrence']
            ) {
                $result[] = $linkedField;
            }
        }
        return $result;
    }

    /**
     * Get subfields from linked fields in 880 for a field
     *
     * @param string  $tag       Original field tag
     * @param string  $linkData  Original field's linkage subfield contents
     * @param array   $subfields Subfields to retrieve
     * @param ?string $separator Subfield separator string. Set to null to
     *                           disable concatenation of subfields.
     *
     * @return array Concatenated subfields for every linked field
     */
    public function getLinkedSubfieldsFrom880(
        string $tag,
        string $linkData,
        array $subfields,
        ?string $separator = ' '
    ): array {
        if (!$linkData) {
            return [];
        }

        $link = $this->parseLinkageField($linkData);
        $result = [];
        foreach ($this->getLinkedFields('880', $tag, $subfields) as $linkedField) {
            if (
                $link['occurrence'] === $linkedField['link']['occurrence']
            ) {
                // phpcs:ignore
                /** @psalm-var list<string> */
                $contents = $this->getSubfields($linkedField);

                if (null !== $separator) {
                    $result[] = implode($separator, $contents);
                } else {
                    $result = [...$result, ...$contents];
                }
            }
        }
        return $result;
    }

    /**
     * Return control field contents
     *
     * @param string $fieldTag The MARC field tag to get
     *
     * @return string
     */
    public function getControlField(string $fieldTag): string
    {
        $field = $this->getField($fieldTag);
        return is_string($field) ? $field : '';
    }

    /**
     * Return contents for all occurrences of a control field
     *
     * @param string $fieldTag The MARC field tag to get
     *
     * @return string[]
     */
    public function getControlFields(string $fieldTag): array
    {
        $result = [];
        foreach ($this->getFields($fieldTag) as $field) {
            if (is_string($field)) {
                $result[] = $field;
            }
        }
        return $result;
    }

    /**
     * Get indicator value
     *
     * @param array $field     MARC field
     * @param int   $indicator Indicator nr, 1 or 2
     *
     * @throws \RuntimeException
     * @return string
     */
    public function getIndicator(array $field, int $indicator): string
    {
        // Note: this handles fields as returned in the non-internal format, so the
        // array keys are i1 and i2.
        switch ($indicator) {
            case 1:
                if (!isset($field['i1'])) {
                    $this->storeWarning('indicator 1 missing');
                    return ' ';
                }
                return $field['i1'];
            case 2:
                if (!isset($field['i2'])) {
                    $this->storeWarning('indicator 2 missing');
                    return ' ';
                }
                return $field['i2'];
            default:
                throw new \RuntimeException("Invalid indicator '$indicator' requested");
        }
    }

    /**
     * Add a new field
     *
     * @param string       $fieldTag Field tag
     * @param string       $ind1     First indicator
     * @param string       $ind2     Second indicator
     * @param string|array $contents String for control field, array of subfields for
     *                               data field
     *
     * @return void
     */
    public function addField(
        string $fieldTag,
        string $ind1,
        string $ind2,
        $contents
    ): void {
        if (is_string($contents)) {
            $this->data['fields'][] = [$fieldTag => $contents];
        } else {
            $field = [
                'ind1' => $ind1,
                'ind2' => $ind2,
                'subfields' => $contents,
            ];
            $this->data['fields'][] = [$fieldTag => $field];
        }
        $this->resultCache = [];
    }

    /**
     * Delete fields by tag
     *
     * @param string $fieldTag Field tag
     *
     * @return void
     */
    public function deleteFields(string $fieldTag): void
    {
        $this->data['fields'] = array_filter(
            $this->data['fields'],
            function ($field) use ($fieldTag) {
                return (string)key($field) !== $fieldTag;
            }
        );
        $this->resultCache = [];
    }

    /**
     * Filter fields
     *
     * @param ?callable $callback Callback that should return false for each field to
     * be deleted or true for each field to be kept (like array_filter)
     *
     * @return void
     */
    public function filterFields(?callable $callback = null): void
    {
        $this->data['fields'] = array_filter($this->data['fields'], $callback);
        $this->resultCache = [];
    }

    /**
     * Add a subfield to a field
     *
     * @param string $fieldTag     Field tag
     * @param int    $fieldIdx     Index of field (0-based)
     * @param string $subfieldCode Subfield code
     * @param string $value        Subfield content
     *
     * @throws \RuntimeException
     * @return void
     */
    public function addFieldSubfield(
        string $fieldTag,
        int $fieldIdx,
        string $subfieldCode,
        string $value
    ): void {
        $this
            ->updateFieldSubfield($fieldTag, $fieldIdx, $subfieldCode, null, $value);
    }

    /**
     * Update a subfield in a field
     *
     * @param string $fieldTag     Field tag
     * @param int    $fieldIdx     Index of field (0-based)
     * @param string $subfieldCode Subfield code
     * @param ?int   $subfieldIdx  Index of subfield (0-based) or null to add a new
     *                             subfield
     * @param string $newValue     New subfield content
     *
     * @throws \RuntimeException
     * @return void
     */
    public function updateFieldSubfield(
        string $fieldTag,
        int $fieldIdx,
        string $subfieldCode,
        ?int $subfieldIdx,
        string $newValue
    ): void {
        $currentFieldIdx = -1;
        $currentSubfieldIdx = -1;
        foreach ($this->data['fields'] as &$field) {
            // Ignore fields without subfields
            if (empty(current($field)['subfields'])) {
                continue;
            }
            if ((string)key($field) === $fieldTag) {
                ++$currentFieldIdx;
                if ($currentFieldIdx === $fieldIdx) {
                    if (null === $subfieldIdx) {
                        // Add new subfield:
                        $field[$fieldTag]['subfields'][] = [
                            $subfieldCode => $newValue,
                        ];
                        $this->resultCache = [];
                        return;
                    }
                    // Find the subfield to update:
                    foreach ($field[$fieldTag]['subfields'] as &$subfield) {
                        if ((string)key($subfield) === $subfieldCode) {
                            ++$currentSubfieldIdx;
                            if ($currentSubfieldIdx === $subfieldIdx) {
                                $subfield = [
                                    $subfieldCode => $newValue,
                                ];
                                $this->resultCache = [];
                                return;
                            }
                        }
                    }
                    unset($subfield);
                    throw new \RuntimeException(
                        "Subfield {$fieldTag}[{$fieldIdx}]/"
                        . "{$subfieldCode}[{$subfieldIdx}] not found"
                    );
                }
            }
        }
        unset($field);
        throw new \RuntimeException("Field {$fieldTag}[{$fieldIdx}] not found");
    }

    /**
     * Add a warning to the warnings list
     *
     * @param string $warning Warning
     *
     * @return void
     */
    protected function storeWarning(string $warning): void
    {
        if (!in_array($warning, $this->warnings)) {
            $this->warnings[] = $warning;
        }
    }
}
