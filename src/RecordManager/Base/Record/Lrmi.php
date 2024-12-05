<?php

/**
 * Lrmi record class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2020.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

use function in_array;

/**
 * Lrmi record class
 *
 * This is a class for processing Lrmi records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Lrmi extends Qdc
{
    /**
     * Fields that are not included in allfield.
     *
     * @var array
     */
    protected $ignoredAllfields = [];

    /**
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = parent::toSolrArray();
        $data['record_format'] = 'lrmi';
        $data['title'] = $data['title_full'] = $data['title_short']
            = $this->getTitle();
        $data['title_sort'] = $this->getTitle(true);
        $data['language'] = $this->getLanguages();

        return $data;
    }

    /**
     * Return title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     */
    public function getTitle($forFiling = false)
    {
        $title = (string)$this->doc->title;
        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        }
        return $title;
    }

    /**
     * Return format from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        return 'LearningMaterial';
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $authors = $this->getPrimaryAuthors();
        return $authors[0] ?? '';
    }

    /**
     * Get topics.
     *
     * @return array
     */
    public function getTopics()
    {
        return $this->getTopicData(false);
    }

    /**
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        return $this->getTopicData(true);
    }

    /**
     * Get primary authors
     *
     * @return array
     */
    protected function getPrimaryAuthors()
    {
        $authors = $this->getSecondaryAuthors();
        return [$authors[0] ?? ''];
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors()
    {
        $result = [];
        foreach ($this->doc->author ?? [] as $author) {
            foreach ($author->person ?? [] as $person) {
                if (isset($person->name)) {
                    $result[] = trim((string)$person->name);
                }
            }
        }
        return $result;
    }

    /**
     * Get corporate authors
     *
     * @return array
     */
    protected function getCorporateAuthors()
    {
        $result = [];
        foreach ($this->doc->author ?? [] as $author) {
            foreach ($author->organization ?? [] as $organization) {
                if (isset($organization->legalName)) {
                    $result[]
                        = trim((string)$organization->legalName);
                }
            }
        }
        return $result;
    }

    /**
     * Get topics with value or id.
     *
     * @param bool $ids Whether to return identifiers instead of values
     *
     * @return array
     */
    protected function getTopicData(bool $ids)
    {
        $result = [];
        foreach ($this->doc->about as $about) {
            if (!isset($about->thing->name)) {
                continue;
            }
            $subject = $about->thing;
            if (!$ids) {
                $result[] = (string)$subject->name;
            } else {
                $id = (string)($subject->identifier ?? '');

                if (preg_match('/(http|https):\/\/(.+)/', $id, $matches)) {
                    $result[] = 'http://' . $matches[2];
                }
            }
        }
        return $result;
    }

    /**
     * Get an array of all fields relevant to allfields search
     *
     * @return array
     */
    protected function getAllFields()
    {
        $allFields = [];
        $iterator = new \RecursiveIteratorIterator(
            new \SimpleXMLIterator($this->doc->asXML())
        );

        foreach ($iterator as $node) {
            $tag = $node->getName();
            $field = trim((string)$node);
            if (!$field || in_array($tag, $this->ignoredAllfields)) {
                continue;
            }
            $allFields[] = $field;
        }

        return $allFields;
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        return [];
    }

    /**
     * Get languages
     *
     * @return array
     */
    protected function getLanguages()
    {
        $languages = [];
        foreach ($this->doc->material ?? [] as $material) {
            $languages[] = (string)($material->inLanguage ?? '');
        }
        foreach ($this->doc->inLanguage ?? [] as $language) {
            $languages[] = (string)$language;
        }
        return $this->metadataUtils
            ->normalizeLanguageStrings(array_unique($languages));
    }
}
