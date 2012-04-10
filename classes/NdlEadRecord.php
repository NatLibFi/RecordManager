<?php
/**
 * NdlEadRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala, The National Library of Finland 2012
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
 */

require_once 'EadRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlEadRecord Class
 *
 * EadRecord with NDL specific functionality
 */
class NdlEadRecord extends EadRecord
{
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $doc = $this->_doc;
        
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
                    $unitdate = $dates[0] . '-01-01T00:00:00Z';
                }
            }
            $data['unit_daterange'] = $unitdate; 
        }

        return $data;
    }
}

