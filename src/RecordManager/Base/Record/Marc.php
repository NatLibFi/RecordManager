<?php
/**
 * Marc record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2022.
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
use RecordManager\Base\Marc\Marc as MarcHandler;
use RecordManager\Base\Utils\DeweyCallNumber;
use RecordManager\Base\Utils\LcCallNumber;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Marc record class
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Marc extends AbstractRecord
{
    /**
     * MARC record
     *
     * @var \RecordManager\Base\Marc\Marc
     */
    protected $record = null;

    /**
     * Default primary author relator codes, may be overridden in configuration
     *
     * @var array
     */
    protected $primaryAuthorRelators = [
        'adp', 'aut', 'cmp', 'cre', 'dub', 'inv'
    ];

    /**
     * Strings in field 300 that signify that the work is illustrated.
     *
     * @var array
     */
    protected $illustrationStrings = ['ill.', 'illus.'];

    /**
     * Default field for geographic coordinates
     *
     * @var string
     */
    protected $defaultGeoField = 'long_lat';

    /**
     * Default field for geographic center coordinates
     *
     * @var string
     */
    protected $defaultGeoCenterField = '';

    /**
     * Default field for geographic displayable coordinates
     *
     * @var string
     */
    protected $defaultGeoDisplayField = 'long_lat_display';

    /**
     * OCLC number patterns
     *
     * @var array
     */
    protected $oclcNumPatterns = [
        '/\([Oo][Cc][Oo][Ll][Cc]\)[^0-9]*[0]*([0-9]+)/',
        '/ocm[0]*([0-9]+)[ ]*[0-9]*/',
        '/ocn[0]*([0-9]+).*/',
        '/on[0]*([0-9]+).*/',
    ];

    /**
     * Patterns for system control numbers considered for unique identifiers
     * (field 035a)
     *
     * @var array
     */
    protected $scnPatterns = [
        '^\((CONSER|DLC|OCoLC)\).+',
        '^\(EXLCZ\).+',     // Ex Libris Community Zone
        '^\(EXLNZ-.+\).+',  // Ex Libris Network Zone
        '^\(\w\w-\w+\).+',  // ISIL style
    ];

    /**
     * MARC record creation callback
     *
     * @var callable
     */
    protected $createRecordCallback;

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     * @param callable      $recordCallback   MARC record creation callback
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils,
        callable $recordCallback
    ) {
        parent::__construct($config, $dataSourceConfig, $logger, $metadataUtils);

        $this->createRecordCallback = $recordCallback;
        if (isset($config['MarcRecord']['primary_author_relators'])) {
            $this->primaryAuthorRelators = explode(
                ',',
                $config['MarcRecord']['primary_author_relators']
            );
        }
    }

    /**
     * Set record data
     *
     * @param string       $source Source ID
     * @param string       $oaiID  Record ID received from OAI-PMH (or empty string
     *                             for file import)
     * @param string|array $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        parent::setData($source, $oaiID, $data);

        $this->record = ($this->createRecordCallback)($data);
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return $this->record->toFormat('JSON');
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        return $this->record->toFormat('MARCXML');
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
        $data = [
            'record_format' => 'marc'
        ];

        // Try to find matches for IDs in link fields
        $fields = [
            '760', '762', '765', '767', '770', '772', '773', '774',
            '775', '776', '777', '780', '785', '786', '787'
        ];
        foreach ($fields as $code) {
            foreach ($this->record->getFields($code) as $fieldIdx => $marcfield) {
                foreach ($this->record->getSubfields($marcfield, 'w')
                    as $subfieldIdx => $marcsubfield
                ) {
                    $targetId = $marcsubfield;
                    $targetRecord = null;
                    if ($db) {
                        $linkingId = $this->createLinkingId($targetId);
                        $targetRecord = $db->findRecord(
                            [
                                'source_id' => $this->source,
                                'linking_id' => $linkingId
                            ],
                            ['projection' => ['_id' => 1]]
                        );
                        // Try with the original id if no exact match
                        if (!$targetRecord && $targetId !== $linkingId) {
                            $targetRecord = $db->findRecord(
                                [
                                    'source_id' => $this->source,
                                    'linking_id' => $targetId
                                ],
                                ['projection' => ['_id' => 1]]
                            );
                        }
                    }
                    if ($targetRecord) {
                        $targetId = $targetRecord['_id'];
                    } else {
                        $targetId = $this->idPrefix . '.' . $targetId;
                    }
                    $this->record->updateFieldSubfield(
                        $code,
                        $fieldIdx,
                        'w',
                        $subfieldIdx,
                        $targetId
                    );
                }
            }
        }

        // building
        $data['building'] = $this->getBuilding();

        // Location coordinates
        if ($geoField = $this->getDriverParam('geoField', $this->defaultGeoField)) {
            if ($geoLocations = $this->getGeographicLocations()) {
                $data[(string)$geoField] = $geoLocations;
                $centerField = $this->getDriverParam(
                    'geoCenterField',
                    $this->defaultGeoCenterField
                );
                if ($centerField) {
                    $centers = [];
                    foreach ($geoLocations as $geoLocation) {
                        $centers[] = $this->metadataUtils
                            ->getCenterCoordinates($geoLocation);
                    }
                    $data[$centerField] = $centers;
                }
                $displayField = $this->getDriverParam(
                    'geoDisplayField',
                    $this->defaultGeoDisplayField
                );
                if ($displayField) {
                    $display = [];
                    foreach ($geoLocations as $geoLocation) {
                        $display[] = $this->metadataUtils
                            ->getGeoDisplayField($geoLocation);
                    }
                    $data[$displayField] = $display;
                }
            }
        }

        // lccn
        $data['lccn'] = $this->getFieldSubfields('010', ['a']);
        $data['ctrlnum'] = $this->getFieldsSubfields(
            [[MarcHandler::GET_NORMAL, '035', ['a']]]
        );
        $data['fullrecord'] = $this->getFullRecord();
        $data['allfields'] = $this->getAllFields();

        // language
        $data['language'] = $this->getLanguages();

        $data['format'] = $this->getFormat();

        $primaryAuthors = $this->getPrimaryAuthors();
        $data['author'] = $primaryAuthors['names'];
        // Support for author_variant is currently not implemented
        $data['author_role'] = $primaryAuthors['relators'];
        if (isset($primaryAuthors['names'][0])) {
            $data['author_sort'] = $primaryAuthors['names'][0];
        }

        $secondaryAuthors = $this->getSecondaryAuthors();
        $data['author2'] = $secondaryAuthors['names'];
        // Support for author2_variant is currently not implemented
        $data['author2_role'] = $secondaryAuthors['relators'];

        $corporateAuthors = $this->getCorporateAuthors();
        $data['author_corporate'] = $corporateAuthors['names'];
        $data['author_corporate_role'] = $corporateAuthors['relators'];
        $data['author_additional'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '505', ['r']]
            ],
            true
        );

        $data['title'] = $this->getTitle();
        $data['title_sub'] = $this->getFieldSubfields(
            '245',
            ['b', 'n', 'p']
        );
        $data['title_short'] = $this->getShortTitle();
        $data['title_full'] = $this->getFullTitle();
        $data['title_alt'] = $this->getAltTitles();
        $data['title_old'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '780', ['a', 's', 't']]
            ]
        );
        $data['title_new'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '785', ['a', 's', 't']]
            ]
        );
        $data['title_sort'] = $this->getTitle(true);

        if (!$data['title_short']) {
            $data['title_short'] = $this->getFieldSubfields('240', ['a', 'n', 'p']);
            $data['title_full'] = $this->getFieldSubfields('240');
        }

        $data['series'] = $this->getSeries();

        $data['publisher'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '260', ['b']]
            ],
            false,
            true
        );
        if (!$data['publisher']) {
            $fields = $this->record->getFields('264');
            foreach ($fields as $field) {
                if ($this->record->getIndicator($field, 2) == '1') {
                    $data['publisher'] = [
                        $this->metadataUtils->stripTrailingPunctuation(
                            $this->record->getSubfield($field, 'b')
                        )
                    ];
                    break;
                }
            }
        }
        $publicationYear = $this->getPublicationYear();
        if ($publicationYear) {
            $data['publishDateSort'] = $publicationYear;
            $data['publishDate'] = [$publicationYear];
        }
        $data['physical'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '300', ['a', 'b', 'c', 'e', 'f', 'g']],
                [MarcHandler::GET_BOTH, '530', ['a', 'b', 'c', 'd']]
            ]
        );
        $data['dateSpan'] = $this->getFieldsSubfields(
            [[MarcHandler::GET_BOTH, '362', ['a']]]
        );
        $data['edition'] = $this->getFieldSubfields('250', ['a']);
        $data['contents'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '505', ['a']],
                [MarcHandler::GET_BOTH, '505', ['t']]
            ]
        );

        $data['isbn'] = $this->getISBNs();
        foreach ($this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '773', ['z']]
            ]
        ) as $isbn) {
            $isbn = str_replace('-', '', $isbn);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
                continue;
            }
            $isbn = $matches[1];
            if (strlen($isbn) == 10) {
                $isbn = $this->metadataUtils->isbn10to13($isbn);
            }
            if ($isbn) {
                $data['isbn'][] = $isbn;
            }
        }
        $data['issn'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '022', ['a']],
                [MarcHandler::GET_NORMAL, '440', ['x']],
                [MarcHandler::GET_NORMAL, '490', ['x']],
                [MarcHandler::GET_NORMAL, '730', ['x']],
                [MarcHandler::GET_NORMAL, '773', ['x']],
                [MarcHandler::GET_NORMAL, '776', ['x']],
                [MarcHandler::GET_NORMAL, '780', ['x']],
                [MarcHandler::GET_NORMAL, '785', ['x']]
            ]
        );
        foreach ($data['issn'] as &$value) {
            $value = str_replace('-', '', $value);
        }

        $data['callnumber-first'] = $this->getFirstFieldSubfields(
            [
                [MarcHandler::GET_NORMAL, '099', ['a']],
                [MarcHandler::GET_NORMAL, '090', ['a']],
                [MarcHandler::GET_NORMAL, '050', ['a']]
            ]
        );
        $value = $this->getFirstFieldSubfields(
            [
                [MarcHandler::GET_NORMAL, '090', ['a']],
                [MarcHandler::GET_NORMAL, '050', ['a']]
            ]
        );
        if ($value) {
            if (preg_match('/^([A-Z]+)/', strtoupper($value), $matches)) {
                $data['callnumber-subject'] = $matches[1];
            }

            [$preDotPart] = explode('.', $value, 2);
            $data['callnumber-label'] = strtoupper($preDotPart);
        }
        $data['callnumber-raw'] = array_map(
            'strtoupper',
            $this->getFieldsSubfields(
                [
                    [MarcHandler::GET_NORMAL, '080', ['a', 'b']],
                    [MarcHandler::GET_NORMAL, '084', ['a', 'b']],
                    [MarcHandler::GET_NORMAL, '050', ['a', 'b']]
                ]
            )
        );
        $useHILCC = $this->getDriverParam('useHILCC', false);
        $sortKey = '';
        foreach ($data['callnumber-raw'] as $callnumber) {
            $cn = new LcCallNumber($callnumber);
            // Store sort key even from an invalid CN in case we don't find a valid
            // one:
            if ('' === $sortKey) {
                $sortKey = $cn->getSortKey();
            }
            if (!$cn->isValid()) {
                continue;
            }
            if (empty($data['callnumber-sort'])) {
                $data['callnumber-sort'] = $cn->getSortKey();
            }
            if ($useHILCC && $category = $cn->getCategory()) {
                $data['category_str_mv'][] = $category;
            }
        }
        if (empty($data['callnumber-sort']) && $sortKey) {
            $data['callnumber-sort'] = $sortKey;
        }

        $data['topic'] = $this->getTopics();
        $data['genre'] = $this->getGenres();
        $data['geographic'] = $this->getGeographicTopics();
        $data['era'] = $this->getEras();

        $data['topic_facet'] = $this->getTopicFacets();
        $data['genre_facet'] = $this->getGenreFacets();
        $data['geographic_facet'] = $this->getGeographicFacets();
        $data['era_facet'] = $this->getEraFacets();

        $data['url'] = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '856', ['u']]
            ]
        );

        $data['illustrated'] = $this->getIllustrated();

        $deweyFields = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '082', ['a']],
                [MarcHandler::GET_NORMAL, '083', ['a']],
            ]
        );
        foreach ($deweyFields as $field) {
            $deweyCallNumber = new DeweyCallNumber($field);
            $data['dewey-hundreds'] = $deweyCallNumber->getNumber(100);
            $data['dewey-tens'] = $deweyCallNumber->getNumber(10);
            $data['dewey-ones'] = $deweyCallNumber->getNumber(1);
            $data['dewey-full'] = $deweyCallNumber->getSearchString();
            if (empty($data['dewey-sort'])) {
                $data['dewey-sort'] = $deweyCallNumber->getSortKey();
            }
            $data['dewey-raw'] = $field;
        }

        if ($res = $this->getOclcNumbers()) {
            $data['oclc_num'] = $res;
        }

        // Get warnings from the MARC handler last:
        foreach ($this->record->getWarnings() as $warning) {
            $this->storeWarning($warning);
        }

        return $data;
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        if ($this->getDriverParam('idIn999', false)) {
            if ($id = $this->getFieldSubfield('999', 'c')) {
                return $id;
            }
        }
        return $this->record->getControlField('001');
    }

    /**
     * Return record linking IDs (typically same as ID) used for links
     * between records in the data source
     *
     * @return array
     */
    public function getLinkingIDs()
    {
        $id = $this->record->getControlField('001');
        if ('' === $id && $this->getDriverParam('idIn999', false)) {
            // Koha style ID fallback
            $id = $this->getFieldSubfield('999', 'c');
        }
        $id = $this->createLinkingId($id);
        $results = [$id];

        $cns = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '035', ['a']]
            ]
        );
        if ($cns) {
            $results = array_merge($results, $cns);
        }

        return $results;
    }

    /**
     * Create a linking id from record id
     *
     * @param string $id Record id
     *
     * @return string
     */
    protected function createLinkingId($id)
    {
        if ('' !== $id && $this->getDriverParam('003InLinkingID', false)) {
            $source = $this->metadataUtils->stripTrailingPunctuation(
                $this->record->getControlField('003')
            );
            if ($source) {
                $id = "($source)$id";
            }
        }
        return $id;
    }

    /**
     * Return whether the record is a component part
     *
     * @return boolean
     */
    public function getIsComponentPart()
    {
        // We could look at the bibliographic level, but we need 773 to do anything
        // useful anyway..
        return !empty($this->record->getField('773'));
    }

    /**
     * Return host record IDs for a component part
     *
     * @return array
     */
    public function getHostRecordIDs()
    {
        $field = $this->record->getField('941');
        if ($field) {
            $hostId = $this->metadataUtils->stripControlCharacters(
                $this->record->getSubfield($field, 'a')
            );
            return [$hostId];
        }
        $ids = $this->getFieldsSubfields(
            [[MarcHandler::GET_NORMAL, '773', ['w']]],
            false,
            true,
            true
        );
        $ids = array_map(
            [$this->metadataUtils, 'stripControlCharacters'],
            $ids
        );
        if ($this->getDriverParam('003InLinkingID', false)) {
            // Check that the linking ids contain something in parenthesis
            $record003 = null;
            foreach ($ids as &$id) {
                if (strncmp('(', $id, 1) !== 0) {
                    if (null === $record003) {
                        $field = $this->record->getControlField('003');
                        $record003 = $field
                            ? $this->metadataUtils
                                ->stripControlCharacters($field)
                            : '';
                    }
                    if ('' !== $record003) {
                        $id = "($record003)$id";
                    }
                }
            }
        }
        return $ids;
    }

    /**
     * Component parts: get the volume that contains this component part
     *
     * @return string
     */
    public function getVolume()
    {
        $field773g = $this->getFieldSubfields('773', ['g']);
        if (!$field773g) {
            return '';
        }

        // Try to parse the data from different versions of 773g
        $matches = [];
        if (preg_match('/(\d*)\s*\((\d{4})\)\s*:\s*(\d*)/', $field773g, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Component parts: get the issue that contains this component part
     *
     * @return string
     */
    public function getIssue()
    {
        $field773g = $this->getFieldSubfields('773', ['g']);
        if (!$field773g) {
            return '';
        }

        // Try to parse the data from different versions of 773g
        $matches = [];
        if (preg_match('/(\d*)\s*\((\d{4})\)\s*:\s*(\d*)/', $field773g, $matches)) {
            return $matches[3];
        }
        if (preg_match('/(\d{4})\s*:\s*(\d*)/', $field773g, $matches)) {
            return $matches[2];
        }
        return '';
    }

    /**
     * Component parts: get the start page of this component part in the host record
     *
     * @return string
     */
    public function getStartPage()
    {
        $field773g = $this->getFieldSubfields('773', ['g']);
        if (!$field773g) {
            return '';
        }

        // Try to parse the data from different versions of 773g
        $matches = [];
        if (preg_match('/,\s*\w\.?\s*([\d,\-]+)/', $field773g, $matches)
            || preg_match('/^\w\.?\s*([\d,\-]+)/', $field773g, $matches)
        ) {
            $pages = explode('-', $matches[1]);
            return $pages[0];
        }
        return '';
    }

    /**
     * Component parts: get the container title
     *
     * @return string
     */
    public function getContainerTitle()
    {
        $first773 = $this->record->getField('773');
        return $this->metadataUtils->stripTrailingPunctuation(
            $this->record->getSubfield($first773, 't')
        );
    }

    /**
     * Component parts: get the free-form reference to the part in the container
     *
     * @return string
     */
    public function getContainerReference()
    {
        $first773 = $this->record->getField('773');
        return $this->metadataUtils->stripTrailingPunctuation(
            $this->record->getSubfield($first773, 'g')
        );
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
        $punctuation = ['b' => ' : ', 'n' => '. ', 'p' => '. ', 'c' => ' '];
        $acceptSubfields = ['b', 'n', 'p'];
        if ($forFiling) {
            $acceptSubfields[] = 'c';
        }
        $fallbackTitle = '';
        foreach (['245', '240'] as $fieldCode) {
            $field = $this->record->getField($fieldCode);
            if ($field) {
                $title = $this->record->getSubfield($field, 'a');
                if ($forFiling) {
                    $nonfiling = intval($this->record->getIndicator($field, 2));
                    if ($nonfiling > 0) {
                        $title = mb_substr($title, $nonfiling, null, 'UTF-8');
                    }
                }
                foreach ($field['subfields'] as $subfield) {
                    if (!in_array($subfield['code'], $acceptSubfields)) {
                        continue;
                    }
                    if (!$this->metadataUtils->hasTrailingPunctuation($title)) {
                        $title .= $punctuation[$subfield['code']];
                    } else {
                        $title .= ' ';
                    }
                    $title .= $subfield['data'];
                }
                if ($forFiling) {
                    $title = $this->metadataUtils->stripLeadingPunctuation($title);
                    $title = mb_strtolower($title, 'UTF-8');
                }
                $cleanTitle
                    = $this->metadataUtils->stripTrailingPunctuation($title);
                if (!empty($cleanTitle)) {
                    return $cleanTitle;
                } elseif ('' === $fallbackTitle) {
                    // Store a fallback title that we can return if as a last resort
                    // in case it contains only punctuation:
                    $fallbackTitle = $title;
                }
            }
        }
        return $fallbackTitle;
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $f100 = $this->record->getField('100');
        if ($f100) {
            $author = $this->record->getSubfield($f100, 'a');
            $order = $this->record->getIndicator($f100, 1);
            if ($order == 0 && strpos($author, ',') === false) {
                $author = $this->metadataUtils->convertAuthorLastFirst($author);
            }
            return $this->metadataUtils->stripTrailingPunctuation($author);
        } elseif ($f700 = $this->record->getField('700')) {
            $author = $this->record->getSubfield($f700, 'a');
            $order = $this->record->getIndicator($f700, 1);
            if ($order == 0 && strpos($author, ',') === false) {
                $author = $this->metadataUtils->convertAuthorLastFirst($author);
            }
            return $this->metadataUtils->stripTrailingPunctuation($author);
        }
        return '';
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     */
    public function getFullTitleForDebugging()
    {
        return $this->getFullTitle();
    }

    /**
     * Dedup: Return unique IDs (control numbers)
     *
     * @return array
     */
    public function getUniqueIDs()
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }
        $arr = [];
        $form = $this->config['Site']['unicode_normalization_form'] ?? 'NFKC';
        $f010 = $this->record->getField('010');
        if ($f010) {
            $lccn = $this->metadataUtils
                ->normalizeKey($this->record->getSubfield($f010, 'a'));
            if ($lccn) {
                $arr[] = "(lccn)$lccn";
            }
            $nucmc = $this->metadataUtils
                ->normalizeKey($this->record->getSubfield($f010, 'b'));
            if ($nucmc) {
                $arr[] = "(nucmc)$lccn";
            }
        }
        $nbn = $this->record->getField('015');
        if ($nbn) {
            $nr = $this->metadataUtils->normalizeKey(
                $this->record->getSubfield($nbn, 'a'),
                $form
            );
            $src = $this->record->getSubfield($nbn, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        $nba = $this->record->getField('016');
        if ($nba) {
            $nr = $this->metadataUtils->normalizeKey(
                $this->record->getSubfield($nba, 'a'),
                $form
            );
            $src = $this->record->getSubfield($nba, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        $id = $this->record->getField('024');
        if ($id) {
            $nr = $this->record->getSubfield($id, 'a');
            switch ($this->record->getIndicator($id, 1)) {
            case '0':
                $src = 'istc';
                break;
            case '1':
                $src = 'upc';
                break;
            case '2':
                $src = 'ismn';
                break;
            case '3':
                $src = 'ian';
                if ($p = strpos($nr, ' ')) {
                    $nr = substr($nr, 0, $p);
                }
                break;
            case '4':
                $src = 'sici';
                break;
            case '7':
                $src = $this->record->getSubfield($id, '2');
                break;
            default:
                $src = '';
            }
            $nr = $this->metadataUtils->normalizeKey($nr, $form);
            // Ignore any invalid ISMN
            if ('ismn' === $src && !preg_match('{([0-9]{13})}', $nr)) {
                $nr = '';
            }
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        foreach ($this->record->getFields('035') as $field) {
            $nr = $this->record->getSubfield($field, 'a');
            if ('' === $nr) {
                continue;
            }
            $match = false;
            foreach ($this->scnPatterns as $pattern) {
                if (preg_match("/$pattern/", $nr)) {
                    $match = true;
                    break;
                }
            }
            if ($match) {
                $arr[] = $this->metadataUtils->normalizeKey($nr);
            }
        }

        $this->resultCache[__METHOD__] = $arr;
        return $arr;
    }

    /**
     * Dedup: Return (unique) ISBNs in ISBN-13 format without dashes
     *
     * @return array
     */
    public function getISBNs()
    {
        $arr = [];
        $fields = $this->record->getFields('020');
        foreach ($fields as $field) {
            $original = $isbn = $this->record->getSubfield($field, 'a');
            if (!$isbn) {
                continue;
            }
            $isbn = $this->metadataUtils->normalizeISBN($isbn);
            if ($isbn) {
                $arr[] = $isbn;
            } else {
                $this->storeWarning("Invalid ISBN '$original'");
            }
        }

        return array_values(array_unique($arr));
    }

    /**
     * Dedup: Return ISSNs
     *
     * @return array
     */
    public function getISSNs()
    {
        $arr = [];
        $fields = $this->record->getFields('022');
        foreach ($fields as $field) {
            $issn = $this->record->getSubfield($field, 'a');
            if ($issn) {
                $arr[] = $issn;
            }
        }

        return $arr;
    }

    /**
     * Dedup: Return series ISSN
     *
     * @return string
     */
    public function getSeriesISSN()
    {
        return $this->getFieldSubfield('490', 'x');
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     */
    public function getSeriesNumbering()
    {
        return $this->getFieldSubfield('490', 'v');
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        // check the 007 - this is a repeating field
        $fields = $this->record->getControlFields('007');
        $online = false;
        foreach ($fields as $contents) {
            $formatCode = strtoupper(substr($contents, 0, 1));
            $formatCode2 = strtoupper(substr($contents, 1, 1));
            switch ($formatCode) {
            case 'A':
                switch ($formatCode2) {
                case 'D':
                    return 'Atlas';
                default:
                    return 'Map';
                }
                // @phpstan-ignore-next-line
                break;
            case 'C':
                switch ($formatCode2) {
                case 'A':
                    return 'TapeCartridge';
                case 'B':
                    return 'ChipCartridge';
                case 'C':
                    return 'DiscCartridge';
                case 'F':
                    return 'TapeCassette';
                case 'H':
                    return 'TapeReel';
                case 'J':
                    return 'FloppyDisk';
                case 'M':
                case 'O':
                    return 'CDROM';
                case 'R':
                    // Do not return - this will cause anything with an
                    // 856 field to be labeled as "Electronic"
                    $online = true;
                    break;
                default:
                    return 'Electronic';
                }
                break;
            case 'D':
                return 'Globe';
            case 'F':
                return 'Braille';
            case 'G':
                switch ($formatCode2) {
                case 'C':
                case 'D':
                    return 'Filmstrip';
                case 'T':
                    return 'Transparency';
                default:
                    return 'Slide';
                }
                // @phpstan-ignore-next-line
                break;
            case 'H':
                return 'Microfilm';
            case 'K':
                switch ($formatCode2) {
                case 'C':
                    return 'Collage';
                case 'D':
                    return 'Drawing';
                case 'E':
                    return 'Painting';
                case 'F':
                    return 'Print';
                case 'G':
                    return 'Photonegative';
                case 'J':
                    return 'Print';
                case 'L':
                    return 'TechnicalDrawing';
                case 'O':
                    return 'FlashCard';
                case 'N':
                    return 'Chart';
                default:
                    return 'Photo';
                }
                // @phpstan-ignore-next-line
                break;
            case 'M':
                switch ($formatCode2) {
                case 'F':
                    return 'VideoCassette';
                case 'R':
                    return 'Filmstrip';
                default:
                    return 'MotionPicture';
                }
                // @phpstan-ignore-next-line
                break;
            case 'O':
                return 'Kit';
            case 'Q':
                return 'MusicalScore';
            case 'R':
                return 'SensorImage';
            case 'S':
                switch ($formatCode2) {
                case 'D':
                    $size = strtoupper(substr($contents, 6, 1));
                    $material = strtoupper(substr($contents, 10, 1));
                    $soundTech = strtoupper(substr($contents, 13, 1));
                    if ($soundTech == 'D'
                        || ($size == 'G' && $material == 'M')
                    ) {
                        return 'CD';
                    }
                    return 'SoundDisc';
                case 'S':
                    return 'SoundCassette';
                default:
                    return 'SoundRecording';
                }
                // @phpstan-ignore-next-line
                break;
            case 'V':
                $videoFormat = strtoupper(substr($contents, 4, 1));
                switch ($videoFormat) {
                case 'S':
                    return 'BluRay';
                case 'V':
                    return 'DVD';
                }

                switch ($formatCode2) {
                case 'C':
                    return 'VideoCartridge';
                case 'D':
                    return 'VideoDisc';
                case 'F':
                    return 'VideoCassette';
                case 'R':
                    return 'VideoReel';
                default:
                    return 'Video';
                }
                // @phpstan-ignore-next-line
                break;
            }
        }

        // check the Leader at position 6
        $leader = $this->record->getLeader();
        $leaderBit = substr($leader, 6, 1);
        switch (strtoupper($leaderBit)) {
        case 'C':
        case 'D':
            return 'MusicalScore';
        case 'E':
        case 'F':
            return 'Map';
        case 'G':
            return 'Slide';
        case 'I':
            return 'SoundRecording';
        case 'J':
            return 'MusicRecording';
        case 'K':
            return 'Photo';
        case 'M':
            return 'Electronic';
        case 'O':
        case 'P':
            return 'Kit';
        case 'R':
            return 'PhysicalObject';
        case 'T':
            return 'Manuscript';
        }

        $field008 = $this->record->getControlField('008');
        if (!$online) {
            $online = substr($field008, 23, 1) === 'o';
        }

        // check the Leader at position 7
        $leaderBit = substr($leader, 7, 1);
        switch (strtoupper($leaderBit)) {
        // Monograph
        case 'M':
            if ($online) {
                return 'eBook';
            } else {
                return 'Book';
            }
            // @phpstan-ignore-next-line
            break;
        // Serial
        case 'S':
            // Look in 008 to determine what type of Continuing Resource
            $formatCode = strtoupper(substr($field008, 21, 1));
            switch ($formatCode) {
            case 'N':
                return $online ? 'eNewspaper' : 'Newspaper';
            case 'P':
                return $online ? 'eJournal' : 'Journal';
            default:
                return $online ? 'eSerial' : 'Serial';
            }
            // @phpstan-ignore-next-line
            break;

        case 'A':
            // Component part in monograph
            return $online ? 'eBookSection' : 'BookSection';
        case 'B':
            // Component part in serial
            return $online ? 'eArticle' : 'Article';
        case 'C':
            // Collection
            return 'Collection';
        case 'D':
            // Component part in collection (sub unit)
            return 'SubUnit';
        case 'I':
            // Integrating resource
            return 'ContinuouslyUpdatedResource';
        }
        return 'Other';
    }

    /**
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     */
    public function getPublicationYear()
    {
        $field = $this->record->getField('260');
        if ($field) {
            $year = $this->extractYear($this->record->getSubfield($field, 'c'));
            if ($year) {
                return $year;
            }
        }
        $fields = $this->record->getFields('264');
        foreach ($fields as $field) {
            if ($this->record->getIndicator($field, 2) == '1') {
                $year = $this->extractYear($this->record->getSubfield($field, 'c'));
                if ($year) {
                    return $year;
                }
            }
        }
        $field008 = $this->record->getControlField('008');
        if (!$field008) {
            return '';
        }
        $year = substr($field008, 7, 4);
        if ($year && $year != '0000' && $year != '9999') {
            return $this->extractYear($year);
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
        $field = $this->record->getField('300');
        if ($field) {
            $extent = $this->record->getSubfield($field, 'a');
            if ($extent && preg_match('/(\d+)/', $extent, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    /**
     * Dedup: Add the dedup key to a suitable field in the metadata.
     * Used when exporting records to a file.
     *
     * @param string $dedupKey Dedup key to be added
     *
     * @return void
     */
    public function addDedupKeyToMetadata($dedupKey)
    {
        if ($dedupKey) {
            $this->record->addField(
                '995',
                ' ',
                ' ',
                [
                    ['a' => $dedupKey]
                ]
            );
        } else {
            $this->record->deleteFields('995');
        }
    }

    /**
     * Get the building field
     *
     * @return array
     */
    protected function getBuilding()
    {
        $building = [];
        $buildingFieldSpec = $this->getDriverParam('buildingFields', false);
        if ($this->getDriverParam('holdingsInBuilding', true)
            || false !== $buildingFieldSpec
        ) {
            $buildingFieldSpec = $this->getDriverParam('buildingFields', false);
            if (false === $buildingFieldSpec) {
                $buildingFields = $this->getDefaultBuildingFields();
            } else {
                $buildingFields = [];
                $parts = explode(':', $buildingFieldSpec);
                foreach ($parts as $part) {
                    $buildingFields[] = [
                        'field' => substr($part, 0, 3),
                        'loc' => substr($part, 3, 1),
                        'sub' => substr($part, 4, 1),
                    ];
                }
            }

            foreach ($buildingFields as $buildingField) {
                foreach ($this->record->getFields($buildingField['field']) as $field
                ) {
                    $location
                        = $this->record->getSubfield($field, $buildingField['loc']);
                    if ($location) {
                        $subLocField = $buildingField['sub'];
                        if ($subLocField) {
                            $sub = $this->record->getSubfield($field, $subLocField);
                            if ($sub) {
                                $location = [$location, $sub];
                            }
                        }
                        $building[] = $location;
                    }
                }
            }
        }
        return $building;
    }

    /**
     * Get default fields used to populate the building field
     *
     * @return array
     */
    protected function getDefaultBuildingFields()
    {
        $useSub = $this->getDriverParam('subLocationInBuilding', '');
        $fields = [
            [
                'field' => '852',
                'loc' => 'b',
                'sub' => $useSub,
            ],
        ];
        if ($this->getDriverParam('kohaNormalization', false)
            || $this->getDriverParam('almaNormalization', false)
        ) {
            $itemSub = $this->getDriverParam('itemSubLocationInBuilding', $useSub);
            $fields[] = [
                'field' => '952',
                'loc' => 'b',
                'sub' => $itemSub,
            ];
        }
        return $fields;
    }

    /**
     * Get alternate titles
     *
     * @return array
     */
    protected function getAltTitles(): array
    {
        return array_values(
            array_unique(
                $this->getFieldsSubfields(
                    [
                        [MarcHandler::GET_ALT, '245', ['a', 'b']],
                        [MarcHandler::GET_BOTH, '130', [
                            'a', 'd', 'f', 'g', 'k', 'l', 'n', 'p', 's', 't'
                        ]],
                        [MarcHandler::GET_BOTH, '240', ['a']],
                        [MarcHandler::GET_BOTH, '246', ['a', 'b', 'n', 'p']],
                        [MarcHandler::GET_BOTH, '730', [
                            'a', 'd', 'f', 'g', 'k', 'l', 'n', 'p', 's', 't'
                        ]],
                        [MarcHandler::GET_BOTH, '740', ['a']]
                    ]
                )
            )
        );
    }

    /**
     * Check if the work is illustrated
     *
     * @return string
     */
    protected function getIllustrated()
    {
        $leader = $this->record->getLeader();
        if (in_array(substr($leader, 6, 1), ['a', 't'])) {
            $illustratedCodes = [
                'a' => 1,
                'b' => 1,
                'c' => 1,
                'd' => 1,
                'e' => 1,
                'f' => 1,
                'g' => 1,
                'h' => 1,
                'i' => 1,
                'j' => 1,
                'k' => 1,
                'l' => 1,
                'm' => 1,
                'o' => 1,
                'p' => 1
            ];

            // 008
            $field008 = $this->record->getControlField('008');
            for ($pos = 18; $pos <= 21; $pos++) {
                $ch = substr($field008, $pos, 1);
                if ('' !== $ch && isset($illustratedCodes[$ch])) {
                    return 'Illustrated';
                }
            }

            // 006
            foreach ($this->record->getControlFields('006') as $field006) {
                for ($pos = 1; $pos <= 4; $pos++) {
                    $ch = substr($field006, $pos, 1);
                    if ('' !== $ch && isset($illustratedCodes[$ch])) {
                        return 'Illustrated';
                    }
                }
            }
        }

        // Now check for interesting strings in 300 subfield b:
        foreach ($this->record->getFields('300') as $field300) {
            $sub = strtolower($this->record->getSubfield($field300, 'b'));
            foreach ($this->illustrationStrings as $illStr) {
                if (strpos($sub, $illStr) !== false) {
                    return 'Illustrated';
                }
            }
        }
        return 'Not Illustrated';
    }

    /**
     * Get key data that can be used to identify expressions of a work
     *
     * Returns an associative array like this where each set of keys defines the
     * keys for a work (multiple sets can be returned for compound works):
     *
     * [
     *   [
     *     'titles' => [
     *       ['type' => 'title', 'value' => 'Title'],
     *       ['type' => 'uniform', 'value' => 'Uniform Title']
     *      ],
     *     'authors' => [
     *       ['type' => 'author', 'value' => 'Name 1'],
     *       ['type' => 'author', 'value' => 'Name 2']
     *     ],
     *     'titlesAltScript' => [
     *       ['type' => 'title', 'value' => 'Title in alternate script'],
     *       ['type' => 'uniform', 'value' => 'Uniform Title in alternate script']
     *     ],
     *     'authorsAltScript' => [
     *       ['type' => 'author', 'value' => 'Name 1 in alternate script'],
     *       ['type' => 'author', 'value' => 'Name 2 in alternate script']
     *     ]
     *   ],
     *   [
     *     'type' => 'analytical',
     *     'titles' => [...],
     *     'authors' => [...],
     *     'titlesAltScript' => [...]
     *     'authorsAltScript' => [...]
     *   ]
     * ]
     *
     * @return array
     */
    public function getWorkIdentificationData()
    {
        $authorFields = [
            '100' => ['a', 'b'],
            '110' => ['a', 'b'],
            '111' => ['a', 'c'],
            '700' => ['a', 'b'],
            '710' => ['a', 'b'],
            '711' => ['a', 'c']
        ];
        $titleFields = [
            '130' => ['n', 'p'],
            '730' => ['n', 'p'],
            '240' => ['n', 'p', 'm', 'r'],
            '245' => ['b', 'n'],
            '246' => ['b', 'n'],
            '247' => ['b', 'n'],
        ];

        $authors = [];
        $authorsAltScript = [];
        $titles = [];
        $titlesAltScript = [];

        $analytical = [];
        foreach ($authorFields as $tag => $subfields) {
            $tag = (string)$tag;
            foreach ($this->record->getFields($tag) as $field) {
                // Check for analytical entries to be processed later:
                if (in_array($tag, ['700', '710', '711'])
                    && (int)$this->record->getIndicator($field, 2) === 2
                ) {
                    $analytical[$tag][] = $field;
                    continue;
                }

                // Take only first author:
                if ($authors) {
                    continue;
                }

                $author = $this->getSubfields($field, $subfields);
                if ($author) {
                    $authors[] = [
                        'type' => 'author',
                        'value' => $author
                    ];

                    $linkedAuthors = $this->record->getLinkedSubfieldsFrom880(
                        $tag,
                        $this->record->getSubfield($field, '6'),
                        $subfields
                    );

                    foreach ($linkedAuthors as $altAuthor) {
                        $authorsAltScript[] = [
                            'type' => 'author',
                            'value' => $altAuthor
                        ];
                    }
                }
            }
        }

        foreach ($titleFields as $tag => $subfields) {
            $tag = (string)$tag;
            $field = $this->record->getField($tag);
            if (!$field) {
                continue;
            }

            $title = '';
            $altTitles = [];
            switch ($tag) {
            case '130':
            case '730':
                $nonFilingInd = 1;
                break;
            case '246':
                $nonFilingInd = null;
                break;
            default:
                $nonFilingInd = 2;
            }

            $title = $this->record->getSubfield($field, 'a');
            if (null !== $nonFilingInd) {
                $nonfiling = (int)$this->record->getIndicator($field, $nonFilingInd);
                if ($nonfiling > 0) {
                    $title = mb_substr($title, $nonfiling, null, 'UTF-8');
                }
            }
            $rest = $this->getSubfields($field, $subfields);
            if ($rest) {
                $title .= " $rest";
            }

            $linkedFields = $this->record->getLinkedFieldsFrom880(
                $tag,
                $this->record->getSubfield($field, '6')
            );
            foreach ($linkedFields as $f880) {
                $altTitle = $this->record->getSubfield($f880, 'a');
                if (null !== $nonFilingInd) {
                    $nonfiling
                        = (int)$this->record->getIndicator($f880, $nonFilingInd);
                    if ($nonfiling > 0) {
                        $altTitle = mb_substr($altTitle, $nonfiling, null, 'UTF-8');
                    }
                }
                $rest = $this->getSubfields($f880, $subfields);
                if ($rest) {
                    $altTitle .= " $rest";
                }
                if ($altTitle) {
                    $altTitles[] = $altTitle;
                }
            }
            $titleType = ('130' == $tag || '730' == $tag) ? 'uniform' : 'title';
            if ($title) {
                $titles[] = [
                    'type' => $titleType,
                    'value' => $title
                ];
            }
            foreach ($altTitles as $altTitle) {
                $titlesAltScript[] = [
                    'type' => $titleType,
                    'value' => $altTitle
                ];
            }
        }

        if (!$titles) {
            return [];
        }

        $result = [
            compact('authors', 'authorsAltScript', 'titles', 'titlesAltScript')
        ];

        // Process any analytical entries
        foreach ($analytical as $tag => $fields) {
            foreach ($fields as $field) {
                $title = $this->getSubfields(
                    $field,
                    ['t', 'n', 'p', 'm', 'r']
                );
                if (!$title) {
                    continue;
                }
                $author = $this->getSubfields($field, $authorFields[$tag]);
                $altTitle = '';
                $altAuthor = '';

                $altTitleField = $this->record->getLinkedField('880', (string)$tag);
                if ($altTitleField) {
                    $altTitle = $this->record->getSubfield($altTitleField, 'a');
                    if ($altTitle) {
                        $altAuthor = $this->getSubfields(
                            $altTitleField,
                            $authorFields[$tag]
                        );
                    }
                }

                $result[] = [
                    'type' => 'analytical',
                    'authors' => [['type' => 'author', 'value' => $author]],
                    'authorsAltScript' => $altAuthor
                        ? [['type' => 'author', 'value' => $altAuthor]]
                        : [],
                    'titles' => [['type' => 'title', 'value' => $title]],
                    'titlesAltScript' => $altTitle
                        ? [['type' => 'title', 'value' => $altTitle]]
                        : []
                ];
            }
        }

        return $result;
    }

    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
        // Koha and Alma record normalization. For Alma normalization to work,
        // item information must be mapped in the enrichments for the publishing
        // process so that it's similar to what Koha does:
        // [x] Add Items Information
        //   Repeatable field: 952
        //   Barcode subfield: p
        //   Item status subfield: 1
        //   Enumeration A subfield: h
        //   Enumeration B subfield: h
        //   Chronology I subfield: h
        //   Chronology J subfield: h
        //   Permanent library subfield: a
        //   Permanent location subfield: a
        //   Current library subfield: b
        //   Current location subfield: c
        //   Call number subfield: o
        //   Public note subfield: z
        //   Due back date subfield: q
        //
        // See https://www.kiwi.fi/x/vAALC for illustration.
        //
        // Note that if kohaNormalization or almaNormalization is enabled, the
        // "building" field in Solr is populated from both 852 and 952. This can be
        // overridden with the buildingFields driver param.
        $koha = $this->getDriverParam('kohaNormalization', false);
        $alma = $this->getDriverParam('almaNormalization', false);
        if ($koha || $alma) {
            // Convert items to holdings
            $useHome = $koha && $this->getDriverParam('kohaUseHomeBranch', false);
            $holdings = [];
            $availableBuildings = [];
            foreach ($this->record->getFields('952') as $field952) {
                $key = [];
                $holding = [];
                $branch
                    = $this->record->getSubfield($field952, $useHome ? 'a' : 'b');
                $key[] = $branch;
                // Always use subfield 'b' for location regardless of where it came
                // from
                $holding[] = ['b' => $branch];
                foreach (['c', 'h', 'o', '8'] as $code) {
                    $value = $this->record->getSubfield($field952, $code);
                    $key[] = $value;
                    if ('' !== $value) {
                        $holding[] = [$code => $value];
                    }
                }

                if ($alma) {
                    $available = $this->record->getSubfield($field952, '1') == 1;
                } else {
                    // Availability
                    static $subfieldsExist = [
                        '0', // Withdrawn
                        '1', // Lost
                        '4', // Damaged
                        'q', // Due date
                    ];
                    $available = true;
                    foreach ($subfieldsExist as $code) {
                        if ($this->record->getSubfield($field952, $code)) {
                            $available = false;
                            break;
                        }
                    }
                    if ($available) {
                        // Not for loan?
                        $status = $this->record->getSubfield($field952, '7');
                        $available = $status === '0' || $status === '1';
                    }
                }

                $key = implode('//', $key);
                if ($available) {
                    $availableBuildings[$key] = 1;
                }

                $holdings[$key] = $holding;
            }
            $this->record->deleteFields('952');
            foreach ($holdings as $key => $holding) {
                if (isset($availableBuildings[$key])) {
                    $holding[] = ['9' => 1];
                }
                $this->record->addField('952', ' ', ' ', $holding);
            }
        }

        if ($koha) {
            // Verify that 001 exists
            if ('' === $this->record->getControlField('001')) {
                if ($id = $this->getFieldSubfields('999', ['c'])) {
                    $this->record->deleteFields('001');
                    $this->record->addField('001', '', '', $id);
                }
            }
        }

        if ($alma) {
            // Add a prefixed id to field 090 to indicate that the record is from
            // Alma. Used at least with OpenURL.
            $id = $this->record->getControlField('001');
            $this->record->addField('090', ' ', ' ', [['a' => "(Alma)$id"]]);
        }
    }

    /**
     * Get all topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawTopicIds(): array
    {
        return $this->record->getFieldsSubfields('650', ['0'], null);
    }

    /**
     * Get all geographic topic identifiers (for enrichment)
     *
     * @return array
     */
    public function getRawGeographicTopicIds(): array
    {
        return $this->record->getFieldsSubfields('651', ['0'], null);
    }

    /**
     * Get music identifiers (for enrichment)
     *
     * @return array
     */
    public function getMusicIds(): array
    {
        $leader = $this->record->getLeader();
        if (substr($leader, 6, 1) !== 'j') {
            return [];
        }

        $indToTypeMap = [
            'x0' => 'isrc',
            'x1' => 'upc',
            'x2' => 'ismn',
            'x3' => 'ian',
        ];

        $result = [];
        foreach ($this->record->getFields('024') as $field024) {
            $ind1 = $this->record->getIndicator($field024, 1);
            if (in_array($ind1, ['0', '1', '2', '3', '7'])
                && ($id = $this->record->getSubfield($field024, 'a'))
            ) {
                $type = $indToTypeMap["x$ind1"]
                    ?? $this->record->getSubfield($field024, '2');
                $result[] = compact('id', 'type');
            }
        }

        return $result;
    }

    /**
     * Get publisher numbers (for enrichment)
     *
     * @return array
     */
    public function getPublisherNumbers(): array
    {
        $result = [];
        foreach ($this->record->getFields('028') as $field028) {
            $id = $this->record->getSubfield($field028, 'a');
            $source = $this->record->getSubfield($field028, 'b');
            $result[] = compact('id', 'source');
        }
        return $result;
    }

    /**
     * Get short title
     *
     * @return string
     */
    public function getShortTitle(): string
    {
        $title = $this->getFieldSubfields('245', ['a'], false);
        // Try to clean up the title but return original if it only contains
        // punctuation:
        return $this->metadataUtils->stripTrailingPunctuation($title, '', true);
    }

    /**
     * Get full title
     *
     * @return string
     */
    protected function getFullTitle(): string
    {
        $title = $this->getFieldSubfields(
            '245',
            ['a', 'b', 'c', 'f', 'g', 'h', 'k', 'n', 'p', 's'],
            false
        );
        // Try to clean up the title but return original if it only contains
        // punctuation:
        return $this->metadataUtils->stripTrailingPunctuation($title, '', true);
    }

    /**
     * Get specified subfields
     *
     * @param array $field MARC Field
     * @param array $codes Accepted subfield codes
     *
     * @return array Subfields
     */
    protected function getSubfieldsArray(array $field, array $codes): array
    {
        $data = [];
        if (!is_array($field['subfields'] ?? null)) {
            return $data;
        }
        foreach ($field['subfields'] as $subfield) {
            if (in_array((string)$subfield['code'], $codes)) {
                $data[] = $subfield['data'];
            }
        }
        return $data;
    }

    /**
     * Get specified subfields
     *
     * @param array $field MARC Field
     * @param array $codes Accepted subfield codes
     *
     * @return string Concatenated subfields (space-separated)
     */
    protected function getSubfields(array $field, array $codes): string
    {
        $data = $this->getSubfieldsArray($field, $codes);
        return implode(' ', $data);
    }

    /**
     * Get specified subfields from all occurrences of a field
     *
     * @param string  $tag                      Field to get
     * @param array   $codes                    Accepted subfields (optional)
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation
     *                                          from the results
     *
     * @return string Concatenated subfields (space-separated)
     */
    protected function getFieldSubfields(
        string $tag,
        array $codes = [],
        bool $stripTrailingPunctuation = true
    ): string {
        $key = __METHOD__ . "$tag-" . implode(',', $codes) . '-'
            . ($stripTrailingPunctuation ? '1' : '0');

        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $result = $this->record->getFieldsSubfields($tag, $codes);
        $result = implode(' ', $result);
        if ($result && $stripTrailingPunctuation) {
            $result = $this->metadataUtils->stripTrailingPunctuation($result);
        }

        $this->resultCache[$key] = $result;
        return $result;
    }

    /**
     * Get first occurrence of the requested subfield
     *
     * @param string  $tag                      Field to get
     * @param string  $code                     Subfield to get
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation
     *                                          from the result
     *
     * @return string
     */
    protected function getFieldSubfield(
        string $tag,
        string $code,
        bool $stripTrailingPunctuation = true
    ) {
        $key = __METHOD__ . "-$tag-$code-" . ($stripTrailingPunctuation ? '1' : '0');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $result = '';
        foreach ($this->record->getFields($tag) as $field) {
            if ($result = $this->record->getSubfield($field, $code)) {
                if ($stripTrailingPunctuation) {
                    $result
                        = $this->metadataUtils->stripTrailingPunctuation($result);
                }
                break;
            }
        }
        $this->resultCache[$key] = $result;
        return $result;
    }

    /**
     * Get all subfields of specified fields
     *
     * @param string $tag Field tag
     *
     * @return array
     */
    protected function getFieldsAllSubfields(string $tag): array
    {
        $data = [];
        foreach ($this->record->getFields($tag) as $field) {
            $fieldContents = $this->getAllSubfields($field);
            if ($fieldContents) {
                $data[] = $fieldContents;
            }
        }
        return $data;
    }

    /**
     * Get subfields for the first found field according to the fieldspecs
     *
     * @param array $fieldspecs Fields to get
     *
     * @return string Concatenated subfields (space-separated)
     */
    protected function getFirstFieldSubfields(array $fieldspecs): string
    {
        $data = $this->getFieldsSubfields($fieldspecs, true);
        if (!empty($data)) {
            return $data[0];
        }
        return '';
    }

    /**
     * Get all subfields of the given field
     *
     * @param array $field   Field
     * @param array $exclude Subfields codes to be excluded (optional)
     *
     * @return array All subfields
     */
    protected function getAllSubfields(array $field, array $exclude = []): array
    {
        if (!$field) {
            return [];
        }

        $subfields = [];
        foreach ($field['subfields'] as $subfield) {
            if (in_array($subfield['code'], $exclude)) {
                continue;
            }
            $subfields[] = $subfield['data'];
        }
        return $subfields;
    }

    /**
     * Get an array of all fields relevant to allfields search
     *
     * @return array
     */
    protected function getAllFields(): array
    {
        $excludedSubfields = [
            '650' => ['0', '2', '6', '8'],
            '773' => ['6', '7', '8', 'w'],
            '856' => ['6', '8', 'q']
        ];
        $allFields = [];
        foreach ($this->record->getAllFields() as $field) {
            $tag = $field['tag'];
            if (($tag >= 100 && $tag < 841) || $tag == 856 || $tag == 880) {
                $subfields = $this->getAllSubfields(
                    $field,
                    $excludedSubfields[$tag] ?? ['0', '6', '8']
                );
                if ($subfields) {
                    $allFields = array_merge($allFields, $subfields);
                }
            }
        }
        $allFields = array_map(
            function ($str) {
                return $this->metadataUtils->stripTrailingPunctuation(
                    $this->metadataUtils->stripLeadingPunctuation($str, null, false)
                );
            },
            $allFields
        );
        return array_values(array_unique($allFields));
    }

    /**
     * Return an array of fields according to the fieldspecs.
     *
     * @param array   $fieldspecs               Fields to get
     * @param boolean $firstOnly                Return only first matching field
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation
     *                                          from the results
     * @param boolean $splitSubfields           Whether to split subfields to
     *                                          separate array items
     *
     * @return array Subfields
     */
    protected function getFieldsSubfields(
        array $fieldspecs,
        bool $firstOnly = false,
        bool $stripTrailingPunctuation = true,
        bool $splitSubfields = false
    ): array {
        $result = $this->record->getFieldsSubfieldsBySpecs(
            $fieldspecs,
            $firstOnly,
            $splitSubfields
        );
        if ($result && $stripTrailingPunctuation) {
            $result = array_map(
                [$this->metadataUtils, 'stripTrailingPunctuation'],
                $result
            );
        }

        return $result;
    }

    /**
     * Get all non-specific topics
     *
     * @return array
     */
    protected function getTopics()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '600', [
                    'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 'l', 'm',
                    'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z'
                ]],
                [MarcHandler::GET_BOTH, '610', [
                    'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'k', 'l', 'm', 'n',
                    'o', 'p', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z'
                ]],
                [MarcHandler::GET_BOTH, '611', [
                    'a', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 'l', 'n', 'p',
                    'q', 's', 't', 'u', 'v', 'x', 'y', 'z'
                ]],
                [MarcHandler::GET_BOTH, '630', [
                    'a', 'd', 'e', 'f', 'g', 'h', 'k', 'l', 'm', 'n', 'o', 'p',
                    'r', 's', 't', 'v', 'x', 'y', 'z'
                ]],
                [MarcHandler::GET_BOTH, '650', [
                    'a', 'b', 'c', 'd', 'e', 'v', 'x', 'y', 'z'
                ]]
            ]
        );
    }

    /**
     * Get all genre topics
     *
     * @return array
     */
    protected function getGenres()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '655', ['a', 'b', 'c', 'v', 'x', 'y', 'z']]
            ]
        );
    }

    /**
     * Get all geographic topics
     *
     * @return array
     */
    protected function getGeographicTopics()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '651', ['a', 'e', 'v', 'x', 'y', 'z']]
            ]
        );
    }

    /**
     * Get all era topics
     *
     * @return array
     */
    protected function getEras()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '648', ['a', 'v', 'x', 'y', 'z']]
            ]
        );
    }

    /**
     * Get topic facet fields
     *
     * @return array Topics
     */
    protected function getTopicFacets()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '600', ['x']],
                [MarcHandler::GET_NORMAL, '610', ['x']],
                [MarcHandler::GET_NORMAL, '611', ['x']],
                [MarcHandler::GET_NORMAL, '630', ['x']],
                [MarcHandler::GET_NORMAL, '648', ['x']],
                [MarcHandler::GET_NORMAL, '650', ['a']],
                [MarcHandler::GET_NORMAL, '650', ['x']],
                [MarcHandler::GET_NORMAL, '651', ['x']],
                [MarcHandler::GET_NORMAL, '655', ['x']]
            ],
            false,
            true,
            true
        );
    }

    /**
     * Get genre facet fields
     *
     * @return array Topics
     */
    protected function getGenreFacets()
    {
        return (array)$this->metadataUtils->ucFirst(
            $this->getFieldsSubfields(
                [
                    [MarcHandler::GET_NORMAL, '600', ['v']],
                    [MarcHandler::GET_NORMAL, '610', ['v']],
                    [MarcHandler::GET_NORMAL, '611', ['v']],
                    [MarcHandler::GET_NORMAL, '630', ['v']],
                    [MarcHandler::GET_NORMAL, '648', ['v']],
                    [MarcHandler::GET_NORMAL, '650', ['v']],
                    [MarcHandler::GET_NORMAL, '651', ['v']],
                    [MarcHandler::GET_NORMAL, '655', ['a']],
                    [MarcHandler::GET_NORMAL, '655', ['v']]
                ],
                false,
                true,
                true
            )
        );
    }

    /**
     * Get geographic facet fields
     *
     * @return array Topics
     */
    protected function getGeographicFacets()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '600', ['z']],
                [MarcHandler::GET_NORMAL, '610', ['z']],
                [MarcHandler::GET_NORMAL, '611', ['z']],
                [MarcHandler::GET_NORMAL, '630', ['z']],
                [MarcHandler::GET_NORMAL, '648', ['z']],
                [MarcHandler::GET_NORMAL, '650', ['z']],
                [MarcHandler::GET_NORMAL, '651', ['a']],
                [MarcHandler::GET_NORMAL, '651', ['z']],
                [MarcHandler::GET_NORMAL, '655', ['z']]
            ],
            false,
            true,
            true
        );
    }

    /**
     * Get era facet fields
     *
     * @return array Topics
     */
    protected function getEraFacets()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '630', ['y']],
                [MarcHandler::GET_NORMAL, '648', ['a']],
                [MarcHandler::GET_NORMAL, '648', ['y']],
                [MarcHandler::GET_NORMAL, '650', ['y']],
                [MarcHandler::GET_NORMAL, '651', ['y']],
                [MarcHandler::GET_NORMAL, '655', ['y']]
            ],
            false,
            true,
            true
        );
    }

    /**
     * Get all language codes
     *
     * @return array Language codes
     */
    protected function getLanguages()
    {
        $languages = [substr($this->record->getControlField('008'), 35, 3)];
        $languages2 = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '041', ['a']],
                [MarcHandler::GET_NORMAL, '041', ['d']],
                [MarcHandler::GET_NORMAL, '041', ['h']],
                [MarcHandler::GET_NORMAL, '041', ['j']]
            ],
            false,
            true,
            true
        );
        $result = array_merge($languages, $languages2);
        return $this->metadataUtils->normalizeLanguageStrings($result);
    }

    /**
     * Normalize relator codes
     *
     * @param array $relators Relators
     *
     * @return array
     */
    protected function normalizeRelators($relators)
    {
        return array_map(
            [$this->metadataUtils, 'normalizeRelator'],
            $relators
        );
    }

    /**
     * Get authors by relator codes
     *
     * @param array $fieldSpecs        Fields to retrieve
     * @param array $relators          Allowed relators
     * @param array $noRelatorRequired Field that is accepted if it doesn't have a
     *                                 relator
     * @param bool  $altScript         Whether to return also alternate scripts
     *                                 relator
     * @param bool  $invertMatch       Return authors that DON'T HAVE an allowed
     *                                 relator
     *
     * @return array Array keyed by 'names' for author names, 'fuller' for fuller
     * forms and 'relators' for relator codes
     */
    protected function getAuthorsByRelator(
        $fieldSpecs,
        $relators,
        $noRelatorRequired,
        $altScript = true,
        $invertMatch = false
    ) {
        $result = [
            'names' => [], 'fuller' => [], 'relators' => [],
            'ids' => [], 'idRoles' => []
        ];
        foreach ($fieldSpecs as $tag => $subfieldList) {
            foreach ($this->record->getFields($tag) as $field) {
                $fieldRelators = $this->normalizeRelators(
                    $this->getSubfieldsArray($field, ['4', 'e'])
                );

                $match = empty($relators);
                if (!$match) {
                    $match = empty($fieldRelators)
                        && in_array($tag, $noRelatorRequired);
                }
                if (!$match) {
                    $match = !empty(array_intersect($relators, $fieldRelators));
                    if ($invertMatch) {
                        $match = !$match;
                    }
                }
                if (!$match) {
                    continue;
                }

                $terms = $this->getSubfields($field, $subfieldList);

                if ($altScript) {
                    $linkedTerms = $this->record->getLinkedSubfieldsFrom880(
                        $tag,
                        $this->record->getSubfield($field, '6'),
                        $subfieldList
                    );

                    if ($linkedTerms) {
                        $terms .= ' ' . implode(' ', $linkedTerms);
                    }
                }
                $result['names'][] = $this->metadataUtils->stripTrailingPunctuation(
                    trim($terms)
                );

                $fuller = ($tag == '100' || $tag == '700')
                    ? $this->record->getSubfields($field, 'q') : '';
                if ($fuller) {
                    $result['fuller'][] = $this->metadataUtils
                        ->stripTrailingPunctuation(trim(implode(' ', $fuller)));
                }

                if ($fieldRelators) {
                    $result['relators'][] = reset($fieldRelators);
                } else {
                    $result['relators'][] = '-';
                }
                if ($authId = $this->record->getSubfield($field, '0')) {
                    $result['ids'][] = $authId;
                    if ($role = $this->record->getSubfield($field, 'e')) {
                        $result['idRoles'][]
                            = $this->formatAuthorIdWithRole(
                                $authId,
                                $this->metadataUtils
                                    ->stripTrailingPunctuation($role, '. ')
                            );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get primary authors
     *
     * @return array
     */
    protected function getPrimaryAuthors()
    {
        $fieldSpecs = [
            '100' => ['a', 'b', 'c', 'd'],
            '700' => [
                'a', 'q', 'b', 'c', 'd'
            ]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            $this->primaryAuthorRelators,
            ['100']
        );
    }

    /**
     * Get secondary authors
     *
     * @return array
     */
    protected function getSecondaryAuthors()
    {
        $fieldSpecs = [
            '100' => ['a', 'b', 'c', 'd'],
            '700' => [
                'a', 'b', 'c', 'd'
            ]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            $this->primaryAuthorRelators,
            ['700'],
            true,
            true
        );
    }

    /**
     * Get corporate authors
     *
     * @return array
     */
    protected function getCorporateAuthors()
    {
        $fieldSpecs = [
            '110' => ['a', 'b'],
            '111' => ['a', 'b'],
            '710' => ['a', 'b'],
            '711' => ['a', 'b']
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            [],
            ['110', '111', '710', '711']
        );
    }

    /**
     * Extract a year from a field such as publication date.
     *
     * @param string $field Field
     *
     * @return string
     */
    protected function extractYear($field)
    {
        // First look for a year in brackets
        if (preg_match('/\[(.+)\]/', $field, $matches)) {
            if (preg_match('/(\d{4})/', $matches[1], $matches)) {
                return $matches[1];
            }
        }
        // Then look for any year
        if (preg_match('/(\d{4})/', $field, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Get geographic locations
     *
     * @return array
     */
    protected function getGeographicLocations()
    {
        $result = [];
        foreach ($this->record->getFields('034') as $field) {
            $westOrig = $this->record->getSubfield($field, 'd');
            $eastOrig = $this->record->getSubfield($field, 'e');
            $northOrig = $this->record->getSubfield($field, 'f');
            $southOrig = $this->record->getSubfield($field, 'g');
            $west = $this->metadataUtils->coordinateToDecimal($westOrig);
            $east = $this->metadataUtils->coordinateToDecimal($eastOrig);
            $north = $this->metadataUtils->coordinateToDecimal($northOrig);
            $south = $this->metadataUtils->coordinateToDecimal($southOrig);

            if (!is_nan($west) && !is_nan($north)) {
                if (($west < -180 || $west > 180) || ($north < -90 || $north > 90)) {
                    $this->logger->logDebug(
                        'Marc',
                        "Discarding invalid coordinates $west,$north decoded from "
                            . "w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig,"
                            . " record {$this->source}." . $this->getID()
                    );
                    $this->storeWarning('invalid coordinates in 034');
                } else {
                    if (!is_nan($east) && !is_nan($south)
                        && ($east !== $west || $north !== $south)
                    ) {
                        if ($east < -180 || $east > 180 || $south < -90
                            || $south > 90
                        ) {
                            $this->logger->logDebug(
                                'Marc',
                                "Discarding invalid coordinates $east,$south "
                                    . "decoded from w=$westOrig, e=$eastOrig, "
                                    . "n=$northOrig, s=$southOrig, record "
                                    . "{$this->source}." . $this->getID()
                            );
                            $this->storeWarning('invalid coordinates in 034');
                        } else {
                            // Try to cope with weird coordinate order
                            if ($north > $south) {
                                [$north, $south] = [$south, $north];
                            }
                            if ($west > $east) {
                                [$west, $east] = [$east, $west];
                            }
                            $result[] = "ENVELOPE($west, $east, $south, $north)";
                        }
                    } else {
                        $result[] = "POINT($west $north)";
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get OCLC numbers
     *
     * @return array
     */
    protected function getOclcNumbers()
    {
        $result = [];

        $ctrlNums = $this->getFieldsSubfields(
            [
                [MarcHandler::GET_NORMAL, '035', ['a']]
            ]
        );
        foreach ($ctrlNums as $ctrlNum) {
            $ctrlLc = mb_strtolower($ctrlNum, 'UTF-8');
            if (strncmp($ctrlLc, '(ocolc)', 7) === 0
                || strncmp($ctrlLc, 'ocm', 3) === 0
                || strncmp($ctrlLc, 'ocn', 3) === 0
                || strncmp($ctrlLc, 'on', 2) === 0
            ) {
                foreach ($this->oclcNumPatterns as $pattern) {
                    if (preg_match($pattern, $ctrlNum, $matches)) {
                        $result[] = $matches[1];
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Combine author id and role into a string that can be indexed.
     *
     * @param string $id   Id
     * @param string $role Role
     *
     * @return string
     */
    protected function formatAuthorIdWithRole($id, $role)
    {
        return '';
    }

    /**
     * Get series information
     *
     * @return array
     */
    protected function getSeries()
    {
        return $this->getFieldsSubfields(
            [
                [MarcHandler::GET_BOTH, '440', ['a']],
                [MarcHandler::GET_BOTH, '490', ['a']],
                [MarcHandler::GET_BOTH, '800', [
                    'a', 'b', 'c', 'd', 'f', 'p', 'q', 't'
                ]],
                [MarcHandler::GET_BOTH, '830', ['a', 'p']]
            ]
        );
    }

    /**
     * Serialize full record to a string
     *
     * @return string
     */
    protected function getFullRecord(): string
    {
        $format = $this->config['MarcRecord']['solr_serialization'] ?? 'JSON';
        $result = $this->record->toFormat($format);
        if (!$result && 'ISO2709' === $format) {
            // If the record exceeds 99999 bytes, it doesn't fit into ISO 2709, so
            // use MARCXML as a fallback:
            $result = $this->record->toFormat('MARCXML');
        }
        return $result;
    }
}
