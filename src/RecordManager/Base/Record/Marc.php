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
    use XmlRecordTrait {
        XmlRecordTrait::parseXMLRecord as parseXMLRecord;
    }

    public const SUBFIELD_INDICATOR = "\x1F";
    public const END_OF_FIELD = "\x1E";
    public const END_OF_RECORD = "\x1D";
    public const LEADER_LEN = 24;

    public const GET_NORMAL = 0;
    public const GET_ALT = 1;
    public const GET_BOTH = 2;

    /**
     * MARC is stored in a multidimensional array:
     *  [001] - "12345"
     *  [245] - i1: '0'
     *          i2: '1'
     *          s:  [{a => "Title"},
     *               {p => "Part"}
     *              ]
     */
    protected $fields = [];

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
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils
    ) {
        parent::__construct($config, $dataSourceConfig, $logger, $metadataUtils);

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

        $firstChar = is_array($data) ? '{' : substr($data, 0, 1);
        if ($firstChar === '{') {
            $fields = is_array($data) ? $data : json_decode($data, true);
            if (!isset($fields['v'])) {
                // Old format, convert...
                $this->fields = [];
                foreach ($fields as $tag => $field) {
                    foreach ($field as $data) {
                        if (strstr($data, self::SUBFIELD_INDICATOR)) {
                            $newField = [
                                'i1' => $data[0],
                                'i2' => $data[1]
                            ];
                            $subfields = explode(
                                self::SUBFIELD_INDICATOR,
                                substr($data, 3)
                            );
                            foreach ($subfields as $subfield) {
                                $newField['s'][] = [
                                    $subfield[0] => substr($subfield, 1)
                                ];
                            }
                            $this->fields[$tag][] = $newField;
                        } else {
                            $this->fields[$tag][] = $data;
                        }
                    }
                }
            } else {
                if ($fields['v'] == 2) {
                    // Convert from previous field format
                    $this->fields = [];
                    foreach ($fields['f'] as $code => $codeFields) {
                        if (!is_array($codeFields)) {
                            // 000
                            $this->fields[$code] = $codeFields;
                            continue;
                        }
                        foreach ($codeFields as $field) {
                            if (is_array($field)) {
                                $newField = [
                                    'i1' => $field['i1'],
                                    'i2' => $field['i2'],
                                    's' => []
                                ];
                                if (isset($field['s'])) {
                                    foreach ($field['s'] as $subfield) {
                                        $newField['s'][] = [
                                            $subfield['c'] => $subfield['v']
                                        ];
                                    }
                                }
                                $this->fields[$code][] = $newField;
                            } else {
                                $this->fields[$code][] = $field;
                            }
                        }
                    }
                } else {
                    $this->fields = $fields['f'];
                }
            }
        } elseif ($firstChar === '<') {
            $this->parseXML($data);
        } else {
            $this->parseISO2709($data);
        }
        if (isset($this->fields['000']) && is_array($this->fields['000'])) {
            $this->fields['000'] = $this->fields['000'][0];
        }
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     */
    public function serialize()
    {
        return json_encode(['v' => 3, 'f' => $this->fields]);
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        $xml = simplexml_load_string(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n"
            . "<collection><record></record></collection>"
        );
        $record = $xml->record[0];

        if (isset($this->fields['000'])) {
            // Voyager is often missing the last '0' of the leader...
            $leader = str_pad(substr($this->fields['000'], 0, 24), 24);
            $record->addChild('leader', htmlspecialchars($leader));
        }

        foreach ($this->fields as $tag => $fields) {
            if ($tag == '000') {
                continue;
            }
            foreach ($fields as $data) {
                if (!is_array($data)) {
                    $field = $record->addChild(
                        'controlfield',
                        htmlspecialchars($data, ENT_NOQUOTES)
                    );
                    $field->addAttribute('tag', $tag);
                } else {
                    $field = $record->addChild('datafield');
                    $field->addAttribute('tag', $tag);
                    $field->addAttribute('ind1', $data['i1']);
                    $field->addAttribute('ind2', $data['i2']);
                    if (isset($data['s'])) {
                        foreach ($data['s'] as $subfield) {
                            $subfieldData = current($subfield);
                            $subfieldCode = key($subfield);
                            if ($subfieldData == '') {
                                continue;
                            }
                            $subfield = $field->addChild(
                                'subfield',
                                htmlspecialchars($subfieldData, ENT_NOQUOTES)
                            );
                            $subfield->addAttribute('code', $subfieldCode);
                        }
                    }
                }
            }
        }

        return $record->asXML();
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
        $fields = ['760', '762', '765', '767', '770', '772', '773', '774',
            '775', '776', '777', '780', '785', '786', '787'];
        foreach ($fields as $code) {
            // Make sure not to use null coalescing with references. That won't work.
            if (!isset($this->fields[$code])) {
                continue;
            }
            foreach ($this->fields[$code] as &$marcfield) {
                // Make sure not to use null coalescing with references. That won't
                // work.
                if (!isset($marcfield['s'])) {
                    continue;
                }
                foreach ($marcfield['s'] as &$marcsubfield) {
                    if (key($marcsubfield) == 'w') {
                        $targetId = current($marcsubfield);
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
                        $marcsubfield = [
                            'w' => $targetId
                        ];
                    }
                }
                unset($marcsubfield);
            }
            unset($marcfield);
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
                        $centers = $this->metadataUtils
                            ->getCenterCoordinates($geoLocation);
                    }
                    $data[$centerField] = $centers;
                }
                $displayField = $this->getDriverParam(
                    'geoDisplayField',
                    $this->defaultGeoDisplayField
                );
                if ($displayField) {
                    foreach ($geoLocations as $geoLocation) {
                        $data[$displayField][] = $this->metadataUtils
                            ->getGeoDisplayField($geoLocation);
                    }
                }
            }
        }

        // lccn
        $data['lccn'] = $this->getFieldSubfields('010', ['a' => 1]);
        $data['ctrlnum'] = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '035', ['a' => 1]]]
        );
        $data['fullrecord'] = $this->toISO2709();
        if (!$data['fullrecord']) {
            // In case the record exceeds 99999 bytes...
            $data['fullrecord'] = $this->toXML();
        }
        $data['allfields'] = $this->getAllFields();

        // language
        $data['language'] = $this->getLanguages();

        $data['format'] = $this->getFormat();

        $primaryAuthors = $this->getPrimaryAuthors();
        $data['author'] = $primaryAuthors['names'];
        // Support for author_variant is currently not implemented
        $data['author_role'] = $primaryAuthors['relators'];
        $data['author_fuller'] = $primaryAuthors['fuller'];
        if (isset($primaryAuthors['names'][0])) {
            $data['author_sort'] = $primaryAuthors['names'][0];
        }

        $secondaryAuthors = $this->getSecondaryAuthors();
        $data['author2'] = $secondaryAuthors['names'];
        // Support for author2_variant is currently not implemented
        $data['author2_role'] = $secondaryAuthors['relators'];
        $data['author2_fuller'] = $secondaryAuthors['fuller'];

        $corporateAuthors = $this->getCorporateAuthors();
        $data['author_corporate'] = $corporateAuthors['names'];
        $data['author_corporate_role'] = $corporateAuthors['relators'];
        $data['author_additional'] = $this->getFieldsSubfields(
            [
                [self::GET_BOTH, '505', ['r' => 1]]
            ],
            true
        );

        $data['title'] = $this->getTitle();
        $data['title_sub'] = $this->getFieldSubfields(
            '245',
            ['b' => 1, 'n' => 1, 'p' => 1]
        );
        $data['title_short'] = $this->getFieldSubfields('245', ['a' => 1]);
        $data['title_full'] = $this->getFieldSubfields(
            '245',
            ['a' => 1, 'b' => 1, 'c' => 1, 'f' => 1, 'g' => 1, 'h' => 1, 'k' => 1,
                'n' => 1, 'p' => 1, 's' => 1]
        );
        $data['title_alt'] = array_values(
            array_unique(
                $this->getFieldsSubfields(
                    [
                        [self::GET_ALT, '245', ['a' => 1, 'b' => 1]],
                        [self::GET_BOTH, '130', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'k' => 1,
                            'l' => 1, 'n' => 1, 'p' => 1, 's' => 1, 't' => 1
                        ]],
                        [self::GET_BOTH, '240', ['a' => 1]],
                        [self::GET_BOTH, '246', [
                            'a' => 1, 'b' => 1, 'n' => 1, 'p' => 1]
                        ],
                        [self::GET_BOTH, '730', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'k' => 1,
                            'l' => 1, 'n' => 1, 'p' => 1, 's' => 1, 't' => 1
                        ]],
                        [self::GET_BOTH, '740', ['a' => 1]]
                    ]
                )
            )
        );
        $data['title_old'] = $this->getFieldsSubfields(
            [
                [self::GET_BOTH, '780', ['a' => 1, 's' => 1, 't' => 1]]
            ]
        );
        $data['title_new'] = $this->getFieldsSubfields(
            [
                [self::GET_BOTH, '785', ['a' => 1, 's' => 1, 't' => 1]]
            ]
        );
        $data['title_sort'] = $this->getTitle(true);

        if (!$data['title_short']) {
            $data['title_short'] = $this->getFieldSubfields(
                '240',
                ['a' => 1, 'n' => 1, 'p' => 1]
            );
            $data['title_full'] = $this->getFieldSubfields('240');
        }

        $data['series'] = $this->getSeries();

        $data['publisher'] = $this->getFieldsSubfields(
            [
                [self::GET_BOTH, '260', ['b' => 1]]
            ],
            false,
            true
        );
        if (!$data['publisher']) {
            $fields = $this->getFields('264');
            foreach ($fields as $field) {
                if ($this->getIndicator($field, 2) == '1') {
                    $data['publisher'] = [
                        $this->metadataUtils->stripTrailingPunctuation(
                            $this->getSubfield($field, 'b')
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
                [self::GET_BOTH, '300', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'e' => 1, 'f' => 1, 'g' => 1
                ]],
                [self::GET_BOTH, '530', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1
                ]]
            ]
        );
        $data['dateSpan'] = $this->getFieldsSubfields(
            [[self::GET_BOTH, '362', ['a' => 1]]]
        );
        $data['edition'] = $this->getFieldSubfields('250', ['a' => 1]);
        $data['contents'] = $this->getFieldsSubfields(
            [
                [self::GET_BOTH, '505', ['a' => 1]],
                [self::GET_BOTH, '505', ['t' => 1]]
            ]
        );

        $data['isbn'] = $this->getISBNs();
        foreach ($this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '773', ['z' => 1]]
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
                [self::GET_NORMAL, '022', ['a' => 1]],
                [self::GET_NORMAL, '440', ['x' => 1]],
                [self::GET_NORMAL, '490', ['x' => 1]],
                [self::GET_NORMAL, '730', ['x' => 1]],
                [self::GET_NORMAL, '773', ['x' => 1]],
                [self::GET_NORMAL, '776', ['x' => 1]],
                [self::GET_NORMAL, '780', ['x' => 1]],
                [self::GET_NORMAL, '785', ['x' => 1]]
            ]
        );
        foreach ($data['issn'] as &$value) {
            $value = str_replace('-', '', $value);
        }

        $data['callnumber-first'] = $this->getFirstFieldSubfields(
            [
                [self::GET_NORMAL, '099', ['a' => 1]],
                [self::GET_NORMAL, '090', ['a' => 1]],
                [self::GET_NORMAL, '050', ['a' => 1]]
            ]
        );
        $value = $this->getFirstFieldSubfields(
            [
                [self::GET_NORMAL, '090', ['a' => 1]],
                [self::GET_NORMAL, '050', ['a' => 1]]
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
                    [self::GET_NORMAL, '080', ['a' => 1, 'b' => 1]],
                    [self::GET_NORMAL, '084', ['a' => 1, 'b' => 1]],
                    [self::GET_NORMAL, '050', ['a' => 1, 'b' => 1]]
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
                [self::GET_NORMAL, '856', ['u' => 1]]
            ]
        );

        $data['illustrated'] = $this->getIllustrated();

        $deweyFields = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '082', ['a' => '1']],
                [self::GET_NORMAL, '083', ['a' => '1']],
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
        return (string)$this->getField('001');
    }

    /**
     * Return record linking IDs (typically same as ID) used for links
     * between records in the data source
     *
     * @return array
     */
    public function getLinkingIDs()
    {
        $id = $this->getField('001');
        if ('' === $id && $this->getDriverParam('idIn999', false)) {
            // Koha style ID fallback
            $id = $this->getFieldSubfield('999', 'c');
        }
        $id = $this->createLinkingId($id);
        $results = [$id];

        $cns = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '035', ['a' => 1]]
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
            $source = $this->getField('003');
            $source = $this->metadataUtils->stripTrailingPunctuation($source);
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
        return isset($this->fields['773']);
    }

    /**
     * Return host record IDs for a component part
     *
     * @return array
     */
    public function getHostRecordIDs()
    {
        $field = $this->getField('941');
        if ($field) {
            $hostId = $this->metadataUtils->stripControlCharacters(
                $this->getSubfield($field, 'a')
            );
            return [$hostId];
        }
        $ids = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '773', ['w' => 1]]],
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
                        $field = $this->getField('003');
                        $record003 = $field
                            ? $this->metadataUtils->stripControlCharacters($field)
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
        $field773g = $this->getFieldSubfields('773', ['g' => 1]);
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
        $field773g = $this->getFieldSubfields('773', ['g' => 1]);
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
        $field773g = $this->getFieldSubfields('773', ['g' => 1]);
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
        $first773 = $this->getField('773');
        return $this->metadataUtils->stripTrailingPunctuation(
            $this->getSubfield($first773, 't')
        );
    }

    /**
     * Component parts: get the free-form reference to the part in the container
     *
     * @return string
     */
    public function getContainerReference()
    {
        $first773 = $this->getField('773');
        return $this->metadataUtils->stripTrailingPunctuation(
            $this->getSubfield($first773, 'g')
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
        foreach (['245', '240'] as $fieldCode) {
            $field = $this->getField($fieldCode);
            if ($field && !empty($field['s'])) {
                $title = $this->getSubfield($field, 'a');
                if ($forFiling) {
                    $nonfiling = intval($this->getIndicator($field, 2));
                    if ($nonfiling > 0) {
                        $title = mb_substr($title, $nonfiling, null, 'UTF-8');
                    }
                }
                foreach ($field['s'] as $subfield) {
                    if (!in_array(key($subfield), $acceptSubfields)) {
                        continue;
                    }
                    if (!$this->metadataUtils->hasTrailingPunctuation($title)) {
                        $title .= $punctuation[key($subfield)];
                    } else {
                        $title .= ' ';
                    }
                    $title .= current($subfield);
                }
                if ($forFiling) {
                    $title = $this->metadataUtils->stripLeadingPunctuation($title);
                    $title = mb_strtolower($title, 'UTF-8');
                }
                $title = $this->metadataUtils->stripTrailingPunctuation($title);
                if (!empty($title)) {
                    return $title;
                }
            }
        }
        return '';
    }

    /**
     * Return main author (format: Last, First)
     *
     * @return string
     */
    public function getMainAuthor()
    {
        $f100 = $this->getField('100');
        if ($f100) {
            $author = $this->getSubfield($f100, 'a');
            $order = $this->getIndicator($f100, 1);
            if ($order == 0 && strpos($author, ',') === false) {
                $author = $this->metadataUtils->convertAuthorLastFirst($author);
            }
            return $this->metadataUtils->stripTrailingPunctuation($author);
        } elseif ($f700 = $this->getField('700')) {
            $author = $this->getSubfield($f700, 'a');
            $order = $this->getIndicator($f700, 1);
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
    public function getFullTitle()
    {
        return $this->getFieldSubfields('245');
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
        $f010 = $this->getField('010');
        if ($f010) {
            $lccn = $this->metadataUtils
                ->normalizeKey($this->getSubfield($f010, 'a'));
            if ($lccn) {
                $arr[] = "(lccn)$lccn";
            }
            $nucmc = $this->metadataUtils
                ->normalizeKey($this->getSubfield($f010, 'b'));
            if ($nucmc) {
                $arr[] = "(nucmc)$lccn";
            }
        }
        $nbn = $this->getField('015');
        if ($nbn) {
            $nr = $this->metadataUtils->normalizeKey(
                $this->getSubfield($nbn, 'a'),
                $form
            );
            $src = $this->getSubfield($nbn, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        $nba = $this->getField('016');
        if ($nba) {
            $nr = $this->metadataUtils->normalizeKey(
                $this->getSubfield($nba, 'a'),
                $form
            );
            $src = $this->getSubfield($nba, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        $id = $this->getField('024');
        if ($id) {
            $nr = $this->getSubfield($id, 'a');
            switch ($this->getIndicator($id, 1)) {
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
                $src = $this->getSubfield($id, '2');
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
        foreach ($this->getFields('035') as $field) {
            $nr = $this->getSubfield($field, 'a');
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
        $fields = $this->getFields('020');
        foreach ($fields as $field) {
            $original = $isbn = $this->getSubfield($field, 'a');
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
        $fields = $this->getFields('022');
        foreach ($fields as $field) {
            $issn = $this->getSubfield($field, 'a');
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
        $fields = $this->getFields('007');
        $online = false;
        foreach ($fields as $field) {
            $contents = $field;
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
        $leader = $this->getField('000');
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

        $field008 = $this->getField('008');
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
        $field = $this->getField('260');
        if ($field) {
            $year = $this->extractYear($this->getSubfield($field, 'c'));
            if ($year) {
                return $year;
            }
        }
        $fields = $this->getFields('264');
        foreach ($fields as $field) {
            if ($this->getIndicator($field, 2) == '1') {
                $year = $this->extractYear($this->getSubfield($field, 'c'));
                if ($year) {
                    return $year;
                }
            }
        }
        $field008 = $this->getField('008');
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
        $field = $this->getField('300');
        if ($field) {
            $extent = $this->getSubfield($field, 'a');
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
            $this->fields['995'] = [
                [
                    'i1' => ' ',
                    'i2' => ' ',
                    's' => [
                        [
                            'a' => $dedupKey
                        ]
                    ]
                ]
            ];
        } else {
            $this->fields['995'] = [];
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
                foreach ($this->getFields($buildingField['field']) as $field) {
                    $location = $this->getSubfield($field, $buildingField['loc']);
                    if ($location) {
                        $subLocField = $buildingField['sub'];
                        if ($subLocField
                            && $sub = $this->getSubfield($field, $subLocField)
                        ) {
                            $location = [$location, $sub];
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
     * Parse MARCXML
     *
     * @param string $marc MARCXML
     *
     * @throws \Exception
     * @return void
     */
    protected function parseXML($marc)
    {
        $xmlHead = '<?xml version';
        if (strcasecmp(substr($marc, 0, strlen($xmlHead)), $xmlHead) === 0) {
            $decl = substr($marc, 0, strpos($marc, '?>'));
            if (strstr($decl, 'encoding') === false) {
                $marc = $decl . ' encoding="utf-8"' . substr($marc, strlen($decl));
            }
        } else {
            $marc = '<?xml version="1.0" encoding="utf-8"?>' . "\n\n$marc";
        }
        $xml = $this->parseXMLRecord($marc);

        // Move to the record element if we were given a collection
        if ($xml->record) {
            $xml = $xml->record;
        }

        $this->fields['000'] = isset($xml->leader) ? (string)$xml->leader[0] : '';

        foreach ($xml->controlfield as $field) {
            $tag = (string)$field['tag'];
            if ('000' === $tag) {
                continue;
            }
            $this->fields[$tag][] = (string)$field;
        }

        foreach ($xml->datafield as $field) {
            $newField = [
                'i1' => str_pad((string)$field['ind1'], 1),
                'i2' => str_pad((string)$field['ind2'], 1)
            ];
            foreach ($field->subfield as $subfield) {
                $newField['s'][] = [(string)$subfield['code'] => (string)$subfield];
            }
            $this->fields[(string)$field['tag']][] = $newField;
        }
    }

    /**
     * Parse ISO2709 exchange format
     *
     * @param string $marc ISO2709 string
     *
     * @throws \Exception
     * @return void
     */
    protected function parseISO2709($marc)
    {
        $this->fields['000'] = substr($marc, 0, 24);
        $dataStart = 0 + (int)substr($marc, 12, 5);
        $dirLen = $dataStart - self::LEADER_LEN - 1;

        $offset = 0;
        while ($offset < $dirLen) {
            $tag = substr($marc, self::LEADER_LEN + $offset, 3);
            $len = (int)substr($marc, self::LEADER_LEN + $offset + 3, 4);
            $dataOffset
                = (int)substr($marc, self::LEADER_LEN + $offset + 7, 5);

            $tagData = substr($marc, $dataStart + $dataOffset, $len);

            if (substr($tagData, -1, 1) == self::END_OF_FIELD) {
                $tagData = substr($tagData, 0, -1);
            } else {
                throw new \Exception(
                    "Invalid MARC record (end of field not found): $marc"
                );
            }

            if (strstr($tagData, self::SUBFIELD_INDICATOR)) {
                $newField = [
                    'i1' => $tagData[0],
                    'i2' => $tagData[1]
                ];
                $subfields = explode(
                    self::SUBFIELD_INDICATOR,
                    substr($tagData, 3)
                );
                foreach ($subfields as $subfield) {
                    $newField['s'][] = [
                        $subfield[0] => substr($subfield, 1)
                    ];
                }
                $this->fields[$tag][] = $newField;
            } else {
                $this->fields[$tag][] = $tagData;
            }

            $offset += 12;
        }
    }

    /**
     * Convert to ISO2709. Return empty string if record too long.
     *
     * @return string
     */
    protected function toISO2709()
    {
        $leader = str_pad(substr($this->fields['000'], 0, 24), 24);

        $directory = '';
        $data = '';
        $datapos = 0;
        foreach ($this->fields as $tag => $fields) {
            if ($tag == '000') {
                continue;
            }
            if (strlen($tag) != 3) {
                $this->logger->logError(
                    'Marc',
                    "Invalid field tag: '$tag', record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning("invalid field tag '$tag'");
                continue;
            }
            foreach ($fields as $field) {
                if (is_array($field)) {
                    $fieldStr = $field['i1'] . $field['i2'];
                    if (isset($field['s']) && is_array($field['s'])) {
                        foreach ($field['s'] as $subfield) {
                            $subfieldCode = key($subfield);
                            $fieldStr .= self::SUBFIELD_INDICATOR
                                . $subfieldCode . current($subfield);
                        }
                    }
                } else {
                    // Additional normalization here so that we don't break ISO2709
                    // directory in SolrUpdater
                    $fieldStr = $this->metadataUtils
                        ->normalizeUnicode($field, 'NFKC');
                }
                $fieldStr .= self::END_OF_FIELD;
                $len = strlen($fieldStr);
                if ($len > 9999) {
                    return '';
                }
                if ($datapos > 99999) {
                    return '';
                }
                $directory .= $tag . str_pad((string)$len, 4, '0', STR_PAD_LEFT)
                    . str_pad((string)$datapos, 5, '0', STR_PAD_LEFT);
                $datapos += $len;
                $data .= $fieldStr;
            }
        }
        $directory .= self::END_OF_FIELD;
        $data .= self::END_OF_RECORD;
        $dataStart = strlen($leader) + strlen($directory);
        $recordLen = $dataStart + strlen($data);
        if ($recordLen > 99999) {
            return '';
        }

        $leader = str_pad((string)$recordLen, 5, '0', STR_PAD_LEFT)
            . substr($leader, 5, 7)
            . str_pad((string)$dataStart, 5, '0', STR_PAD_LEFT)
            . substr($leader, 17);
        return $leader . $directory . $data;
    }

    /**
     * Check if the work is illustrated
     *
     * @return string
     */
    protected function getIllustrated()
    {
        $leader = $this->getField('000');
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
            $field008 = $this->getField('008');
            for ($pos = 18; $pos <= 21; $pos++) {
                $ch = substr($field008, $pos, 1);
                if ('' !== $ch && isset($illustratedCodes[$ch])) {
                    return 'Illustrated';
                }
            }

            // 006
            foreach ($this->getFields('006') as $field006) {
                for ($pos = 1; $pos <= 4; $pos++) {
                    $ch = substr($field006, $pos, 1);
                    if ('' !== $ch && isset($illustratedCodes[$ch])) {
                        return 'Illustrated';
                    }
                }
            }
        }

        // Now check for interesting strings in 300 subfield b:
        foreach ($this->getFields('300') as $field300) {
            $sub = strtolower($this->getSubfield($field300, 'b'));
            foreach ($this->illustrationStrings as $illStr) {
                if (strpos($sub, $illStr) !== false) {
                    return 'Illustrated';
                }
            }
        }
        return 'Not Illustrated';
    }

    /**
     * Get first matching field
     *
     * @param string $field Tag to get
     *
     * @return string|array String for controlfields, array for datafields
     */
    public function getField($field)
    {
        if (isset($this->fields[$field])) {
            if (is_array($this->fields[$field])) {
                return $this->fields[$field][0];
            } else {
                return $this->fields[$field];
            }
        }
        return '';
    }

    /**
     * Get all matching fields
     *
     * @param string $field Tag to get
     *
     * @return mixed[]
     */
    public function getFields($field)
    {
        if (isset($this->fields[$field])) {
            return $this->fields[$field];
        }
        return [];
    }

    /**
     * Get indicator value
     *
     * @param array $field     MARC field
     * @param int   $indicator Indicator nr, 1 or 2
     *
     * @return string
     */
    public function getIndicator($field, $indicator)
    {
        switch ($indicator) {
        case 1:
            if (!isset($field['i1'])) {
                $this->logger->logError(
                    'Marc',
                    'Indicator 1 missing from field:' . print_r($field, true)
                        . ", record {$this->source}." . $this->getID()
                );
                $this->storeWarning('indicator 1 missing');
                return ' ';
            }
            return $field['i1'];
        case 2:
            if (!isset($field['i2'])) {
                $this->logger->logError(
                    'Marc',
                    'Indicator 2 missing from field:' . print_r($field, true)
                        . ", record {$this->source}." . $this->getID()
                );
                $this->storeWarning('indicator 2 missing');
                return ' ';
            }
            return $field['i2'];
        default:
            throw new \Exception("Invalid indicator '$indicator' requested");
        }
    }

    /**
     * Get a single subfield from the given field
     *
     * @param array  $field Field
     * @param string $code  Subfield code
     *
     * @return string Subfield
     */
    public function getSubfield($field, $code)
    {
        if (!$field || !isset($field['s']) || !is_array($field['s'])) {
            return '';
        }
        foreach ($field['s'] as $subfield) {
            if ((string)key($subfield) === (string)$code) {
                return current($subfield);
            }
        }
        return '';
    }

    /**
     * Get specified subfields
     *
     * @param array $field MARC Field
     * @param array $codes Array with keys of accepted subfield codes
     *
     * @return array Subfields
     */
    public function getSubfieldsArray($field, $codes)
    {
        $data = [];
        if (!$field || !isset($field['s']) || !is_array($field['s'])) {
            return $data;
        }
        foreach ($field['s'] as $subfield) {
            $code = key($subfield);
            if (isset($codes[(string)$code])) {
                $data[] = current($subfield);
            }
        }
        return $data;
    }

    /**
     * Get specified subfields
     *
     * @param array $field MARC Field
     * @param array $codes Array with keys of accepted subfield codes
     *
     * @return string Concatenated subfields (space-separated)
     */
    protected function getSubfields($field, $codes)
    {
        $data = $this->getSubfieldsArray($field, $codes);
        return implode(' ', $data);
    }

    /**
     * Get field data
     *
     * @param string  $tag                      Field to get
     * @param array   $codes                    Optional array with keys of accepted
     *                                          subfields
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation
     *                                          from the results
     *
     * @return string Concatenated subfields (space-separated)
     */
    protected function getFieldSubfields(
        $tag,
        $codes = null,
        $stripTrailingPunctuation = true
    ) {
        $key = __METHOD__ . "$tag-" . implode(',', array_keys($codes ?? [])) . '-'
            . ($stripTrailingPunctuation ? '1' : '0');

        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $subfields = [];
        foreach ($this->fields[$tag] ?? [] as $field) {
            if (!isset($field['s'])) {
                continue;
            }
            foreach ($field['s'] as $subfield) {
                if ($codes && !isset($codes[(string)key($subfield)])) {
                    continue;
                }
                $subfields[] = current($subfield);
            }
        }
        $result = implode(' ', $subfields);
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
        $tag,
        $code,
        $stripTrailingPunctuation = true
    ) {
        $key = __METHOD__ . "-$tag-$code-"
            . ($stripTrailingPunctuation ? '1' : '0');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $result = '';
        foreach ($this->fields[$tag] ?? [] as $field) {
            if (!isset($field['s'])) {
                continue;
            }
            foreach ($field['s'] as $subfield) {
                if (key($subfield) === $code) {
                    $result = current($subfield);
                    if ($stripTrailingPunctuation) {
                        $result = $this->metadataUtils
                            ->stripTrailingPunctuation($result);
                    }
                    break 2;
                }
            }
        }
        $this->resultCache[$key] = $result;
        return $result;
    }

    /**
     * Return an array of fields according to the fieldspecs.
     *
     * Format of fieldspecs:
     * [
     *   type (e.g. self::GET_BOTH),
     *   field code (e.g. '245'),
     *   subfields (e.g. ['a'=>1, 'b'=>1, 'c'=>1]),
     *   required subfields (e.g. ['t'=>1])
     * ]
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
        $fieldspecs,
        $firstOnly = false,
        $stripTrailingPunctuation = true,
        $splitSubfields = false
    ) {
        $key = __METHOD__ . '-' . json_encode($fieldspecs) . '-'
            . ($firstOnly ? '1' : '0') . ($stripTrailingPunctuation ? '1' : '0')
            . ($splitSubfields ? '1' : '0');
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }

        $data = [];
        foreach ($fieldspecs as $fieldspec) {
            $type = $fieldspec[0];
            $tag = $fieldspec[1];
            $codes = $fieldspec[2];

            if (!isset($this->fields[$tag])) {
                continue;
            }

            foreach ($this->fields[$tag] as $field) {
                if (!isset($field['s'])) {
                    $this->logger->logDebug(
                        'Marc',
                        "Subfields missing in field $tag, record {$this->source}."
                            . $this->getID()
                    );
                    $this->storeWarning("missing subfields in $tag");
                    continue;
                }
                if (!is_array($field['s'])) {
                    $this->logger->logDebug(
                        'Marc',
                        "Invalid subfields in field $tag, record {$this->source}."
                            . $this->getID()
                    );
                    $this->storeWarning("invalid subfields in $tag");
                    continue;
                }

                // Check for required subfields
                if (isset($fieldspec[3])) {
                    foreach (array_keys($fieldspec[3]) as $required) {
                        $found = false;
                        foreach ($field['s'] as $subfield) {
                            if ($required == key($subfield)) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            continue 2;
                        }
                    }
                }

                if ($type != self::GET_ALT) {
                    // Handle normal field
                    if ($codes) {
                        if ($splitSubfields) {
                            foreach ($field['s'] as $subfield) {
                                $code = key($subfield);
                                if ($code === 0) {
                                    $code = '0';
                                }
                                if (isset($codes[(string)$code])) {
                                    $data[] = current($subfield);
                                }
                            }
                        } else {
                            $fieldContents = '';
                            foreach ($field['s'] as $subfield) {
                                $code = key($subfield);
                                if (isset($codes[(string)$code])) {
                                    if ($fieldContents) {
                                        $fieldContents .= ' ';
                                    }
                                    $fieldContents .= current($subfield);
                                }
                            }
                            if ($fieldContents) {
                                $data[] = $fieldContents;
                            }
                        }
                    } else {
                        $fieldContents = '';
                        foreach ($field['s'] as $subfield) {
                            if ($fieldContents) {
                                $fieldContents .= ' ';
                            }
                            $fieldContents .= current($subfield);
                        }
                        if ($fieldContents) {
                            $data[] = $fieldContents;
                        }
                    }
                }
                if (($type == self::GET_ALT || $type == self::GET_BOTH)
                    && isset($this->fields['880'])
                    && ($origSub6 = $this->getSubfield($field, '6'))
                ) {
                    $altSubfields = $this->getAlternateScriptSubfields(
                        $tag,
                        $origSub6,
                        $codes,
                        $splitSubfields
                    );
                    $data = array_merge($data, $altSubfields);
                }
                if ($firstOnly) {
                    break 2;
                }
            }
        }
        if ($stripTrailingPunctuation) {
            $data = array_map(
                [$this->metadataUtils, 'stripTrailingPunctuation'],
                $data
            );
        }
        $this->resultCache[$key] = $data;
        return $data;
    }

    /**
     * Get any alternate script field
     *
     * @param string $tag  Field code
     * @param string $sub6 Subfield 6 in original script identifying the
     *                     alt field
     *
     * @return array
     */
    protected function getAlternateScriptField(
        $tag,
        $sub6
    ) {
        $findSub6 = "$tag-" . substr($sub6, 4, 2);
        foreach ($this->fields['880'] ?? [] as $field880) {
            if (strncmp($this->getSubfield($field880, '6'), $findSub6, 6) === 0) {
                return $field880;
            }
        }
        return [];
    }

    /**
     * Get the subfields for any alternate script field
     *
     * @param string  $tag            Field code
     * @param string  $sub6           Subfield 6 in original script identifying the
     *                                alt field
     * @param array   $codes          Array of subfield codes in keys
     * @param boolean $splitSubfields Whether to split subfields to separate array
     *                                items
     *
     * @return array
     */
    protected function getAlternateScriptSubfields(
        $tag,
        $sub6,
        $codes,
        $splitSubfields = false
    ) {
        $data = [];
        if ($field880 = $this->getAlternateScriptField($tag, $sub6)) {
            if ($codes) {
                if ($splitSubfields) {
                    foreach ($field880['s'] as $subfield) {
                        $code = key($subfield);
                        if (isset($codes[(string)$code])) {
                            $data[] = current($subfield);
                        }
                    }
                } else {
                    $fieldContents = '';
                    foreach ($field880['s'] as $subfield) {
                        $code = key($subfield);
                        if ($code === 0) {
                            $code = '0';
                        }
                        if (isset($codes[(string)$code])) {
                            if ($fieldContents) {
                                $fieldContents .= ' ';
                            }
                            $fieldContents .= current($subfield);
                        }
                    }
                    if ($fieldContents) {
                        $data[] = $fieldContents;
                    }
                }
            } else {
                $fieldContents = '';
                foreach ($field880['s'] as $subfield) {
                    if ($fieldContents) {
                        $fieldContents .= ' ';
                    }
                    $fieldContents .= current($subfield);
                }
                if ($fieldContents) {
                    $data[] = $fieldContents;
                }
            }
        }
        return $data;
    }

    /**
     * Get all subfields of specified fields
     *
     * @param string $tag Field tag
     *
     * @return array
     */
    protected function getFieldsAllSubfields($tag)
    {
        $data = [];
        foreach ($this->getFields($tag) as $field) {
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
     * Format of fieldspecs: [+*][fieldcode][subfields]:...
     *              + = return only alternate script fields (880 equivalents)
     *              * = return normal and alternate script fields
     *
     * @param array $fieldspecs Field specifications
     *
     * @return string Concatenated subfields (space-separated)
     */
    protected function getFirstFieldSubfields($fieldspecs)
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
     * @param array $field  Field
     * @param array $filter Optional array with keys of subfields codes to be
     *                      excluded
     *
     * @return array All subfields
     */
    protected function getAllSubfields($field, $filter = null)
    {
        if (!$field) {
            return [];
        }
        if (!isset($field['s'])) {
            $this->logger->logDebug(
                'Marc',
                "Subfields missing in field: " . print_r($field, true)
                    . ", record {$this->source}." . $this->getID()
            );
            $this->storeWarning('missing subfields');
            return [];
        }
        if (!is_array($field['s'])) {
            $this->logger->logError(
                'Marc',
                'Invalid subfields in field: '
                    . print_r($field, true) . ", record {$this->source}."
                    . $this->getID()
            );
            $this->storeWarning('invalid subfields');
            return [];
        }

        $subfields = [];
        foreach ($field['s'] as $subfield) {
            if (isset($filter) && isset($filter[(string)key($subfield)])) {
                continue;
            }
            $subfields[] = current($subfield);
        }
        return $subfields;
    }

    /**
     * Set field to given value
     *
     * @param string $field Field tag
     * @param array  $value Field data
     *
     * @return void
     */
    protected function setField($field, $value)
    {
        $this->fields[$field] = $value;
        // Invalidate cache
        $this->resultCache = [];
    }

    /**
     * Get an array of all fields relevant to allfields search
     *
     * @return array
     */
    protected function getAllFields()
    {
        $subfieldFilter = [
            '650' => ['0' => 1, '2' => 1, '6' => 1, '8' => 1],
            '773' => ['6' => 1, '7' => 1, '8' => 1, 'w' => 1],
            '856' => ['6' => 1, '8' => 1, 'q' => 1]
        ];
        $allFields = [];
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 841) || $tag == 856 || $tag == 880) {
                foreach ($fields as $field) {
                    $subfields = $this->getAllSubfields(
                        $field,
                        $subfieldFilter[$tag] ?? ['0' => 1, '6' => 1, '8' => 1]
                    );
                    if ($subfields) {
                        $allFields = array_merge($allFields, $subfields);
                    }
                }
            }
        }
        $allFields = array_map(
            function ($str) {
                return $this->metadataUtils->stripTrailingPunctuation(
                    $this->metadataUtils->stripLeadingPunctuation($str)
                );
            },
            $allFields
        );
        return array_values(array_unique($allFields));
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
                [self::GET_BOTH, '600', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1,
                    'g' => 1, 'h' => 1, 'j' => 1, 'k' => 1, 'l' => 1, 'm' => 1,
                    'n' => 1, 'o' => 1, 'p' => 1, 'q' => 1, 'r' => 1, 's' => 1,
                    't' => 1, 'u' => 1, 'v' => 1, 'x' => 1, 'y' => 1, 'z' => 1
                ]],
                [self::GET_BOTH, '610', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1,
                    'g' => 1, 'h' => 1, 'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1,
                    'o' => 1, 'p' => 1, 'r' => 1, 's' => 1, 't' => 1, 'u' => 1,
                    'v' => 1, 'x' => 1, 'y' => 1, 'z' => 1
                ]],
                [self::GET_BOTH, '611', [
                    'a' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1, 'g' => 1,
                    'h' => 1, 'j' => 1, 'k' => 1, 'l' => 1, 'n' => 1, 'p' => 1,
                    'q' => 1, 's' => 1, 't' => 1, 'u' => 1, 'v' => 1, 'x' => 1,
                    'y' => 1, 'z' => 1
                ]],
                [self::GET_BOTH, '630', [
                    'a' => 1, 'd' => 1, 'e' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                    'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1, 'o' => 1, 'p' => 1,
                    'r' => 1, 's' => 1, 't' => 1, 'v' => 1, 'x' => 1, 'y' => 1,
                    'z' => 1
                ]],
                [self::GET_BOTH, '650', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'v' => 1,
                    'x' => 1, 'y' => 1, 'z' => 1
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
                [self::GET_BOTH, '655', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'v' => 1, 'x' => 1, 'y' => 1,
                    'z' => 1
                ]]
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
                [self::GET_BOTH, '651', [
                    'a' => 1, 'e' => 1, 'v' => 1, 'x' => 1, 'y' => 1, 'z' => 1
                ]]
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
                [self::GET_BOTH, '648', [
                    'a' => 1, 'v' => 1, 'x' => 1, 'y' => 1, 'z' => 1
                ]]
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
                [self::GET_NORMAL, '600', ['x' => 1]],
                [self::GET_NORMAL, '610', ['x' => 1]],
                [self::GET_NORMAL, '611', ['x' => 1]],
                [self::GET_NORMAL, '630', ['x' => 1]],
                [self::GET_NORMAL, '648', ['x' => 1]],
                [self::GET_NORMAL, '650', ['a' => 1]],
                [self::GET_NORMAL, '650', ['x' => 1]],
                [self::GET_NORMAL, '651', ['x' => 1]],
                [self::GET_NORMAL, '655', ['x' => 1]]
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
                    [self::GET_NORMAL, '600', ['v' => 1]],
                    [self::GET_NORMAL, '610', ['v' => 1]],
                    [self::GET_NORMAL, '611', ['v' => 1]],
                    [self::GET_NORMAL, '630', ['v' => 1]],
                    [self::GET_NORMAL, '648', ['v' => 1]],
                    [self::GET_NORMAL, '650', ['v' => 1]],
                    [self::GET_NORMAL, '651', ['v' => 1]],
                    [self::GET_NORMAL, '655', ['a' => 1]],
                    [self::GET_NORMAL, '655', ['v' => 1]]
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
                [self::GET_NORMAL, '600', ['z' => 1]],
                [self::GET_NORMAL, '610', ['z' => 1]],
                [self::GET_NORMAL, '611', ['z' => 1]],
                [self::GET_NORMAL, '630', ['z' => 1]],
                [self::GET_NORMAL, '648', ['z' => 1]],
                [self::GET_NORMAL, '650', ['z' => 1]],
                [self::GET_NORMAL, '651', ['a' => 1]],
                [self::GET_NORMAL, '651', ['z' => 1]],
                [self::GET_NORMAL, '655', ['z' => 1]]
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
                [self::GET_NORMAL, '630', ['y' => 1]],
                [self::GET_NORMAL, '648', ['a' => 1]],
                [self::GET_NORMAL, '648', ['y' => 1]],
                [self::GET_NORMAL, '650', ['y' => 1]],
                [self::GET_NORMAL, '651', ['y' => 1]],
                [self::GET_NORMAL, '655', ['y' => 1]]
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
        $languages = [substr($this->getField('008'), 35, 3)];
        $languages2 = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '041', ['a' => 1]],
                [self::GET_NORMAL, '041', ['d' => 1]],
                [self::GET_NORMAL, '041', ['h' => 1]],
                [self::GET_NORMAL, '041', ['j' => 1]]
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
            foreach ($this->getFields($tag) as $field) {
                $fieldRelators = $this->normalizeRelators(
                    $this->getSubfieldsArray($field, ['4' => 1, 'e' => 1])
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
                if ($altScript
                    && isset($this->fields['880'])
                    && $sub6 = $this->getSubfield($field, '6')
                ) {
                    $terms .= ' ' . implode(
                        ' ',
                        $this->getAlternateScriptSubfields(
                            $tag,
                            $sub6,
                            $subfieldList
                        )
                    );
                }
                $result['names'][] = $this->metadataUtils->stripTrailingPunctuation(
                    trim($terms)
                );

                $fuller = ($tag == '100' || $tag == '700')
                    ? $this->getSubfields($field, ['q' => 1]) : '';
                if ($fuller) {
                    $result['fuller'][] = $this->metadataUtils
                        ->stripTrailingPunctuation(trim($fuller));
                }

                if ($fieldRelators) {
                    $result['relators'][] = reset($fieldRelators);
                } else {
                    $result['relators'][] = '-';
                }
                if ($authId = $this->getSubField($field, '0')) {
                    $result['ids'][] = $authId;
                    if ($role = $this->getSubField($field, 'e')) {
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
            '100' => ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1],
            '700' => [
                'a' => 1, 'q' => 1, 'b' => 1, 'c' => 1, 'd' => 1
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
            '100' => ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1],
            '700' => [
                'a' => 1, 'q' => 1, 'b' => 1, 'c' => 1, 'd' => 1
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
            '110' => ['a' => 1, 'b' => 1],
            '111' => ['a' => 1, 'b' => 1],
            '710' => ['a' => 1, 'b' => 1],
            '711' => ['a' => 1, 'b' => 1]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            [],
            ['110', '111', '710', '711']
        );
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
            '100' => ['a' => 1, 'b' => 1],
            '110' => ['a' => 1, 'b' => 1],
            '111' => ['a' => 1, 'c' => 1],
            '700' => ['a' => 1, 'b' => 1],
            '710' => ['a' => 1, 'b' => 1],
            '711' => ['a' => 1, 'c' => 1]
        ];
        $titleFields = [
            '130' => ['n' => 1, 'p' => 1],
            '730' => ['n' => 1, 'p' => 1],
            '240' => ['n' => 1, 'p' => 1, 'm' => 1, 'r' => 1],
            '245' => ['b' => 1, 'n' => 1],
            '246' => ['b' => 1, 'n' => 1],
            '247' => ['b' => 1, 'n' => 1],
        ];

        $authors = [];
        $authorsAltScript = [];
        $titles = [];
        $titlesAltScript = [];

        $analytical = [];
        foreach ($authorFields as $tag => $subfields) {
            $tag = (string)$tag;
            foreach ($this->getFields($tag) as $field) {
                // Check for analytical entries to be processed later:
                if (in_array($tag, ['700', '710', '711'])
                    && (int)$this->getIndicator($field, 2) === 2
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

                    $sub6 = $this->getSubfield($field, '6');
                    if ($sub6
                        && $f880 = $this->getAlternateScriptField($tag, $sub6)
                    ) {
                        $author = $this->getSubfields($f880, $subfields);
                        if ($author) {
                            $authorsAltScript[] = [
                                'type' => 'author',
                                'value' => $author
                            ];
                        }
                    }
                }
            }
        }

        foreach ($titleFields as $tag => $subfields) {
            $tag = (string)$tag;
            $field = $this->getField($tag);
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
            if ($field && !empty($field['s'])) {
                $title = $this->getSubfield($field, 'a');
                if (null !== $nonFilingInd) {
                    $nonfiling = (int)$this->getIndicator($field, $nonFilingInd);
                    if ($nonfiling > 0) {
                        $title = substr($title, $nonfiling);
                    }
                }
                $rest = $this->getSubfields($field, $subfields);
                if ($rest) {
                    $title .= " $rest";
                }
                $sub6 = $this->getSubfield($field, '6');
                if ($sub6 && $f880 = $this->getAlternateScriptField($tag, $sub6)
                ) {
                    $altTitle = $this->getSubfield($f880, 'a');
                    if (null !== $nonFilingInd) {
                        $nonfiling = (int)$this->getIndicator($f880, $nonFilingInd);
                        if ($nonfiling > 0) {
                            $altTitle = substr($altTitle, $nonfiling);
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
                    ['t' => 1, 'n' => 1, 'p' => 1, 'm' => 1, 'r' => 1]
                );
                if (!$title) {
                    continue;
                }
                $author = $this->getSubfields($field, $authorFields[$tag]);
                $altTitle = '';
                $altAuthor = '';
                $sub6 = $this->getSubfield($field, '6');
                if ($sub6
                    && $f880 = $this->getAlternateScriptField((string)$tag, $sub6)
                ) {
                    $altTitle = $this->getSubfield($f880, 'a');
                    if ($altTitle) {
                        $altAuthor
                            = $this->getSubfields($field, $authorFields[$tag]);
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
            foreach ($this->getFields('952') as $field952) {
                $key = [];
                $holding = [];
                $branch = $this->getSubfield($field952, $useHome ? 'a' : 'b');
                $key[] = $branch;
                // Always use subfield 'b' for location regardless of where it came
                // from
                $holding[] = ['b' => $branch];
                foreach (['c', 'h', 'o', '8'] as $code) {
                    $value = $this->getSubfield($field952, $code);
                    $key[] = $value;
                    if ('' !== $value) {
                        $holding[] = [$code => $value];
                    }
                }

                if ($alma) {
                    $available = $this->getSubfield($field952, '1') == 1;
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
                        if ($this->getSubfield($field952, $code)) {
                            $available = false;
                            break;
                        }
                    }
                    if ($available) {
                        $status = $this->getSubfield($field952, '7'); // Not for loan
                        $available = $status === '0' || $status === '1';
                    }
                }

                $key = implode('//', $key);
                if ($available) {
                    $availableBuildings[$key] = 1;
                }

                $holdings[$key] = $holding;
            }
            $this->fields['952'] = [];
            foreach ($holdings as $key => $holding) {
                if (isset($availableBuildings[$key])) {
                    $holding[] = ['9' => 1];
                }
                $this->fields['952'][] = [
                    'i1' => ' ',
                    'i2' => ' ',
                    's' => $holding
                ];
            }
        }

        if ($koha) {
            // Verify that 001 exists
            if ('' === $this->getField('001')) {
                if ($id = $this->getFieldSubfields('999', ['c' => 1])) {
                    $this->fields['001'] = [$id];
                }
            }
        }

        if ($alma) {
            // Add a prefixed id to field 090 to indicate that the record is from
            // Alma. Used at least with OpenURL.
            $id = $this->getField('001');
            $this->fields['090'][] = [
                'i1' => ' ',
                'i2' => ' ',
                's' => [
                    ['a' => "(Alma)$id"]
                ]
            ];
            ksort($this->fields);
        }
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
        if (preg_match('/\[(\d{4})\]/', $field, $matches)) {
            return $matches[1];
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
        foreach ($this->getFields('034') as $field) {
            $westOrig = $this->getSubfield($field, 'd');
            $eastOrig = $this->getSubfield($field, 'e');
            $northOrig = $this->getSubfield($field, 'f');
            $southOrig = $this->getSubfield($field, 'g');
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
                [self::GET_NORMAL, '035', ['a' => 1]]
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
                [self::GET_BOTH, '440', ['a' => 1]],
                [self::GET_BOTH, '490', ['a' => 1]],
                [self::GET_BOTH, '800', [
                    'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'f' => 1, 'p' => 1,
                    'q' => 1, 't' => 1
                ]],
                [self::GET_BOTH, '830', ['a' => 1, 'p' => 1]]
            ]
        );
    }
}
