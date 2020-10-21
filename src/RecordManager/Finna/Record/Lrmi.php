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
namespace RecordManager\Finna\Record;

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

        $data['format_ext_str_mv'] = $data['format'];

        $topics = $topicIds = [];
        foreach ($this->getTopics() as $topic) {
            $topics[] = $topic['value'];
            if ($id = $topic['id']) {
                $topicIds[] = $id;
            }
        }
        $data['topic'] = array_merge($data['topic'] ?? [], $topics);
        $data['topic_facet'] = array_merge($data['topic_facet'] ?? [], $topics);
        $data['topic_uri_str_mv']
            = array_merge($data['topic_uri_str_mv'] ?? [], $topicIds);

        $data['author2'] = $this->getSecondaryAuthors();

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

        // Materials
        if (isset($doc->material)) {
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            $data['free_online_boolean'] = true;
            $data['free_online_str_mv'] = $this->source;

            foreach ($doc->material as $material) {
                $languages[] = (string)$material->inLanguage ?? '';

                if ($url = (string)$material->url ?? '') {
                    $link = [
                        'url' => $url,
                        'text' => trim((string)$material->name ?? $url),
                        'source' => $this->source
                    ];
                    $data['online_urls_str_mv'][] = json_encode($link);
                }
            }
        }

        $data['language']
            = MetadataUtils::normalizeLanguageStrings(array_unique($languages));

        // Facets
        foreach ($doc->educationalAudience as $audience) {
            $data['educational_audience_str_mv'][]
                = (string)$audience->educationalRole;
        }
        $data['educational_level_str_mv']
            = $this->getAlignmentObjects('educationalLevel');

        $data['educational_aim_str_mv']
            = $this->getAlignmentObjects('teaches');

        $data['educational_subject_str_mv']
            = $this->getAlignmentObjects('educationalSubject');

        foreach ($doc->type as $type) {
            $data['educational_material_type_str_mv'][] = (string)$type;
        }

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
     * Return record format.
     *
     * @return string
     */
    public function getRecordFormat()
    {
        return 'lrmi';
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
        $ignoredFields = [
            'format', 'id', 'identifier', 'date', 'dateCreated', 'dateModified',
            'filesize', 'inLanguage', 'position', 'recordID', 'rights', 'targetUrl',
            'url'
        ];

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
            if (in_array($tag, $ignoredFields) || !$field) {
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
}
