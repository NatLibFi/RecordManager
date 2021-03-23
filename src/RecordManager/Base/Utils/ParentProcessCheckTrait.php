<?php
/**
 * Parent process health check trait
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2020.
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
 * Parent process health check trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait ParentProcessCheckTrait
{
    /**
     * Last time the parent alive check was made
     *
     * @var int
     */
    protected $lastParentCheckTime = 0;

    /**
     * Check that the parent process is alive
     *
     * @return void
     * @throws \Exception
     */
    protected function checkParentIsAlive()
    {
        $time = microtime(true);
        if (0 === $this->lastParentCheckTime
            || $time - $this->lastParentCheckTime > 5
        ) {
            $parentPid = posix_getpgrp();
            if (!posix_kill($parentPid, 0)) {
                $pid = getmypid();
                echo "Fatal: worker $pid parent process $parentPid has died"
                    . " unexpectedly\n";
                throw new \Exception(
                    "Parent process $parentPid has died unexpectedly"
                );
            }
            $this->lastParentCheckTime = $time;
        }
    }
}
