<?php
/**
 * NdlEadRecord Class
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

require_once 'EadRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlEadRecord Class
 *
 * EadRecord with NDL specific functionality
 * 
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlEadRecord extends EadRecord
{
    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $doc = $this->doc;
        
        $unitdate = (string)$doc->did->unitdate;
        if ($unitdate && $unitdate != '-') {
            $dates = explode('-', $unitdate);
            if (isset($dates[1]) && $dates[1]) {
                if ($dates[0]) {
                    $unitdate = $dates[0] . '-01-01T00:00:00Z,' . $dates[1] . '-12-31T23:59:59Z';
                } else {
                    $unitdate = '0000-01-01T00:00:00Z,' . $dates[1] . '-12-31T23:59:59Z';
                }
            } else {
                if (strpos($unitdate, '-') > 0) {
                    $unitdate = $dates[0] . '-01-01T00:00:00Z,9999-12-31T23:59:59Z';
                } else {
                    $unitdate = $dates[0] . '-01-01T00:00:00Z,' . $dates[0] . '-12-31T23:59:59Z';
                }
            }
            $data['unit_daterange'] = $unitdate; 
            $data['main_date_str'] = MetadataUtils::extractYear($dates[0]);
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }
        
        $data['source_str_mv'] = isset($data['institution']) ? $data['institution'] : $this->source;

        // Digitized?
        if ($doc->did->daogrp) {
            $data['format'] = 'digitized_' . $data['format'];
            if ($this->doc->did->daogrp->daoloc) {
                foreach ($this->doc->did->daogrp->daoloc as $daoloc) {
                    if ($daoloc->attributes()->{'href'}) {
                        $data['online_boolean'] = true;
                        break;
                    }
                }
            }
        }
        
        return $data;
    }
}

