<?php
namespace RecordManager\Finna\Record;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Ead3 record class
 *
 * This is a class for processing EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/jlehmus/RecordManager
 */
class Ead3 extends \RecordManager\Base\Record\Base
{
    protected $doc = null;

    /**
     * Constructor
     *

    public function __construct(Logger $logger, $config, $dataSourceSettings)
    {
        parent::__construct($logger, $config, $dataSourceSettings);

    }

     */
     public function setData($source, $oaiID, $data) {
        parent::setData($source, $oaiID, $data);
        $this->doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        if (isset($this->doc->{'add-data'})
            && isset($this->doc->{'add-data'}->attributes()->identifier)
        ) {
            return (string)$this->doc->{'add-data'}->attributes()->identifier;
        }

        if (isset($this->doc->did->unitid)) {
            foreach ($this->doc->did->unitid as $i) {
                if ($i->attributes()->label == 'Tekninen') {
                    $id = $i->attributes()->identifier 
                        ? (string)$i->attributes()->identifier
                        : (string)$this->doc->did->unitid;
                }
            }

        } else {
            die('No ID found for record: ' . $this->doc->asXML());
        }
        return urlencode($id);
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return MetadataUtils::trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        return $this->doc->asXML();
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = [];
        $doc = $this->doc;
        $data['ctrlnum'] = (string)$this->doc->attributes()->{'id'};
        $data['fullrecord'] = MetadataUtils::trimXMLWhitespace($doc->asXML());
        $data['allfields'] = $this->getAllFields($doc);

        $data['title_sub'] = '';
	$analogID = '';

        if ($this->doc->did->unitid) {
            foreach ($this->doc->did->unitid as $i) {
                if ($i->attributes()->label == 'Analoginen') {
                    $idstr = (string) $i;
                    $analogID = (strpos($idstr, "/") > 0)
                              ? substr($idstr, strpos($idstr, "/") + 1)
                              : $idstr;
                    $data['identifier'] = $analogID;   
                }
            }
        }

        $genre = $doc->xpath('controlaccess/genreform/part');
        $data['format'] = (string) ($genre ? $genre[0] : $doc->attributes()->level);

        switch ($data['format']) {
        case 'fonds':
            break;
        case 'collection':
            break;
        case 'series':
        case 'subseries':
            if ($analogID) { $data['title_sub'] == $analogID; }
            break;
        default:
            if ($analogID) { $data['title_sub'] == $analogID; }
            if ($doc->{'add-data'}->parent) {
                $data['series']
                    = (string)$doc->{'add-data'}->parent->attributes()->unittitle;
            }
            break;
        }

        $data['title_short'] 
                = isset($doc->did->unittitle) 
                ? (string)$doc->did->unittitle->attributes()->label
                : "";

        $data['title'] = '';
        if ($this->getDriverParam('prependTitleWithSubtitle', true)) {
            if ($data['title_sub'] && $data['title_sub'] != $data['title_short']) {
                $data['title'] = $data['title_sub'] . ' ';
            }
        }
        $data['title'] .= $data['title_short'];
        $data['title_full'] = $data['title_sort'] = $data['title'];
        $data['title_sort'] = mb_strtolower(
            MetadataUtils::stripLeadingPunctuation($data['title_sort']), 'UTF-8'
        );

        if ($doc->scopecontent) {
            if ($doc->scopecontent->p) {
                // Join all p-elements into a flat string.
                $desc = [];
                foreach ($doc->scopecontent->p as $p) {
                    $desc[] = trim((string)$p);
                }
                $desc = implode('   /   ', $desc);
            } else {
                $desc = (string)$doc->scopecontent;
            }
            $data['description'] = $desc;
        }

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

        if ($names = $doc->xpath('origination/name')) {
            foreach ($names as $name) {
                // relator juttu?
                foreach ($name->part as $part) {
                    $data['author_corporate'][] = trim((string)$part);           
                }
                // debug info
                $data['author_corporate'][] = "IDENT " . (string)$name->attributes()->identifier;
            }                    
        }

        if (!empty($doc->did->origination->persname)) {
            $data['author2'] = trim(
                (string)$doc->did->origination->persname
            );
        }

        if (isset($doc->did->repository->corpname->part)) {
            $data['institution'] = (string) $doc->did->repository->corpname->part;
        }

        if ($names = $doc->xpath('controlaccess/corpname')) {
            foreach ($names as $name) {
                $data['author_corporate'][] = trim((string)$name);
            }
        }

        if ($geoNames = $doc->xpath('controlaccess/geogname')) {
            $names = [];
            foreach ($geoNames as $name) {
                if (trim((string)$name) !== '-') {
                    $names[] = trim((string)$name);
                }
            }
            $data['geographic'] = $data['geographic_facet'] = $names;
        }

        if ($subjects = $doc->xpath('controlaccess/subject')) {
            $topics = [];
            foreach ($subjects as $subject) {
                if (trim((string)$subject) !== '-') {
                    $topics[] = trim((string)$subject);
                }
            }
            $data['topic'] = $data['topic_facet'] = $topics;
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }

        $data['source_str_mv'] = isset($data['institution'])
                               ? $data['institution']
                               : $this->source;
        $data['datasource_str_mv'] = $this->source;

        // Digitized?
        if ($doc->did->daogrp) {
            if (in_array($data['format'],['collection', 'series', 'fonds', 'item'])) {
                $data['format'] = 'digitized_' . $data['format'];
            }
            if ($this->doc->did->daogrp->daoloc) {
                foreach ($this->doc->did->daogrp->daoloc as $daoloc) {
                    if ($daoloc->attributes()->{'href'}) {
                        $data['online_boolean'] = true;
                        // This is sort of special. Make sure to use source instead
                        // of datasource.
                        $data['online_str_mv'] = $data['source_str_mv'];
                        break;
                    }
                }
            }
        }

        if (isset($doc->did->dimensions)) {
            // display measurements
            $data['measurements'] = (string)$doc->did->dimensions;
        }

        if (isset($doc->did->physdesc)) {
            foreach($doc->did->physdesc as $physdesc) {
                if (isset($physdesc->attributes()->label)) {
                    $material[] = (string) $physdesc . " "
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
            $role[] = "";
            foreach($doc->controlaccess->name as $name) {
                foreach($name->part as $part) {
                    switch ((string)$part->attributes()->localtype) {
                    case 'Ensisijainen nimi':
                        $author[] = (string) $part;
                    case 'Vaihtoehtoinen nimi':
                        $author_variant[] = (string) $part;
                    case 'Vanhentunut nimi':
                        $author_variant[] = (string) $part;
                    }            
                }
               
                if (isset($name->attributes()->relator)) {
                    $author_role[] = (string) $name->attributes()->relator;
                }
            }
            $data['author'] = $author;
            if ($author_role) { $data['author_role'] = $author_role; }
            if ($author_variant) { $data['author_variant'] = $author_variant; }
        }

        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }

        if (isset($doc->index->index->indexentry)) {
            foreach($doc->index->index->indexentry as $indexentry) {
                if (isset($indexentry->name->part)) {
                    // vain eka part, localtypelliset paremmin pois
                    $contents[] = (string) $indexentry->name->part;
                }
            }
            $data['contents'] = $contents;
        }

        if ($languages = $doc->did->xpath('langmaterial/language')) {
            foreach ($languages as $lang) {
                if (isset($lang->attributes()->langcode)) {
                    $langCode = trim((string)$lang->attributes()->langcode);
                    if ($langCode != '') {
                        $data['language'][] = $langCode;
                    }
                }
            }
        }

        if ($extents = $doc->did->xpath('physdesc/extent')) {
            foreach ($extents as $extent) {
                if (trim((string)$extent) !== '-') {
                    $data['physical'][] = (string)$extent;
                }
            }
        }

        $nodes = isset($this->doc->did->daogrp)
            ? $this->doc->did->daogrp->xpath('daoloc[@role="image_thumbnail"]')
            : null;
        if ($nodes) {
            // store first thumbnail
            $node = $nodes[0];
            if (isset($node->attributes()->href)) {
                $data['thumbnail'] = (string)$node->attributes()->href;
            }
        }

        $data['hierarchytype'] = 'Default';

        if ($this->doc->{'add-data'}->archive) {
            $archiveAttr = $this->doc->{'add-data'}->archive->attributes();
            $data['hierarchy_top_id'] = (string)$archiveAttr->{'id'};
            $data['hierarchy_top_title'] = (string)$archiveAttr->title;
            if ($archiveAttr->subtitle) {
                $data['hierarchy_top_title'] .= ' : '
                    . (string)$archiveAttr->subtitle;
            }
            $data['allfields'][] = $data['hierarchy_top_title'];
            if ($archiveAttr->sequence) {
                $data['hierarchy_sequence'] = (string)$archiveAttr->sequence;
            }
        }
        if ($this->doc->{'add-data'}->{'parent'}) {
            $data['hierarchy_parent_id']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            $data['allfields'][] = $data['hierarchy_parent_title']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title']
                = isset($doc->did->unittitle) 
                ? (string)$doc->did->unittitle->attributes()->label
                : "";
        }

        return $data;
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
                    return ['No known copyright restrictions'];
                }
            }
        }

        if (isset($this->doc->accessrestrict->p)) {
            foreach ($this->doc->accessrestrict->p as $restrict) {
                if (strstr((string)$restrict, 'No known copyright restrictions')) {
                    return ['No known copyright restrictions'];
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
            } catch (\Exception $e) {
                $this->logger->log(
                    'Ead3',
                    "Failed to parse date $endDate, record {$this->source}."
                    . $this->getID(),
                    Logger::WARNING
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
            } catch (\Exception $e) {
                $this->logger->log(
                    'Ead3',
                    "Failed to parse date $endDate, record {$this->source}."
                    . $this->getID(),
                    Logger::WARNING
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
                Logger::WARNING
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [$startDate, $endDate];
    }

/**
     * Get all XML fields
     *
     * @param SimpleXMLElement $xml The XML document
     *
     * @return string[]
     */
    protected function getAllFields($xml)
    {
        $allFields = [];
        foreach ($xml->children() as $tag => $field) {
            $s = trim((string)$field);
            if ($s) {
                $allFields[] = $s;
            }
            $s = $this->getAllFields($field);
            if ($s) {
                $allFields = array_merge($allFields, $s);
            }
        }
        return $allFields;
    }
}

