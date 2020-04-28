<?php
/**
 * EAD 3 Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2019.
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
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * EAD 3 Record Class
 *
 * EAD 3 records with Finna specific functionality
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Ead3 extends \RecordManager\Base\Record\Ead3
{
    /**
     * Archive fonds format
     *
     * @return string
     */
    protected $fondsType = 'Document/Arkisto';

    /**
     * Archive collection format
     *
     * @return string
     */
    protected $collectionType = 'Document/Kokoelma';

    /**
     * Undefined format type
     *
     * @return string
     */
    protected $undefinedType = 'Määrittämätön';

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
        $doc = $this->doc;

        $unitDateRange = $this->parseDateRange((string)$doc->did->unitdate);
        $data['search_daterange_mv'] = $data['unit_daterange']
            = MetadataUtils::dateRangeToStr($unitDateRange);

        if ($unitDateRange) {
            $data['main_date_str'] = MetadataUtils::extractYear($unitDateRange[0]);
            $data['main_date'] = $this->validateDate($unitDateRange[0]);
            // Append year range to title (only years, not the full dates)
            $startYear = MetadataUtils::extractYear($unitDateRange[0]);
            $endYear = MetadataUtils::extractYear($unitDateRange[1]);
            $yearRange = '';
            if ($startYear != '-9999') {
                $yearRange = $startYear;
            }
            if ($endYear != $startYear) {
                $yearRange .= '-';
                if ($endYear != '9999') {
                    $yearRange .= $endYear;
                }
            }
            if ($yearRange) {
                $len = strlen($yearRange);
                foreach (
                    ['title_full', 'title_sort', 'title', 'title_short']
                    as $field
                ) {
                    if (substr($data[$field], -$len) != $yearRange
                        && substr($data[$field], -$len - 2) != "($yearRange)"
                    ) {
                        $data[$field] .= " ($yearRange)";
                    }
                }
            }
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }

        $data['source_str_mv'] = isset($data['institution'])
            ? $data['institution'] : $this->source;
        $data['datasource_str_mv'] = $this->source;

        // Digitized?
        if (isset($this->doc->did->daoset->dao)) {
            foreach ($this->doc->did->daoset->dao as $dao) {
                if ($dao->attributes()->{'href'}) {
                    $data['online_boolean'] = true;
                    // This is sort of special. Make sure to use source instead
                    // of datasource.
                    $data['online_str_mv'] = $data['source_str_mv'];
                    break;
                }
            }
        }

        if ($this->doc->did->unitid) {
            foreach ($this->doc->did->unitid as $i) {
                if ($i->attributes()->label == 'Analoginen') {
                    $idstr = (string) $i;
                    $p = strpos($idstr, '/');
                    $analogID = $p > 0
                        ? substr($idstr, $p + 1)
                        : $idstr;

                    $data['identifier'] = $analogID;
                }
            }
        }

        if (isset($doc->did->dimensions)) {
            // display measurements
            $data['measurements'] = (string)$doc->did->dimensions;
        }

        if (isset($doc->did->physdesc)) {
            foreach ($doc->did->physdesc as $physdesc) {
                if (isset($physdesc->attributes()->label)) {
                    $material[] = (string) $physdesc . ' '
                        . $physdesc->attributes()->label;
                } else {
                    $material[] = (string) $physdesc;
                }
            }
            $data['material'] = $material;
        }

        if (isset($doc->did->userestrict->p)) {
            $data['rights'] = (string)$doc->did->userestrict->p;
        } elseif (isset($doc->did->accessrestrict->p)) {
            $data['rights'] = (string)$doc->did->accessrestrict->p;
        }

        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        if (isset($doc->controlaccess->name)) {
            $data['author'] = [];
            $data['author_role'] = [];
            $data['author_variant'] = [];
            $data['author_facet'] = [];
            foreach ($doc->controlaccess->name as $name) {
                foreach ($name->part as $part) {
                    $role = null;
                    if (isset($name->attributes()->relator)) {
                        // TODO translate role label
                        $role = (string)$name->attributes()->relator;
                    }

                    switch ($part->attributes()->localtype) {
                    case 'Ensisijainen nimi':
                        $data['author'][]
                            = $this->getNameWithRole((string)$part, $role);
                        if (! isset($part->attributes()->lang)
                            || (string)$part->attributes()->lang === 'fin'
                        ) {
                            $data['author_facet'][] = (string)$part;
                        }
                        break;
                    case 'Varianttinimi':
                    case 'Vaihtoehtoinen nimi':
                    case 'Vanhentunut nimi':
                        $data['author_variant'][]
                            = $this->getNameWithRole((string)$part, $role);
                        break;
                    }
                }
            }
        }

        if (isset($doc->index->index->indexentry)) {
            foreach ($doc->index->index->indexentry as $indexentry) {
                if (isset($indexentry->name->part)) {
                    $data['contents'][] = (string) $indexentry->name->part;
                }
            }
        }

        $data['author_id_str_mv'] = array_merge(
            $this->getAuthorIds(),
            $this->getCorporateAuthorIds()
        );

        $data['format_ext_str_mv'] = $data['format'];

        $data['topic_uri_str_mv'] = $this->getTopicURIs();

        return $data;
    }

    /**
     * Return subtitle
     *
     * @return string
     */
    protected function getSubtitle()
    {
        // TODO return series id when available in metadata
        return '';
    }

    /**
     * Get unit id
     *
     * @return string
     */
    protected function getUnitId()
    {
        if (isset($this->doc->did->unitid)) {
            foreach ($this->doc->did->unitid as $i) {
                $attr = $i->attributes();
                if ($attr->label == 'Tekninen' && isset($attr->identifier)) {
                    return (string)$attr->identifier;
                }
            }
        }
        return '';
    }

    /**
     * Get authors
     *
     * @return array
     */
    protected function getAuthors()
    {
        $result = [];
        if (!isset($this->doc->relations->relation)) {
            return $result;
        }

        foreach ($this->doc->relations->relation as $relation) {
            $type = (string)$relation->attributes()->relationtype;
            if ('cpfrelation' !== $type) {
                continue;
            }
            $role = (string)$relation->attributes()->arcrole;
            switch ($role) {
            case '':
            case 'http://www.rdaregistry.info/Elements/u/P60672':
            case 'http://www.rdaregistry.info/Elements/u/P60434':
                $role = 'aut';
                break;
            case 'http://www.rdaregistry.info/Elements/u/P60429':
                $role = 'pht';
                break;
            default:
                $role = '';
            }
            if ('' === $role) {
                continue;
            }
            $result[] = trim((string)$relation->relationentry);
        }
        return $result;
    }

    /**
     * Get author identifiers
     *
     * @return array
     */
    protected function getAuthorIds()
    {
        $result = [];
        if (!isset($this->doc->relations->relation)) {
            return $result;
        }

        foreach ($this->doc->relations->relation as $relation) {
            $type = (string)$relation->attributes()->relationtype;
            if ('cpfrelation' !== $type) {
                continue;
            }
            $role = (string)$relation->attributes()->arcrole;
            switch ($role) {
            case '':
            case 'http://www.rdaregistry.info/Elements/u/P60672':
            case 'http://www.rdaregistry.info/Elements/u/P60434':
                $role = 'aut';
                break;
            case 'http://www.rdaregistry.info/Elements/u/P60429':
                $role = 'pht';
                break;
            default:
                $role = '';
            }
            if ('' === $role) {
                continue;
            }
            $result[] = (string)$relation->attributes()->href;
        }
        return $result;
    }

    /**
     * Get corporate author identifiers
     *
     * @return array
     */
    protected function getCorporateAuthorIds()
    {
        $result = [];
        if (isset($this->doc->did->origination->name)) {
            foreach ($this->doc->did->origination->name as $name) {
                if (isset($name->attributes()->identifier)) {
                    $result[] = (string)$name->attributes()->identifier;
                }
            }
        }
        return $result;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        if (isset($this->doc->userestrict->p)) {
            foreach ($this->doc->userestrict->p as $restrict) {
                if (strstr((string)$restrict, 'No known copyright restrictions')) {
                    return [];
                }
            }
        }

        if (isset($this->doc->accessrestrict->p)) {
            foreach ($this->doc->accessrestrict->p as $restrict) {
                if (strstr((string)$restrict, 'No known copyright restrictions')) {
                    return [];
                }
            }
        }
        return ['restricted'];
    }

    /**
     * Parse date range string
     *
     * @param string $input Date range
     *
     * @return NULL|array
     */
    protected function parseDateRange($input)
    {
        if (!$input || $input == '-') {
            return null;
        }

        if (true
            && preg_match(
                '/(\d\d?).(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d?).(\d\d\d\d)/',
                $input,
                $matches
            ) > 0
        ) {
            $startYear = $matches[3];
            $startMonth = sprintf('%02d', $matches[2]);
            $startDay = sprintf('%02d', $matches[1]);
            $startDate = $startYear . '-' . $startMonth . '-' . $startDay
                . 'T00:00:00Z';
            $endYear = $matches[6];
            $endMonth = sprintf('%02d', $matches[5]);
            $endDay = sprintf('%02d', $matches[4]);
            $endDate = $endYear . '-' . $endMonth . '-' . $endDay . 'T23:59:59Z';
        } elseif (true
            && preg_match(
                '/(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d\d\d)/', $input, $matches
            ) > 0
        ) {
            $startYear = $matches[2];
            $startMonth = sprintf('%02d', $matches[1]);
            $startDay = '01';
            $startDate = $startYear . '-' . $startMonth . '-' . $startDay
                . 'T00:00:00Z';
            $endYear = $matches[4];
            $endMonth = sprintf('%02d', $matches[3]);
            $endDate = $endYear . '-' . $endMonth . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (Exception $e) {
                $this->logger->log(
                    'Ead3',
                    "Failed to parse date $endDate, record {$this->source}."
                    . $this->getID(),
                    Logger::DEBUG
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d\d\d) ?- ?(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?).(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[3];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?)\.(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (Exception $e) {
                $this->logger->log(
                    'Ead3',
                    "Failed to parse date $endDate, record {$this->source}."
                    . $this->getID(),
                    Logger::DEBUG
                );
                $this->storeWarning('invalid end date');
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

        if (strtotime($startDate) > strtotime($endDate)) {
            $this->logger->log(
                'Ead3',
                "Invalid date range {$startDate} - {$endDate}, record " .
                "{$this->source}." . $this->getID(),
                Logger::DEBUG
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [$startDate, $endDate];
    }

    /**
     * Return author name with role.
     *
     * @param string $name Name
     * @param string $role Role
     *
     * @return string
     */
    protected function getNameWithRole($name, $role = null)
    {
        return $role
            ? "$name " . strtolower($role)
            : $name;
    }

    /**
     * Get topics URIs
     *
     * @return array
     */
    public function getTopicURIs()
    {
        $result = [];
        if (!isset($this->doc->controlaccess->subject)) {
            return $result;
        }
        foreach ($this->doc->controlaccess->subject as $subject) {
            $attr = $subject->attributes();
            if (isset($attr->relator)
                && (string)$attr->relator === 'aihe'
                && isset($attr->identifier)
                && ('' !== ($id = trim((string)$attr->identifier)))
            ) {
                $result[] = $id;
            }
        }
        return array_unique($result);
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        $level1 = $level2 = null;

        if ((string)$this->doc->attributes()->level === 'fonds') {
            $level1 = 'Document';
        }

        if (isset($this->doc->controlaccess->genreform)) {
            foreach ($this->doc->controlaccess->genreform as $genreform) {
                $format = null;
                foreach ($genreform->part as $part) {
                    $attributes = $part->attributes();
                    if (isset($attributes->lang)
                        && (string)$attributes->lang === 'fin'
                    ) {
                        $format = (string)$part;
                        break;
                    }
                }

                if (!$format) {
                    continue;
                }

                $attr = $genreform->attributes();
                if (isset($attr->encodinganalog)) {
                    $type = (string)$attr->encodinganalog;
                    if ($type === 'ahaa:AI08') {
                        if ($level1 === null) {
                            $level1 = $format;
                        } else {
                            $level2 = $format;
                        }
                    } elseif ($type === 'ahaa:AI57') {
                        $level2 = $format;
                    }
                }
            }
        }

        return $level2 ? "$level1/$level2" : $level1;
    }

    /**
     * Get topics
     *
     * @return array
     */
    protected function getTopics()
    {
        $result = [];
        if (!isset($this->doc->controlaccess->subject)) {
            return $result;
        }
        foreach ($this->doc->controlaccess->subject as $subject) {
            $attr = $subject->attributes();
            if (isset($attr->relator)
                && (string)$attr->relator === 'aihe'
                && isset($subject->part)
                && ('' !== ($subject = trim((string)$subject->part)))
            ) {
                $result[] = $subject;
            }
        }
        return $result;
    }

    /**
     * Get institution
     *
     * @return string
     */
    protected function getInstitution()
    {
        if (isset($this->doc->did->repository->corpname)) {
            foreach ($this->doc->did->repository->corpname as $node) {
                if (! isset($node->part)) {
                    continue;
                }
                foreach ($node->part->attributes() as $key => $val) {
                    if ($key === 'lang' && (string)$val === 'fin') {
                        return (string)$node->part;
                    }
                }
            }
        }
        return '';
    }

    /**
     *  Get description
     *
     * @return string
     */
    protected function getDescription()
    {
        if (!empty($this->doc->scopecontent)) {
            $desc = [];
            foreach ($this->doc->scopecontent as $el) {
                if (isset($el->attributes()->encodinganalog)) {
                    continue;
                }
                if (! isset($el->head) || (string)$el->head !== 'Tietosisältö') {
                    continue;
                }
                foreach ($el->p as $p) {
                    $desc[] = trim(html_entity_decode((string)$el->p));
                }
            }
            if (!empty($desc)) {
                return implode('   /   ', $desc);
            }
        }
        return '';
    }
}
