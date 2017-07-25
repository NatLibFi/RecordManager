<?php
namespace RecordManager\Base\Record;

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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Ead3 extends Base
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

/*
        if ($names = $doc->xpath('controlaccess/persname')) {
            foreach ($names as $name) {
                if (trim((string)$name) !== '-') {
                    $data['author'][] = trim((string)$name);
                }
            }
        }
*/ 

        if ($names = $doc->xpath('controlaccess/corpname')) {
            foreach ($names as $name) {
                $data['author_corporate'][] = trim((string)$name);
            }
        }

        // NdlEad3-kamaako?

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

        $genre = $doc->xpath('controlaccess/genreform/part');
        $data['format'] = (string) ($genre ? $genre[0] : $doc->attributes()->level);

        if (isset($doc->did->repository->corpname->part)) {
            $data['institution'] = (string) $doc->did->repository->corpname->part;
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



