<?php
/**
 * Forward record class
 *
 * PHP version 7
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
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * Forward record class
 *
 * This is a class for processing records in the Forward format (EN 15907).
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Forward extends \RecordManager\Base\Record\Forward
{
    use AuthoritySupportTrait;
    use ForwardRecordTrait;

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
     * Primary language to use
     *
     * @var string
     */
    protected $primaryLanguage = 'fi';

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

        if (isset($data['publishDate'])) {
            $year = $this->metadataUtils->extractYear($data['publishDate']);
            $data['main_date_str'] = $year;
            $data['main_date'] = $this->validateDate("$year-01-01T00:00:00Z");
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = $this->metadataUtils->dateRangeToStr(
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

        $data['building'] = $this->getBuilding();

        $allAuthors = $this->getAuthorsByRelator();
        $data['author2_id_str_mv']
            = $this->addNamespaceToAuthorityIds($allAuthors['ids']);
        $data['author2_id_role_str_mv']
            = $this->addNamespaceToAuthorityIds($allAuthors['idRoles']);

        $data['question_category_str_mv'] = $this->getQuestionCategories();

        $languages = $this->getLanguages();
        $data['language']
            = $this->metadataUtils->normalizeLanguageStrings($languages);

        $subtitles = $this->getSubtitleLanguages();
        $data['subtitle_lng_str_mv']
            = $this->metadataUtils->normalizeLanguageStrings($subtitles);

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
                    (string)$type->attributes()->{'elokuva-laji1fin'},
                    'UTF-8'
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
     * Get all authors or authors by relator codes.
     *
     * @param array $relators List of allowed relators, or an empty list
     *                        to return all authors.
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
            $id = (string)$agent->AgentIdentifier->IDTypeName . '_'
                . (string)$agent->AgentIdentifier->IDValue;
            if ($id != '_') {
                $result['ids'][] = $id;
                if ($relator) {
                    $result['idRoles'][]
                        = $this->formatAuthorIdWithRole($id, $relator);
                }
            }
            $result['relators'][] = $relator;
        }

        return $result;
    }

    /**
     * Get primary authors with names and relators.
     *
     * @return array
     */
    protected function getPrimaryAuthorsSorted()
    {
        $unsortedPrimaryAuthors = parent::getPrimaryAuthorsSorted();

        // Make sure directors are first of the primary authors
        $directors = $others = [
            'ids' => [],
            'idRoles' => []
        ];

        foreach ($unsortedPrimaryAuthors['relators'] as $i => $relator) {
            if ('d02' === $relator) {
                $directors['ids'][] = $unsortedPrimaryAuthors['ids'][$i] ?? null;
                $directors['idRoles'][]
                    = $unsortedPrimaryAuthors['idRoles'][$i] ?? null;
            } else {
                $others['ids'][] = $unsortedPrimaryAuthors['ids'][$i] ?? null;
                $others['idRoles'][]
                    = $unsortedPrimaryAuthors['idRoles'][$i] ?? null;
            }
        }
        $unsortedPrimaryAuthors['ids']
            = array_merge($directors['ids'], $others['ids']);
        $unsortedPrimaryAuthors['idRoles']
            = array_merge($directors['idRoles'], $others['idRoles']);

        return $unsortedPrimaryAuthors;
    }

    /**
     * Return genres
     *
     * @return array
     */
    protected function getGenres()
    {
        $result = $this->getProductionEventAttribute('elokuva-genre');

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
     * @return array
     */
    protected function getProductionEventAttribute($attribute)
    {
        $result = [];
        foreach ($this->getAllMainElements() as $record) {
            foreach ($record->ProductionEvent as $event) {
                $attributes = $event->ProductionEventType->attributes();
                if (!empty($attributes->{$attribute})) {
                    $result[] = (string)$attributes->{$attribute};
                }
            }
        }
        return $result;
    }

    /**
     * Get relator code for the agent
     *
     * @param \SimpleXMLElement $agent Agent
     *
     * @return string
     */
    protected function getRelator($agent)
    {
        if (empty($agent->Activity)) {
            return '';
        }
        $activity = $agent->Activity;
        $relator = $this->metadataUtils->normalizeRelator((string)$activity);
        if (in_array($relator, ['a00', 'a08', 'a99', 'd99', 'e04', 'e99'])) {
            $relator = null;
            foreach (
                ['finna-activity-text', 'tehtava', 'elokuva-elotekija-tehtava']
                as $field
            ) {
                if (!empty($activity->attributes()->{$field})) {
                    $label = trim((string)$activity->attributes()->{$field});
                    if (!in_array($label, ['', '"'])) {
                        $relator = $label;
                        break;
                    }
                }
            }
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
     * @return \SimpleXMLElement
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
            $videoMatch = false;
            $videoType = 'elokuva';
            $description = '';
            if (isset($record->Title->PartDesignation->Value)) {
                $attributes = $record->Title->PartDesignation->Value->attributes();
                if (!empty($attributes->{'video-tyyppi'})) {
                    $videoType = (string)$attributes->{'video-tyyppi'};
                }
                if (!empty($attributes->{'online-video'})) {
                    $videoMatch = boolval((string)$attributes->{'online-video'});
                }
                $description = (string)$attributes->{'video-lisatieto'};
            }
            if (!$videoMatch) {
                continue;
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
     * Get the building field
     *
     * @return array
     */
    protected function getBuilding()
    {
        foreach ($this->getMainElement()->ProductionEvent as $event) {
            if (null !== $event->attributes()->{'elonet-tag'}
                && (string)$event->attributes()->{'elonet-tag'} === 'skftunniste'
            ) {
                return ['skf'];
            }
        }
        return [];
    }

    /**
     * Get question categories
     *
     * @return array
     */
    protected function getQuestionCategories()
    {
        $result = [];
        $categories = array_merge(
            $this->getProductionEventAttribute(
                'elokuva-elotiedonkeruu-henkilotyyppi'
            ),
            $this->getProductionEventAttribute('elokuva-elotiedonkeruu-kuvauspaikka')
        );
        foreach ($categories as $category) {
            $result = array_merge($result, explode(';', $category));
        }
        return $result;
    }

    /**
     * Get languages of all videos
     *
     * @return array
     */
    public function getLanguages()
    {
        $result = [];
        $attrName = 'elokuva-elonet-materiaali-video-kieli';
        $languages = $this->getProductionEventAttribute($attrName);
        foreach ($languages as $language) {
            $result = array_merge($result, explode(',', $language));
        }
        return $result;
    }

    /**
     * Get languages of all video subtitles
     *
     * @return array
     */
    public function getSubtitleLanguages()
    {
        $result = [];
        $attrName = 'elokuva-elonet-materiaali-video-alatekstikieli';
        $languages = $this->getProductionEventAttribute($attrName);
        foreach ($languages as $language) {
            $result = array_merge($result, explode(',', $language));
        }
        return $result;
    }
}
