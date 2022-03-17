<?php
/**
 * Call number base class
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
 * Call number base class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractCallNumber
{
    /**
     * Constructor
     *
     * @param string $callnumber Call Number
     */
    abstract public function __construct($callnumber);

    /**
     * Check if the call number is valid
     *
     * @return bool
     */
    abstract public function isValid();

    /**
     * Create a sort key
     *
     * @return string
     */
    abstract public function getSortKey();

    /**
     * Make a string numerically sortable
     *
     * @param string $str String
     *
     * @return string
     */
    protected function createSortableString($str)
    {
        $str = preg_replace_callback(
            '/(\d+)/',
            function ($matches) {
                return strlen((string)(intval($matches[1]))) . $matches[1];
            },
            strtoupper($str)
        );
        return preg_replace('/\s{2,}/', ' ', $str);
    }
}
