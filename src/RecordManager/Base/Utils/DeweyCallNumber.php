<?php

/**
 * Dewey Call Number Class
 *
 * PHP version 8
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

use function floatval;
use function sprintf;

/**
 * Dewey Call Number Class
 *
 * This is a class for processing Dewey call numbers. Inspired by SolrMarc
 * DeweyCallNumber.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DeweyCallNumber extends AbstractCallNumber
{
    /**
     * Raw value
     *
     * @var string
     */
    protected $raw;

    /**
     * Classification
     *
     * @var string
     */
    protected $classification = null;

    /**
     * Digits
     *
     * @var string
     */
    protected $digits = null;

    /**
     * Decimal Part
     *
     * @var string
     */
    protected $decimal = null;

    /**
     * Cutter
     *
     * @var string
     */
    protected $cutter = null;

    /**
     * Suffix
     *
     * @var string
     */
    protected $suffix = null;

    /**
     * Constructor
     *
     * @param string $callnumber Call Number
     */
    public function __construct($callnumber)
    {
        $this->raw = $callnumber = trim($callnumber);

        $rest = '';
        if (
            $callnumber
            && preg_match('/^((\d+)(\.\d+)?)(.*)/', $callnumber, $matches)
        ) {
            $this->classification = $matches[1];
            $this->digits = $matches[2];
            $this->decimal = $matches[3];
            $rest = $matches[4];
        }

        $cutterMatch = preg_match(
            '/ *\.?([A-Z]\d{1,3}(?:[A-Z]+)?) *(.+)?/',
            $rest,
            $matches
        );
        if ($cutterMatch) {
            $this->cutter = $matches[1];
            $this->suffix = $matches[2] ?? '';
        } else {
            $this->suffix = $rest;
        }
    }

    /**
     * Check if the call number is valid
     *
     * @return bool
     */
    public function isValid()
    {
        return null !== $this->digits;
    }

    /**
     * Get Dewey number in the specified precision
     *
     * @param int $precision Precision (1, 10 or 100)
     *
     * @return string
     */
    public function getNumber($precision)
    {
        if (null !== $this->classification) {
            $val = floatval($this->classification);
            return sprintf('%03.0F', floor($val / $precision) * $precision);
        }
        return '';
    }

    /**
     * Create a searchable string
     *
     * @return string
     */
    public function getSearchString()
    {
        return $this->isValid()
            ? mb_strtoupper(str_replace(' ', '', $this->raw), 'UTF-8') : '';
    }

    /**
     * Create a sort key
     *
     * @return string
     */
    public function getSortKey()
    {
        $result = '';
        if (null !== $this->digits) {
            $result .= $this->createSortableString($this->digits);
        }
        if (null !== $this->decimal) {
            $result .= $this->decimal;
        }
        if (null !== $this->cutter) {
            if ($result) {
                $result .= ' ';
            }
            $result .= $this->cutter;
        }
        if (null !== $this->suffix) {
            if ($result) {
                $result .= ' ';
            }
            $result .= $this->createSortableString($this->suffix);
        }

        return $result;
    }
}
