<?php
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

class Ead3 extends Base
{
    protected $doc = null;

    /**
     * Constructor
     *
     */
    public function __construct(Logger $logger, $config, $dataSourceSettings)
    {
        parent::__construct(Logger $logger, $config, $dataSourceSettings);

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
     * @param boolean $prependTitleWithSubtitle If true and title_sub differs from
     * title_short, title is formed by combining title_sub and title_short
     *
     * @return string[]
     */
    public function toSolrArray($prependTitleWithSubtitle = false)
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

    // NdlEad3-kamaa

        if ($names = $doc->xpath('controlaccess/name')) {
            foreach ($names as $name) {
        // relator juttu?
           if (strpos((string) $name->attributes()->relator, 'Tekij')) {
                      foreach ($name->part as $part) {
                           if ($part->attributes()->localtype) {
            // well well what do we have here now?
         //            $data['author'][] = trim((string)$part->attributes()->localtype . " [localtype]");
                } else {
                       $data['author'][] = trim((string)$part);           
               }
                  }
           }
         }                    
        }

       if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }

        if ($names = $doc->xpath('controlaccess/corpname')) {
            foreach ($names as $name) {
                $data['author_corporate'][] = trim((string)$name);
            }
        }

/*
        if (!empty($doc->did->origination->corpname)) {
            $data['author_corporate'] = trim(
                (string)$doc->did->origination->corpname
            );
        }
*/

    // NdlEad3-kamaako?

        if ($names = $doc->xpath('origination/name')) {
            foreach ($names as $name) {
        // relator juttu?
//           if (strpos((string) $name->attributes()->relator, 'Arkistonmuod')) {
                      foreach ($name->part as $part) {
//                           if ($part->attributes()->localtype) {
//            // well well what do we have here now?
 //        //            $data['author_corporate'][] = trim((string)$part->attributes()->localtype . " [localtype]");
//                } else {
                       $data['author_corporate'][] = trim((string)$part);           
//               }
                  }
//           }
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

        if (isset($doc->did->repository->corpname)) {
            $data['institution']
                = (string) (isset($doc->did->repository->corpname->part)
                ? $doc->did->repository->corpname->part
                : 'UNOWN INSTITUTIO');
        }

        $data['title_sub'] = '';

    $analogID = '';

        if (isset($doc->did->unitid)) {
    // xpath -toteutus tilalle
                foreach ($doc->did->unitid as $i) {

                    if ($i->attributes()->label == 'Analoginen') {

               $idstr = (string) $i;

                        $analogID = (strpos($idstr, "/") > 0)
                  ? substr($idstr, strpos($idstr, "/") + 1)
                  : $idstr;
                  }
                }      
    }        


        switch ($data['format']) {
        case 'fonds':
            break;
        case 'collection':
            break;
        case 'series':
        case 'subseries':
            $data['title_sub'] = $analogID; // (string)$doc->did->unitid;
            break;
        default:
            $data['title_sub'] = $analogID; //(string)$doc->did->unitid;
            if ($doc->{'add-data'}->parent) {
                $data['series']
                    = (string)$doc->{'add-data'}->parent->attributes()->unittitle;
            }
            break;
        }

        $data['title_short'] 
                = isset($doc->did->unittitle) 
          ? (string)$doc->did->unittitle->attributes()->label
//          : "NO-TITLE-IN-ORIGINAL";
          : "";

        $data['title'] = '';
        if ($prependTitleWithSubtitle) {
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
//                = (string)$doc->did->unittitle;
                = isset($doc->did->unittitle) 
          ? (string)$doc->did->unittitle->attributes()->label
//          : "NO-TITLE-IN-ORIGINAL";
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



