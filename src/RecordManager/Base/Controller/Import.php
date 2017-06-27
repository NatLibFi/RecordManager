<?php
/**
 * Import
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Controller;

use RecordManager\Base\Database\Database;

/**
 * Import
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Import extends AbstractBase
{
    use StoreRecordTrait;

    /**
     * Load records into the database from a file
     *
     * @param string $source Source id
     * @param string $files  Wildcard pattern of files containing the records
     * @param bool   $delete Whether to delete the records (default = false)
     *
     * @throws Exception
     * @return void
     */
    public function launch($source, $files, $delete = false)
    {
        $this->initSourceSettings();

        $this->dedupHandler = $this->getDedupHandler();

        if (!isset($this->dataSourceSettings[$source])) {
            $this->logger->log(
                'import',
                "Settings not found for data source $source",
                Logger::FATAL
            );
            throw new \Exception("Error: settings not found for $source\n");
        }
        $settings = $this->dataSourceSettings[$source];
        if (!$settings['recordXPath']) {
            $this->logger->log(
                'loadFromFile', "recordXPath not defined for $source", Logger::FATAL
            );
            throw new \Exception("recordXPath not defined for $source");
        }
        $count = 0;
        foreach (glob($files) as $file) {
            $this->logger->log(
                'loadFromFile', "Loading records from '$file' into '$source'"
            );
            $data = file_get_contents($file);
            if ($data === false) {
                throw new \Exception("Could not read file '$file'");
            }

            if ($settings['preTransformation']) {
                if ($this->verbose) {
                    echo "Executing pretransformation\n";
                }
                $data = $this->pretransform($data);
            }

            if ($this->verbose) {
                echo "Creating FileSplitter\n";
            }
            $splitter = new \RecordManager\Base\Splitter\File(
                $data, $settings['recordXPath'], $settings['oaiIDXPath']
            );

            if ($this->verbose) {
                echo "Storing records\n";
            }
            while (!$splitter->getEOF()) {
                $oaiID = '';
                $data = $splitter->getNextRecord($oaiID);
                $count += $this->storeRecord($source, $oaiID, $delete, $data);
                if ($this->verbose) {
                    echo "Stored records: $count\n";
                }
            }
            $this->logger->log('loadFromFile', "$count records loaded");
        }

        $this->logger->log('loadFromFile', "Total $count records loaded");
        return $count;
    }

    /**
     * Execute a pretransformation on data before it is split into records and
     * loaded.
     *
     * @param string $data   The original data
     * @param string $source Source ID
     *
     * @return string Transformed data
     */
    protected function pretransform($data, $source)
    {
        $settings = &$this->dataSourceSettings[$source];
        if (!isset($settings['preXSLT'])) {
            $style = new \DOMDocument();
            $style->load(
                $this->basePath . '/transformations/'
                . $settings['preTransformation']
            );
            $settings['preXSLT'] = new \XSLTProcessor();
            $settings['preXSLT']->importStylesheet($style);
            $settings['preXSLT']->setParameter('', 'source_id', $source);
            $settings['preXSLT']->setParameter(
                '', 'institution', $settings['institution']
            );
            $settings['preXSLT']->setParameter('', 'format', $settings['format']);
            $settings['preXSLT']->setParameter(
                '', 'id_prefix', $settings['idPrefix']
            );
        }
        $doc = new \DOMDocument();
        $doc->loadXML($data, LIBXML_PARSEHUGE);
        return $settings['preXSLT']->transformToXml($doc);
    }
}
