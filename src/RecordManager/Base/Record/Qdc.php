<?php
/**
 * Qdc record class
 *
 * PHP version 5
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
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

/**
 * Qdc record class
 *
 * This is a class for processing Qualified Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Qdc extends Base
{
    protected $doc = null;

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     *                       file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        parent::setData($source, $oaiID, $data);

        $this->doc = $this->parseXMLRecord($data);
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
        return trim((string)$this->doc->recordID[0]);
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
     * @return array
     */
    public function toSolrArray()
    {
        $data = [];

        $doc = $this->doc;
        $data['record_format'] = $data['recordtype'] = 'qdc';
        $data['ctrlnum'] = trim((string)$doc->recordID);
        $data['fullrecord'] = $doc->asXML();

        // allfields
        $allFields = [];
        foreach ($doc->children() as $tag => $field) {
            $allFields[] = trim((string)$field);
        }
        $data['allfields'] = $allFields;

        // language
        $languages = [];
        foreach (explode(' ', trim((string)$doc->language)) as $language) {
            foreach (str_split($language, 3) as $code) {
                $languages[] = $code;
            }
        }
        $data['language'] = MetadataUtils::normalizeLanguageStrings($languages);

        $data['format'] = trim((string)$doc->type);
        foreach ($this->getValues('creator') as $author) {
            $data['author'][]
                = MetadataUtils::stripTrailingPunctuation($author);
        }
        if (!empty($data['author'])) {
            $data['author_sort'] = $data['author'][0];
        }
        foreach ($this->getValues('contributor') as $contributor) {
            $data['author2'][]
                = MetadataUtils::stripTrailingPunctuation($contributor);
        }

        foreach ($doc->title as $title) {
            if (!isset($data['title'])
                && $title->attributes()->{'type'} !== 'alternative'
            ) {
                $data['title'] = $data['title_full'] = trim((string)$title);
                $titleParts = explode(' : ', $data['title']);
                if (!empty($titleParts)) {
                    $data['title_short'] = $titleParts[0];
                    if (isset($titleParts[1])) {
                        $data['title_sub'] = $titleParts[1];
                    }
                }
            } else {
                $data['title_alt'][] = trim((string)$title);
            }
        }
        $data['title_sort'] = $this->getTitle(true);

        $data['publisher'] = [trim((string)$doc->publisher)];
        $data['publishDate'] = $this->getPublicationYear();

        $data['isbn'] = $this->getISBNs();
        $data['issn'] = $this->getISSNs();

        $data['topic'] = $data['topic_facet'] = $this->getValues('subject');

        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $data['url'][] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $data['url'][] = $description;
            } elseif (preg_match('/^\d+\.\d+$/', $description)) {
                // Classification, put somewhere?
            } else {
                $data['contents'][] = $description;
            }
        }

        return $data;
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitle()
    {
        return trim((string)$this->doc->title);
    }

    /**
     * Dedup: Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     */
    public function getTitle($forFiling = false)
    {
        $title = trim((string)$this->doc->title);
        $title = MetadataUtils::stripTrailingPunctuation($title);
        if ($forFiling) {
            $title = MetadataUtils::stripLeadingPunctuation($title);
            $title = MetadataUtils::stripLeadingArticle($title);
            // Again, just in case stripping the article affected this
            $title = MetadataUtils::stripLeadingPunctuation($title);
            $title = mb_strtolower($title, 'UTF-8');
        }
        return $title;
    }

    /**
     * Dedup: Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        return trim((string)$this->doc->creator);
    }

    /**
     * Dedup: Return unique IDs (control numbers)
     *
     * @return array
     */
    public function getUniqueIDs()
    {
        $arr = [];
        $form = isset($this->config['Site']['unicode_normalization_form'])
            ? $this->config['Site']['unicode_normalization_form'] : 'NFKC';
        foreach ($this->doc->identifier as $identifier) {
            $identifier = strtolower(trim((string)$identifier));
            if (strncmp('urn:', $identifier, 4) === 0) {
                $arr[] = '(urn)' . MetadataUtils::normalizeKey($identifier, $form);
            }
        }

        return array_unique($arr);
    }

    /**
     * Dedup: Return ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
    {
        $arr = [];
        foreach ([$this->doc->identifier, $this->doc->isFormatOf] as $field) {
            foreach ($field as $identifier) {
                $identifier = str_replace('-', '', trim($identifier));
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
     * Dedup: Return ISSNs
     *
     * @return array
     */
    public function getISSNs()
    {
        if (!isset($this->doc->relation)) {
            return [];
        }

        $result = [];
        foreach ($this->doc->relation as $rel) {
            if ((string)$rel->attributes()->{'type'} === 'issn') {
                $result[] = trim((string)$rel);
            }
        }
        return $result;
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
        return $this->doc->type ? trim((string)$this->doc->type) : 'Unknown';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        foreach ($this->doc->date as $date) {
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                return (string)$date;
            }
        }
        foreach ($this->doc->issued as $date) {
            $date = trim($date);
            if (preg_match('{^(\d{4})$}', $date)) {
                return (string)$date;
            }
        }
        return '';
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
     * @return array
     */
    protected function getValues($tag)
    {
        $values = [];
        foreach ($this->doc->{$tag} as $value) {
            $values[] = trim((string)$value);
        }
        return $values;
    }
}
