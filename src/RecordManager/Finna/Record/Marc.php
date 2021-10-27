<?php
/**
 * Marc record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2021.
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
 * @license  http://opensource.org/licenses/gpl-2.0.1 GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Record\CreateRecordTrait;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
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
class Marc extends \RecordManager\Base\Record\Marc
{
    use AuthoritySupportTrait;
    use CreateRecordTrait;

    /**
     * Record plugin manager
     *
     * @var RecordPluginManager
     */
    protected $recordPluginManager;

    /**
     * Strings in field 300 that signify that the work is illustrated.
     *
     * @var string
     */
    protected $illustrationStrings = [
        'ill.', 'illus.', 'kuv.', 'kuvitettu', 'illustrated'
    ];

    /**
     * Extra data to be included in Solr fields e.g. from component parts
     *
     * @var array
     */
    protected $extraFields = [];

    /**
     * Default field for geographic coordinates
     *
     * @var string
     */
    protected $defaultGeoField = 'location_geo';

    /**
     * Default field for geographic center coordinates
     *
     * @var string
     */
    protected $defaultGeoCenterField = 'center_coords';

    /**
     * Default field for geographic center coordinates
     *
     * @var string
     */
    protected $defaultGeoDisplayField = '';

    /**
     * Cache for record format
     *
     * @var mixed
     */
    protected $cachedFormat = null;

    /**
     * Constructor
     *
     * @param array               $config              Main configuration
     * @param array               $dataSourceConfig    Data source settings
     * @param Logger              $logger              Logger
     * @param MetadataUtils       $metadataUtils       Metadata utilities
     * @param RecordPluginManager $recordPluginManager Record plugin manager
     */
    public function __construct(
        $config,
        $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils,
        RecordPluginManager $recordPluginManager
    ) {
        parent::__construct($config, $dataSourceConfig, $logger, $metadataUtils);

        $this->recordPluginManager = $recordPluginManager;
    }

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     *                       file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        $this->extraFields = [];
        $this->cachedFormat = null;
        parent::setData($source, $oaiID, $data);
    }

    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
        parent::normalize();

        // Kyyti enumeration from 362 to title
        if ($this->source == 'kyyti' && isset($this->fields['245'])
            && isset($this->fields['362'])
        ) {
            $enum = $this->getFieldSubfields('362', ['a' => 1]);
            if ($enum) {
                $this->fields['245'][0]['s'][] = ['n' => $enum];
            }
        }
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
        $data = parent::toSolrArray($db);

        if (empty($data['author'])) {
            foreach ($this->getFields('110') as $field110) {
                $author = $this->getSubfield($field110, 'a');
                if ($author) {
                    $data['author'][] = $author;
                    $role = $this->getSubfield($field110, '4');
                    if (!$role) {
                        $role = $this->getSubfield($field110, 'e');
                    }
                    $data['author_role'][] = $role
                        ? $this->metadataUtils->normalizeRelator($role) : '';
                }
            }
        }

        $primaryAuthors = $this->getPrimaryAuthors();
        $secondaryAuthors = $this->getSecondaryAuthors();
        $corporateAuthors = $this->getCorporateAuthors();
        $data['author2_id_str_mv'] = array_merge(
            $this->addNamespaceToAuthorityIds($primaryAuthors['ids']),
            $this->addNamespaceToAuthorityIds($secondaryAuthors['ids']),
            $this->addNamespaceToAuthorityIds($corporateAuthors['ids'])
        );
        $data['author2_id_role_str_mv'] = array_merge(
            $this->addNamespaceToAuthorityIds($primaryAuthors['idRoles']),
            $this->addNamespaceToAuthorityIds($secondaryAuthors['idRoles']),
            $this->addNamespaceToAuthorityIds($corporateAuthors['idRoles'])
        );

        if (isset($data['publishDate'])) {
            $data['main_date_str']
                = $this->metadataUtils->extractYear($data['publishDate'][0]);
            $data['main_date']
                = $this->validateDate($data['main_date_str'] . '-01-01T00:00:00Z');
        }
        if ($range = $this->getPublicationDateRange()) {
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = $this->metadataUtils->dateRangeToStr($range);
        }
        $data['publication_place_txt_mv'] = $this->metadataUtils->arrayTrim(
            $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '260', ['a' => 1]]
                ]
            ),
            ' []'
        );
        if (empty($data['publication_place_txt_mv'])) {
            $fields = $this->getFields('264');
            foreach ($fields as $field) {
                if ($this->getIndicator($field, 2) == '1') {
                    $data['publication_place_txt_mv'][]
                        = $this->metadataUtils->stripTrailingPunctuation(
                            $this->getSubfield($field, 'a')
                        );
                }
            }
        }

        $data['subtitle_lng_str_mv'] = $this->getSubtitleLanguages();
        $data['original_lng_str_mv'] = $this->getOriginalLanguages();

        // 979cd = component part authors
        // 900, 910, 911 = Finnish reference field
        foreach ($this->getFieldsSubfields(
            [
                [self::GET_BOTH, '979', ['c' => 1]],
                [self::GET_BOTH, '979', ['d' => 1]],
                [self::GET_BOTH, '900', ['a' => 1]],
                [self::GET_BOTH, '910', ['a' => 1, 'b' => 1]],
                [self::GET_BOTH, '911', ['a' => 1, 'e' => 1]]
            ],
            false,
            true,
            true
        ) as $field) {
            $field = trim($field);
            if ($field) {
                $data['author2'][] = $field;
                $data['author2_role'][] = '-';
            }
        }
        // 979l = component part author id's
        foreach ($this->getFields('979') as $field) {
            $ids = $this->getSubfieldsArray($field, ['l' => 1]);
            $data['author2_id_str_mv'] = array_merge(
                $data['author2_id_str_mv'],
                $this->addNamespaceToAuthorityIds($ids)
            );
        }

        $data['title_alt'] = array_values(
            array_unique(
                $this->getFieldsSubfields(
                    [
                        [self::GET_ALT, '245', ['a' => 1, 'b' => 1]],
                        [self::GET_BOTH, '130', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'k' => 1, 'l' => 1, 'n' => 1, 'p' => 1, 'r' => 1,
                            's' => 1, 't' => 1
                        ]],
                        [self::GET_BOTH, '240', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1, 'o' => 1,
                            'p' => 1, 'r' => 1, 's' => 1
                        ]],
                        [self::GET_BOTH, '243', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1, 'o' => 1,
                            'p' => 1, 'r' => 1, 's' => 1
                        ]],
                        [self::GET_BOTH, '246', [
                            'a' => 1, 'b' => 1, 'g' => 1
                        ]],
                        // Use only 700 fields that contain subfield 't'
                        [self::GET_BOTH, '700', [
                            't' => 1, 'm' => 1, 'r' => 1, 'h' => 1,
                            'i' => 1, 'g' => 1, 'n' => 1, 'p' => 1, 's' => 1,
                            'l' => 1, 'o' => 1, 'k' => 1],
                            ['t' => 1]
                        ],
                        [self::GET_BOTH, '730', [
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'h' => 1,
                            'i' => 1, 'k' => 1, 'l' => 1, 'm' => 1, 'n' => 1,
                            'o' => 1, 'p' => 1, 'r' => 1, 's' => 1, 't' => 1
                        ]],
                        [self::GET_BOTH, '740', ['a' => 1]],
                        // 979b = component part title
                        [self::GET_BOTH, '979', ['b' => 1]],
                        // 979e = component part uniform title
                        [self::GET_BOTH, '979', ['e' => 1]],
                        // Finnish 9xx reference field
                        [self::GET_BOTH, '940', ['a' => 1]],
                    ]
                )
            )
        );

        // Classifications
        foreach ($this->getFields('080') as $field080) {
            $classification = trim($this->getSubfield($field080, 'a'));
            $classification .= trim($this->getSubfield($field080, 'b'));
            if ($classification) {
                $aux = trim($this->getSubfields($field080, ['x' => 1]));
                if ($aux) {
                    $classification .= " $aux";
                }
                $data['classification_txt_mv'][] = "udk $classification";

                [$mainClass] = explode('.', $classification, 2);
                $mainClass = ".$mainClass";
                if (is_numeric($mainClass) && (!isset($data['major_genre_str_mv'])
                    || $data['major_genre_str_mv'] == 'nonfiction')
                ) {
                    if ($mainClass >= 0.82 && $mainClass < 0.9
                        && in_array($aux, ['-1', '-2', '-3', '-4', '-5', '-6', '-8'])
                    ) {
                        $data['major_genre_str_mv'] = 'fiction';
                    } elseif ($mainClass >= 0.78 && $mainClass < 0.79) {
                        $data['major_genre_str_mv'] = 'music';
                    } else {
                        $data['major_genre_str_mv'] = 'nonfiction';
                    }
                }
            }
        }
        $dlc = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '050', ['a' => 1, 'b' => 1]]]
        );
        foreach ($dlc as $classification) {
            $data['classification_txt_mv'][] = 'dlc '
                . mb_strtolower(str_replace(' ', '', $classification), 'UTF-8');
            $data['classification_txt_mv'][] = "dlc $classification";
        }
        $nlm = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '060', ['a' => 1, 'b' => 1]]]
        );
        foreach ($nlm as $classification) {
            $data['classification_txt_mv'][] = 'nlm '
                . mb_strtolower(str_replace(' ', '', $classification), 'UTF-8');
            $data['classification_txt_mv'][] = "nlm $classification";
        }
        foreach ($this->getFields('084') as $field) {
            $source = $this->getSubfield($field, '2');
            $classification = $this->getSubfields($field, ['a' => 1, 'b' => 1]);
            if ($source) {
                $data['classification_txt_mv'][] = "$source "
                    . mb_strtolower(str_replace(' ', '', $classification), 'UTF-8');
                $data['classification_txt_mv'][] = "$source $classification";
            }
            // Major genre
            if ($source == 'ykl' && (!isset($data['major_genre_str_mv'])
                || $data['major_genre_str_mv'] == 'nonfiction')
            ) {
                switch (substr($classification, 0, 2)) {
                case '78':
                    $data['major_genre_str_mv'] = 'music';
                    break;
                case '80':
                case '81':
                case '82':
                case '83':
                case '84':
                case '85':
                    $data['major_genre_str_mv'] = 'fiction';
                    break;
                default:
                    $data['major_genre_str_mv'] = 'nonfiction';
                    break;
                }
            }
        }

        // Keep classification_str_mv for backward-compatibility for now
        if (isset($data['classification_txt_mv'])) {
            $data['classification_str_mv'] = $data['classification_txt_mv'];
        }

        // Original Study Number
        $data['ctrlnum'] = array_merge(
            $data['ctrlnum'],
            $this->getFieldsSubfields([[self::GET_NORMAL, '036', ['a' => 1]]])
        );

        // Source
        $data['source_str_mv'] = $this->source;
        $data['datasource_str_mv'] = [$this->source];

        // ISSN
        $data['issn'] = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '022', ['a' => 1]]]
        );
        foreach ($data['issn'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        $data['other_issn_isn_mv'] = $data['other_issn_str_mv']
            = $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '440', ['x' => 1]],
                    [self::GET_NORMAL, '480', ['x' => 1]],
                    [self::GET_NORMAL, '490', ['x' => 1]],
                    [self::GET_NORMAL, '730', ['x' => 1]],
                    [self::GET_NORMAL, '776', ['x' => 1]],
                    [self::GET_NORMAL, '830', ['x' => 1]]
                ]
            );
        foreach ($data['other_issn_str_mv'] as &$value) {
            $value = str_replace('-', '', $value);
        }
        $data['linking_issn_str_mv'] = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '022', ['l' => 1]]]
        );
        foreach ($data['linking_issn_str_mv'] as &$value) {
            $value = str_replace('-', '', $value);
        }

        // URLs
        $fields = $this->getFields('856');
        foreach ($fields as $field) {
            $ind2 = $this->getIndicator($field, 2);
            $sub3 = $this->getSubfield($field, '3');
            if (($ind2 == '0' || $ind2 == '1') && !$sub3) {
                $url = trim($this->getSubfield($field, 'u'));
                if (!$url) {
                    continue;
                }
                // Require at least one dot surrounded by valid characters or a
                // familiar scheme
                if (!preg_match('/[A-Za-z0-9]\.[A-Za-z0-9]/', $url)
                    && !preg_match('/^(http|ftp)s?:\/\//', $url)
                ) {
                    continue;
                }
                $data['online_boolean'] = true;
                $data['online_str_mv'] = $this->source;
                $linkText = $this->getSubfield($field, 'y');
                if (!$linkText) {
                    $linkText = $this->getSubfield($field, 'z');
                }
                $link = [
                    'url' => $this->getSubfield($field, 'u'),
                    'text' => $linkText,
                    'source' => $this->source
                ];
                $data['online_urls_str_mv'][] = json_encode($link);
            }
        }

        // Holdings
        $data['holdings_txtP_mv']
            = $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '852', [
                        'a' => 1, 'b' => 1, 'h' => 1, 'z' => 1
                    ]],
                    [self::GET_NORMAL, '952', [
                        'b' => 1, 'c' => 1, 'o' => 1, 'h' => 1
                    ]]
                ]
            );
        if (!empty($data['holdings_txtP_mv'])) {
            $updateFunc = function (&$val, $k, $source) {
                $val .= " $source";
            };
            array_walk($data['holdings_txtP_mv'], $updateFunc, $this->source);
        }

        // Shelving location in building_sub_str_mv
        $subBuilding = $this->getDriverParam('subBuilding', '');
        if ('1' === $subBuilding) { // true
            $subBuilding = 'c';
        }
        $itemSubBuilding = $this->getDriverParam('itemSubBuilding', $subBuilding);
        if ($subBuilding) {
            foreach ($this->getFields('852') as $field) {
                $location = $this->getSubfield($field, $subBuilding);
                if ('' !== $location) {
                    $data['building_sub_str_mv'][] = $location;
                }
            }
        }
        if ($itemSubBuilding) {
            foreach ($this->getFields('952') as $field) {
                $location = $this->getSubfield($field, $itemSubBuilding);
                if ('' !== $location) {
                    $data['building_sub_str_mv'][] = $location;
                }
            }
        }

        // Collection code from MARC fields
        $collectionFields = $this->getDriverParam('collectionFields', '');
        if ($collectionFields) {
            foreach (explode(':', $collectionFields) as $fieldSpec) {
                $fieldTag = substr($fieldSpec, 0, 3);
                $subfields = array_flip(str_split(substr($fieldSpec, 3)));
                foreach ($this->getFields($fieldTag) as $field) {
                    $subfieldArray
                        = $this->getSubfieldsArray($field, $subfields);
                    foreach ($subfieldArray as $subfield) {
                        $data['collection'] = $subfield;
                    }
                }
            }
        }

        // Access restrictions
        if ($restrictions = $this->getAccessRestrictions()) {
            $data['restricted_str'] = $restrictions;
        }

        // NBN
        foreach ($this->getFields('015') as $field015) {
            $nbn = $this->getSubfield($field015, 'a');
            $data['nbn_isn_mv'] = $nbn;
        }

        // ISMN, ISRC, UPC, EAN
        foreach ($this->getFields('024') as $field024) {
            $ind1 = $this->getIndicator($field024, 1);
            switch ($ind1) {
            case '0':
                $isrc = $this->getSubfield($field024, 'a');
                $data['isrc_isn_mv'][] = $isrc;
                break;
            case '1':
                $upc = $this->getSubfield($field024, 'a');
                $data['upc_isn_mv'][] = $upc;
                break;
            case '2':
                $ismn = $this->getSubfield($field024, 'a');
                $ismn = str_replace('-', '', $ismn);
                if (!preg_match('{([0-9]{13})}', $ismn, $matches)) {
                    continue 2; // foreach
                }
                $data['ismn_isn_mv'][] = $matches[1];
                break;
            case '3':
                $ean = $this->getSubfield($field024, 'a');
                $ean = str_replace('-', '', $ean);
                if (!preg_match('{([0-9]{13})}', $ean, $matches)) {
                    continue 2; // foreach
                }
                $data['ean_isn_mv'][] = $matches[1];
                break;
            }
        }

        // Identifiers from component parts (type as a leading string)
        foreach ($this->getFieldsSubfields(
            [[self::GET_NORMAL, '979', ['k' => 1]]],
            false,
            true,
            true
        ) as $identifier) {
            $parts = explode(' ', $identifier, 2);
            if (!isset($parts[1])) {
                continue;
            }
            switch ($parts[0]) {
            case 'ISBN':
                $data['isbn'][] = $parts[1];
                break;
            case 'ISSN':
                $data['issn'][] = $parts[1];
                break;
            case 'ISRC':
                $data['isrc_isn_mv'][] = $parts[1];
                break;
            case 'UPC':
                $data['upc_isn_mv'][] = $parts[1];
                break;
            case 'ISMN':
                $data['ismn_isn_mv'][] = $parts[1];
                break;
            case 'EAN':
                $data['ean_isn_mv'][] = $parts[1];
                break;
            }
        }

        // Project ID in 960 (Fennica)
        if ($this->getDriverParam('projectIdIn960', false)) {
            $data['project_id_str_mv'] = $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '960', ['a' => 1]]
                ]
            );
        }

        // Hierarchical Categories (database records in Voyager)
        foreach ($this->getFields('886') as $field886) {
            if ($this->getIndicator($field886, 1) != '2'
                || $this->getSubfield($field886, '2') != 'local'
            ) {
                continue;
            }
            $type = $this->getSubfield($field886, 'a');
            if (in_array($type, ['aineistotyyppi', 'resurstyp'])) {
                $resourceType = $this->getSubfield($field886, 'c');
                if (in_array($resourceType, ['tietokanta', 'databas'])) {
                    $data['format'] = 'Database';
                    foreach ($this->getFields('035') as $f035) {
                        if ($originalId = $this->getSubfield($f035, 'a')) {
                            $originalId
                                = preg_replace('/^\(.*?\)/', '', $originalId);
                            $data['original_id_str_mv'][] = $originalId;
                        }
                    }
                }
                $access = $this->metadataUtils->normalizeKey(
                    $this->getFieldSubfields('506', ['f' => 1]),
                    'NFKC'
                );
                switch ($access) {
                case 'unrestricted':
                case 'unrestrictedonlineaccess':
                    // no restrictions
                    break;
                default:
                    $data['restricted_str'] = 'restricted';
                    break;
                }
            }
            if (in_array($type, ['kategoria', 'kategori'])) {
                $category = $this->getSubfield($field886, 'c');
                $sub = $this->getSubfield($field886, 'd');
                if ($sub) {
                    $category .= "/$sub";
                }
                $data['category_str_mv'][] = $category;
            }
        }

        // Hierarchical categories (e.g. SFX)
        if ($this->getDriverParam('categoriesIn650', false)) {
            foreach ($this->getFields('650') as $field650) {
                $category = $this->getSubfield($field650, 'a');
                $category = trim(str_replace(['/', '\\'], '', $category));
                if (!$category) {
                    continue;
                }
                $sub = $this->getSubfield($field650, 'x');
                $sub = trim(str_replace(['/', '\\'], '', $sub));
                if ($sub) {
                    $category .= "/$sub";
                }
                $data['category_str_mv'][] = $category;
            }
        }

        // Call numbers
        $data['callnumber-first'] = strtoupper(
            str_replace(
                ' ',
                '',
                $this->getFirstFieldSubfields(
                    [
                        [self::GET_NORMAL, '080', ['a' => 1, 'b' => 1]],
                        [self::GET_NORMAL, '084', ['a' => 1, 'b' => 1]],
                        [self::GET_NORMAL, '050', ['a' => 1, 'b' => 1]]
                    ]
                )
            )
        );
        $data['callnumber-raw'] = array_map(
            'strtoupper',
            $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '080', ['a' => 1, 'b' => 1]],
                    [self::GET_NORMAL, '084', ['a' => 1, 'b' => 1]],
                ]
            )
        );
        $data['callnumber-sort'] = '';
        if (!empty($data['callnumber-raw'])) {
            $data['callnumber-sort'] = $data['callnumber-raw'][0];
        }
        $lccn = array_map(
            'strtoupper',
            $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '050', ['a' => 1, 'b' => 1]]
                ]
            )
        );
        if ($lccn) {
            $data['callnumber-raw'] = array_merge(
                $data['callnumber-raw'],
                $lccn
            );
            if (empty($data['callnumber-sort'])) {
                // Try to find a valid call number
                $firstCn = null;
                foreach ($lccn as $callnumber) {
                    $cn = new LcCallNumber($callnumber);
                    if (null === $firstCn) {
                        $firstCn = $cn;
                    }
                    if ($cn->isValid()) {
                        $data['callnumber-sort'] = $cn->getSortKey();
                        break;
                    }
                }
                if (empty($data['callnumber-sort'])) {
                    // No valid call number, take first
                    $data['callnumber-sort'] = $cn->getSortKey();
                }
            }
        }

        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        if (!empty($data['online_str_mv'])) {
            $access = $this->metadataUtils->normalizeKey(
                $this->getFieldSubfields('506', ['f' => 1]),
                'NFKC'
            );
            if ($access !== 'onlineaccesswithauthorization') {
                $data['free_online_str_mv'] = $data['online_str_mv'];
                $data['free_online_boolean'] = true;
            }
        } else {
            // Check online availability from carrier type. This is intentionally
            // done after the free check above, since these records seem to often not
            // have the 506 field.
            $fields = $this->getFields('338');
            foreach ($fields as $field) {
                $b = $this->getSubfield($field, 'b');
                if ('cr' === $b) {
                    $data['online_boolean'] = true;
                    $data['online_str_mv'] = $this->source;
                }
            }
        }

        // Author facet
        $primaryAuthors = $this->getPrimaryAuthorsFacet();
        $secondaryAuthors = $this->getSecondaryAuthorsFacet();
        $corporateAuthors = $this->getCorporateAuthorsFacet();
        $data['author_facet'] = array_map(
            function ($s) {
                return preg_replace('/\s+/', ' ', $s);
            },
            array_merge(
                $primaryAuthors['names'],
                $secondaryAuthors['names'],
                $corporateAuthors['names']
            )
        );

        if ('VideoGame' === $data['format']) {
            if ($platforms = $this->getGamePlatformIds()) {
                $data['format'] = [['VideoGame', reset($platforms)]];
                $data['format_ext_str_mv'] = [];
                foreach ($platforms as $platform) {
                    $data['format_ext_str_mv'] = [['VideoGame', $platform]];
                }
            }
        } else {
            $data['format_ext_str_mv'] = $data['format'];
        }

        $availableBuildings = $this->getAvailableItemsBuildings();
        if ($availableBuildings) {
            $data['building_available_str_mv'] = $availableBuildings;
            $data['source_available_str_mv'] = $this->source;
        }

        // Additional authority ids
        $data['topic_id_str_mv'] = $this->getTopicIds();

        // Make sure center_coords is single-valued
        if (!empty($data['center_coords'])) {
            $data['center_coords'] = $data['center_coords'][0];
        }

        $data['description'] = implode(
            ' ',
            $this->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '520', ['a' => 1]],
                ]
            )
        );

        // Merge any extra fields from e.g. merged component parts
        foreach ($this->extraFields as $field => $fieldData) {
            $data[$field] = array_merge(
                (array)($data[$field] ?? []),
                (array)$fieldData
            );
        }

        return $data;
    }

    /**
     * Get all non-specific topics
     *
     * @return array
     */
    protected function getTopicIds()
    {
        $fieldTags = ['600', '610', '611', '630', '650'];
        $result = [];
        foreach ($fieldTags as $tag) {
            foreach ($this->getFields($tag) as $field) {
                if ($id = $this->getSubfield($field, '0')) {
                    $result[] = $id;
                }
            }
        }
        return $this->addNamespaceToAuthorityIds($result);
    }

    /**
     * Merge component parts to this record
     *
     * @param \Traversable $componentParts Component parts to be merged
     * @param mixed        $changeDate     Latest database timestamp for the
     *                                     component part set
     *
     * @return int Count of records merged
     */
    public function mergeComponentParts($componentParts, &$changeDate)
    {
        $count = 0;
        $parts = [];
        foreach ($componentParts as $componentPart) {
            if (null === $changeDate || $changeDate < $componentPart['date']) {
                $changeDate = $componentPart['date'];
            }
            $data = $this->metadataUtils->getRecordData($componentPart, true);
            $marc = $this->createRecord(
                $componentPart['format'],
                $data,
                '',
                $this->source
            );
            $title = $marc->getFieldSubfields(
                '245',
                ['a' => 1, 'b' => 1, 'n' => 1, 'p' => 1]
            );
            $uniTitle
                = $marc->getFieldSubfields('240', ['a' => 1, 'n' => 1, 'p' => 1]);
            if (!$uniTitle) {
                $uniTitle = $marc->getFieldSubfields(
                    '130',
                    ['a' => 1, 'n' => 1, 'p' => 1]
                );
            }
            $additionalTitles = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '740', ['a' => 1]]
                ]
            );
            $authors = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '100', ['a' => 1, 'e' => 1]],
                    [self::GET_NORMAL, '110', ['a' => 1, 'e' => 1]]
                ]
            );
            $additionalAuthors = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '700', ['a' => 1, 'e' => 1]],
                    [self::GET_NORMAL, '710', ['a' => 1, 'e' => 1]]
                ]
            );
            $authorIds = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '100', ['0' => 1]],
                    [self::GET_NORMAL, '110', ['0' => 1]],
                    [self::GET_NORMAL, '700', ['0' => 1]],
                    [self::GET_NORMAL, '710', ['0' => 1]],
                ]
            );
            $duration = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '306', ['a' => 1]]
                ]
            );
            $languages = [substr($marc->getField('008'), 35, 3)];
            $languages = array_unique(
                array_merge(
                    $languages,
                    $marc->getFieldsSubfields(
                        [
                            [self::GET_NORMAL, '041', ['a' => 1]],
                            [self::GET_NORMAL, '041', ['d' => 1]]
                        ],
                        false,
                        true,
                        true
                    )
                )
            );
            $languages = $this->metadataUtils->normalizeLanguageStrings($languages);
            $originalLanguages = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '041', ['h' => 1]]
                ],
                false,
                true,
                true
            );
            $originalLanguages
                = $this->metadataUtils->normalizeLanguageStrings($originalLanguages);
            $subtitleLanguages = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '041', ['j' => 1]]
                ],
                false,
                true,
                true
            );
            $subtitleLanguages
                = $this->metadataUtils->normalizeLanguageStrings($subtitleLanguages);
            $id = $componentPart['_id'];

            $identifierFields = [
                'ISBN' => [self::GET_NORMAL, '020', ['a' => 1]],
                'ISSN' => [self::GET_NORMAL, '022', ['a' => 1]],
                'OAN' => [self::GET_NORMAL, '025', ['a' => 1]],
                'FI' => [self::GET_NORMAL, '026', ['a' => 1, 'b' => 1]],
                'STRN' => [self::GET_NORMAL, '027', ['a' => 1]],
                'PDN' => [self::GET_NORMAL, '028', ['a' => 1]]
            ];

            foreach ($identifierFields as $idKey => $settings) {
                $identifiers = $marc->getFieldsSubfields([$settings]);
                $identifiers = array_map(
                    function ($s) use ($idKey) {
                        return "$idKey $s";
                    },
                    $identifiers
                );
            }

            foreach ($marc->getFields('024') as $field024) {
                $ind1 = $marc->getIndicator($field024, 1);
                switch ($ind1) {
                case '0':
                    $isrc = $marc->getSubfield($field024, 'a');
                    $identifiers[] = "ISRC $isrc";
                    break;
                case '1':
                    $upc = $marc->getSubfield($field024, 'a');
                    $identifiers[] = "UPC $upc";
                    break;
                case '2':
                    $ismn = $marc->getSubfield($field024, 'a');
                    $ismn = str_replace('-', '', $ismn);
                    if (!preg_match('{([0-9]{13})}', $ismn, $matches)) {
                        continue 2; // foreach
                    }
                    $identifiers[] = 'ISMN ' . $matches[1];
                    break;
                case '3':
                    $ean = $marc->getSubfield($field024, 'a');
                    $ean = str_replace('-', '', $ean);
                    if (!preg_match('{([0-9]{13})}', $ean, $matches)) {
                        continue 2; // foreach
                    }
                    $identifiers[] = 'EAN ' . $matches[1];
                    break;
                }
            }

            foreach ($marc->getFields('031') as $field031) {
                foreach ($marc->getSubfieldsArray($field031, ['t' => 1]) as $lyrics
                ) {
                    $this->extraFields['allfields'][] = $lyrics;
                    // Text incipit is treated as an alternative title
                    $this->extraFields['title_alt'][] = $lyrics;
                }
            }
            $titlesVarying = $marc->getFieldsSubfields(
                [[self::GET_NORMAL, '246', ['a' => 1, 'b' => 1, 'n' => 1, 'p' => 1]]]
            );
            if ($titlesVarying) {
                $this->extraFields['allfields'] = array_merge(
                    (array)($this->extraFields['allfields'] ?? []),
                    $titlesVarying
                );
                $this->extraFields['title_alt'] = array_merge(
                    (array)($this->extraFields['title_alt'] ?? []),
                    $titlesVarying
                );
            }

            $newField = [
                'i1' => ' ',
                'i2' => ' ',
                's' => [
                    ['a' => $id]
                 ]
            ];
            if ($title) {
                $newField['s'][] = ['b' => $title];
            }
            if ($authors) {
                $newField['s'][] = ['c' => array_shift($authors)];
                foreach ($authors as $author) {
                    $newField['s'][] = ['d' => $author];
                }
            }
            foreach ($additionalAuthors as $addAuthor) {
                $newField['s'][] = ['d' => $addAuthor];
            }
            if ($uniTitle) {
                $newField['s'][] = ['e' => $uniTitle];
            }
            if ($duration) {
                $newField['s'][] = ['f' => reset($duration)];
            }
            foreach ($additionalTitles as $addTitle) {
                $newField['s'][] = ['g' => $addTitle];
            }
            foreach ($languages as $language) {
                if ('|||' !== $language) {
                    $newField['s'][] = ['h' => $language];
                }
            }
            foreach ($originalLanguages as $language) {
                if ('|||' !== $language) {
                    $newField['s'][] = ['i' => $language];
                }
            }
            foreach ($subtitleLanguages as $language) {
                if ('|||' !== $language) {
                    $newField['s'][] = ['j' => $language];
                }
            }
            foreach ($identifiers as $identifier) {
                $newField['s'][] = ['k' => $identifier];
            }
            foreach ($authorIds as $identifier) {
                $newField['s'][] = ['l' => $identifier];
            }

            $key = $this->metadataUtils->createIdSortKey($id);
            $parts["$key $count"] = $newField;
            ++$count;
        }
        ksort($parts);
        $this->fields['979'] = array_values($parts);
        return $count;
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     */
    public function getFormat()
    {
        if (null === $this->cachedFormat) {
            $this->cachedFormat = $this->getFormatFunc();
        }
        return $this->cachedFormat;
    }

    /**
     * Return format from predefined values
     *
     * @return string
     */
    protected function getFormatFunc()
    {
        // Custom predefined type in 977a
        $field977a = $this->getFieldSubfields('977', ['a' => 1]);
        if ($field977a) {
            return $field977a;
        }

        // Dissertations and Thesis
        if (isset($this->fields['502'])) {
            return 'Dissertation';
        }
        $dissTypes = [];
        if (isset($this->fields['509'])) {
            $dissTypes = $this->getFieldsSubfields(
                [[self::GET_NORMAL, '509', ['a' => 1]]]
            );
        }
        if (!$dissTypes && isset($this->fields['920'])) {
            $dissTypes = $this->getFieldsSubfields(
                [[self::GET_NORMAL, '920', ['a' => 1]]]
            );
        }
        if ($dissTypes) {
            foreach ($dissTypes as $dissType) {
                $dissType = mb_strtolower(
                    $this->metadataUtils->normalizeUnicode($dissType, 'NFKC'),
                    'UTF-8'
                );
                switch ($dissType) {
                case 'kandidaatintutkielma':
                case 'kandidaatintyö':
                case 'kandidatarbete':
                    return 'BachelorsThesis';
                case 'pro gradu -tutkielma':
                case 'pro gradu -työ':
                case 'pro gradu':
                    return 'ProGradu';
                case 'laudaturtyö':
                case 'laudaturavh':
                    return 'LaudaturThesis';
                case 'lisensiaatintyö':
                case 'lic.avh.':
                    return 'LicentiateThesis';
                case 'diplomityö':
                case 'diplomarbete':
                    return 'MastersThesis';
                case 'erikoistyö':
                case 'vicenot.ex.':
                    return 'Thesis';
                case 'lopputyö':
                case 'rättsnot.ex.':
                    return 'Thesis';
                case 'amk-opinnäytetyö':
                case 'yh-examensarbete':
                    return 'BachelorsThesisPolytechnic';
                case 'ylempi amk-opinnäytetyö':
                case 'högre yh-examensarbete':
                    return 'MastersThesisPolytechnic';
                }
            }
            return 'Thesis';
        }

        // Get the type of record from leader position 6
        $leader = $this->getField('000');
        $typeOfRecord = substr($leader, 6, 1);

        // Get the bibliographic level from leader position 7
        $bibliographicLevel = substr($leader, 7, 1);

        // Get 008
        $field008 = $this->getField('008');

        // Board games and video games
        $self = $this;
        $termsIn655 = null;
        $termIn655 = function (string $term) use ($self, &$termsIn655) {
            if (null === $termsIn655) {
                $termsIn655 = $self->getFieldsSubfields(
                    [[self::GET_NORMAL, '655', ['a' => 1]]]
                );
                $termsIn655 = array_map(
                    function ($s) {
                        return mb_strtolower($s, 'UTF-8');
                    },
                    $termsIn655
                );
            }
            return in_array($term, $termsIn655);
        };
        if ('r' === $typeOfRecord) {
            $visualType = substr($field008, 33, 1);
            if ('g' === $visualType || $termIn655('lautapelit')) {
                return 'BoardGame';
            }
        } elseif ('m' === $typeOfRecord) {
            $electronicType = substr($field008, 26, 1);
            if ('g' === $electronicType || $termIn655('videopelit')) {
                return 'VideoGame';
            }
        }

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
                        return 'i' === $typeOfRecord ? 'NonmusicalCD' : 'CD';
                    }
                    return 'i' === $typeOfRecord ? 'NonmusicalDisc' : 'SoundDisc';
                case 'S':
                    return 'i' === $typeOfRecord
                        ? 'NonmusicalCassette' : 'SoundCassette';
                default:
                    if ('i' === $typeOfRecord) {
                        return 'NonmusicalRecording';
                    }
                    if ('j' === $typeOfRecord) {
                        return 'MusicRecording';
                    }
                    return 'SoundRecording';
                }
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
                case 'Z':
                    if ($online) {
                        return 'OnlineVideo';
                    }
                    return 'Video';
                default:
                    return 'Video';
                }
                break;
            }
        }

        switch (strtoupper($typeOfRecord)) {
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
            break;
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

        if (!$online) {
            $online = substr($field008, 23, 1) === 'o';
        }

        switch (strtoupper($bibliographicLevel)) {
        // Monograph
        case 'M':
            if ($online) {
                return 'eBook';
            } else {
                return 'Book';
            }
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
     * Check if record has access restrictions.
     *
     * @return string 'restricted' or more specific licence id if restricted,
     * empty string otherwise
     */
    public function getAccessRestrictions()
    {
        // Access restrictions based on location
        $restricted = $this->getDriverParam('restrictedLocations', '');
        if ($restricted) {
            $restricted = array_flip(
                array_map(
                    'trim',
                    explode(',', $restricted)
                )
            );
        }
        if ($restricted) {
            foreach ($this->getFields('852') as $field852) {
                $locationCode = trim($this->getSubfield($field852, 'b'));
                if (isset($restricted[$locationCode])) {
                    return 'restricted';
                }
            }
            foreach ($this->getFields('952') as $field952) {
                $locationCode = trim($this->getSubfield($field952, 'b'));
                if (isset($restricted[$locationCode])) {
                    return 'restricted';
                }
            }
        }
        foreach ($this->getFields('540') as $field) {
            $sub3 = $this->metadataUtils->stripTrailingPunctuation(
                $this->getSubfield($field, '3')
            );
            if ($sub3 == 'Metadata' || strncasecmp($sub3, 'metadata', 8) == 0) {
                $subA = $this->metadataUtils->stripTrailingPunctuation(
                    $this->getSubfield($field, 'a')
                );
                if (strncasecmp($subA, 'ei poimintaa', 12) == 0) {
                    return 'restricted';
                }
            }
        }
        return '';
    }

    /**
     * Check if the record is suppressed.
     *
     * @return bool
     */
    public function getSuppressed()
    {
        if (parent::getSuppressed()) {
            return true;
        }
        if ($this->getDriverParam('kohaNormalization', false)) {
            foreach ($this->getFields('942') as $field942) {
                $suppressed = $this->getSubfield($field942, 'n');
                return (bool)$suppressed;
            }
        }
        return false;
    }

    /**
     * Try to determine the gaming console or other platform identifiers
     *
     * @return array
     */
    protected function getGamePlatformIds()
    {
        $result = [];
        $fields = $this->getFields('753');
        if ($fields) {
            foreach ($fields as $field) {
                if ($id = $this->getSubfield($field, '0')) {
                    $result[] = $id;
                }
                if ($os = $this->getSubfield($field, 'c')) {
                    $result[] = $os;
                }
                if ($device = $this->getSubfield($field, 'a')) {
                    $result[] = $device;
                }
            }
        } elseif ($field = $this->getField('245')) {
            if ($b = $this->getSubfield($field, 'b')) {
                $result[] = $this->metadataUtils->stripTrailingPunctuation($b);
            }
        }

        return $result;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        $rights = [];
        foreach ($this->getFields('540') as $field) {
            $sub3 = $this->metadataUtils->stripTrailingPunctuation(
                $this->getSubfield($field, '3')
            );
            if ($sub3 == 'Metadata' || strncasecmp($sub3, 'metadata', 8) == 0) {
                continue;
            }
            $subC = $this->metadataUtils->stripTrailingPunctuation(
                $this->getSubfield($field, 'c')
            );
            $rights[] = $subC ? $subC : 'restricted';
        }
        return $rights;
    }

    /**
     * Return publication year/date range
     *
     * @return array Date range
     */
    protected function getPublicationDateRange()
    {
        $field008 = $this->getField('008');
        if ($field008) {
            switch (substr($field008, 6, 1)) {
            case 'c':
                $year = substr($field008, 7, 4);
                $startDate = "$year-01-01T00:00:00Z";
                $endDate = '9999-12-31T23:59:59Z';
                break;
            case 'd':
            case 'i':
            case 'k':
            case 'm':
            case 'q':
                $year1 = substr($field008, 7, 4);
                $year2 = substr($field008, 11, 4);
                if (ctype_digit($year1) && ctype_digit($year2) && $year2 < $year1) {
                    $startDate = "$year2-01-01T00:00:00Z";
                    $endDate = "$year1-12-31T23:59:59Z";
                } else {
                    $startDate = "$year1-01-01T00:00:00Z";
                    $endDate = "$year2-12-31T23:59:59Z";
                }
                break;
            case 'e':
                $year = substr($field008, 7, 4);
                $mon = substr($field008, 11, 2);
                $day = substr($field008, 13, 2);
                $startDate = "$year-$mon-{$day}T00:00:00Z";
                $endDate = "$year-$mon-{$day}T23:59:59Z";
                break;
            case 's':
            case 't':
            case 'u':
                $year = substr($field008, 7, 4);
                $startDate = "$year-01-01T00:00:00Z";
                $endDate = "$year-12-31T23:59:59Z";
                break;
            }
        }

        if (!isset($startDate) || !isset($endDate)
            || $this->metadataUtils->validateISO8601Date($startDate) === false
            || $this->metadataUtils->validateISO8601Date($endDate) === false
        ) {
            $field = $this->getField('260');
            if ($field) {
                $year = $this->getSubfield($field, 'c');
                $matches = [];
                if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                    $startDate = "{$matches[1]}-01-01T00:00:00Z";
                    $endDate = "{$matches[1]}-12-31T23:59:59Z";
                }
            }
        }

        if (!isset($startDate) || !isset($endDate)
            || $this->metadataUtils->validateISO8601Date($startDate) === false
            || $this->metadataUtils->validateISO8601Date($endDate) === false
        ) {
            $fields = $this->getFields('264');
            foreach ($fields as $field) {
                if ($this->getIndicator($field, 2) == '1') {
                    $year = $this->getSubfield($field, 'c');
                    $matches = [];
                    if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                        $startDate = "{$matches[1]}-01-01T00:00:00Z";
                        $endDate = "{$matches[1]}-12-31T23:59:59Z";
                        break;
                    }
                }
            }
        }
        if (isset($startDate) && isset($endDate)
            && $this->metadataUtils->validateISO8601Date($startDate) !== false
            && $this->metadataUtils->validateISO8601Date($endDate) !== false
        ) {
            if ($endDate < $startDate) {
                $this->logger->logDebug(
                    'Marc',
                    "Invalid date range {$startDate} - {$endDate}, record "
                        . "{$this->source}." . $this->getID()
                );
                $this->storeWarning('invalid date range in 008');
                $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
            }
            return [$startDate, $endDate];
        }

        return '';
    }

    /**
     * Get 653 fields that have the requested second indicator
     *
     * @param string|array $ind Allowed second indicator value(s)
     *
     * @return array
     */
    protected function get653WithSecondInd($ind)
    {
        $key = __METHOD__ . '-' . (is_array($ind) ? implode(',', $ind) : $ind);
        if (isset($this->resultCache[$key])) {
            return $this->resultCache[$key];
        }
        $result = [];
        $ind = (array)$ind;
        foreach ($this->getFields('653') as $field) {
            if (in_array($this->getIndicator($field, 2), $ind)) {
                $term = $this->getSubfields($field, ['a' => 1]);
                if ($term) {
                    $result[] = $term;
                }
            }
        }
        $this->resultCache[$key] = $result;
        return $result;
    }

    /**
     * Get an array of all fields relevant to allfields search
     *
     * @return array
     */
    protected function getAllFields()
    {
        $fieldFilter = [
            '300' => 1, '336' => 1, '337' => 1, '338' => 1
        ];
        $subfieldFilter = [
            '015' => ['q' => 1, 'z' => 1, '2' => 1, '6' => 1, '8' => 1],
            '024' => ['c' => 1, 'd' => 1, 'z' => 1, '6' => 1, '8' => 1],
            '027' => ['z' => 1, '6' => 1, '8' => 1],
            '031' => [
                'a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'g' => 1, 'm' => 1,
                'n' => 1, 'o' => 1, 'p' => 1, 'q' => 1, 'r' => 1, 's' => 1, 'u' => 1,
                'y' => 1, 'z' => 1, '2' => 1, '6' => 1, '8' => 1
            ],
            '650' => ['0' => 1, '2' => 1, '6' => 1, '8' => 1],
            '100' => ['0' => 1, '4' => 1],
            '700' => ['0' => 1, '4' => 1],
            '710' => ['0' => 1, '4' => 1],
            '711' => ['0' => 1, '4' => 1],
            '773' => [
                '0' => 1, '4' => 1, '6' => 1, '7' => 1, '8' => 1, 'g' => 1, 'q' => 1,
                'w' => 1
            ],
            '787' => ['i' => 1],
            // Koha serial enumerations
            '952' => ['a' => 1, 'b' => 1, 'c' => 1, 'o' => 1],
            '979' => ['0' => 1, 'a' => 1, 'f' => 1]
        ];
        $allFields = [];
        // Include ISBNs, also normalized if possible
        foreach ($this->getFields('020') as $field) {
            $isbns = $this->getSubfieldsArray($field, ['a' => 1, 'z' => 1]);
            foreach ($isbns as $isbn) {
                if (strlen($isbn) < 10) {
                    continue;
                }
                $allFields[] = $isbn;
                $isbn = $this->metadataUtils->normalizeISBN($isbn);
                if ($isbn) {
                    $allFields[] = $isbn;
                }
            }
        }
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 841 && !isset($fieldFilter[$tag]))
                || in_array(
                    $tag,
                    [
                        '015', '024', '025', '026', '027', '028', '031', '880',
                        '900', '910', '911', '940', '952', '979'
                    ]
                )
            ) {
                foreach ($fields as $field) {
                    $subfields = $this->getAllSubfields(
                        $field,
                        $subfieldFilter[$tag]
                        ?? ['0' => 1, '6' => 1, '8' => 1]
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
     * Get the building field
     *
     * @return array
     */
    protected function getBuilding()
    {
        $building = parent::getBuilding();

        // Ebrary location
        $ebraryLocs = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '035', ['a' => 1]]]
        );
        foreach ($ebraryLocs as $field) {
            if (strncmp($field, 'ebr', 3) == 0 && is_numeric(substr($field, 3))) {
                if (!in_array('EbraryDynamic', $building)) {
                    $building[] = 'EbraryDynamic';
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
        $itemSub = $this->getDriverParam('itemSubLocationInBuilding', $useSub);
        return [
            [
                'field' => '852',
                'loc' => 'b',
                'sub' => $useSub,
            ],
            [
                'field' => '952',
                'loc' => 'b',
                'sub' => $itemSub,
            ],
        ];
    }

    /**
     * Get era facet fields
     *
     * @return array Topics
     */
    protected function getEraFacets()
    {
        $result = parent::getEraFacets();
        $result = array_merge(
            $result,
            $this->getAdditionalEraFields()
        );
        return $result;
    }

    /**
     * Get all era topics
     *
     * @return array
     */
    protected function getEras()
    {
        $result = parent::getEras();
        $result = array_merge(
            $result,
            $this->getAdditionalEraFields()
        );
        return $result;
    }

    /**
     * Get additional era fields
     *
     * @return array
     */
    protected function getAdditionalEraFields()
    {
        if (!isset($this->resultCache[__METHOD__])) {
            $this->resultCache[__METHOD__] = array_merge(
                $this->get653WithSecondInd('4'),
                $this->getFieldsSubfields([[self::GET_NORMAL, '388', ['a' => 1]]])
            );
        }
        return $this->resultCache[__METHOD__];
    }

    /**
     * Get genre facet fields
     *
     * @return array Topics
     */
    protected function getGenreFacets()
    {
        $result = parent::getGenreFacets();
        $result = array_merge(
            $result,
            $this->get653WithSecondInd('6')
        );
        return $result;
    }

    /**
     * Get all genre topics
     *
     * @return array
     */
    protected function getGenres()
    {
        $result = parent::getGenres();
        $result = array_merge(
            $result,
            $this->get653WithSecondInd('6')
        );
        return $result;
    }

    /**
     * Get geographic facet fields
     *
     * @return array Topics
     */
    protected function getGeographicFacets()
    {
        $result = parent::getGeographicFacets();
        $result = array_merge(
            $result,
            $this->get653WithSecondInd('5'),
            $this->getFieldsSubfields([[self::GET_NORMAL, '370', ['g' => 1]]])
        );
        return $result;
    }

    /**
     * Get all geographic topics
     *
     * @return array
     */
    protected function getGeographicTopics()
    {
        $result = parent::getGeographicTopics();
        $result = array_merge(
            $result,
            $this->get653WithSecondInd('5'),
            $this->getFieldsSubfields([[self::GET_NORMAL, '370', ['g' => 1]]])
        );
        return $result;
    }

    /**
     * Get topic facet fields
     *
     * @return array Topics
     */
    protected function getTopicFacets()
    {
        $result = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '600', ['a' => 1, 'x' => 1]],
                [self::GET_NORMAL, '610', ['a' => 1, 'x' => 1]],
                [self::GET_NORMAL, '611', ['a' => 1, 'x' => 1]],
                [self::GET_NORMAL, '630', ['a' => 1, 'x' => 1]],
                [self::GET_NORMAL, '648', ['x' => 1]],
                [self::GET_NORMAL, '650', ['a' => 1, 'x' => 1]],
                [self::GET_NORMAL, '651', ['x' => 1]],
                [self::GET_NORMAL, '655', ['x' => 1]]
            ],
            false,
            true,
            true
        );
        $result = array_merge(
            $result,
            $this->get653WithSecondInd([' ', '0', '1', '2', '3'])
        );
        return $result;
    }

    /**
     * Get all non-specific topics
     *
     * @return array
     */
    protected function getTopics()
    {
        $result = array_merge(
            parent::getTopics(),
            $this->get653WithSecondInd([' ', '0', '1', '2', '3'])
        );
        return $result;
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
                // 979h = component part language
                [self::GET_NORMAL, '979', ['h' => 1]]
            ],
            false,
            true,
            true
        );
        $result = array_merge($languages, $languages2);
        return $this->metadataUtils->normalizeLanguageStrings($result);
    }

    /**
     * Get primary authors
     *
     * @return array
     */
    protected function getPrimaryAuthors()
    {
        $fieldSpecs = [
            '100' => ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1],
            '700' => [
                'a' => 1, 'q' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1
            ]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            $this->primaryAuthorRelators,
            ['100']
        );
    }

    /**
     * Get primary authors for faceting
     *
     * @return array
     */
    protected function getPrimaryAuthorsFacet()
    {
        $fieldSpecs = [
            '100' => ['a' => 1, 'b' => 1, 'c' => 1],
            '700' => [
                'a' => 1, 'q' => 1, 'b' => 1, 'c' => 1
            ]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            $this->primaryAuthorRelators,
            ['100'],
            false
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
            '100' => ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1],
            '700' => [
                'a' => 1, 'q' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1
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
     * Get secondary authors for faceting
     *
     * @return array
     */
    protected function getSecondaryAuthorsFacet()
    {
        $fieldSpecs = [
            '100' => ['a' => 1, 'b' => 1, 'c' => 1],
            '700' => [
                'a' => 1, 'q' => 1, 'b' => 1, 'c' => 1
            ]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            $this->primaryAuthorRelators,
            ['700'],
            false,
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
            '110' => ['a' => 1, 'b' => 1, 'e' => 1],
            '111' => ['a' => 1, 'b' => 1, 'e' => 1],
            '710' => ['a' => 1, 'b' => 1, 'e' => 1],
            '711' => ['a' => 1, 'b' => 1, 'e' => 1]
        ];
        return $this->getAuthorsByRelator(
            $fieldSpecs,
            [],
            ['110', '111', '710', '711'],
            false
        );
    }

    /**
     * Get corporate authors for faceting
     *
     * @return array
     */
    protected function getCorporateAuthorsFacet()
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
     * Dedup: Return unique IDs (control numbers)
     *
     * @return array
     */
    public function getUniqueIDs()
    {
        if (isset($this->resultCache[__METHOD__])) {
            return $this->resultCache[__METHOD__];
        }
        $result = parent::getUniqueIDs();
        // Melinda ID
        foreach ($this->getFields('035') as $field) {
            $id = $this->getSubfield($field, 'a');
            if (strncmp('FCC', $id, 3) === 0) {
                $idNumber = substr($id, 3);
                if (ctype_digit($idNumber)) {
                    $result[] = "(FI-MELINDA)$idNumber";
                    break;
                }
            }
        }
        $this->resultCache[__METHOD__] = $result;
        return $result;
    }

    /**
     * Get locations for available items (from Koha 952 fields)
     *
     * @return array
     */
    protected function getAvailableItemsBuildings()
    {
        $building = [];
        if ($this->getDriverParam('holdingsInBuilding', true)) {
            foreach ($this->getFields('952') as $field) {
                $available = $this->getSubfield($field, '9');
                if (!$available) {
                    continue;
                }
                $location = $this->getSubfield($field, 'b');
                if ($location) {
                    $building[] = $location;
                }
            }
        }
        return $building;
    }

    /**
     * Get original languages in normalized form
     *
     * @return array
     */
    protected function getOriginalLanguages()
    {
        // 041h - Language code of original
        $languages = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '041', ['h' => 1]],
                // 979i = component part original language
                [self::GET_NORMAL, '979', ['i' => 1]]
            ],
            false,
            true,
            true
        );
        // If not a translation, take also language from 041a and 041d.
        foreach ($this->getFields('041') as $f041) {
            if ($this->getIndicator($f041, 1) === '0') {
                foreach ($this->getSubfieldsArray($f041, ['a' => 1, 'd' => 1]) as $s
                ) {
                    $languages[] = $s;
                }
            }
        }
        return $this->metadataUtils->normalizeLanguageStrings($languages);
    }

    /**
     * Get subtitle languages in normalized form
     *
     * @return array
     */
    protected function getSubtitleLanguages()
    {
        $languages = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '041', ['j' => 1]],
                // 979j = component part subtitle language
                [self::GET_NORMAL, '979', ['j' => 1]]
            ],
            false,
            true,
            true
        );
        return $this->metadataUtils->normalizeLanguageStrings($languages);
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
                [self::GET_BOTH, '830', ['a' => 1, 'v' => 1, 'n' => 1, 'p' => 1]]
            ]
        );
    }
}
