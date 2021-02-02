<?php
/**
 * EAC-CPF Record Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2018.
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
namespace RecordManager\Finna\Record;

/**
 * EAC-CPF Record Class
 *
 * This is a class for processing EAC-CPF records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Eaccpf extends \RecordManager\Base\Record\Eaccpf
{
    /**
     * Return fields to be indexed in Solr
     *
     * @param \RecordManager\Base\Database\Database $db Database connection. Omit to
     *                                                  avoid database lookups for
     *                                                  related records.
     *
     * @return array
     */
    public function toSolrArray(\RecordManager\Base\Database\Database $db = null)
    {
        $data = parent::toSolrArray($db);

        $data['record_format'] = 'eaccpf';

        $data['agency_str_mv'] = $this->getAgencyName();
        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        return $data;
    }

    /**
     * Get use for headings
     *
     * @return array
     */
    protected function getUseForHeadings()
    {
        if (!isset($this->doc->cpfDescription->identity->nameEntryParallel)) {
            return [];
        }
        foreach ($this->doc->cpfDescription->identity->nameEntryParallel as $entry) {
            if (!isset($entry->nameEntry->part)) {
                continue;
            }
            $name1 = '';
            $name2 = '';
            foreach ($entry->nameEntry->part as $part) {
                $type = (string)$part->attributes()->localType;
                if ('TONI1' === $type) {
                    $name1 = (string)$part;
                } elseif ('TONI4' === $type) {
                    $name2 = (string)$part;
                }
            }
            $s = trim("$name1 $name2");
            if ($s) {
                $result[] = $s;
            }
        }
        return $result;
    }
}
