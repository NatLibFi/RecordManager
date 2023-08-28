<?php

/**
 * Ead3SkosmosEnrichment Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2022.
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

namespace RecordManager\Base\Enrichment;

/**
 * Ead3SkosmosEnrichment Class
 *
 * This is a class for enrichment of EAD3 records from a Skosmos instance.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead3SkosmosEnrichment extends SkosmosEnrichment
{
    /**
     * Default fields to enrich. Key is the method in driver and value is array
     * - pref, preferred field in solr
     * - alt, alternative field in solr
     * - check, check field for existing values
     *
     * @var array<string, array>
     */
    protected $defaultFields = [
        'getRawTopicIds' => [
            'pref' => 'topic_add_txt_mv',
            'alt' => 'topic_alt_txt_mv',
            'check' => 'topic',
        ],
        'getRawGeographicTopicIds' => [
            'pref' => 'geographic_add_txt_mv',
            'alt' => 'geographic_alt_txt_mv',
            'check' => 'geographic',
        ],
        'getCorporateAuthorIds' => [
            'pref' => '',
            'alt' => 'author_variant',
            'check' => 'author',
        ],
        'getAuthorIds' => [
            'pref' => 'author2',
            'alt' => 'author2_variant',
            'check' => 'author2',
        ],
    ];

    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        if (!($record instanceof \RecordManager\Base\Record\Ead3)) {
            return;
        }
        parent::enrich($sourceId, $record, $solrArray);
    }
}
