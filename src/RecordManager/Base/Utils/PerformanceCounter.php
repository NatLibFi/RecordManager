<?php

/**
 * Performance Counter
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2012.
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

use function count;

/**
 * PerformanceCounter
 *
 * This class provides average speed estimation for different processes
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class PerformanceCounter
{
    /**
     * Array of previous counts
     *
     * @var array
     */
    protected $counts = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->reset();
    }

    /**
     * Reset counter
     *
     * @return void
     */
    public function reset()
    {
        $this->counts = [['t' => microtime(true), 'c' => 0]];
    }

    /**
     * Add the current count
     *
     * @param int $count Current progress
     *
     * @return void
     */
    public function add($count)
    {
        $this->counts[] = ['t' => microtime(true), 'c' => $count];
        if (count($this->counts) > 10) {
            array_shift($this->counts);
        }
    }

    /**
     * Get the speed as units / second
     *
     * @return int
     */
    public function getSpeed()
    {
        if (count($this->counts) < 2) {
            return 0;
        }
        $first = $this->counts[0];
        $last = end($this->counts);
        $count = $last['c'] - $first['c'];
        $time = $last['t'] - $first['t'];
        if ($time > 0) {
            return (int)round($count / $time);
        }
        return 0;
    }
}
