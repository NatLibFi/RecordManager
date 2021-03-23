<?php
/**
 * Simple in-memory cache implementation
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2021.
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
 * Simple in-memory cache implementation
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Cache
{
    /**
     * Cache capacity
     *
     * @var int
     */
    protected $capacity;

    /**
     * Cache hits
     *
     * @var int
     */
    protected $hits = 0;

    /**
     * Cache misses
     *
     * @var int
     */
    protected $misses = 0;

    /**
     * Cache inserts
     *
     * @var int
     */
    protected $inserts = 0;

    /**
     * Cache deletions
     *
     * @var int
     */
    protected $deletions = 0;

    /**
     * Cached items
     *
     * @var array
     */
    protected $items = [];

    /**
     * Constructor
     *
     * @param int $capacity Maximum capacity for the cache
     */
    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }

    /**
     * Get an item
     *
     * @param string $key Key
     *
     * @return mixed
     */
    public function get(string $key)
    {
        $id = md5($key);
        if (isset($this->items[$id])) {
            ++$this->hits;
            $this->items[$id]['time'] = microtime(true);
            return $this->items[$id]['value'];
        }
        ++$this->misses;
        return null;
    }

    /**
     * Set an item
     *
     * @param string $key   Key
     * @param mixed  $value Value
     *
     * @return void
     */
    public function set(string $key, $value)
    {
        $id = md5($key);
        $this->items[$id] = [
            'time' => microtime(true),
            'value' => $value
        ];
        ++$this->inserts;
        if (count($this->items) > $this->capacity) {
            $this->removeLRUItem();
        }
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats()
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'inserts' => $this->inserts,
            'deletions' => $this->deletions,
            'items' => count($this->items),
            'capacity' => $this->capacity
        ];
    }

    /**
     * Remove least recently used item
     *
     * @return void
     */
    protected function removeLRUItem()
    {
        $oldest = null;
        $time = 0;
        foreach ($this->items as $key => $item) {
            if (null === $oldest || $item['time'] < $time) {
                $oldest = $key;
                $time = $item['time'];
            }
        }
        if (null !== $oldest) {
            unset($this->items[$oldest]);
            ++$this->deletions;
        }
    }
}
