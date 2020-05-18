<?php
/**
 * Enrich biblio records with authority record data.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2014-2020.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Enrichment;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Utils\Logger;

/**
 * Enrich biblio records with authority record data.
 *
 * This is a base class for enrichment from authority record data.
 * Authority records are retrieved from Mongo.
 * Record drivers need to implement the 'enrich' method
 * (i.e. call enrichField with an URI and name of the Solr-field to enrich).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class AuthEnrichment extends Enrichment
{
    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Constructor
     *
     * @param Database $db     Database connection (for cache)
     * @param Logger   $logger Logger
     * @param array    $config Main configuration
     */
    public function __construct($db, $logger, $config)
    {
        parent::__construct($db, $logger, $config);

        $url = $config['AuthorityEnrichment']['url']
            ?? $config['Mongo']['url'];
        $database = $config['AuthorityEnrichment']['database']
            ?? $config['Mongo']['database'];

        try {
            $this->db = new Database($url, $database, $config['Mongo']);
        } catch (\Exception $e) {
            $this->logger->log(
                'startup',
                'Failed to connect to MongoDB: ' . $e->getMessage(),
                Logger::FATAL
            );
            throw $e;
        }
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @throws Exception
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        // Implemented in record drivers
    }

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId           Source ID
     * @param object $record             Record
     * @param array  $solrArray          Metadata to be sent to Solr
     * @param string $id                 Onki id
     * @param string $solrField          Target Solr field
     * @param bool   $includeInAllfields Whether to include the enriched
     *                                   value also in allFields
     *
     * @return void
     */
    protected function enrichField($sourceId, $record, &$solrArray,
        $id, $solrField, $includeInAllfields = false
    ) {
        $localData = $this->db->findOntologyEnrichment(['_id' => $id]);
        if ($localData) {
            $solrArray[$solrField]
                = array_merge($solrArray[$solrField], $localData);
            if ($includeInAllfields) {
                $solrArray['allfields']
                    = array_merge($solrArray['allfield'], $localData);
            }
            return;
        }
        $recAuthSource = $record->getAuthorityNamespace();

        if (!$data = $this->db->getRecord($recAuthSource . '.' . $id)) {
            return;
        }

        $doc = $data['original_data'];
        $source = $data['source_id'];

        if ($source !== $recAuthSource) {
            return;
        }

        $authRecord = $this->recordFactory->createRecord(
            $data['format'],
            $data['original_data'],
            $id,
            $source
        );
            
        if ($altNames = $authRecord->getAlternativeNames()) {
            $solrArray[$solrField]
                = array_merge($solrArray[$solrField] ?? [], $altNames);
            if ($includeInAllfields) {
                $solrArray['allfields']
                    = array_merge($solrArray['allfields'], $altNames);
            }
        }
    }
}
