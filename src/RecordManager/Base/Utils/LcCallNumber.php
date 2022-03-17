<?php
/**
 * LcCallNumber Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2015-2021.
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
 * LcCallNumber Class
 *
 * This is a class for processing LC Call Numbers. Inspired by SolrMarc LCCallNumber.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LcCallNumber extends AbstractCallNumber
{
    /**
     * Classification
     *
     * @var string
     */
    protected $classification = '';

    /**
     * Class Letters
     *
     * @var string
     */
    protected $letters = '';

    /**
     * Digits
     *
     * @var string
     */
    protected $digits = '';

    /**
     * Decimal Part
     *
     * @var string
     */
    protected $decimal = '';

    /**
     * Cutter
     *
     * @var string
     */
    protected $cutter = '';

    /**
     * Suffix
     *
     * @var string
     */
    protected $suffix = '';

    /**
     * Constructor
     *
     * @param string $callnumber Call Number
     */
    public function __construct($callnumber)
    {
        $callnumber = trim($callnumber);

        $rest = '';
        $found = preg_match(
            '/^([a-zA-Z]+) *(?:(\d+)(\.\d+)?)?/',
            $callnumber,
            $matches
        );
        if ($found) {
            $this->classification = isset($matches[0]) ? trim($matches[0]) : '';
            $this->letters = isset($matches[1]) ? trim($matches[1]) : '';
            $this->digits = isset($matches[2]) ? trim($matches[2]) : '';
            $this->decimal = isset($matches[3]) ? trim($matches[3]) : '';
            $rest = isset($matches[5]) ? trim($matches[4]) : '';
        }

        $this->cutter = '';
        if ($rest) {
            $parts = preg_split('/[A-Za-z]\d+/', $rest, 2);
            if (isset($parts[1])) {
                $this->suffix = trim($parts[0]);
                $this->cutter = trim($parts[1]);
            } else {
                $this->suffix = trim($rest);
            }
            if ($this->classification) {
                $this->classification .= ' ';
            }
            $this->classification .= $this->suffix;
        }
    }

    /**
     * Check if the LCCN is valid
     *
     * @return bool
     */
    public function isValid()
    {
        if (!$this->letters || !$this->digits) {
            return false;
        }
        if (in_array($this->letters[0], ['I', 'O', 'W', 'X', 'Y'])) {
            return false;
        }
        return true;
    }

    /**
     * Create a sort key
     *
     * @return string
     */
    public function getSortKey()
    {
        $key = strtoupper($this->letters);
        if ($this->digits) {
            if ($key) {
                $key .= ' ';
            }
            $key .= strlen((string)(intval($this->digits)));
            $key .= $this->digits;
        }
        $key .= $this->decimal;
        if ($this->suffix) {
            if ($key) {
                $key .= ' ';
                if (ctype_alpha($this->suffix[0])) {
                    $key .= '_';
                }
            }
            $key .= $this->createSortableString($this->suffix);
        }
        if ($this->cutter) {
            foreach (preg_split('/[A-Za-z]\d+/', $this->cutter) as $part) {
                if ($key) {
                    $key .= ' ';
                }
                $key .= $this->createSortableString($part);
            }
        }
        return $key;
    }

    /**
     * Get a hierarchical category for the call number
     *
     * Requires the HILCC mapping file. See RecordManager wiki for more information.
     *
     * @return string
     */
    public function getCategory(): string
    {
        if (!$this->isValid()) {
            return '';
        }

        static $mapping = null;
        static $cache = [];
        if (null === $mapping) {
            $mappingFile = RECMAN_BASE_PATH . '/mappings/LcCallNumberCategories.php';
            if (!file_exists($mappingFile)) {
                throw new \Exception(
                    "$mappingFile not available. Install it to use the categories."
                );
            }
            $mapping = include $mappingFile;
        }

        $digits = intval($this->digits);
        $decimal = intval($this->decimal);
        $cacheKey = $this->letters . '/' . $digits . '/' . $decimal;
        if (isset($cache[$cacheKey])) {
            $ptr = $cache[$cacheKey];
            return '' === $ptr ? '' : $mapping[$ptr]['cat'];
        }
        foreach ($mapping as $key => $item) {
            if ($this->letters >= $item['a1']
                && $this->letters <= $item['a2']
                && $digits >= $item['d1']
                && $digits <= $item['d2']
                && $decimal >= $item['f1']
                && $decimal <= $item['f2']
            ) {
                $cache[$cacheKey] = $key;
                return $item['cat'];
            }
        }
        return $cache[$cacheKey] = '';
    }
}
