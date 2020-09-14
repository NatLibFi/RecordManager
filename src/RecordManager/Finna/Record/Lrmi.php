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
 * This is a class for processing Qualified Dublin Core records.
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

        $subjects = [];
        $subjectIds = [];
        foreach ($doc->about as $about) {
            if (!isset($about->thing->name)) {
                continue;
            }
            $subject = $about->thing;
            $subjects[] = (string)$subject->name;
            if ($id = (string)($subject->identifier ?? '')) {
                $subjectIds[] = $id;
            }
        }
        $data['format'] = $data['format_ext_str_mv'] = 'LearningMaterial';
        $data['record_format'] = $data['recordtype'] = 'lrmi';

        $topics = $topicIds = [];

        
        foreach ($this->getTopics() as $topic) {
            $topics[] = $topic['value'];
            if ($id = $topic['id']) {
                $topicIds[] = $id;
            }
        }
        $data['topic']
            = array_merge($data['topic'] ?? [], $topics);
        $data['topic_facet']
            = array_merge($data['topic_facet'] ?? [], $topics);

        $data['topic_uri_str_mv']
            = array_merge($data['topic_uri_str_mv'] ?? [], $topicIds);

        if (isset($doc->author)) {
            $authors = $corporateAuthors = [];
            foreach ($doc->author as $author) {
                if (isset($author->person)) {
                    foreach ($author->person as $person) {
                        if (isset($person->name)) {
                            $authors[] = trim((string)$person->name);
                        }
                    }
                } else if (isset($author->organization)) {
                    foreach ($author->organization as $organization) {
                        if (isset($organization->legalName)) {
                            $corporateAuthors[] = trim((string)$organization->legalName);
                        }
                    }
                }
            }
            $data['author2'] = $authors;
            $data['author_corporate'] = $corporateAuthors;
            $data['author_facet'] = array_merge($authors, $corporateAuthors);
        }
        
        $languages = [];

        // Reset url to remove thumbnail (from Qdc-driver)
        unset($data['url']);

        // Materials
        if (isset($doc->material)) {
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            // TODO: free?
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
        // TODO: use dynamic fields for now...
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

        $data['allfields'] = $this->getAllFields($doc);

        return $data;
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
     * Get all XML fields
     *
     * @param SimpleXMLElement $xml The XML document
     *
     * @return array
     */
    protected function getAllFields($xml)
    {
        $ignoredFields = [
            'id', 'date', 'dateCreated', 'dateModified', 'inLanguage', 'url',
            'recordID', 'rights'
        ];

        $allFields = [];
        foreach ($xml->children() as $tag => $field) {
            if (in_array($tag, $ignoredFields)) {
                continue;
            }
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
