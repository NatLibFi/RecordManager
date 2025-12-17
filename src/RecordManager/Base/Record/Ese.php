<?php

/**
 * Ese record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2025.
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
        $key = __METHOD__ . ($forFiling ? '1' : '0');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $title = trim((string)$this->doc->title);
        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        }
        return $this->resultCache[$key] = $title;
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
     * Get ISBNs in ISBN-13 format without dashes.
     *
     * @return array
     */
    protected function getISBNs(): array
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
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'ese';
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

    /**
     * Get control numbers.
     *
     * @return array
     */
    protected function getControlNumbers(): array
    {
        $id = trim((string)$this->doc->recordID);
        return '' !== $id ? [$id] : [];
    }

    /**
     * Get full record.
     *
     * @return string
     */
    protected function getFullRecord(): string
    {
        return (string)$this->doc->asXML();
    }

    /**
     * Get an array of all fields relevant to allfields search.
     *
     * @return array
     */
    protected function getAllFields()
    {
        $allFields = [];
        foreach ($this->doc->children() as $field) {
            $allFields[] = trim((string)$field);
        }
        return $allFields;
    }

    /**
     * Get languages.
     *
     * @return array
     */
    protected function getLanguages(): array
    {
        return $this->metadataUtils->normalizeLanguageStrings(explode(' ', $this->doc->language));
    }

    /**
     * Get primary authors.
     *
     * @return array
     */
    protected function getPrimaryAuthors(): array
    {
        $result = [];
        foreach ($this->getValues('creator') as $author) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation($author);
        }
        return $result;
    }

    /**
     * Get secondary authors.
     *
     * @return array
     */
    protected function getSecondaryAuthors(): array
    {
        $result = [];
        foreach ($this->getValues('contributor') as $contributor) {
            $result[] = $this->metadataUtils->stripTrailingPunctuation($contributor);
        }
        return $result;
    }

    /**
     * Get full title.
     *
     * @return string
     */
    protected function getFullTitle(): string
    {
        return $this->getTitle();
    }

    /**
     * Get short title.
     *
     * @return string
     */
    protected function getShortTitle(): string
    {
        $titleParts = explode(' : ', $this->getFullTitle(), 2);
        return $titleParts[0];
    }

    /**
     * Get subtitle.
     *
     * @return string
     */
    protected function getTitleSub(): string
    {
        $titleParts = explode(' : ', $this->getFullTitle(), 2);
        return $titleParts[1] ?? '';
    }

    /**
     * Get publishers.
     *
     * @return array
     */
    protected function getPublishers(): array
    {
        return $this->getValues('publisher');
    }

    /**
     * Get topics.
     *
     * @return array
     */
    protected function getTopics(): array
    {
        return $this->getValues('subject');
    }

    /**
     * Get topic facet fields.
     *
     * @return array
     */
    protected function getTopicFacets(): array
    {
        return $this->getValues('subject');
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        $urls = [];
        foreach ($this->getValues('identifier') as $identifier) {
            if (preg_match('/^https?/', $identifier)) {
                $urls[] = $identifier;
            }
        }
        foreach ($this->getValues('description') as $description) {
            if (preg_match('/^https?/', $description)) {
                $urls[] = $description;
            }
        }
        return $urls;
    }

    /**
     * Get publication years.
     *
     * @return array
     */
    protected function getPublicationYears(): array
    {
        $result = [];
        foreach ($this->doc->date as $date) {
            if (preg_match('{^(\d{4})$}', $date)) {
                $result[] = (string)$date;
            }
        }
        return $result;
    }
}
