<?php
/**
 * MetaLib Primo Export File Harvesting Class
 *
 * Based on harvest-oai.php in VuFind
 *
 * PHP version 5
 *
 * Copyright (c) Demian Katz 2010.
 * Copyright (c) The National Library of Finland 2011-2014.
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
require_once 'HTTP/Request2.php';

/**
 * HarvestMetaLibExport Class
 *
 * This class harvests MetaLib knowledge base export files via HTTP using settings
 * from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class HarvestMetaLibExport extends HarvestHTTPFiles
{
    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return null
     * @throws Exception
     */
    public function harvest($callback)
    {
        if (!$this->filePrefix) {
            throw new Exception(
                'Error: filePrefix not specified in source configuration'
            );
        }
        parent::harvest($callback);
    }

    /**
     * Fetch a file to be harvested
     *
     * @param string $filename File to retrieve
     *
     * @return string xml
     * @throws Exception
     */
    protected function retrieveFile($filename)
    {
        $data = parent::retrieveFile($filename);
        // Remove the namespace declaration. Helps process the file, and it's invalid
        // anyway.
        $data = str_replace(
            '<collection xmlns="http://www.loc.gov/MARC21/slim">',
            '<collection>',
            $data
        );
        // Fix the data
        $dataRows = explode("\n", $data);
        foreach ($dataRows as &$row) {
            // Looks like the category data comes in without proper encoding
            // of characters that must be encoded in XML.
            if (strncmp($row, '<main>', 6) == 0
                || strncmp($row, '<sub>', 5) == 0
            ) {
                $row = str_replace('&', '&amp;', $row);
            } elseif (strncmp($row, '<line>', 6) == 0) {
                // Remove all the <line>... stuff
                $row = '';
            }
        }
        return implode("\n", $dataRows);
    }

    /**
     * Create an OAI style ID
     *
     * @param string $sourceId Source ID
     * @param string $id       Record ID
     *
     * @return string OAI ID
     */
    protected function createOaiId($sourceId, $id)
    {
        return "metalib:$sourceId:$id";
    }

    /**
     * Retrieve list of files to be harvested, filter by date
     *
     * @throws Exception
     * @return string[]
     */
    protected function retrieveFileList()
    {
        $list = parent::retrieveFileList();
        // Take only the latest file
        if ($list) {
            $list = [array_pop($list)];
        }
        return $list;
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->message('Harvested ' . $this->changedRecords . ' records');
    }
}

