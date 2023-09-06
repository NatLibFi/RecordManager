<?php

/**
 * Profiling support class
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

namespace RecordManager\Base\Utils;

use function extension_loaded;
use function ini_get;

/**
 * Profiling support class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Profiler
{
    /**
     * Function name for enabling profiling
     *
     * @var string
     */
    protected $enableFunc = '';

    /**
     * Function name for disabling profiling
     *
     * @var string
     */
    protected $disableFunc = '';

    /**
     * Profiler base URL
     *
     * @var string
     */
    protected $baseUrl = '';

    /**
     * Constructor
     *
     * @param string $baseUrl XHProf base URL
     */
    public function __construct($baseUrl)
    {
        $this->baseUrl = $baseUrl;
        if (extension_loaded('xhprof')) {
            $this->enableFunc = 'xhprof_enable';
            $this->disableFunc = 'xhprof_disable';
        } elseif (extension_loaded('tideways_xhprof')) {
            $this->enableFunc = 'tideways_xhprof_enable';
            $this->disableFunc = 'tideways_xhprof_disable';
        }
    }

    /**
     * Start profiling
     *
     * @return void
     */
    public function start()
    {
        if ($this->enableFunc) {
            ($this->enableFunc)();
            register_shutdown_function([$this, 'finishAndReport']);
        } else {
            echo 'WARNING: Profiler extension (xhprof or tideways_xhprof) not'
                . " available, profiling disabled\n";
        }
    }

    /**
     * Shutdown callback for finishing profiling and reporting the results
     *
     * @return void
     */
    public function finishAndReport()
    {
        if (!$this->disableFunc) {
            return;
        }
        $data = ($this->disableFunc)();
        $runId = uniqid();
        $suffix = 'recman';
        $dir = ini_get('xhprof.output_dir');
        if (empty($dir)) {
            $dir = sys_get_temp_dir();
        }
        file_put_contents("$dir/$runId.$suffix.xhprof", serialize($data));
        $url = $this->baseUrl . "?run=$runId&source=$suffix";
        echo "\nProfiler output for " . getmypid() . ": $url\n";
    }
}
