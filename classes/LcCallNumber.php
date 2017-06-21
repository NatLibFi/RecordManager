<?php
/**
 * LcCallNumber Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015.
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
 * LcCallNumber Class
 *
 * This is a class for processing LC Call Numbers. Inspired by SolrMarc LCCallNumber.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class LcCallNumber
{
    /**
     * Classification
     *
     * @var string
     */
    protected $classification;

    /**
     * Class Letters
     *
     * @var string
     */
    protected $letters;

    /**
     * Digits
     *
     * @var string
     */
    protected $digits;

    /**
     * Decimal Part
     *
     * @var string
     */
    protected $decimal;

    /**
     * Cutter
     *
     * @var string
     */
    protected $cutter;

    /**
     * Suffix
     *
     * @var string
     */
    protected $suffix;

    /**
     * Constructor
     *
     * @param string $callnumber Call Number
     */
    public function __construct($callnumber)
    {
        $callnumber = trim($callnumber);

        $rest = '';
        if (true
            && preg_match(
                '/^([a-zA-Z]+) *(?:(\d+)(\.\d+)?)?/', $callnumber, $matches
            )
        ) {
            $this->classification = isset($matches[0]) ? trim($matches[0]) : '';
            $this->letters = isset($matches[1]) ? trim($matches[1]) : '';
            $this->digits = isset($matches[2]) ? trim($matches[2]) : '';
            $this->decimal = isset($matches[3]) ? trim($matches[3]) : '';
            $rest = isset($matches[5]) ? trim($matches[4]) : '';
        }

        $this->cutter = '';
        if ($rest) {
            $parts = preg_split('/[A-Za-z]\d+/', $rest, 2);
            print_r($parts);
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
        } else {
            $this->suffix = '';
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
            $key .= strlen((int)$this->digits);
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
            $key .= MetadataUtils::createSortableString($this->suffix);
        }
        if ($this->cutter) {
            foreach (preg_split('/[A-Za-z]\d+/', $this->cutter) as $part) {
                if ($key) {
                    $key .= ' ';
                }
                $key .= MetadataUtils::createSortableString($part);
            }
        }
        return $key;
    }
}
