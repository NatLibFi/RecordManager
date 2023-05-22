<?php

/**
 * Splitter interface
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021.
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

namespace RecordManager\Base\Splitter;

/**
 * Splitter interface
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
interface SplitterInterface
{
    /**
     * Initializer
     *
     * @param array $params Splitter configuration
     *
     * @return void
     */
    public function init(array $params);

    /**
     * Set metadata
     *
     * @param string $data Record metadata
     *
     * @return void
     */
    public function setData($data);

    /**
     * Check whether EOF has been encountered
     *
     * @return bool
     */
    public function getEOF();

    /**
     * Get next record
     *
     * Returns false on EOF or an associative array with the following keys:
     * - string metadata       Actual metadata
     * - array  additionalData Any additional data
     *
     * @return array|bool
     */
    public function getNextRecord();
}
