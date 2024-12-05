<?php

/**
 * Ese record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * Ese record class
 *
 * This is a class for processing ESE (Europeana) records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ese extends AbstractRecord
{
    use XmlRecordTrait;

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return '';
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = [];

        $doc = $this->doc;
        $data['record_format'] = 'ese';
        $data['ctrlnum'] = (string)$doc->recordID;
        $data['fullrecord'] = $doc->asXML();

        // allfields
        $allFields = [];
        foreach ($doc->children() as $field) {
            $allFields[] = $field;
        }
        $data['allfields'] = $allFields;

        // language
        $data['language'] = $this->metadataUtils->normalizeLanguageStrings(
            explode(' ', $doc->language)
        );

        $data['format'] = (string)$doc->type;
        $data['author'] = (string)$doc->creator;
        $data['author2'] = $this->getValues('contributor');

        $data['title'] = $data['title_full'] = (string)$doc->title;
        $titleParts = explode(' : ', $data['title']);
        $data['title_short'] = $titleParts[0];
        if (isset($titleParts[1])) {
            $data['title_sub'] = $titleParts[1];
        }
        $data['title_sort'] = $this->getTitle(true);

        $data['publisher'] = [(string)$doc->publisher];
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

        return $data;
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitleForDebugging()
    {
        return (string)$this->doc->title;
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
        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        }
        return $title;
    }

    /**
     * Return main author (format: Last, First)
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
     * @return array
     */
    public function getISBNs()
    {
        $arr = [];
        foreach ($this->doc->identifier as $identifier) {
            $identifier = str_replace('-', '', $identifier);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $identifier, $matches)) {
                continue;
            }
            $isbn = $this->metadataUtils->normalizeISBN($matches[1]);
            if ($isbn) {
                $arr[] = $isbn;
            }
        }
        return array_values(array_unique($arr));
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
     * @return string|array
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
     * Get values for a tag
     *
     * @param string $tag XML tag
     *
     * @return array
     */
    protected function getValues($tag)
    {
        $values = [];
        foreach ($this->doc->{$tag} as $value) {
            $values[] = (string)$value;
        }
        return $values;
    }
}
