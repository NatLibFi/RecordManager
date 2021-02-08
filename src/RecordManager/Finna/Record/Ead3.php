<?php
/**
 * EAD 3 Record Class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2020.
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

use RecordManager\Base\Database\DatabaseInterface as Database;
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
    use AuthoritySupportTrait;

    const UNIT_ID_RELATORS = ['tekninen'];
    const GEOGRAPHIC_SUBJECT_RELATORS = ['aihe', 'alueellinen kattavuus'];
    const SUBJECT_RELATORS = ['aihe'];

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
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array
     */
    public function toSolrArray(Database $db = null)
    {
        $data = parent::toSolrArray($db);
        $doc = $this->doc;

        if ($unitDateRange = $this->getDaterange()) {
            $startDateUnknown = $unitDateRange['startDateUnknown'];
            $unitDateRange = $unitDateRange['date'];

            $data['search_daterange_mv'][] = $data['unit_daterange']
                = MetadataUtils::dateRangeToStr($unitDateRange);

            $data['main_date_str'] = MetadataUtils::extractYear($unitDateRange[0]);
            $data['main_date'] = $this->validateDate($unitDateRange[0]);

            if (!$startDateUnknown) {
                // When startDate is known, Append year range to title
                // (only years, not the full dates)
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
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }

        $data['source_str_mv'] = $data['institution'] ?? $this->source;
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
        $data['free_online_boolean']
            = isset($data['online_boolean'])
            && !isset($this->doc->accessrestrict);

        if ($this->doc->did->unitid) {
            $identifier = null;
            foreach ($this->doc->did->unitid as $i) {
                $identifier = (string)$i;
                if ($i->attributes()->label == 'Analoginen') {
                    break;
                }
            }
            if ($identifier) {
                $p = strpos($identifier, '/');
                $identifier = $p > 0
                    ? substr($identifier, $p + 1)
                    : $identifier;
                $data['identifier'] = $identifier;
            }
        }

        if (isset($doc->did->dimensions)) {
            // display measurements
            $data['measurements'] = (string)$doc->did->dimensions;
        }

        if (isset($doc->did->physdesc)) {
            $material = [];
            foreach ($doc->did->physdesc as $physdesc) {
                if (isset($physdesc->attributes()->label)) {
                    $material[] = (string)$physdesc . ' '
                        . $physdesc->attributes()->label;
                } else {
                    $material[] = (string)$physdesc;
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

        $corporateAuthorIds = $this->getCorporateAuthorIds();
        if (isset($doc->controlaccess->name)) {
            $data['author'] = [];
            $data['author_role'] = [];
            $data['author_variant'] = [];
            $data['author_facet'] = [];
            $author2Ids = $author2IdRoles = [];
            foreach ($doc->controlaccess->name as $name) {
                foreach ($name->part as $part) {
                    $id = $role = null;
                    $attr = $name->attributes();
                    if (isset($attr->relator)) {
                        $role = (string)$name->attributes()->relator;
                    }
                    if (isset($attr->identifier)) {
                        $id = (string)$name->attributes()->identifier;
                    }

                    switch ($part->attributes()->localtype) {
                    case 'Ensisijainen nimi':
                        $data['author'][] = (string)$part;
                        if (! isset($part->attributes()->lang)
                            || (string)$part->attributes()->lang === 'fin'
                        ) {
                            $data['author_facet'][] = (string)$part;
                        }
                        if ($id) {
                            $author2Ids[] = $id;
                            if ($role) {
                                $author2IdRoles[]
                                    = $this->formatAuthorIdWithRole($id, $role);
                            }
                        }
                        break;
                    case 'Varianttinimi':
                    case 'Vaihtoehtoinen nimi':
                    case 'Vanhentunut nimi':
                        $data['author_variant'][] = (string)$part;
                        if ($id) {
                            $author2Ids[] = $id;
                            if ($role) {
                                $author2IdRoles[]
                                    = $this->formatAuthorIdWithRole($id, $role);
                            }
                        }

                        break;
                    }
                }
            }

            $data['author2_id_str_mv']
                = $this->addNamespaceToAuthorityIds(
                    array_unique(array_merge($corporateAuthorIds, $author2Ids))
                );
            $data['author2_id_role_str_mv']
                = $this->addNamespaceToAuthorityIds($author2IdRoles);
        }

        if (isset($doc->index->index->indexentry)) {
            foreach ($doc->index->index->indexentry as $indexentry) {
                if (isset($indexentry->name->part)) {
                    $data['contents'][] = (string)$indexentry->name->part;
                }
            }
        }
        $data['format_ext_str_mv'] = $data['format'];

        $data['topic_id_str_mv'] = $this->getTopicURIs();
        $data['geographic_id_str_mv'] = $this->getGeographicTopicURIs();

        return $data;
    }

    /**
     * Get topic URIs.
     *
     * @return array
     */
    public function getTopicURIs()
    {
        return $this->getTopicTerms('subject', self::SUBJECT_RELATORS, true);
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
                if ((string)$attr->label === 'Tekninen' || !isset($attr->label)
                    && isset($attr->identifier)
                ) {
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
     * Return subtitle
     *
     * @return string
     */
    protected function getSubtitle()
    {
        $noSubtitleFormats = [
            $this->fondsType,
            $this->collectionType
        ];
        if (in_array($this->getFormat(), $noSubtitleFormats)) {
            return '';
        }

        return (string)$this->doc->did->unitid;
    }

    /**
     * Get date range.
     *
     * @return NULL|array
     */
    protected function getDaterange()
    {
        if (isset($this->doc->did->unitdatestructured)) {
            $date = $this->doc->did->unitdatestructured;
            if (isset($date->daterange)) {
                $range = $this->doc->did->unitdatestructured->daterange;
                if (isset($range->fromdate) && isset($range->todate)) {
                    $fromDates = $range->xpath('fromdate');
                    $toDates = $range->xpath('todate');

                    $from = reset($fromDates);
                    $to = end($toDates);

                    return $this->parseDateRange(
                        (string)$from . '/' . (string)$to
                    );
                }
            } elseif (isset($date->datesingle)) {
                $year = (string)$date->datesingle;
                return $this->parseDateRange("{$year}/{$year}");
            }
        } elseif (isset($this->doc->did->unitdate)) {
            foreach ($this->doc->did->unitdate as $unitdate) {
                $attributes = $unitdate->attributes();
                if ($attributes->label
                    && (string)$attributes->label === 'Ajallinen kattavuus'
                ) {
                    return $this->parseDateRange(
                        (string)$unitdate->attributes()->normal
                    );
                    break;
                }
            }
        }
        return null;
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
        if (!$input || $input == '-' || false === strpos($input, '/')) {
            return null;
        }

        $yearLimits = ['0000', '9999'];

        list($start, $end) = explode('/', $input);

        $parseDate = function (
            $date, $defaultYear = '0', $defaultMonth = '01', $defaultDay = '01',
            $hour = '00:00:00'
        ) {
            $unknownDate = false;

            // Set year/month/day to defaults
            $year = str_repeat($defaultYear, 4);
            $month = $defaultMonth;
            $day = $defaultDay;
            if (!in_array($date, ['open', 'unknown'])) {
                $parts = explode('-', $date);
                $year = str_replace('u', $defaultYear, $parts[0]);

                if (isset($parts[1]) && $parts[1] !== 'uu') {
                    $month = $parts[1];
                }

                if (isset($parts[2]) && $parts[2] !== 'uu') {
                    $day = $parts[2];
                }
            } else {
                $unknownDate = true;
            }

            if (null === $day) {
                // Set day to last day of month if default day was not given
                $day = date('t', strtotime("{$year}-{$month}"));
            }

            $date = sprintf(
                '%04d-%02d-%02dT%sZ',
                $year, $month, $day, $hour
            );

            try {
                $d = new \DateTime($date);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Ead3',
                    "Failed to parse date $date, record {$this->source}."
                    . $this->getID()
                );
                $this->storeWarning('invalid date');
                return null;
            }

            return ['date' => $date, 'unknown' => $unknownDate];
        };

        if (null === ($startDate = $parseDate($start))) {
            $this->logger->logDebug(
                'Ead3',
                "Failed to parse startDate $start, record {$this->source}."
                . $this->getID()
            );
            $this->storeWarning('invalid start date');
            return null;
        }

        if (null === ($endDate = $parseDate($end, '9', '12', null, '23:59:59'))) {
            $this->logger->logDebug(
                'Ead3',
                "Failed to parse endDate $end, record {$this->source}."
                . $this->getID()
            );
            $this->storeWarning('invalid end date');
            return null;
        }

        $startDateUnknown = $startDate['unknown'];

        $startDate = $startDate['date'];
        $endDate = $endDate['date'];

        if (strtotime($startDate) > strtotime($endDate)) {
            $this->logger->logDebug(
                'Ead3',
                "Invalid date range {$startDate} - {$endDate}, record " .
                "{$this->source}." . $this->getID()
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [
            'date' => [$startDate, $endDate],
            'startDateUnknown' => $startDateUnknown
        ];
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
     * Helper function for getting controlaccess access elements filtered
     * by relator-attribute.
     *
     * @param string $nodeName Name of node that contains the topic terms
     * @param array  $relators Accepted relator-attribute values when relator
     *                         is defined.
     * @param bool   $uri      Return URI's instead of labels?
     *
     * @return array
     */
    protected function getTopicTermsFromNodeWithRelators(
        $nodeName, $relators, $uri = false
    ) {
        $result = [];
        if (!isset($this->doc->controlaccess->{$nodeName})) {
            return $result;
        }

        foreach ($this->doc->controlaccess->{$nodeName} as $node) {
            $attr = $node->attributes();
            $value = trim((string)$node->part);
            if (!$attr->relator || in_array((string)$attr->relator, $relators)
                && $value
            ) {
                $result[] = $uri ? $attr->identifier : $value;
            }
        }
        return $result;
    }

    /**
     * Get topics
     *
     * @return array
     */
    protected function getTopics()
    {
        return $this->getTopicTermsFromNodeWithRelators(
            'subject', self::SUBJECT_RELATORS
        );
    }

    /**
     * Get geographic topics
     *
     * @return array
     */
    protected function getGeographicTopics()
    {
        return $this->getTopicTermsFromNodeWithRelators(
            'geogname', self::GEOGRAPHIC_SUBJECT_RELATORS
        );
    }

    /**
     * Get geographic topics URIs
     *
     * @return array
     */
    protected function getGeographicTopicURIs()
    {
        return $this->getTopicTermsFromNodeWithRelators(
            'geogname', self::GEOGRAPHIC_SUBJECT_RELATORS, true
        );
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        $level1 = $level2 = null;

        $docLevel = (string)$this->doc->attributes()->level;
        $level1 = $docLevel === 'fonds' ? 'Document' : null;

        if (!isset($this->doc->controlaccess->genreform)) {
            return $docLevel;
        }

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

        return $level2 ? "$level1/$level2" : $level1;
    }

    /**
     * Get institution
     *
     * @return string
     */
    protected function getInstitution()
    {
        if (isset($this->doc->did->repository)) {
            foreach ($this->doc->did->repository as $repo) {
                $attr = $repo->attributes();
                if (! isset($attr->encodinganalog)
                    || 'ahaa:AI42' !== (string)$attr->encodinganalog
                ) {
                    continue;
                }
                foreach ($repo->corpname as $node) {
                    $attr = $node->attributes();
                    if (! isset($attr->identifier)) {
                        continue;
                    }
                    return (string)$attr->identifier;
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
                foreach ($el->p as $p) {
                    $desc[] = trim(html_entity_decode((string)$p));
                }
            }
            if (!empty($desc)) {
                return implode('   /   ', $desc);
            }
        }
        return '';
    }
}
