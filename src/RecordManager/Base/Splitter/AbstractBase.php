<?php

/**
 * Splitter base class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021-2022.
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

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Splitter base class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
abstract class AbstractBase
{
    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Record count
     *
     * @var int
     */
    protected $recordCount;

    /**
     * Current position
     *
     * @var int
     */
    protected $currentPos;

    /**
     * Constructor
     *
     * @param MetadataUtils $metadataUtils Metadata utilities
     */
    public function __construct(MetadataUtils $metadataUtils)
    {
        $this->metadataUtils = $metadataUtils;
    }

    /**
     * Initializer
     *
     * @param array $params Splitter configuration
     *
     * @return void
     */
    public function init(array $params): void
    {
    }

    /**
     * Check whether EOF has been encountered
     *
     * @return bool
     */
    public function getEOF()
    {
        return $this->currentPos >= $this->recordCount;
    }
}
