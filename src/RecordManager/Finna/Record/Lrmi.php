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
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

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
class Lrmi extends \RecordManager\Base\Record\Lrmi
{
    /**
     * Fields that are not included in allfield.
     *
     * @var array
     */
    protected $ignoredAllfields = [
        'format', 'id', 'identifier', 'date', 'dateCreated', 'dateModified',
        'filesize', 'inLanguage', 'position', 'recordID', 'rights', 'targetUrl',
        'url'
    ];

    use QdcRecordTrait {
        toSolrArray as _toSolrArray;
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array
     */
    public function toSolrArray(Database $db = null)
    {
        $data = $this->_toSolrArray();

        $doc = $this->doc;

        // Materials
        if (isset($doc->material)) {
            $data['online_boolean'] = true;
            $data['online_str_mv'] = $this->source;
            $data['free_online_boolean'] = true;
            $data['free_online_str_mv'] = $this->source;

            foreach ($doc->material as $material) {
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

        // Facets
        foreach ($doc->educationalAudience as $audience) {
            $data['educational_audience_str_mv'][]
                = (string)$audience->educationalRole;
        }
        $data['educational_level_str_mv'] = array_map(
            'strval',
            (array)($doc->learningResource->educationalLevel ?? [])
        );
        $data['educational_aim_str_mv'] = array_map(
            'strval',
            (array)($doc->learningResource->teaches ?? [])
        );
        foreach ($doc->learningResource->educationalAlignment ?? [] as $alignment) {
            if ($subject = $alignment->educationalSubject ?? null) {
                $data['educational_subject_str_mv'][] = (string)$subject;
            }
        }

        foreach ($doc->type as $type) {
            $data['educational_material_type_str_mv'][] = (string)$type;
        }

        // Topic ids
        $data['topic_id_str_mv'] = array_merge(
            $data['topic_id_str_mv'] ?? [],
            array_map(
                function ($topic) {
                    return $topic['id'];
                },
                $this->getTopicsExtended()
            )
        );

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
        $doc = $this->doc;
        $title = (string)$doc->title;
        foreach ($doc->title as $t) {
            if ((string)$t->attributes()->lang === 'fi') {
                $title = (string)$t;
                break;
            }
        }
        if ($forFiling) {
            $title = $this->metadataUtils->stripLeadingPunctuation($title);
            $title = $this->metadataUtils->stripLeadingArticle($title);
            // Again, just in case stripping the article affected this
            $title = $this->metadataUtils->stripLeadingPunctuation($title);
            $title = mb_strtolower($title, 'UTF-8');
        }
        return $title;
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
