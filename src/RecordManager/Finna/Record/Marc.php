<?php
/**
 * Marc record class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2012-2019.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Finna\Record;

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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Marc extends \RecordManager\Base\Record\Marc
{
    /**
     * Strings in field 300 that signify that the work is illustrated.
     *
     * @var string
     */
    protected $illustrationStrings = [
        'ill.', 'illus.', 'kuv.', 'kuvitettu', 'illustrated'
    ];

    /**
     * Extra data to be included in allfields e.g. from component parts
     *
     * @var array
     */
    protected $extraAllFields = [];

    /**
     * Set record data
     *
     * @param string $source Source ID
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for
     * file import)
     * @param string $data   Metadata
     *
     * @return void
     */
    public function setData($source, $oaiID, $data)
    {
        $this->extraAllFields = [];
        parent::setData($source, $oaiID, $data);
    }

    /**
     * Normalize the record (optional)
     *
     * @return void
     */
    public function normalize()
    {
        // Kyyti enumeration from 362 to title
        if ($this->source == 'kyyti' && isset($this->fields['245'])
            && isset($this->fields['362'])
        ) {
            $enum = $this->getFieldSubfields('362', ['a' => 1]);
            if ($enum) {
                $this->fields['245'][0]['s'][] = ['n' => $enum];
            }
        }

        // Koha record normalization
        if ($this->getDriverParam('kohaNormalization', false)) {
            // Convert items to holdings
            $useHome = $this->getDriverParam('kohaUseHomeBranch', false);
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
            // Verify that 001 exists
            if ('' === $this->getField('001')) {
                if ($id = $this->getFieldSubfields('999', ['c' => 1])) {
                    $this->fields['001'] = [$id];
                }
            }
        }
    }

    /**
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return array
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();

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
                        ? MetadataUtils::normalizeRelator($role) : '';
                }
            }
        }

        if (isset($data['publishDate'])) {
            $data['main_date_str']
                = MetadataUtils::extractYear($data['publishDate'][0]);
            $data['main_date']
                = $this->validateDate($data['main_date_str'] . '-01-01T00:00:00Z');
        }
        if ($range = $this->getPublicationDateRange()) {
            $data['search_daterange_mv'][] = $data['publication_daterange']
                = MetadataUtils::dateRangeToStr($range);
        }
        $data['publication_place_txt_mv'] = MetadataUtils::arrayTrim(
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
                        = MetadataUtils::stripTrailingPunctuation(
                            $this->getSubfield($field, 'a')
                        );
                }
            }
        }

        $data['subtitle_lng_str_mv'] = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '041', ['j' => 1]],
                // 979j = component part subtitle language
                [self::GET_NORMAL, '979', ['j' => 1]]
            ],
            false, true, true
        );
        $data['subtitle_lng_str_mv'] = MetadataUtils::normalizeLanguageStrings(
            $data['subtitle_lng_str_mv']
        );

        $data['original_lng_str_mv'] = $this->getFieldsSubfields(
            [
                [self::GET_NORMAL, '041', ['h' => 1]],
                // 979i = component part original language
                [self::GET_NORMAL, '979', ['i' => 1]]
            ],
            false, true, true
        );
        $data['original_lng_str_mv'] = MetadataUtils::normalizeLanguageStrings(
            $data['original_lng_str_mv']
        );

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
            false, true, true
        ) as $field) {
            $field = trim($field);
            if ($field) {
                $data['author2'][] = $field;
                $data['author2_role'][] = '-';
            }
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
                            'a' => 1, 'd' => 1, 'f' => 1, 'g' => 1, 'k' => 1,
                            'l' => 1, 'n' => 1, 'p' => 1, 'r' => 1, 's' => 1
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

        // Location coordinates
        $field = $this->getField('034');
        if ($field) {
            $westOrig = $this->getSubfield($field, 'd');
            $eastOrig = $this->getSubfield($field, 'e');
            $northOrig = $this->getSubfield($field, 'f');
            $southOrig = $this->getSubfield($field, 'g');
            $west = MetadataUtils::coordinateToDecimal($westOrig);
            $east = MetadataUtils::coordinateToDecimal($eastOrig);
            $north = MetadataUtils::coordinateToDecimal($northOrig);
            $south = MetadataUtils::coordinateToDecimal($southOrig);

            if (!is_nan($west) && !is_nan($north)) {
                if (($west < -180 || $west > 180) || ($north < -90 || $north > 90)) {
                    $this->logger->log(
                        'Marc',
                        "Discarding invalid coordinates $west,$north decoded from "
                        . "w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, "
                        . "record {$this->source}." . $this->getID(),
                        Logger::DEBUG
                    );
                    $this->storeWarning('invalid coordinates in 034');
                } else {
                    if (!is_nan($east) && !is_nan($south)) {
                        if ($east < -180 || $east > 180 || $south < -90
                            || $south > 90
                        ) {
                            $this->logger->log(
                                'Marc',
                                "Discarding invalid coordinates $east,$south "
                                . "decoded from w=$westOrig, e=$eastOrig, "
                                . "n=$northOrig, s=$southOrig, record "
                                . "{$this->source}." . $this->getID(),
                                Logger::DEBUG
                            );
                            $this->storeWarning('invalid coordinates in 034');
                        } else {
                            // Try to cope with weird coordinate order
                            if ($north > $south) {
                                list($north, $south) = [$south, $north];
                            }
                            if ($west > $east) {
                                list($west, $east) = [$east, $west];
                            }
                            $data['location_geo']
                                = "ENVELOPE($west, $east, $south, $north)";
                        }
                    } else {
                        $data['location_geo'] = "POINT($west $north)";
                    }
                }
            }
        }
        if (!empty($data['location_geo'])) {
            $data['center_coords']
                = MetadataUtils::getCenterCoordinates($data['location_geo']);
        }

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

                list($mainClass) = explode('.', $classification, 2);
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
        if ($subBuilding) {
            if ('1' === $subBuilding) { // true
                $subBuilding = 'c';
            }
            foreach ($this->getFields('852') as $field) {
                $location = $this->getSubfield($field, $subBuilding);
                if ('' !== $location) {
                    $data['building_sub_str_mv'][] = $location;
                }
            }
            foreach ($this->getFields('952') as $field) {
                $location = $this->getSubfield($field, $subBuilding);
                if ('' !== $location) {
                    $data['building_sub_str_mv'][] = $location;
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
                    continue;
                }
                $data['ismn_isn_mv'][] = $matches[1];
                break;
            case '3':
                $ean = $this->getSubfield($field024, 'a');
                $ean = str_replace('-', '', $ean);
                if (!preg_match('{([0-9]{13})}', $ean, $matches)) {
                    continue;
                }
                $data['ean_isn_mv'][] = $matches[1];
                break;
            }
        }

        // Identifiers from component parts (type as a leading string)
        foreach ($this->getFieldsSubfields(
            [[self::GET_NORMAL, '979', ['k' => 1]]], false, true, true
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
                $access = MetadataUtils::normalizeKey(
                    $this->getFieldSubfields('506', ['f' => 1]), 'NFKC'
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
                    [self::GET_NORMAL, '050', ['a' => 1, 'b' => 1]]
                ]
            )
        );
        $data['callnumber-sort'] = empty($data['callnumber-raw'])
            ? '' : $data['callnumber-raw'][0];

        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
        }

        if (!empty($data['online_str_mv'])) {
            $access = MetadataUtils::normalizeKey(
                $this->getFieldSubfields('506', ['f' => 1]), 'NFKC'
            );
            if ($access !== 'onlineaccesswithauthorization') {
                $data['free_online_str_mv'] = $data['online_str_mv'];
                $data['free_online_boolean'] = true;
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

        $data['format_ext_str_mv'] = $data['format'];

        $availableBuildings = $this->getAvailableItemsBuildings();
        if ($availableBuildings) {
            $data['building_available_str_mv'] = $availableBuildings;
            $data['source_available_str_mv'] = $this->source;
        }

        return $data;
    }

    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     * @param MongoDate|null  $changeDate     Latest timestamp for the component part
     * set
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
            $data = MetadataUtils::getRecordData($componentPart, true);
            $marc = new Marc(
                $this->logger, $this->config, $this->dataSourceSettings
            );
            $marc->setData($this->source, '', $data);
            $title = $marc->getFieldSubfields(
                '245', ['a' => 1, 'b' => 1, 'n' => 1, 'p' => 1]
            );
            $uniTitle
                = $marc->getFieldSubfields('240', ['a' => 1, 'n' => 1, 'p' => 1]);
            if (!$uniTitle) {
                $uniTitle = $marc->getFieldSubfields(
                    '130', ['a' => 1, 'n' => 1, 'p' => 1]
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
            $duration = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '306', ['a' => 1]]
                ]
            );
            $languages = [substr($marc->getField('008'), 35, 3)];
            $languages = array_unique(
                array_merge(
                    $languages, $marc->getFieldsSubfields(
                        [
                            [self::GET_NORMAL, '041', ['a' => 1]],
                            [self::GET_NORMAL, '041', ['d' => 1]]
                        ],
                        false, true, true
                    )
                )
            );
            $languages = MetadataUtils::normalizeLanguageStrings($languages);
            $originalLanguages = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '041', ['h' => 1]]
                ],
                false, true, true
            );
            $originalLanguages
                = MetadataUtils::normalizeLanguageStrings($originalLanguages);
            $subtitleLanguages = $marc->getFieldsSubfields(
                [
                    [self::GET_NORMAL, '041', ['j' => 1]]
                ],
                false, true, true
            );
            $subtitleLanguages
                = MetadataUtils::normalizeLanguageStrings($subtitleLanguages);
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
                        continue;
                    }
                    $identifiers[] = 'ISMN ' . $matches[1];
                    break;
                case '3':
                    $ean = $marc->getSubfield($field024, 'a');
                    $ean = str_replace('-', '', $ean);
                    if (!preg_match('{([0-9]{13})}', $ean, $matches)) {
                        continue;
                    }
                    $identifiers[] = 'EAN ' . $matches[1];
                    break;
                }
            }

            foreach ($marc->getFields('031') as $field031) {
                foreach ($marc->getSubfieldsArray($field031, ['t' => 1]) as $lyrics
                ) {
                    $this->extraAllFields[] = $lyrics;
                }
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

            $key = MetadataUtils::createIdSortKey($id);
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
        // Custom predefined type in 977a
        $field977a = $this->getFieldSubfields('977', ['a' => 1]);
        if ($field977a) {
            return $field977a;
        }

        // Dissertations and Thesis
        if (isset($this->fields['502'])) {
            return 'Dissertation';
        }
        if (isset($this->fields['509'])) {
            $field509a = MetadataUtils::stripTrailingPunctuation(
                $this->getFieldSubfields('509', ['a' => 1])
            );
            switch (strtolower($field509a)) {
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
            return 'Thesis';
        }
        $format = parent::getFormat();

        // Separate non-musical sound from other sound types. This is not quite
        // perfect since there's already e.g. MusicRecording, but we need to keep
        // e.g. CD intact for backwards-compatibility.
        if (in_array($format, ['CD', 'SoundCassette', 'SoundDisc', 'SoundRecording'])
        ) {
            $leader = $this->getField('000');
            $type = substr($leader, 6, 1);
            if ($type == 'i') {
                switch ($format) {
                case 'CD':
                    $format = 'NonmusicalCD';
                    break;
                case 'SoundCassette':
                    $format = 'NonmusicalCassette';
                    break;
                case 'SoundDisc':
                    $format = 'NonmusicalDisc';
                    break;
                case 'SoundRecording':
                    $format = 'NonmusicalRecording';
                    break;
                }
            } elseif ($type == 'j' && $format == 'SoundRecording') {
                $format = 'MusicRecording';
            }
        }
        return $format;
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
            $sub3 = MetadataUtils::stripTrailingPunctuation(
                $this->getSubfield($field, '3')
            );
            if ($sub3 == 'Metadata' || strncasecmp($sub3, 'metadata', 8) == 0) {
                $subA = MetadataUtils::stripTrailingPunctuation(
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
        if ($this->getDriverParam('kohaNormalization', false)) {
            foreach ($this->getFields('942') as $field942) {
                $suppressed = $this->getSubfield($field942, 'n');
                return (bool)$suppressed;
            }
        }
        return false;
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
            $sub3 = MetadataUtils::stripTrailingPunctuation(
                $this->getSubfield($field, '3')
            );
            if ($sub3 == 'Metadata' || strncasecmp($sub3, 'metadata', 8) == 0) {
                continue;
            }
            $subC = MetadataUtils::stripTrailingPunctuation(
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
                $startDate = "$year1-01-01T00:00:00Z";
                $endDate = "$year2-12-31T23:59:59Z";
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
            || MetadataUtils::validateISO8601Date($startDate) === false
            || MetadataUtils::validateISO8601Date($endDate) === false
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
            || MetadataUtils::validateISO8601Date($startDate) === false
            || MetadataUtils::validateISO8601Date($endDate) === false
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
            && MetadataUtils::validateISO8601Date($startDate) !== false
            && MetadataUtils::validateISO8601Date($endDate) !== false
        ) {
            if ($endDate < $startDate) {
                $this->logger->log(
                    'Marc',
                    "Invalid date range {$startDate} - {$endDate}, record "
                    . "{$this->source}." . $this->getID(),
                    Logger::DEBUG
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
            '100' => ['4' => 1],
            '700' => ['4' => 1],
            '710' => ['4' => 1],
            '711' => ['4' => 1],
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
                $isbn = MetadataUtils::normalizeISBN($isbn);
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
                        '952', '979'
                    ]
                )
            ) {
                foreach ($fields as $field) {
                    $subfields = $this->getAllSubfields(
                        $field,
                        isset($subfieldFilter[$tag]) ? $subfieldFilter[$tag]
                        : ['0' => 1, '6' => 1, '8' => 1]
                    );
                    if ($subfields) {
                        $allFields = array_merge($allFields, $subfields);
                    }
                }
            }
        }
        if ($this->extraAllFields) {
            $allFields = array_merge($allFields, $this->extraAllFields);
        }
        $allFields = array_map(
            function ($str) {
                return MetadataUtils::stripLeadingPunctuation(
                    MetadataUtils::stripTrailingPunctuation($str)
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
        $building = [];
        if ($this->getDriverParam('holdingsInBuilding', true)) {
            $useSub = $this->getDriverParam('subLocationInBuilding', '');
            $itemSub = $this->getDriverParam('itemSubLocationInBuilding', $useSub);
            foreach ($this->getFields('852') as $field) {
                $location = $this->getSubfield($field, 'b');
                if ($location) {
                    if ($useSub && $sub = $this->getSubfield($field, $useSub)) {
                        $location = [$location, $sub];
                    }
                    $building[] = $location;
                }
            }
            foreach ($this->getFields('952') as $field) {
                $location = $this->getSubfield($field, 'b');
                if ($location) {
                    if ($itemSub && $sub = $this->getSubfield($field, $itemSub)) {
                        $location = [$location, $sub];
                    }
                    $building[] = $location;
                }
            }
        }

        // Ebrary location
        $ebraryLocs = $this->getFieldsSubfields(
            [[self::GET_NORMAL, '035', ['a' => 1]]]
        );
        foreach ($ebraryLocs as $field) {
            if (strncmp($field, 'ebr', 3) == 0 && is_numeric(substr($field, 3))) {
                if (!isset($data['building'])
                    || !in_array('EbraryDynamic', $data['building'])
                ) {
                    $building[] = 'EbraryDynamic';
                }
            }
        }

        return $building;
    }

    /**
     * Get era facet fields
     *
     * @return array Topics
     */
    protected function getEraFacets()
    {
        $result = parent::getEraFacets();
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd('4')
            )
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
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd('4')
            )
        );
        return $result;
    }

    /**
     * Get genre facet fields
     *
     * @return array Topics
     */
    protected function getGenreFacets()
    {
        $result = parent::getGenreFacets();
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd('6')
            )
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
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd('6')
            )
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
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd('5')
            )
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
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd('5')
            )
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
            false, true, true
        );
        $result = array_unique(
            array_merge(
                $result,
                $this->get653WithSecondInd([' ', '0', '1', '2', '3'])
            )
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
        $result = array_unique(
            array_merge(
                parent::getTopics(),
                $this->get653WithSecondInd([' ', '0', '1', '2', '3'])
            )
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
            false, true, true
        );
        $result = array_merge($languages, $languages2);
        return MetadataUtils::normalizeLanguageStrings($result);
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
            $fieldSpecs, $this->primaryAuthorRelators, ['100']
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
            $fieldSpecs, $this->primaryAuthorRelators, ['100'], false
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
            $fieldSpecs, $this->secondaryAuthorRelators, ['700']
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
            $fieldSpecs, $this->secondaryAuthorRelators, ['700'], false
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
            array_merge(
                $this->primaryAuthorRelators, $this->secondaryAuthorRelators
            ),
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
            array_merge(
                $this->primaryAuthorRelators, $this->secondaryAuthorRelators
            ),
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
        $result = parent::getUniqueIDs();
        // Melinda ID
        $f035 = $this->getField('035');
        if ($f035) {
            $id = $this->getSubfield($f035, 'a');
            if (strncmp($id, 'FCC', 3) === 0 && ctype_digit(substr($id, 3))) {
                $result[] = $id;
            }
        }
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
     * Get key data that can be used to identify expressions of a work
     *
     * @return array Associative array of authors and titles
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
            '130' => ['n' => 1],
            '240' => ['n' => 1],
            '245' => ['b' => 1, 'n' => 1],
            '246' => ['b' => 1, 'n' => 1],
            '247' => ['b' => 1, 'n' => 1],
        ];

        $authors = [];
        $authorsAltScript = [];
        $titles = [];
        $titlesAltScript = [];

        foreach ($authorFields as $tag => $subfields) {
            $auths = $this->getFieldsSubfields(
                [[self::GET_BOTH, $tag, $subfields]],
                true,
                false
            );
            if (isset($auths[1])) {
                $authorsAltScript[] = [
                    'type' => 'author',
                    'value' => $auths[1]
                ];
            }
            if (isset($auths[0])) {
                $authors[] = [
                    'type' => 'author',
                    'value' => $auths[0]
                ];
                break;
            }
        }

        foreach ($titleFields as $tag => $subfields) {
            $field = $this->getField($tag);
            $title = '';
            $altTitles = [];
            $ind = '130' == $tag ? 1 : 2;
            if ($field && !empty($field['s'])) {
                $title = $this->getSubfield($field, 'a');
                $nonfiling = $this->getIndicator($field, $ind);
                if ($nonfiling > 0) {
                    $title = substr($title, $nonfiling);
                }
                $rest = $this->getSubfields($field, $subfields);
                if ($rest) {
                    $title .= " $rest";
                }
                $sub6 = $this->getSubfield($field, '6');
                if ($sub6) {
                    $sub6 = "$tag-" . substr($sub6, 4, 2);
                    foreach ($this->getFields('880') as $f880) {
                        if (strncmp($this->getSubfield($f880, '6'), $sub6, 6) != 0) {
                            continue;
                        }
                        $altTitle = $this->getSubfield($f880, 'a');
                        $nonfiling = $this->getIndicator($f880, $ind);
                        if ($nonfiling > 0) {
                            $altTitle = substr($altTitle, $nonfiling);
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
            }
            $titleType = '130' == $tag ? 'uniform' : 'title';
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

        return compact('authors', 'authorsAltScript', 'titles', 'titlesAltScript');
    }
}
