<?php
/**
 * NdlMarcRecord Class
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'MarcRecord.php';
require_once 'MetadataUtils.php';

/**
 * NdlMarcRecord Class
 *
 * MarcRecord with NDL specific functionality
 * 
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class NdlMarcRecord extends MarcRecord
{
    /**
     * Return record linking ID (typically same as ID) used for links
     * between records in the data source
     *
     * @return string
     */
    public function getLinkingID()
    {
        global $configArray;
        if (isset($configArray['Local']['linking_id_003']) && in_array($this->source, $configArray['Local']['linking_id_003'])) {
            $source = $this->getField('003');
            if ($source) {
                return "($source)" . $this->getID();
            }
        }
        return $this->getID();
    }

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        if (isset($data['publishDate'])) {
            $data['main_date_str'] = MetadataUtils::extractYear($data['publishDate']);
        }
        
        // Location coordinates
        $field = $this->getField('034');
        if ($field) {
            $westOrig = $this->getSubfield($field, 'd');
            $eastOrig = $this->getSubfield($field, 'e');
            $northOrig = $this->getSubfield($field, 'f');
            $southOrig = $this->getSubfield($field, 'g');
            $west = MetadataUtils::coordinateToDecimal($westOrig);
            $east = MetadataUtils::coordinateToDecimal($eastOrig);
            $north = MetadataUtils::coordinateToDecimal($northOrig);
            $south = MetadataUtils::coordinateToDecimal($southOrig);

            if (!is_nan($west) && !is_nan($north)) {
                if (($west < -180 || $west > 180) || ($north < -90 || $north > 90)) {
                    global $logger;
                    $logger->log('NdlMarcRecord', "Discarding invalid coordinates $west,$north decoded from w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, record {$this->source}." . $this->getID(), Logger::WARNING);
                } else {
                    if (!is_nan($east) && !is_nan($south)) {
                        if (($east < -180 || $east > 180) || ($south < -90 || $south > 90)) {
                            global $logger;
                            $logger->log('NdlMarcRecord', "Discarding invalid coordinates $east,$south decoded from w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, record {$this->source}." . $this->getID(), Logger::WARNING);
                        } else {
                            // Try to cope with weird coordinate order
                            if ($north > $south) {
                                $data['location_geo'] = "$west $south $east $north";
                            } else {
                                $data['location_geo'] = "$west $north $east $south";
                            }
                        }
                    } else {
                        $data['location_geo'] = "$west $north";
                    }
                }
            }
        }
        
        return $data;
    }
    
    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     * @access public
     */
    public function getFormat()
    {
        // Custom predefined type in 977a
        $field977a = $this->getFieldSubfields('977a');
        if ($field977a) {
            return $field977a;
        }
        return parent::getFormat();
    }
}
