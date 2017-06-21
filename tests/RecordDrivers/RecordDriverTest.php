<?php
/**
 * Generic Record Driver test class
 *
 * PHP version 5
 *
 * Copyright (C) Eero Heikkinen 2013.
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
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */

use RecordManager\Record\RecordFactory;

/**
 * Generic Record Driver Test Class
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
abstract class RecordDriverTest extends AbstractTest
{
    // Override this from subclass
    protected $driver;

    /**
     * Standard setup method.
     *
     * @return void
     */
    public function setUp()
    {
        if(empty($this->driver))
            $this->markTestIncomplete('Record driver needs to be set in subclass.');
    }

    /**
     * Process a sample record
     *
     * @param string $sample Sample record file
     *
     * @return array SOLR record array
     */
    protected function processSample($sample)
    {
        $actualdir = dirname(__FILE__);
        $sample = file_get_contents($actualdir . "/../samples/" . $sample);
        $record = RecordFactory::createRecord($this->driver, $sample, "__unit_test_no_id__", "__unit_test_no_source__");
        return $record->toSolrArray();
    }
}
