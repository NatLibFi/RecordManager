<?php
/**
 * MarcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2013
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */

require_once 'BaseRecord.php';
require_once 'MetadataUtils.php';
require_once 'Logger.php';

/**
 * MarcRecord Class
 *
 * This is a class for processing MARC records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MarcRecord extends BaseRecord
{
    const SUBFIELD_INDICATOR = "\x1F";
    const END_OF_FIELD = "\x1E";
    const END_OF_RECORD = "\x1D";
    const LEADER_LEN = 24;

    const GET_NORMAL = 0;
    const GET_ALT = 1;
    const GET_BOTH = 2;
    
    /** MARC is stored in a multidimensional array:
     *  [001] - "12345"
     *  [245] - i1: '0'
     *          i2: '1'
     *          s:  [{a => "Title"},
     *               {p => "Part"}
     *              ]
     */
    protected $fields;
    protected $idPrefix = '';
    protected $source;

    /**
     * Constructor
     *
     * @param string $data   Metadata
     * @param string $oaiID  Record ID received from OAI-PMH (or empty string for file import)
     * @param string $source Source ID
     */
    public function __construct($data, $oaiID, $source)
    {
        parent::__construct($data, $oaiID, $source);

        $firstChar = substr($data, 0, 1);
        if ($firstChar === '{') {
            $fields = json_decode($data, true);
            if (!isset($fields['v'])) {
                // Old format, convert...
                $this->fields = array();
                foreach ($fields as $tag => $field) {
                    foreach ($field as $data) {
                        if (strstr($data, MarcRecord::SUBFIELD_INDICATOR)) {
                            $newField = array(
                                'i1' => $data[0],
                                'i2' => $data[1]
                            );
                            foreach (explode(MarcRecord::SUBFIELD_INDICATOR, substr($data, 3)) as $subfield) {
                                $newField['s'][] = array($subfield[0] => substr($subfield, 1));
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
                    $this->fields = array();
                    foreach ($fields['f'] as $code => $codeFields) {
                        if (!is_array($codeFields)) {
                            // 000
                            $this->fields[$code] = $codeFields;
                            continue;
                        }
                        foreach ($codeFields as $field) {
                            if (is_array($field)) {
                                $newField = array(
                                    'i1' => $field['i1'],
                                    'i2' => $field['i2'],
                                    's' => array()
                                );
                                if (isset($field['s'])) {
                                    foreach ($field['s'] as $subfield) {
                                        $newField['s'][] = array($subfield['c'] => $subfield['v']);
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
        return json_encode(array('v' => 3, 'f' => $this->fields));
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     */
    public function toXML()
    {
        $xml = simplexml_load_string(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n<collection><record></record></collection>"
        );
        $record = $xml->record[0];

        if (isset($this->fields['000'])) {
            // Voyager is often missing the last '0' of the leader...
            $leader = str_pad(substr($this->fields['000'], 0, 24), 24);
            $record->addChild('leader', $leader);
        }
          
        foreach ($this->fields as $tag => $fields) {
            if ($tag == '000') {
                continue;
            }
            foreach ($fields as $data) {
                if (!is_array($data)) {
                    $field = $record->addChild('controlfield', htmlspecialchars($data, ENT_NOQUOTES));
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
     * Return fields to be indexed in Solr (an alternative to an XSL transformation)
     *
     * @return string[]
     */
    public function toSolrArray()
    {
        $data = parent::toSolrArray();
          
        // building
        $data['building'] = array();
        foreach ($this->getFields('852') as $field) {
            $location = $this->getSubfield($field, 'b');
            if ($location) {
                $data['building'][] = $location;
            }
        }
          
        // long_lat
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
                if (!is_nan($east)) {
                    $longitude = ($west + $east) / 2;
                } else {
                    $longitude = $west;
                }
                    
                if (!is_nan($south)) {
                    $latitude = ($north + $south) / 2;
                } else {
                    $latitude = $north;
                }
                if (($longitude < -180 || $longitude > 180) || ($latitude < -90 || $latitude > 90)) {
                    global $logger;
                    $logger->log('MarcRecord', "Discarding invalid coordinates $longitude,$latitude decoded from w=$westOrig, e=$eastOrig, n=$northOrig, s=$southOrig, record {$this->source}." . $this->getID(), Logger::WARNING);
                } else {
                    $data['long_lat'] = "$longitude,$latitude";
                }
            }
        }

        // lccn
        $data['lccn'] = $this->getFieldSubfields('010', array('a'));
        $data['ctrlnum'] = $this->getFieldsSubfields(array(array(MarcRecord::GET_NORMAL, '035', array('a'))));
        $data['fullrecord'] = $this->toISO2709();
        if (!$data['fullrecord']) {
            // In case the record exceeds 99999 bytes...
            $data['fullrecord'] = $this->toXML();
        }
        
        // allfields
        $allFields = array();
        $subfieldFilter = array(
            '650' => array('2', '6', '8'), 
            '773' => array('6', '7', '8', 'w'), 
            '856' => array('6', '8', 'q'), 
            '979' => array('a')
        );
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 841) || $tag == 856 || $tag == 979) {
                foreach ($fields as $field) {
                    $allFields[] = MetadataUtils::stripLeadingPunctuation(
                        MetadataUtils::stripTrailingPunctuation(
                            $this->getAllSubfields(
                                $field,
                                isset($subfieldFilter[$tag]) ? $subfieldFilter[$tag] : array('6', '8')
                            )
                        )
                    );
                }
            }
        }
        $data['allfields'] = array_values(array_unique($allFields));
          
        // language
        $languages = array(substr($this->getField('008'), 35, 3));
        $languages += $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '041', array('a')),
                array(MarcRecord::GET_NORMAL, '041', array('d')),
                array(MarcRecord::GET_NORMAL, '041', array('h')),
                array(MarcRecord::GET_NORMAL, '041', array('j'))
            ), 
            false, true, true
        );
            
        foreach ($languages as $language) {
            if (preg_match('/^\w{3}$/', $language) && $language != 'zxx' && $language != 'und') {
                $data['language'][] = $language;
            }
        }
          
        $data['format'] = $this->getFormat();
        
        $data['author'] = $this->getFieldSubfields('100', array('a', 'b', 'c', 'd', 'e'));
        $data['author_fuller'] = $this->getFieldSubfields('100', array('q'));
        $data['author-letter'] = $this->getFieldSubfields('100', array('a'));

        $data['author2'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_ALT, '100', array('a', 'b', 'c', 'd')),
                array(MarcRecord::GET_BOTH, '110', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '111', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '700', array('a', 'b', 'c', 'd', 'e')),
                array(MarcRecord::GET_BOTH, '710', array('a', 'b')),
                array(MarcRecord::GET_BOTH, '711', array('a', 'b'))
            )
        );
        // 979cd = component part authors
        foreach ($this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '979', array('c')),
                array(MarcRecord::GET_BOTH, '979', array('d'))
            ),
            false, true, true
        ) as $field) {
            $data['author2'][] = $field;
        }
        $data['author2'] = array_values(array_unique($data['author2']));
        
        $key = array_search($data['author'], $data['author2']);
        if ($key !== false) {
            unset($data['author2'][$key]);
        }
        $data['author2'] = array_filter(array_values($data['author2']));
        $data['author2-role'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '700', array('e')),
                array(MarcRecord::GET_BOTH, '710', array('e'))
            ),
            true
        );
        $data['author_additional'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '505', array('r'))
            ),
            true
        );
          
        $data['title'] = $data['title_auth'] = $this->getTitle();
        $data['title_sub'] = $this->getFieldSubfields('245', array('b', 'n', 'p'));
        $data['title_short'] = $this->getFieldSubfields('245', array('a'));
        $data['title_full'] = $this->getFieldSubfields('245', array('a', 'b', 'c', 'f', 'g', 'h', 'k', 'n', 'p', 's'));
        $data['title_alt'] = array_values(
            array_unique(
                $this->getFieldsSubfields(
                    array(
                        array(MarcRecord::GET_ALT, '245', array('a', 'b')),
                        array(MarcRecord::GET_BOTH, '130', array('a', 'd', 'f', 'g', 'k', 'l', 'n', 'p', 's', 't')),
                        array(MarcRecord::GET_BOTH, '240', array('a')),
                        array(MarcRecord::GET_BOTH, '246', array('g')),
                        array(MarcRecord::GET_BOTH, '730', array('a', 'd', 'f', 'g', 'k', 'l', 'n', 'p', 's', 't')),
                        array(MarcRecord::GET_BOTH, '740', array('a')),
                        array(MarcRecord::GET_BOTH, '979', array('b')),
                        array(MarcRecord::GET_BOTH, '979', array('e')),
                    )
                )
            )
        ); // 979b and e = component part title and uniform title
        $data['title_old'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '780', array('a', 's', 't'))
            )
        );
        $data['title_new'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '785', array('a', 's', 't'))
            )
        );
        $data['title_sort'] = $this->getTitle(true);
        
        if (!$data['title_short']) {
            $data['title_short'] = $this->getFieldSubfields('240', array('a', 'n', 'p'));
            $data['title_full'] = $this->getFieldSubfields('240');
        }
        
        $data['series'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '440', array('a')),
                array(MarcRecord::GET_BOTH, '490', array('a')),
                array(MarcRecord::GET_BOTH, '800', array('a', 'b', 'c', 'd', 'f', 'p', 'q', 't')),
                array(MarcRecord::GET_BOTH, '830', array('a', 'p'))
            )
        );
          
        $data['publisher'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '260', array('b'))
            ), 
            false, true
        );
        if (!$data['publisher']) {
            $fields = $this->getFields('264');
            foreach ($fields as $field) {
                if ($this->getIndicator($field, 2) == '1') {
                    $data['publisher'] = metadataUtils::stripTrailingPunctuation($this->getSubfield($field, 'b'));
                    break;
                }
            }
        }
        $data['publishDateSort'] = $this->getPublicationYear();
        $data['publishDate'] = array($data['publishDateSort']);
        $data['physical'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '300', array('a', 'b', 'c', 'e', 'f', 'g')),
                array(MarcRecord::GET_BOTH, '530', array('a', 'b', 'c', 'd'))
            )
        );
        $data['dateSpan'] = $this->getFieldsSubfields(array(array(MarcRecord::GET_BOTH, '362', array('a'))));
        $data['edition'] = $this->getFieldSubfields('250', array('a'));
        $data['contents'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '505', array('a')),
                array(MarcRecord::GET_BOTH, '505', array('t'))
            )
        );

        $data['isbn'] = $this->getISBNs();
        foreach ($this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '773', array('z'))
            )
        ) as $isbn) {
            $isbn = str_replace('-', '', $isbn);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
                continue;
            };
            $isbn = $matches[1];
            if (strlen($isbn) == 10) {
                $isbn = MetadataUtils::isbn10to13($isbn);
            }
            if ($isbn) {
                $data['isbn'][] = $isbn;
            }
        }
        $data['issn'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '022', array('a')),
                array(MarcRecord::GET_NORMAL, '440', array('x')),
                array(MarcRecord::GET_NORMAL, '490', array('x')),
                array(MarcRecord::GET_NORMAL, '730', array('x')),
                array(MarcRecord::GET_NORMAL, '773', array('x')),
                array(MarcRecord::GET_NORMAL, '776', array('x')),
                array(MarcRecord::GET_NORMAL, '780', array('x')),
                array(MarcRecord::GET_NORMAL, '785', array('x'))
            )
        );
        foreach ($data['issn'] as &$value) {
            $value = str_replace('-', '', $value);
        }

        $data['callnumber'] = strtoupper(
            str_replace(
                ' ', 
                '', 
                $this->getFirstFieldSubfields(
                    array(
                        array(MarcRecord::GET_NORMAL, '080', array('a', 'b')),
                        array(MarcRecord::GET_NORMAL, '084', array('a', 'b')),
                        array(MarcRecord::GET_NORMAL, '050', array('a', 'b'))
                    )
                )
            )
        );
        $data['callnumber-a'] = $this->getFirstFieldSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '080', array('a')),
                array(MarcRecord::GET_NORMAL, '084', array('a')),
                array(MarcRecord::GET_NORMAL, '050', array('a'))
            )
        );
        $data['callnumber-first-code'] = substr($data['callnumber-a'], 0, 1);

        $data['topic'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '600', array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '610', array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'u', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '611', array('a', 'c', 'd', 'e', 'f', 'g', 'h', 'j', 'k', 'l', 'n', 'p', 'q', 's', 't', 'u', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '630', array('a', 'd', 'e', 'f', 'g', 'h', 'k', 'l', 'm', 'n', 'o', 'p', 'r', 's', 't', 'v', 'x', 'y', 'z')),
                array(MarcRecord::GET_BOTH, '650', array('a', 'b', 'c', 'd', 'e', 'v', 'x', 'y', 'z'))
            )
        );
        $data['genre'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '655', array('a', 'b', 'c', 'v', 'x', 'y', 'z'))
            )
        );
        $data['geographic'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '651', array('a', 'e', 'v', 'x', 'y', 'z'))
            )
        );
        $data['era'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_BOTH, '648', array('a', 'v', 'x', 'y', 'z'))
            )
        );

        $data['topic_facet'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '600', array('x')),
                array(MarcRecord::GET_NORMAL, '610', array('x')),
                array(MarcRecord::GET_NORMAL, '611', array('x')),
                array(MarcRecord::GET_NORMAL, '630', array('x')),
                array(MarcRecord::GET_NORMAL, '648', array('x')),
                array(MarcRecord::GET_NORMAL, '650', array('a')),
                array(MarcRecord::GET_NORMAL, '650', array('x')),
                array(MarcRecord::GET_NORMAL, '651', array('x')),
                array(MarcRecord::GET_NORMAL, '655', array('x'))
            ),
            false, true, true
        );
        $data['genre_facet'] = MetadataUtils::ucFirst(
            $this->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '600', array('v')),
                    array(MarcRecord::GET_NORMAL, '610', array('v')),
                    array(MarcRecord::GET_NORMAL, '611', array('v')),
                    array(MarcRecord::GET_NORMAL, '630', array('v')),
                    array(MarcRecord::GET_NORMAL, '648', array('v')),
                    array(MarcRecord::GET_NORMAL, '650', array('v')),
                    array(MarcRecord::GET_NORMAL, '651', array('v')),
                    array(MarcRecord::GET_NORMAL, '655', array('a')),
                    array(MarcRecord::GET_NORMAL, '655', array('v'))
                ),
                false, true, true
            )
        );
        $data['geographic_facet'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '600', array('z')),
                array(MarcRecord::GET_NORMAL, '610', array('z')),
                array(MarcRecord::GET_NORMAL, '611', array('z')),
                array(MarcRecord::GET_NORMAL, '630', array('z')),
                array(MarcRecord::GET_NORMAL, '648', array('z')),
                array(MarcRecord::GET_NORMAL, '650', array('z')),
                array(MarcRecord::GET_NORMAL, '651', array('a')),
                array(MarcRecord::GET_NORMAL, '651', array('z')),
                array(MarcRecord::GET_NORMAL, '655', array('z'))
            ),
            false, true, true
        );
        $data['era_facet'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '630', array('y')),
                array(MarcRecord::GET_NORMAL, '648', array('a')),
                array(MarcRecord::GET_NORMAL, '648', array('y')),
                array(MarcRecord::GET_NORMAL, '650', array('y')),
                array(MarcRecord::GET_NORMAL, '651', array('y')),
                array(MarcRecord::GET_NORMAL, '655', array('y'))
            ),
            false, true, true
        );

        $data['url'] = $this->getFieldsSubfields(
            array(
                array(MarcRecord::GET_NORMAL, '856', array('u'))
            )
        );

        $data['illustrated'] = $this->getIllustrated();

        // TODO: dewey fields and OCLC numbers
          
        return $data;
    }

    /**
     * Merge component parts to this record
     *
     * @param MongoCollection $componentParts Component parts to be merged
     * 
     * @return void
     */
    public function mergeComponentParts($componentParts)
    {
        $count = 0;
        $parts = array();
        foreach ($componentParts as $componentPart) {
            $data = MetadataUtils::getRecordData($componentPart, true);
            $marc = new MARCRecord($data, '', $this->source);
            $title = $marc->getFieldSubfields('245', array('a', 'b', 'n', 'p'));
            $uniTitle = $marc->getFieldSubfields('240', array('a', 'n', 'p'));
            $additionalTitles = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '740', array('a'))
                )
            );
            $authors = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '100', array('a', 'e')),
                    array(MarcRecord::GET_NORMAL, '110', array('a', 'e'))
                )
            );
            $additionalAuthors = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '700', array('a', 'e')),
                    array(MarcRecord::GET_NORMAL, '710', array('a', 'e'))
                )
            );
            $duration = $marc->getFieldsSubfields(
                array(
                    array(MarcRecord::GET_NORMAL, '306', array('a'))
                )
            );
            $id = $componentPart['_id'];

            $newField = array(
                'i1' => ' ',
                'i2' => ' ',
                's' => array(
                    array('a' => $id)
                 )
            );
            if ($title) {
                $newField['s'][] = array('b' => $title);
            }
            if ($authors) {
                $newField['s'][] = array('c' => array_shift($authors));
                foreach ($authors as $author) {
                    $newField['s'][] = array('d' => $author);
                }
            }
            foreach ($additionalAuthors as $addAuthor) {
                $newField['s'][] = array('d' => $addAuthor);
            }
            if ($uniTitle) {
                $newField['s'][] = array('e' => $uniTitle);
            }
            if ($duration) {
                $newField['s'][] = array('f' => reset($duration));
            }
            foreach ($additionalTitles as $addTitle) {
                $newField['s'][] = array('g' => $addTitle);
            }
                        
            $key = MetadataUtils::createIdSortKey($id);
            $parts[$key] = $newField;
            ++$count;
        }
        ksort($parts);
        $this->fields['979'] = array_values($parts);
        return $count;
    }

    /**
     * Return record ID (local)
     *
     * @return string
     */
    public function getID()
    {
        return $this->getField('001');
    }

    /**
     * Return whether the record is a component part
     *
     * @return boolean
     */
    public function getIsComponentPart()
    {
        // We could look at the bibliographic level, but we need 773 to do anything useful anyway..
        return isset($this->fields['773']);
    }
    
    /**
     * Return host record ID for component part
     *
     * @return string
     * @access public
     */
    public function getHostRecordID()
    {
        $field = $this->getField('941');
        if ($field) {
            return $this->getSubfield($field, 'a');
        }
        $field = $this->getField('773');
        if (!$field) {
            return '';
        }
        return MetadataUtils::stripTrailingPunctuation($this->getSubfield($field, 'w'));
    }

    /**
    * Component parts: get the volume that contains this component part
    *
    * @return string
    */
    public function getVolume()
    {
        $field773g = $this->getFieldSubfields('773', array('g'));
        if (!$field773g) {
            return '';
        }
        
        // Try to parse the data from different versions of 773g
        $matches = array();
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
        $field773g = $this->getFieldSubfields('773', array('g'));
        if (!$field773g) {
            return '';
        }
        
        // Try to parse the data from different versions of 773g
        $matches = array();
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
        $field773g = $this->getFieldSubfields('773', array('g'));
        if (!$field773g) {
            return '';
        }
        
        // Try to parse the data from different versions of 773g
        $matches = array();
        if (preg_match('/,\s*\w\.?\s*([\d,\-]+)/', $field773g, $matches) || preg_match('/^\w\.?\s*([\d,\-]+)/', $field773g, $matches)) {
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
        return $this->getFieldSubfields('773', array('t'));
    }

    /**
     * Component parts: get the free-form reference to the part in the container
     *
     * @return string
     */
    public function getContainerReference()
    {
        return $this->getFieldSubfields('773', array('g'));
    }
    
    /**
     * Dedup: Return record title
     *
     * @param bool $forFiling Whether the title is to be used in filing 
     *                        (e.g. sorting, non-filing characters should be removed)
     *                        
     * @return string
     * @access public
     */
    public function getTitle($forFiling = false)
    {
        $punctuation = array('b' => ' : ', 'n' => '. ', 'p' => '. ');
        foreach (array('245', '240') as $fieldCode) {
            $field = $this->getField($fieldCode);
            if ($field) {
                $title = $this->getSubfield($field, 'a');
                if ($forFiling) {
                    $nonfiling = $this->getIndicator($field, 2);
                    if ($nonfiling > 0) {
                        $title = substr($title, $nonfiling);
                    }
                }
                foreach ($field['s'] as $subfield) {
                    if (!in_array(key($subfield), array('b', 'n', 'p'))) {
                        continue;
                    }
                    if (!MetadataUtils::hasTrailingPunctuation($title)) {
                        $title .= $punctuation[key($subfield)];
                    } else {
                        $title .= ' ';
                    }
                    $title .= current($subfield);
                }
                $title = MetadataUtils::stripTrailingPunctuation($title);
                if ($forFiling) {
                    $title = MetadataUtils::stripLeadingPunctuation($title);
                    $title = mb_strtolower($title, 'UTF-8');
                }
                if (!empty($title)) {
                    return $title;
                }
            }
        }
        return '';
    }

    /**
     * Dedup: Return main author (format: Last, First)
     *
     * @return string
     * @access public
     */
    public function getMainAuthor()
    {
        $f100 = $this->getField('100');
        if ($f100) {
            $author = $this->getSubfield($f100, 'a');
            $order = $this->getIndicator($f100, 1);
            if ($order == 0 && strpos($author, ',') === false) {
                $p = strrpos($author, ' ');
                if ($p > 0) {
                    $author = substr($author, $p + 1) . ', ' . substr($author, 0, $p);
                }
            }
            return MetadataUtils::stripTrailingPunctuation($author);
        }
        /* Not a good idea?
         $f110 = $this->getField('110');
        if ($f110)
        {
        $author = $this->getSubfield($f110, 'a');
        return $author;
        }
        */
        return '';
    }

    /**
     * Dedup: Return full title (for debugging purposes only)
     *
     * @return string
     * @access public
     */
    public function getFullTitle()
    {
        return $this->getFieldSubfields('245');
    }

    /**
     * Dedup: Return unique IDs (control numbers)
     *
     * @return string[]
     */
    public function getUniqueIDs()
    {
        $arr = array();
        $nbn = $this->getField('015');
        if ($nbn) {
            $nr = MetadataUtils::normalize(strtok(MetadataUtils:: $this->getSubfield($nbn, 'a'), ' '));
            $src = $this->getSubfield($nbn, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        $nba = $this->getField('016');
        if ($nba) {
            $nr = MetadataUtils::normalize(strtok($this->getSubfield($nba, 'a'), ' '));
            $src = $this->getSubfield($nba, '2');
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        $id = $this->getField('024');
        if ($id) {
            $nr = MetadataUtils::normalize(strtok($this->getSubfield($id, 'a'), ' '));
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
            if ($src && $nr) {
                $arr[] = "($src)$nr";
            }
        }
        return $arr;
    }
    
    /**
     * Dedup: Return (unique) ISBNs in ISBN-13 format without dashes
     *
     * @return string[]
     * @access public
     */
    public function getISBNs()
    {
        $arr = array();
        $fields = $this->getFields('020');
        foreach ($fields as $field) {
            $isbn = $this->getSubfield($field, 'a');
            $isbn = str_replace('-', '', $isbn);
            if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
                continue;
            };
            $isbn = $matches[1];
            if (strlen($isbn) == 10) {
                $isbn = MetadataUtils::isbn10to13($isbn);
            }
            if ($isbn) {
                $arr[] = $isbn;
            }
        }

        return array_values(array_unique($arr));
    }

    /**
     * Dedup: Return ISSNs
     *
     * @return string[]
     * @access public
     */
    public function getISSNs()
    {
        $arr = array();
        $fields = $this->getFields('022');
        foreach ($fields as $field) {
            $issn = $this->getSubfield($field, 'a');
            $issn = str_replace('-', '', $issn);
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
     * @access public
     */
    public function getSeriesISSN()
    {
        $field = $this->getField('490');
        if (!$field) {
            return '';
        }
        return $this->getSubfield($field, 'x');
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     * @access public
     */
    public function getSeriesNumbering()
    {
        $field = $this->getField('490');
        if (!$field)
        return '';
        return $this->getSubfield($field, 'v');
    }

    /**
     * Dedup: Return format from predefined values
     *
     * @return string
     * @access public
     */
    public function getFormat()
    {
        // check the 007 - this is a repeating field
        $fields = $this->getFields('007');
        $formatCode = '';
        $online = false;
        foreach ($fields as $field) {
            $contents = $field;
            $formatCode = strtoupper(substr($contents, 0, 1));
            $formatCode2 = strtoupper(substr($contents, 1, 1));
            switch ($formatCode) {
            case 'A':
                switch($formatCode2) {
                case 'D':
                    return 'Atlas';
                default:
                    return 'Map';
                }
                break;
            case 'C':
                switch($formatCode2) {
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
                switch($formatCode2) {
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
                switch($formatCode2) {
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
                switch($formatCode2) {
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
                $soundTech = strtoupper(substr($contents, 13, 1));
                switch($formatCode2) {
                case 'D':
                    return $soundTech == 'D' ? 'CD' : 'SoundDisc';
                case 'S':
                    return 'SoundCassette';
                default:
                    return 'SoundRecording';
                }
                break;
            case 'V':
                $videoFormat = strtoupper(substr($contents, 4, 1));
                switch($videoFormat) {
                case 'S':
                    return 'BluRay';
                case 'V':
                    return 'DVD';
                }

                switch($formatCode2) {
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
            break;
        // Serial
        case 'S':
            // Look in 008 to determine what type of Continuing Resource
            $field008 = $this->getField('008');
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
     * Dedup: Return publication year (four digits only)
     *
     * @return string
     * @access public
     */
    public function getPublicationYear()
    {
        $field = $this->getField('260');
        if ($field) {
            $year = $this->getSubfield($field, 'c');
            $matches = array();
            if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                return $matches[1];
            }
        }
        $fields = $this->getFields('264');
        foreach ($fields as $field) {
            if ($this->getIndicator($field, 2) == '1') {
                $year = $this->getSubfield($field, 'c');
                $matches = array();
                if ($year && preg_match('/(\d{4})/', $year, $matches)) {
                    return $matches[1];
                }
            }
        }
        $field008 = $this->getField('008');
        if (!$field008) {
            return '';
        }
        $year = substr($field008, 7, 4);
        if ($year && $year != '0000' && $year != '9999' && preg_match('/(\d{4})/', $year)) {
            return $year;
        }
        return '';
    }

    /**
     * Dedup: Return page count (number only)
     *
     * @return string
     * @access public
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
     * @access public
     */
    public function addDedupKeyToMetadata($dedupKey)
    {
        if ($dedupKey) {
            $this->fields['995'][] = array(
                'i1' => ' ',
                'i2' => ' ',
                's' => array(
                    array(
                        'a' => $dedupKey
                    )    
                )
            );
        } else {
            $this->fields['995'] = array();
        }
    }

    /**
     * Parse MARCXML
     * 
     * @param string $marc MARCXML
     * 
     * @throws Exception
     * @return void
     */
    protected function parseXML($marc)
    {
        $xmlHead = '<?xml version';
        if (strcasecmp(substr($marc, 0, strlen($xmlHead)), $xmlHead) === 0) {
            $decl = substr($marc, 0, strpos($marc, '?>'));
            if (strstr($decl, 'encoding') === false) {
                $marc = $decl .  ' encoding="utf-8"' . substr($marc, strlen($decl));
            }
        } else {
            $marc = '<?xml version="1.0" encoding="utf-8"?>' . "\n\n$marc";
        }
        $xml = simplexml_load_string($marc);
        if ($xml === false) {
            throw new Exception('MarcRecord: failed to parse from XML');
        }

        $this->fields['000'] = isset($xml->leader) ? (string)$xml->leader[0] : '';

        foreach ($xml->controlfield as $field) {
            $this->fields[(string)$field['tag']][] = (string)$field;
        }

        foreach ($xml->datafield as $field) {
            $newField = array(
                'i1' => str_pad((string)$field['ind1'], 1), 
                'i2' => str_pad((string)$field['ind2'], 1)
            );
            foreach ($field->subfield as $subfield) {
                $newField['s'][] = array((string)$subfield['code'] => (string)$subfield);
            }
            $this->fields[(string)$field['tag']][] = $newField;
        }
    }

    /**
     * Parse ISO2709 exchange format
     * 
     * @param unknown_type $marc ISO2709 string
     * 
     * @throws Exception
     * @return void
     */
    protected function parseISO2709($marc)
    {
        $this->fields['000'] = substr($marc, 0, 24);
        $dataStart = 0 + substr($marc, 12, 5);
        $dirLen = $dataStart - MARCRecord::LEADER_LEN - 1;

        $offset = 0;
        while ($offset < $dirLen) {
            $tag = substr($marc, MARCRecord::LEADER_LEN + $offset, 3);
            $len = substr($marc, MARCRecord::LEADER_LEN + $offset + 3, 4);
            $dataOffset = substr($marc, MARCRecord::LEADER_LEN + $offset + 7, 5);

            $tagData = substr($marc, $dataStart + $dataOffset, $len);

            if (substr($tagData, -1, 1) == MARCRecord::END_OF_FIELD) {
                $tagData = substr($tagData, 0, -1);
                $len--;
            } else {
                throw new Exception("Invalid MARC record (end of field not found): $marc");
            }
            
            if (strstr($tagData, MARCRecord::SUBFIELD_INDICATOR)) {
                $newField = array(
                    'i1' => $tagData[0],
                    'i2' => $tagData[1]
                );                
                $subfields = explode(MARCRecord::SUBFIELD_INDICATOR, substr($tagData, 3));
                foreach ($subfields as $subfield) {
                    $newField['s'][] = array($subfield[0] => substr($subfield, 1));
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
        global $configArray;
        
        $leader = str_pad(substr($this->fields['000'], 0, 24), 24);

        $directory = '';
        $data = '';
        $datapos = 0;
        foreach ($this->fields as $tag => $fields) {
            if ($tag == '000') {
                continue;
            }
            if (strlen($tag) != 3) {
                error_log("Invalid field tag: '$tag', id " . $this->getField('001'));
                continue;
            }
            foreach ($fields as $field) {
                $fieldStr = '';
                if (is_array($field)) {
                    $fieldStr = $field['i1'] . $field['i2'];
                    if (isset($field['s']) && is_array($field['s'])) {
                        foreach ($field['s'] as $subfield) {
                            $subfieldCode = key($subfield);
                            $fieldStr .= MARCRecord::SUBFIELD_INDICATOR . $subfieldCode . current($subfield);
                        }
                    }
                } else {
                    // Additional normalization here so that we don't break ISO2709 directory in SolrUpdater
                    $fieldStr = MetadataUtils::normalizeUnicode($field);
                }
                $fieldStr .= MARCRecord::END_OF_FIELD;
                $len = strlen($fieldStr);
                if ($len > 9999) {
                    return '';
                }
                if ($datapos > 99999) {
                    return '';
                }
                $directory .= $tag . str_pad($len, 4, '0', STR_PAD_LEFT) . str_pad($datapos, 5, '0', STR_PAD_LEFT);
                $datapos += $len;
                $data .= $fieldStr;
            }
        }
        $directory .= MARCRecord::END_OF_FIELD;
        $data .= MARCRecord::END_OF_RECORD;
        $dataStart = strlen($leader) + strlen($directory);
        $recordLen = $dataStart + strlen($data);
        if ($recordLen > 99999) {
            return '';
        }

        $leader = str_pad($recordLen, 5, '0', STR_PAD_LEFT)
            . substr($leader, 5, 7)
            . str_pad($dataStart, 5, '0', STR_PAD_LEFT)
            . substr($leader, 17);
        return $leader . $directory . $data;
    }

    /**
     * Check if the work is illustrated
     * 
     * @return boolean
     */
    protected function getIllustrated()
    {
        $leader = $this->getField('000');
        if (substr($leader, 6, 1) == 'a') {
            $illustratedCodes = 'abcdefghijklmop';

            // 008
            $field008 = $this->getField('008');
            for ($pos = 18; $pos <= 21; $pos++) {
                if (strpos($illustratedCodes, substr($field008, $pos, 1)) !== false) {
                    return 'Illustrated';
                }
            }

            // 006
            foreach ($this->getFields('006') as $field006) {
                for ($pos = 1; $pos <= 4; $pos++) {
                    if (strpos($illustratedCodes, substr($field006, $pos, 1)) !== false) {
                        return 'Illustrated';
                    }
                }
            }
        }

        // Now check for interesting strings in 300 subfield b:
        $illustrationStrings = array('ill.', 'illus.', 'kuv.');
        foreach ($this->getFields('300') as $field300) {
            $sub = strtolower($this->getSubfield($field300, 'b'));
            foreach ($illustrationStrings as $illStr) {
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
     * @return string
     */
    protected function getField($field)
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
    protected function getFields($field)
    {
        if (isset($this->fields[$field])) {
            return $this->fields[$field];
        }
        return array();
    }

    /**
     * Get indicator value
     * 
     * @param array $field     MARC field
     * @param int   $indicator Indicator nr, 1 or 2
     * 
     * @return string
     */
    protected function getIndicator($field, $indicator)
    {
        switch ($indicator) {
        case 1: return $field['i1'];
        case 2: return $field['i2'];
        default: die("Invalid indicator '$indicator' requested\n");
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
    protected function getSubfield($field, $code)
    {
        if (!$field || !isset($field['s']) || !is_array($field['s'])) {
            return '';
        }
        foreach ($field['s'] as $subfield) {
            if (key($subfield) == $code) {
                return current($subfield);
            }
        }
        return '';
    }

    /**
     * Get specified subfields
     * 
     * @param array  $field MARC Field
     * @param string $codes Subfield codes
     * 
     * @return string[] Subfields 
     */
    protected function getSubfieldsArray($field, $codes)
    {
        $data = array();
        if (!$field || !isset($field['s']) || !is_array($field['s'])) {
            return $data;
        }
        foreach ($field['s'] as $subfield) {
            $code = key($subfield);
            if ($code === 0) {
                $code = '0';
            }
            if (strstr($codes, $code)) {
                $data[] = current($subfield);
            }
        }
        return $data;
    }
    
    /**
     * Get specified subfields
     * 
     * @param array  $field MARC Field
     * @param string $codes Subfield codes
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
     * @param array   $codes                    Optional array of accepted subfields
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation from the results
     * 
     * @return string Concatenated subfields (space-separated)
     */
    protected function getFieldSubfields($tag, $codes = null, $stripTrailingPunctuation = true)
    {
        if (!isset($this->fields[$tag])) {
            return '';
        }
        $subfields = '';
        foreach ($this->fields[$tag] as $field) {
            if (!isset($field['s'])) {
                continue;
            }
            foreach ($field['s'] as $subfield) {
                if ($codes && !in_array(key($subfield), $codes)) {
                    continue;
                }
                if ($subfields) {
                    $subfields .= ' ';
                }
                $subfields .= current($subfield);
            }
        }
        if ($stripTrailingPunctuation) {
            $subfields = MetadataUtils::stripTrailingPunctuation($subfields);
        }
        return $subfields;
    }

    /**
     * Return an array of fields according to the fieldspecs.
     * 
     * Format of fieldspecs: [+*][fieldcode][subfields]:...
     *              + = return only alternate script fields (880 equivalents)
     *              * = return normal and alternate script fields
     * 
     * @param array   $fieldspecs               Fields to get
     * @param boolean $firstOnly                Return only first matching field
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation from the results
     * @param boolean $splitSubfields           Whether to split subfields to separate array items
     * 
     * @return string[] Subfields
     */
    protected function getFieldsSubfields($fieldspecs, $firstOnly = false, $stripTrailingPunctuation = true, $splitSubfields = false)
    {
        $data = array();
        foreach ($fieldspecs as $fieldspec) {
            $type = $fieldspec[0];
            $tag = $fieldspec[1];
            $codes = $fieldspec[2];

            $idx = 0;
            if (!isset($this->fields[$tag])) {
                continue;
            }
            foreach ($this->fields[$tag] as $field) {
                if (!isset($field['s'])) {
                    continue;
                }
                if ($type != MarcRecord::GET_ALT) {
                    // Handle normal field
                    if ($codes) {
                        if ($splitSubfields) {
                            foreach ($field['s'] as $subfield) {
                                $code = key($subfield);
                                if ($code === 0) {
                                    $code = '0';
                                }
                                if (in_array($code, $codes)) {
                                    $data[] = current($subfield);
                                }
                            }                            
                        } else {
                            $fieldContents = '';
                            foreach ($field['s'] as $subfield) {
                                $code = key($subfield);
                                if ($code === 0) {
                                    $code = '0';
                                }
                                if (in_array($code, $codes)) {
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
                if (($type == MarcRecord::GET_ALT || $type == MarcRecord::GET_BOTH) && isset($this->fields['866']) && ($origSub6 = $this->getSubfield($field, '6'))) {
                    // Handle alternate script field
                    $findSub6 = "$tag-" . substr($origSub6, 4, 2);
                    foreach ($this->fields['866'] as $field) {
                        if (strncmp($this->getSubfield($field, '6'), $findSub6, 6) != 0) {
                            continue;
                        }
                        if ($codes) {
                            if ($splitSubfields) {
                                foreach ($field['s'] as $subfield) {
                                    $code = key($subfield);
                                    if ($code === 0) {
                                        $code = '0';
                                    }
                                    if (in_array($code, $codes)) {
                                        $data[] = current($subfield);
                                    }
                                }                            
                            } else {
                                $fieldContents = '';                                
                                foreach ($field['s'] as $subfield) {
                                    $code = key($subfield);
                                    if ($code === 0) {
                                        $code = '0';
                                    }
                                    if (in_array($code, $codes)) {
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
                }
                if ($firstOnly) {
                    break 2;
                }
            }
        }
        if ($stripTrailingPunctuation) {
            foreach ($data as &$item) {
                $item = MetadataUtils::stripTrailingPunctuation($item);
            }
        }
        return $data;
    }

    /**
     * Get all subfields of specified fields
     * 
     * @param string $tag Field tag
     * 
     * @return string[]
     */
    protected function getFieldsAllSubfields($tag)
    {
        $data = array();
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
     * @param array $filter Optional filter to exclude subfield
     * 
     * @return string Concatenated subfields (space-separated)
     */
    protected function getAllSubfields($field, $filter = null)
    {
        if (!$field || !isset($field['s'])) {
            return '';
        }
        
        $subfields = '';
        foreach ($field['s'] as $subfield) {
            if (isset($filter) && in_array(key($subfield), $filter)) {
                continue;
            }
            if ($subfields) {
                $subfields .= ' ';
            }
            $subfields .= current($subfield);
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
    }
}

