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
        
        $unitDateRange = $this->parseDateRange((string)$doc->did->unitdate);
        $data['search_sdaterange_mv'] = $data['unit_sdaterange'] = MetadataUtils::convertDateRange($unitDateRange);
        if ($unitDateRange) {
            $data['main_date_str'] = MetadataUtils::extractYear($unitDateRange[0]);
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }
        
        $data['source_str_mv'] = isset($data['institution']) ? $data['institution'] : $this->source;
        $data['datasource_str_mv'] = $this->source;
        
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
    
    /**
     * Parse date range string
     * 
     * @param string $input Date range
     * 
     * @return NULL|string
     */
    protected function parseDateRange($input)
    {
        if (!$input || $input == '-') {
            return null;
        }

        if (preg_match('/(\d\d\d\d) ?- ?(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d?).(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $startYear = $matches[3];
            $startMonth = sprintf('%02d', $matches[2]);
            $startDay = sprintf('%02d', $matches[1]);
            $startDate = $startYear . '-' . $startMonth . '-' .  $startDay . 'T00:00:00Z';
            $endYear = $matches[6];
            $endMonth =  sprintf('%02d', $matches[5]);
            $endDay = sprintf('%02d', $matches[4]);
            $endDate = $endYear . '-' . $endMonth . '-' .  $endDay . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $startYear = $matches[2];
            $startMonth = sprintf('%02d', $matches[1]);
            $startDay = '01';
            $startDate = $startYear . '-' . $startMonth . '-' .  $startDay . 'T00:00:00Z';
            $endYear = $matches[4];
            $endMonth =  sprintf('%02d', $matches[3]);
            $endDate = $endYear . '-' . $endMonth . '-01';
            try {
                $d = new DateTime($endDate);
            } catch (Exception $e) {
                global $logger;
                $logger->log('NdlEadRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month =  sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' .  $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' .  $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?)\.(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month =  sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new DateTime($endDate);
            } catch (Exception $e) {
                global $logger;
                $logger->log('NdlEadRecord', "Failed to parse date $endDate, record {$this->source}." . $this->getID(), Logger::ERROR);
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d+) ?- ?(\d+)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $startDate = $year . '-01-01T00:00:00Z';
            $endDate = $year . '-12-31T23:59:59Z';
        } else {
            return null;
        }
        return array($startDate, $endDate);
    }
}

