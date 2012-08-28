<?php
/**
 * QdcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012
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

require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';

/**
 * QdcRecord Class
 *
 * This is a class for processing Qualified Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class QdcRecord extends BaseRecord
{
    protected $doc = null;

    /**
     * Constructor
     *
     * @param string $data  Record metadata
     * @param string $oaiID Record ID in OAI-PMH
     */
    public function __construct($data, $oaiID)
    {
        $this->doc = simplexml_load_string($data);
        if (empty($this->doc->recordID)) {
            $p = strpos($oaiID, ':');
            $p = strpos($oaiID, ':', $p + 1);
            $this->doc->addChild('recordID', substr($oaiID, $p + 1));
        }
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return $this->doc->recordID[0];
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
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param string $prefix (e.g. "source.")
     * 
     * @return void
     */
    public function setIDPrefix($prefix)
    {
        $this->doc->recordID = $prefix . $this->doc->recordID;
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = array();

        $doc = $this->doc;
        $data['ctrlnum'] = (string)$doc->recordID;
        $data['fullrecord'] = $doc->asXML();
          
        // allfields
        $allFields = array();
        foreach ($doc->children() as $tag => $field) {
            $allFields[] = (string)$field;
        }
        $data['allfields'] = $allFields;
          
        // language
        $data['language'] = array_values(
            array_filter(
                explode(
                    ' ', 
                    (string)$doc->language
                ),
                function($value) {
                    return preg_match('/^[a-z]{2,3}$/', $value) && $value != 'zxx';
                }
            )
        );
                
        $data['format'] = (string)$doc->type;
        $data['author'] = (string)$doc->creator;
        $data['author2'] = $this->getValues('contributor');

        $data['title'] = $data['title_full'] = (string)$doc->title;
        $titleParts = explode(' : ', $data['title']);
        if (!empty($titleParts)) {
            $data['title_short'] = $titleParts[0];
            if (isset($titleParts[1])) {
                $data['title_sub'] = $titleParts[1];
            }
        }
        $data['title_sort'] = $this->getTitle(true);

        $data['publisher'] = (string)$doc->publisher;
        $data['publishDate'] = $this->getPublicationYear();

        $data['isbn'] = $this->getISBNs();

        $data['topic'] = $data['topic_facet'] = $this->getValues('subject');

        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $data['url'] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $data['url'] = $description;
            }
        }

        /*
        TODO: Can we handle any of the following fields?

        $data['series'] = $this->getFieldsSubfields('440ap:800abcdfpqt:830ap');
          
        $data['physical'] = $this->getFieldsSubfields('300abcefg:530abcd');
        $data['edition'] = $this->getFieldSubfields('250a');
        $data['contents'] = $this->getFieldsSubfields('505a:505t');
          
        $data['issn'] = $this->getFieldsSubfields('022a:440x:490x:730x:776x:780x:785x');

        $data['callnumber'] = strtoupper(str_replace(' ', '', $this->getFirstFieldSubfields('080ab:084ab:050ab')));
        $data['callnumber-a'] = $this->getFirstFieldSubfields('080a:084a:050a');
        $data['callnumber-first-code'] = substr($this->getFirstFieldSubfields('080a:084a:050a'), 0, 1);

        $data['genre'] = $this->getFieldsSubfields('655');
        $data['geographic'] = $this->getFieldsSubfields('651');
        $data['era'] = $this->getFieldsSubfields('648');

        $data['genre_facet'] = $this->getFieldsSubfields('600v:610v:611v:630v:648v:650v:651v:655a:655v');
        $data['geographic_facet'] = $this->getFieldsSubfields('600z:610z:611z:630z:648z:650z:651a:651z:655z');
        $data['era_facet'] = $this->getFieldsSubfields('600d:610y:611y:630y:648a:648y:650y:651y:655y');

        $data['illustrated'] = $this->getIllustrated();
        */
        return $data;
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitle()
    {
        return (string)$this->doc->title;
    }

    /**
     * Dedup: Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing (e.g. sorting, non-filing characters should be removed)
     * 
     * @return string
     */
    public function getTitle($forFiling = false)
    {
        // TODO: strip common articles when $forFiling = true?
        return (string)$this->doc->title;
    }

    /**
     * Dedup: Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        return (string)$this->doc->creator;
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return string[]
     */
    public function getISBNs()
    {
        $arr = array();
        foreach (array($this->doc->identifier, $this->doc->isFormatOf) as $field) {
            foreach ($field as $identifier) {
                $identifier = str_replace('-', '', $identifier);
                if (!preg_match('{^([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
                    continue;
                }
                $isbn = $matches[1];
                if (strlen($isbn) == 10) {
                    $isbn = MetadataUtils::isbn10to13($isbn);
                }
                if ($isbn) {
                    $arr[] = $isbn;
                }
            }
        }
        
        return array_unique($arr);
    }

    /**
     * Dedup: Return series ISSN
     *
     * @return string
     */
    public function getSeriesISSN()
    {
        return '';
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     */
    public function getSeriesNumbering()
    {
        return '';
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        return $this->doc->type ? (string)$this->doc->type : 'Unknown';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        foreach ($this->doc->date as $date) {
            if (preg_match('{^(\d{4})$}', $date)) {
                return (string)$date;
            }
        }
    }

    /**
     * Dedup: Return page count (number only)
     *
     * @return string
     */
    public function getPageCount()
    {
        return '';
    }

    /**
     * Get xml field values
     * 
     * @param string $tag Field name
     * 
     * @return string[]
     */
    protected function getValues($tag)
    {
        $values = array();
        foreach ($this->doc->{$tag} as $value) {
            $values[] = (string)$value;
        }
        return $values;
    }
}

