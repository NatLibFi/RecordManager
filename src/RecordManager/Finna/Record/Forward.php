<?php
/**
 * Forward record class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2016-2019.
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

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Forward record class
 *
 * This is a class for processing records in the Forward format (EN 15907).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Forward extends \RecordManager\Base\Record\Forward
{
    /**
     * Default primary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $primaryAuthorRelators = [
        'd02', 'a00', 'a01', 'a02', 'a03', 'a05', 'a06', 'a08', 'a09', 'a10', 'a11',
        'a12', 'a13', 'a31', 'a38', 'a43', 'a50', 'a99',
        // Some of these are from Marc
        'adp', 'aud', 'chr', 'cmm', 'cmp', 'cre', 'dub', 'inv'
    ];

    /**
     * Default secondary author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $secondaryAuthorRelators = [
        'd01', 'd99', 'e01', 'e02', 'e03', 'e04', 'e05', 'e06', 'e08',
        'f01', 'f02', 'f99', 'ctb', 'exp', 'rce', 'wst', 'sds', 'oth',
        // These are copied from Marc
        'act', 'anm', 'ann', 'arr', 'acp', 'ar', 'ard', 'aft', 'aud', 'aui', 'aus',
        'bjd', 'bpd', 'cll', 'ctg', 'chr', 'cng', 'clb', 'clr', 'cmm', 'cwt', 'com',
        'cpl', 'cpt', 'cpe', 'ccp', 'cnd', 'cos', 'cot', 'coe', 'cts', 'ctt', 'cte',
        'ctb', 'crp', 'cst', 'cov', 'cur', 'dnc', 'dtc', 'dto', 'dfd', 'dft', 'dfe',
        'dln', 'dpc', 'dsr', 'drt', 'dis', 'drm', 'edt', 'elt', 'egr', 'etr', 'fac',
        'fld', 'flm', 'frg', 'ilu', 'ill', 'ins', 'itr', 'ivr', 'ldr', 'lsa', 'led',
        'lil', 'lit', 'lie', 'lel', 'let', 'lee', 'lbt', 'lgd', 'ltg', 'lyr', 'mrb',
        'mte', 'msd', 'mus', 'nrt', 'opn', 'org', 'pta', 'pth', 'prf', 'pht', 'ptf',
        'ptt', 'pte', 'prt', 'pop', 'prm', 'pro', 'pmn', 'prd', 'prg', 'pdr', 'pbd',
        'ppt', 'ren', 'rpt', 'rth', 'rtm', 'res', 'rsp', 'rst', 'rse', 'rpy', 'rsg',
        'rev', 'rbr', 'sce', 'sad', 'scr', 'scl', 'spy', 'std', 'sng', 'sds', 'spk',
        'stm', 'str', 'stl', 'sht', 'ths', 'trl', 'tyd', 'tyg', 'vdg', 'voc', 'wde',
        'wdc', 'wam'
    ];

    /**
     * Default corporate author relator codes, may be overridden in configuration.
     *
     * @var array
     */
    protected $corporateAuthorRelators = [
        'e10', 'dst', 'prn', 'fnd', 'lbr'
    ];

    /**
     * Relator to RDA role mapping.
     *
     * @var array
     */
    protected $roleMap = [
        'A00' => 'oth',
        'A03' => 'aus',
        'A06' => 'cmp',
        'A50' => 'aud',
        'A99' => 'oth',
        'D01' => 'fmp',
        'D02' => 'drt',
        'E01' => 'act',
        'E10' => 'pro',
        'F01' => 'cng',
        'F02' => 'flm'
    ];

    /**
     * ELONET role to RDA role mapping.
     *
     * @var array
     */
    protected $elonetRoleMap = [
        'dialogi' => 'aud',
        'lavastus' => 'std',
        'lavastaja' => 'std',
        'puvustus' => 'cst',
        'tuotannon suunnittelu' => 'prs',
        'tuotantopäällikkö' => 'pmn',
        'muusikko' => 'mus',
        'selostaja' => 'spk',
        'valokuvaaja' => 'pht',
        'valonmääritys' => 'lgd',
        'vastaava tuottaja' => 'pro',
        'äänitys' => 'rce'
    ];

    /**
     * Primary language to use
     *
     * @var string
     */
    protected $primaryLanguage = 'fi';

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();

        if (isset($data['publishDate'])) {
            $year = MetadataUtils::extractYear($data['publishDate']);
            $data['main_date_str'] = $year;
            $data['main_date'] = $this->validateDate("$year-01-01T00:00:00Z");
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = metadataUtils::dateRangeToStr(
                    ["$year-01-01T00:00:00Z", "$year-12-31T23:59:59Z"]
                );
        }

        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = $this->source;

        if ($urls = $this->getOnlineUrls()) {
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            // Mark everything free until we know better
            $data['free_online_boolean'] = true;
            $data['free_online_str_mv'] = $this->source;
            foreach ($urls as $url) {
                $data['online_urls_str_mv'][] = json_encode($url);
            }
        }

        $data['author_facet'] = array_merge(
            isset($data['author']) ? (array)$data['author'] : [],
            isset($data['author2']) ? (array)$data['author2'] : [],
            isset($data['author_corporate']) ? (array)$data['author_corporate'] : []
        );

        $data['format_ext_str_mv'] = (array)$data['format'];
        if (!empty($data['thumbnail'])) {
            $data['format_ext_str_mv'][] = 'Image';
        }

        return $data;
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        foreach ($this->getMainElement()->ProductionEvent as $event) {
            if (!isset($event->ProductionEventType)) {
                continue;
            }
            $type = $event->ProductionEventType;
            if (null !== ($type->attributes()->{'elokuva-laji1fin'})) {
                $laji = mb_strtolower(
                    (string)$type->attributes()->{'elokuva-laji1fin'}, 'UTF-8'
                );
                switch ($laji) {
                case 'lyhyt':
                    return 'VideoShort';
                case 'pitkä':
                    return 'VideoFeature';
                case 'kooste':
                    return 'VideoCompilation';
                }
            }
        }
        return 'Video';
    }

    /**
     * Return host record IDs for a component part
     *
     * @return array
     */
    public function getHostRecordIDs()
    {
        if (!($parentIdType = $this->getDriverParam('parentIdType', ''))) {
            return [];
        }
        foreach ($this->getMainElement()->HasAgent as $agent) {
            if ($agent->AgentIdentifier && $agent->AgentIdentifier->IDTypeName
                && $agent->AgentIdentifier->IDValue
                && (string)$agent->AgentIdentifier->IDTypeName == $parentIdType
            ) {
                return [
                    (string)$agent->AgentIdentifier->IDTypeName . '_'
                    . (string)$agent->AgentIdentifier->IDValue
                ];
            }
        }
        return [];
    }

    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     * @param MongoDate|null  $changeDate     Latest timestamp for the component part
     *                                        set
     *
     * @return int Count of records merged
     */
    public function mergeComponentParts($componentParts, &$changeDate)
    {
        $count = 0;
        $parts = [];
        foreach ($componentParts as $componentPart) {
            if (null === $changeDate || $changeDate < $componentPart['date']) {
                $changeDate = $componentPart['date'];
            }
            $data = MetadataUtils::getRecordData($componentPart, true);
            $xml = simplexml_load_string($data);
            foreach ($xml->children() as $child) {
                $parts[] = [
                    'xml' => $child,
                    'order' => empty($child->Title->PartDesignation->Value)
                        ? 0 : (int)$child->Title->PartDesignation->Value
                ];
            }
            ++$count;
        }
        usort(
            $parts,
            function ($a, $b) {
                return $a['order'] - $b['order'];
            }
        );
        foreach ($parts as $part) {
            $this->appendXml($this->doc, $part['xml']);
        }
        return $count;
    }

    /**
     * Get all authors or authors by relator codes.
     *
     * @param array $relators List of allowed relators, or an empty list
     * to return all authors.
     *
     * @return array
     */
    protected function getAuthorsByRelator($relators = [])
    {
        $result = ['names' => [], 'ids' => [], 'relators' => [], 'idRoles' => []];
        foreach ($this->getMainElement()->HasAgent as $agent) {
            $relator = $this->getRelator($agent);
            if (!empty($relators) && !in_array($relator, $relators)) {
                continue;
            }
            $name = (string)$agent->AgentName;
            if (empty($name)) {
                $attrName = 'elokuva-elokreditoimatontekija-nimi';
                $attrs = $agent->AgentName->attributes();
                if (!empty($attrs->{$attrName})) {
                    $name = (string)$attrs->{$attrName};
                }
            }
            $result['names'][] = $name;
            $id = (string)$agent->AgentIdentifier->IDTypeName . ':'
                . (string)$agent->AgentIdentifier->IDValue;
            if ($id != ':') {
                $result['ids'][] = $id;
                $result['idRoles'][]
                    = $this->formatAuthorIdWithRole($id, $relator);

            }
            $result['relators'][] = $relator;
        }

        return $result;
    }

    /**
     * Return genres
     *
     * @return array
     */
    protected function getGenres()
    {
        $result[] = $this->getProductionEventAttribute('elokuva-genre');

        foreach ($this->getMainElement()->ProductionEvent as $event) {
            if (null !== ($event->elokuva_laji2fin)) {
                $parts = explode(',', $event->elokuva_laji2fin);

                foreach ($parts as $part) {
                        $result[] = trim($part);
                }
            }
        }

        return $result;
    }

    /**
     * Return publishers
     *
     * @return array
     */
    protected function getPublishers()
    {
        $result = [];
        foreach ($this->getMainElement()->HasAgent as $agent) {
            $attributes = $agent->Activity->attributes();
            if (!empty($attributes->{'elokuva-elotuotantoyhtio'})) {
                $result[] = (string)$agent->AgentName;
            }
        }
        return $result;
    }

    /**
     * Return publication year/date range
     *
     * @return array|null
     */
    protected function getPublicationDateRange()
    {
        $year = $this->getPublicationYear();
        if ($year) {
            $startDate = "$year-01-01T00:00:00Z";
            $endDate = "$year-12-31T23:59:59Z";
            return [$startDate, $endDate];
        }
        return null;
    }

    /**
     * Return a production event attribute
     *
     * @param string $attribute Attribute name
     *
     * @return string
     */
    protected function getProductionEventAttribute($attribute)
    {
        foreach ($this->getMainElement()->ProductionEvent as $event) {
            $attributes = $event->ProductionEventType->attributes();
            if (!empty($attributes{$attribute})) {
                return (string)$attributes{$attribute};
            }
        }
        return '';
    }

    /**
     * Get relator code for the agent
     *
     * @param SimpleXMLElement $agent Agent
     *
     * @return string
     */
    protected function getRelator($agent)
    {
        if (empty($agent->Activity)) {
            return '';
        }
        $activity = $agent->Activity;
        $relator = MetadataUtils::normalizeRelator((string)$activity);
        if (in_array($relator, ['a00', 'a08', 'a99', 'd99', 'e04', 'e99'])) {
            $relator = null;
            foreach (
                ['finna-activity-text', 'tehtava', 'elokuva-elotekija-tehtava']
                as $field
            ) {
                if (!empty($activity->attributes()->{$field})) {
                    $relator = (string)$activity->attributes()->{$field};
                    break;
                }
            }
            if ($relator) {
                $relator = $this->elonetRoleMap[$relator] ?? $relator;
            }
        } else {
            $relator = $this->roleMap[strtoupper($relator)] ?? $relator;
        }
        return $relator;

    }

    /**
     * Get thumbnail
     *
     * @return string
     */
    protected function getThumbnail()
    {
        foreach ($this->getAllMainElements() as $record) {
            foreach ($record->ProductionEvent as $event) {
                $attributes = $event->ProductionEventType->attributes();
                if ($attributes->{'elokuva-elonet-materiaali-kuva-url'}) {
                    return (string)$attributes
                        ->{'elokuva-elonet-materiaali-kuva-url'};
                }
            }
        }
        return '';
    }

    /**
     * Get all metadata elements
     *
     * @return SimpleXMLElement
     */
    protected function getAllMainElements()
    {
        $nodes = (array)$this->doc->children();
        $node = reset($nodes);
        return is_array($node) ? $node : [$node];
    }

    /**
     * Get URLs
     *
     * @return array
     */
    protected function getUrls()
    {
        $results = [];
        $records = $this->doc->children();
        $records = reset($records);
        foreach (is_array($records) ? $records : [$records] as $record) {
            foreach ($record->ProductionEvent as $event) {
                $attrs = [
                    'elokuva-elonet-url', 'elokuva-elonet-materiaali-video-url'
                ];
                foreach ($attrs as $attr) {
                    $attributes = $event->ProductionEventType->attributes();
                    if ($attributes->{$attr}) {
                        $results[] = (string)$attributes->{$attr};
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Get online URLs
     *
     * @return array
     */
    protected function getOnlineUrls()
    {
        $results = [];
        $records = $this->doc->children();
        $records = reset($records);
        foreach (is_array($records) ? $records : [$records] as $record) {
            if (!isset($record->Title->TitleText)
                || substr((string)$record->Title->TitleText, -4) !== '.mp4'
            ) {
                $attrs = $record->Identifier->attributes();
                continue;
            }
            $videoType = 'elokuva';
            $description = '';
            if (isset($record->Title->PartDesignation->Value)) {
                $attributes = $record->Title->PartDesignation->Value->attributes();
                if (!empty($attributes{'video-tyyppi'})) {
                    $videoType = (string)$attributes{'video-tyyppi'};
                }
                $description = (string)$attributes->{'video-lisatieto'};
            }
            foreach ($record->ProductionEvent as $event) {
                $attributes = $event->ProductionEventType->attributes();
                $url = (string)$attributes
                    ->{'elokuva-elonet-materiaali-video-url'};
                $results[] = [
                    'url' => $url,
                    'text' => $description ? $description : $videoType,
                    'source' => $this->source
                ];
            }
        }
        return $results;
    }

    /**
     * Recursively append XML
     *
     * @param SimpleXMLElement $simplexml Node to append to
     * @param SimpleXMLElement $append    Node to be appended
     *
     * @return void
     */
    protected function appendXml(&$simplexml, $append)
    {
        if ($append !== null) {
            $name = $append->getName();
            // addChild doesn't encode & ...
            $data = (string)$append;
            $data = str_replace('&', '&amp;', $data);
            $xml = $simplexml->addChild($name, $data);
            foreach ($append->attributes() as $key => $value) {
                 $xml->addAttribute($key, $value);
            }
            foreach ($append->children() as $child) {
                $this->appendXML($xml, $child);
            }
        }
    }
}
