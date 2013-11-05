<?php
/**
 * NdlQdcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2013
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

require_once 'QdcRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlQdcRecord Class
 *
 * QdcRecord with NDL specific functionality
 * 
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlQdcRecord extends QdcRecord
{
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        
        // Nonstandard author fields
        $authors = $this->getValues('author');
        if ($authors) {
            $data['author'] = array_shift($authors);
            if (isset($data['author2'])) {
                $data['author2'] = array_merge($authors, $data['author2']);
            } else {
                $data['author2'] = $authors;
            }
        }
                
        if (isset($data['publishDate'])) {
            $data['main_date_str'] = MetadataUtils::extractYear($data['publishDate']);
        }
        
        $data['publication_sdaterange'] = $this->getPublicationDateRange();
        if ($data['publication_sdaterange']) {
            $data['search_sdaterange_mv'][] = $data['publication_sdaterange'];
        }
        
        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;
        
        return $data;
    }

    /**
     * Return publication year/date range
     *
     * @return string
     * @access protected
     */
    protected function getPublicationDateRange()
    {
        $year = $this->getPublicationYear();
        if ($year) {
            $startDate = "$year-01-01T00:00:00Z";
            $endDate = "$year-12-31T23:59:59Z";
            return MetadataUtils::convertDateRange(array($startDate, $endDate));
        }
        return '';
    }   
}
