<?php
/**
 * Lrmi record class
 *
 * PHP version 7
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Record;

use RecordManager\Base\Utils\MetadataUtils;

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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Lrmi extends Qdc
{
    /**
     * Fields that are not included in allfield.
     *
     * @var array
     */
    protected $ignored_allfields = [];

    /**
     * Return fields to be indexed in Solr
     *
     * @param \RecordManager\Base\Database\Database $db Database connection. Omit to
     *                                                  avoid database lookups for
     *                                                  related records.
     *
     * @return array
     */
    public function toSolrArray(\RecordManager\Base\Database\Database $db = null)
    {
        $data = parent::toSolrArray();

        $doc = $this->doc;

        $data['record_format'] = 'lrmi';

        $topics = array_map(
            function ($topic) {
                return $topic['value'];
            }, $this->getTopics()
        );
        $data['topic'] = array_merge($data['topic'] ?? [], $topics);
        $data['topic_facet'] = array_merge($data['topic_facet'] ?? [], $topics);

        $corporateAuthors = [];
        if (isset($doc->author)) {
            foreach ($doc->author as $author) {
                if (isset($author->organization)) {
                    foreach ($author->organization as $organization) {
                        if (isset($organization->legalName)) {
                            $corporateAuthors[]
                                = trim((string)$organization->legalName);
                        }
                    }
                }
            }
            $data['author_corporate'] = $corporateAuthors;
        }
        $data['author_facet'] = array_merge($data['author2'], $corporateAuthors);

        $languages = [];
        if (isset($doc->material)) {
            foreach ($doc->material as $material) {
                $languages[] = (string)$material->inLanguage ?? '';
            }
        }

        $data['language']
            = MetadataUtils::normalizeLanguageStrings(array_unique($languages));

        return $data;
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        return 'LearningMaterial';
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors()
    {
        $result = [];
        if (isset($this->doc->author)) {
            foreach ($this->doc->author as $author) {
                if (isset($author->person)) {
                    foreach ($author->person as $person) {
                        if (isset($person->name)) {
                            $result[] = trim((string)$person->name);
                        }
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Get topics.
     *
     * @return array
     */
    public function getTopics()
    {
        $result = [];
        foreach ($this->doc->about as $about) {
            if (!isset($about->thing->name)) {
                continue;
            }
            $subject = $about->thing;
            $value = (string)$subject->name;
            $id = (string)($subject->identifier ?? '');

            if (!preg_match('/(http|https):\/\/(.*)/', $id, $matches)) {
                $id = null;
            }

            if ($id && isset($matches[2])) {
                $id = 'http://' . $matches[2];
            }

            $result[] = compact('value', 'id');
        }
        return $result;
    }

    /**
     * Get alignment object.
     *
     * @param string $type Type
     *
     * @return array
     */
    protected function getAlignmentObjects($type)
    {
        $result = [];
        foreach ($this->doc->alignmentObject as $obj) {
            if (isset($obj->alignmentType)
                && $type === (string)$obj->alignmentType
                && isset($obj->targetName)
            ) {
                $result[] = (string)$obj->targetName;
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
        $iterator->rewind();
        $iterator->next();

        while ($node = $iterator->current()) {
            $tag = $node->getName();
            $field = trim((string)$node);
            $iterator->next();
            if (in_array($tag, $this->ignored_allfields) || !$field) {
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
        // Resolved together with doc->material
        return [];
    }
}
