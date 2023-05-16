<?php

/**
 * PDO result iterator class that adds any attributes to each returned record
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2020-2021.
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

namespace RecordManager\Base\Database;

/**
 * PDO result iterator class that adds any attributes to each returned record
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 *
 * @psalm-suppress MissingTemplateParam
 */
class PDOResultIterator extends \IteratorIterator
{
    /**
     * Database
     *
     * @var PDODatabase
     */
    protected $db;

    /**
     * Collection
     *
     * @var string
     */
    protected $collection;

    /**
     * Constructor
     *
     * @param \Traversable $iterator   Iterator
     * @param PDODatabase  $db         Database
     * @param string       $collection Collection
     */
    public function __construct(
        \Traversable $iterator,
        PDODatabase $db,
        string $collection
    ) {
        parent::__construct($iterator);

        $this->db = $db;
        $this->collection = $collection;
    }

    /**
     * Get the current value
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        $result = parent::current();
        if ($result) {
            $result += $this->db->getRecordAttrs($this->collection, $result['_id']);
        }
        return $result;
    }
}
