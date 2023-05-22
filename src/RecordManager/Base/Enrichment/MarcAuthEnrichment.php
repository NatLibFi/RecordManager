<?php

/**
 * Enrich Marc biblio records with authority record data.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Enrichment;

/**
 * Enrich Marc biblio records with authority record data.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcAuthEnrichment extends AuthEnrichment
{
    /**
     * Enrich the record and return any additions in solrArray
     *
     * @param string $sourceId  Source ID
     * @param object $record    Metadata Record
     * @param array  $solrArray Metadata to be sent to Solr
     *
     * @throws \Exception
     * @return void
     */
    public function enrich($sourceId, $record, &$solrArray)
    {
        if (!($record instanceof \RecordManager\Base\Record\Marc)) {
            return;
        }

        foreach ($solrArray['author2_id_str_mv'] ?? [] as $id) {
            $this->enrichField(
                $sourceId,
                $record,
                $solrArray,
                $id,
                'author_variant',
                'author_variant',
                true
            );
        }
    }
}
