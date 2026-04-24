<?php

/**
 * EAD 3 Record Class
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
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * EAD 3 Record Class
 *
 * This is a class for processing EAD 3 records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Jukka Lehmus <jlehmus@mappi.helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead3 extends Ead
{
    /**
     * Set record data
     *
     * @param string $source    Source ID
     * @param string $oaiID     Record ID received from OAI-PMH (or empty string for
     *                          file import)
     * @param string $data      Record metadata
     * @param array  $extraData Extra metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data, $extraData)
    {
        parent::setData($source, $oaiID, $data, $extraData);

        $this->doc = $this->parseXMLRecord($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        if (
            isset($this->doc->{'add-data'})
            && isset($this->doc->{'add-data'}->attributes()->identifier)
        ) {
            return (string)$this->doc->{'add-data'}->attributes()->identifier;
        }
        if (isset($this->doc->control->recordid)) {
            $id = (string)$this->doc->control->recordid;
        } else {
            throw new \Exception('No ID found for record: ' . $this->doc->asXML());
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
        return $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        $xml = $this->doc->asXML();
        if (false === $xml) {
            throw new \Exception(
                "Could not serialize record '{$this->source}."
                . $this->getId() . "' to XML"
            );
        }
        return (string)$xml;
    }

    /**
     * Return format from predefined values
     *
     * @return string|array
     */
    public function getFormat()
    {
        if ($format = trim((string)($this->doc->controlaccess->genreform->part ?? ''))) {
            return $format;
        }
        return (string)$this->doc->attributes()->level;
    }

    /**
     * Get short title for enrichment.
     *
     * @return string
     */
    public function getShortTitleForEnrichment(): string
    {
        return (string)($this->doc->did->unittitle ?? '');
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
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        return $this->getTopicTermsFromNode('subject', true);
    }

    /**
     * Get all geographic topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawGeographicTopicIds(): array
    {
        return [];
    }

    /**
     * Get primary author identifiers
     *
     * @return array<int, string>
     */
    public function getPrimaryAuthorIds(): array
    {
        return [];
    }

    /**
     * Get secondary author identifiers
     *
     * @return array<int, string>
     */
    public function getSecondaryAuthorIds(): array
    {
        return [];
    }

    /**
     * Get corporate author identifiers
     *
     * @return array<int, string>
     */
    public function getCorporateAuthorIds(): array
    {
        return [];
    }

    /**
     * Get short title
     *
     * @return string
     */
    public function getShortTitle(): string
    {
        return (string)($this->doc->did->unittitle ?? '');
    }

    /**
     * Get sort title.
     *
     * @return string
     */
    public function getTitleSort(): string
    {
        return mb_strtolower($this->metadataUtils->stripPunctuation($this->getTitle()), 'UTF-8');
    }

    /**
     * Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getTitle($forFiling = false): string
    {
        return $this->getTitleByLanguage($forFiling);
    }

    /**
     * Get title by language
     *
     * @param bool    $forFiling Whether the title is to be used in filing
     *                           (e.g. sorting, non-filing characters
     *                           should be removed)
     * @param ?string $language  Return title with specific language code (for downstream usage).
     *                           Otherwise returns first title.
     *
     * @return string
     */
    protected function getTitleByLanguage($forFiling = false, ?string $language = null): string
    {
        $key = __METHOD__ . ($forFiling ? '1' : '0') . ($language ?? '');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }
        $title = $titleLang = '';
        foreach ($this->doc->did->unittitle ?? [] as $unittitle) {
            if ($this->metadataUtils->normalizeLanguageCode($unittitle->attributes()->lang ?? '') === $language) {
                $titleLang = (string)$unittitle;
                break;
            }
        }
        if ($language && !$titleLang) {
            return '';
        }
        $shortTitle = $language ? $titleLang : $this->getShortTitle();
        $titleSub = $this->getTitleSubByLanguage($language);
        // Ini handling returns true as '1':
        $prependTitle = $this->getDriverParam('prependTitleWithSubtitle', '1');
        if (
            '1' === $prependTitle
            || ('children' === $prependTitle && $this->doc->{'add-data'}->{'parent'})
        ) {
            if (
                '' !== $titleSub
                && $titleSub !== $shortTitle
            ) {
                $title = $titleSub . ' ';
            }
        }
        $title .= $shortTitle;

        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        }

        return $this->resultCache[$key] = $title;
    }

    /**
     * Return subtitle by language
     *
     * @param ?string $language Return subtitle with specific language code (for downstream usage).
     *
     * @return string
     */
    protected function getTitleSubByLanguage($language = null): string
    {
        // Subtitle is currently not language specific
        return $this->getTitleSub();
    }

    /**
     * Do any post-processing for the record after the main conversion to Solr array.
     *
     * @param ?Database $db   Database connection, if available
     * @param array     $data Array of Solr fields
     *
     * @return void
     */
    protected function postProcessRecordForIndexing(?Database $db, &$data): void
    {
        // Additional titles should be added before adding hierarchy fields
        $this->addAdditionalTitles($data);
        $this->addHierarchyFields($data);
    }

    /**
     * Get record format.
     *
     * @return string
     */
    protected function getRecordFormat(): string
    {
        return 'ead3';
    }

    /**
     * Get topic identifiers.
     *
     * @return array
     */
    protected function getTopicIDs(): array
    {
        return $this->getRawTopicIds();
    }

    /**
     *  Get description
     *
     * @return string
     */
    protected function getDescription(): string
    {
        if (!empty($this->doc->scopecontent)) {
            if (!empty($this->doc->scopecontent->p)) {
                // Join all p-elements into a flat string.
                $desc = [];
                foreach ($this->doc->scopecontent->p as $p) {
                    $desc[] = trim((string)$p);
                }
                return implode('   /   ', $desc);
            }
            return (string)$this->doc->scopecontent;
        }
        return '';
    }

    /**
     * Get primary authors.
     *
     * @return array<int, string>
     */
    protected function getPrimaryAuthors(): array
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }

        $result = [];
        foreach ($this->getAuthorElements() as $name) {
            foreach ($name->part as $part) {
                if ($trimmed = trim((string)$part)) {
                    $result[] = $trimmed;
                }
            }
        }
        return $this->resultCache[__METHOD__] = $result;
    }

    /**
     * Get corporate authors
     *
     * @return array<int, string>
     */
    protected function getCorporateAuthors(): array
    {
        $result = [];
        foreach ($this->getCorporateAuthorElements() as $name) {
            foreach ($name->part as $part) {
                if ($trimmed = trim((string)$part)) {
                    $result[] = $trimmed;
                }
            }
        }
        return $result;
    }

    /**
     * Helper function for getting author elements
     *
     * @return \SimpleXMLElement[] Array of author nodes
     */
    protected function getAuthorElements(): array
    {
        $result = [];
        foreach ($this->doc->controlaccess as $controlaccess) {
            foreach ($controlaccess->name as $name) {
                $result[] = $name;
            }
            foreach ($controlaccess->persname as $persname) {
                $result[] = $persname;
            }
        }
        foreach ($this->doc->did->origination ?? [] as $origination) {
            foreach ($origination->name as $name) {
                $result[] = $name;
            }
            foreach ($origination->persname as $persname) {
                $result[] = $persname;
            }
        }
        return $result;
    }

    /**
     * Helper function for getting corporate author elements
     *
     * @return \SimpleXMLElement[] Array of author nodes
     */
    protected function getCorporateAuthorElements(): array
    {
        $result = [];
        foreach ($this->doc->controlaccess as $controlaccess) {
            foreach ($controlaccess->corpname as $name) {
                $result[] = $name;
            }
        }
        foreach ($this->doc->did->origination ?? [] as $origination) {
            foreach ($origination->corpname as $name) {
                $result[] = $name;
            }
        }
        return $result;
    }

    /**
     * Get topics
     *
     * @return array
     */
    protected function getTopics(): array
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }
        return $this->resultCache[__METHOD__] = $this->getTopicTermsFromNode('subject');
    }

    /**
     * Get topic facet fields
     *
     * @return array<int, string> Topics
     */
    protected function getTopicFacets(): array
    {
        return $this->getTopics();
    }

    /**
     * Get geographic topics
     *
     * @return array
     */
    protected function getGeographicTopics()
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }
        return $this->resultCache[__METHOD__] = $this->getTopicTermsFromNode('geogname');
    }

    /**
     * Get geographic facets.
     *
     * @return array
     */
    protected function getGeographicFacets(): array
    {
        return $this->getGeographicTopics();
    }

    /**
     * Helper function for getting controlaccess child
     * elements with their identifiers.
     *
     * @param string $nodeName    Element name to search for
     * @param bool   $identifiers Whether to return identifiers instead of labels.
     *
     * @return array
     */
    protected function getTopicTermsFromNode($nodeName, $identifiers = false)
    {
        $result = [];
        if (!isset($this->doc->controlaccess->{$nodeName})) {
            return $result;
        }

        foreach ($this->doc->controlaccess->{$nodeName} as $node) {
            if ($identifiers) {
                if ($id = $node['identifier']) {
                    $result[] = (string)$id;
                }
            } elseif ($value = trim((string)$node->part)) {
                $result[] = $value;
            }
        }
        return $result;
    }

    /**
     * Get institution
     *
     * @return string
     */
    protected function getInstitution(): string
    {
        return (string)($this->doc->did->repository->corpname->part ?? '');
    }

    /**
     * Get languages
     *
     * @return array
     */
    protected function getLanguages()
    {
        $result = [];
        foreach ($this->doc->did->langmaterial->language ?? [] as $lang) {
            if (isset($lang->attributes()->langcode)) {
                $langCode = trim((string)$lang->attributes()->langcode);
                if ($langCode != '') {
                    $result[] = $langCode;
                }
            }
        }
        return $result;
    }

    /**
     * Get physical descriptions.
     *
     * @return array
     */
    protected function getPhysicalDescriptions(): array
    {
        $result = [];
        foreach ($this->doc->did->physdesc->extent ?? [] as $extent) {
            if (trim((string)$extent) !== '-') {
                $result[] = (string)$extent;
            }
        }
        return $result;
    }

    /**
     * Get thumbnail URL.
     *
     * @return string
     */
    protected function getThumbnailUrl(): string
    {
        foreach ([$this->doc->did ?? [], $this->doc->did->daoset ?? []] as $root) {
            foreach ($root as $set) {
                foreach ($set->dao ?? [] as $dao) {
                    $attrs = $dao->attributes();
                    if (
                        'thumbnail' === (string)$attrs->localtype
                        && !empty($attrs->href)
                    ) {
                        return (string)$attrs->href;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Get unit id
     *
     * @return string
     */
    protected function getUnitId()
    {
        return (string)($this->doc->did->unitid ?? '');
    }

    /**
     * Get old identifier for the record.
     *
     * @return string
     */
    protected function getOldIdentifier(): string
    {
        $idLabel = $this->getDriverParam('oldIdLabel', 'Old id');
        foreach ($this->doc->did->unitid ?? [] as $unitid) {
            if (($id = trim((string)$unitid)) && ($idLabel === (string)$unitid->attributes()->label)) {
                return "($idLabel)" . $id;
            }
        }
        return '';
    }

    /**
     * Add hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addHierarchyFields(array &$data): void
    {
        $data['hierarchytype'] = 'Default';
        $sequenceUnitId = $firstId = '';
        if ($this->doc->{'add-data'}->archive) {
            $archiveAttr = $this->doc->{'add-data'}->archive->attributes();
            $data['hierarchy_top_id'] = (string)$archiveAttr->{'id'};
            $data['hierarchy_top_title'] = (string)$archiveAttr->title;
            if ($archiveAttr->subtitle) {
                $data['hierarchy_top_title'] .= ' : '
                    . (string)$archiveAttr->subtitle;
            }
            $data['allfields'][] = $data['hierarchy_top_title'];
            $seqLabel = $this->getDriverParam('sequenceUnitIdLabel', 'sequence');
            if ($seqLabel) {
                foreach ($this->doc->did->unitid ?? [] as $unitId) {
                    $firstId = $firstId ?: (string)$unitId;
                    if ($seqLabel === (string)$unitId->attributes()->label) {
                        $sequenceUnitId = (string)$unitId;
                        $data['hierarchy_sequence']
                            = str_pad($sequenceUnitId, 7, '0', STR_PAD_LEFT);
                        break;
                    }
                }
            }
            if (!isset($data['hierarchy_sequence']) && $archiveAttr->sequence) {
                $data['hierarchy_sequence'] = (string)$archiveAttr->sequence;
            }
        }
        if ($this->doc->{'add-data'}->{'parent'}) {
            $data['hierarchy_parent_id']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->{'id'};
            $data['hierarchy_parent_title']
                = (string)$this->doc->{'add-data'}->{'parent'}->attributes()->title;
        } else {
            $data['is_hierarchy_id'] = $data['hierarchy_top_id'] = $this->getID();
            $data['is_hierarchy_title'] = $data['hierarchy_top_title']
                = (string)($this->doc->did->unittitle ?? '');
        }
        $sequenceUnitId = $sequenceUnitId ?: $firstId;
        if ($sequenceUnitId) {
            $this->addHierarchyTitles($data, $sequenceUnitId);
        }
    }

    /**
     * Add hierarchy titles.
     *
     * @param array  $data           Reference to the target array
     * @param string $sequenceUnitId Id to be added
     *
     * @return void
     */
    protected function addHierarchyTitles(array &$data, string $sequenceUnitId): void
    {
        // Note: title_in_hierarchy is only needed if it differs from title.
        if ($this->getDriverParam('addIdToHierarchyTitle', true)) {
            $data['title_in_hierarchy'] = trim("$sequenceUnitId " . $data['title']);
        }
    }

    /**
     * Add additional titles (for downstream usage).
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function addAdditionalTitles(array &$data): void
    {
    }

    /**
     * Get all XML fields
     *
     * @param ?\SimpleXMLElement $xml XML fragment to process, or null to process whole document
     *
     * @return array<int, string>
     */
    protected function getAllFields($xml = null)
    {
        $xml ??= $this->doc;
        $allFields = [];
        foreach ($xml->children() as $field) {
            $s = trim((string)$field);
            if ($s) {
                $allFields[] = $s;
            }
            $s = $this->getAllFields($field);
            if ($s) {
                $allFields = [...$allFields, ...$s];
            }
        }
        return $allFields;
    }

    /**
     * Get full record.
     *
     * @return string
     */
    protected function getFullRecord(): string
    {
        return $this->metadataUtils->trimXMLWhitespace($this->doc->asXML());
    }

    /**
     * Get author sort field.
     *
     * @return string
     */
    protected function getAuthorSort(): string
    {
        $authors = $this->getPrimaryAuthors();
        return $authors[0] ?? '';
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
}
