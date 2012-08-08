<?php
/**
 * MarcRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012
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

    /** MARC is stored in a multidimensional array:
     *  [001] - "12345"
     *  [245] - i1: '0'
     *          i2: '1'
     *          [s] - c: "a", v: "Title"
     *                c: "p", v: "Part"
     */
    protected $fields;
    protected $idPrefix = '';

    /**
     * Constructor
     *
     * @param string $data  Record metadata
     * @param string $oaiID Record ID in OAI-PMH
     * 
     * @access public
     */
    public function __construct($data, $oaiID)
    {
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
                                $newField['s'][] = array('c' => $subfield[0], 'v' => substr($subfield, 1));
                            }
                            $this->fields[$tag][] = $newField;
                        } else {
                            $this->fields[$tag][] = $data;
                        }
                    }
                }
            } else {
                $this->fields = $fields['f'];
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
        return json_encode(array('v' => 2, 'f' => $this->fields));
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
                        foreach ($data['s'] as $subfieldData) {
                            if ($subfieldData == '') {
                                continue;
                            }
                            $subfield = $field->addChild(
                                'subfield',
                                htmlspecialchars($subfieldData['v'], ENT_NOQUOTES)
                            );
                            $subfield->addAttribute('code', $subfieldData['c']);
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
            $location = $this->getSubfield($field, 'a');
            $sub = $this->getSubfield($field, 'b');
            if ($location && $sub) {
                $location .= "/$sub";
            } else {
                $location .= $sub;
            }
            if ($location) {
                $data['building'][] = $location;
            }
            $this->getFieldsSubfields('852b');
        }
          
        // long_lat
        $field = $this->getField('034');
        if ($field) {
            $west = MetadataUtils::coordinateToDecimal($this->getSubfield($field, 'd'));
            $east = MetadataUtils::coordinateToDecimal($this->getSubfield($field, 'e'));
            $north = MetadataUtils::coordinateToDecimal($this->getSubfield($field, 'f'));
            $south = MetadataUtils::coordinateToDecimal($this->getSubfield($field, 'g'));

            if (!is_nan($west) && !is_nan($north)) {
                if (!is_nan($east)) {
                    $west = ($west + $east) / 2;
                }
                if (!is_nan($south)) {
                    $north = ($north + $south) / 2;
                }
                $data['long_lat'] = $west . ',' . $north;
            }
        }

        // lccn
        $data['lccn'] = $this->getFieldSubfields('010', 'a');
        $data['ctrlnum'] = $this->getFieldsSubfields('035', 'a');
        $data['fullrecord'] = $this->toISO2709();
        if (!$data['fullrecord']) {
            // In case the record exceeds 99999 bytes...
            $data['fullrecord'] = $this->toXML();
        }
        
        // allfields
        $allFields = array();
        $subfieldFilter = array('650' => array('2'));
        foreach ($this->fields as $tag => $fields) {
            if (($tag >= 100 && $tag < 900) || $tag == 979) {
                foreach ($fields as $field) {
                    $allFields[] = $this->getAllSubfields(
                        $field,
                        isset($subfieldFilter[$tag]) ? $subfieldFilter[$tag] : null
                    );
                }
            }
        }
        //echo "allFields: $allFields\n";
        $data['allfields'] = MetadataUtils::array_iunique($allFields);
          
        // language
        $languages = array(substr($this->getField('008'), 35, 3));
        $languages += $this->getFieldsSubfields('041a:041d:041h:041j');
        foreach ($languages as $language) {
            if (preg_match('/^\w{3}$/', $language))
            $data['language'][] = $language;
        }
          
        $data['format'] = $this->getFormat();
        
        $data['author'] = $this->getFieldSubfields('100abcd', true);
        $data['author_fuller'] = $this->getFieldSubfields('100q');
        $data['author-letter'] = $this->getFieldSubfields('100a', true);

        $data['author2'] = $this->getFieldsSubfields(
            '+100abcd:*110ab:*111ab:*700abcd:*710ab:*711ab', 
            false, 
            true
        );
        // 979cd = component part authors
        foreach ($this->getFieldsSubfields('*979c:*979d', false, true, true) as $field) {
            $data['author2'][] = $field;
        }
        $data['author2'] = MetadataUtils::array_iunique($data['author2']);
        
        $key = array_search(mb_strtolower($data['author']), array_map('mb_strtolower', $data['author2']));
        if ($key !== false) {
            unset($data['author2'][$key]);
        }
        $data['author2'] = array_values($data['author2']);
        $data['author2-role'] = $this->getFieldsSubfields('*700e:*710e', true);
        $data['author_additional'] = $this->getFieldsSubfields('*505r', true);
          
        $data['title'] = $data['title_auth'] = $this->getTitle();
        $data['title_sub'] = $this->getFieldSubfields('245b', true);
        $data['title_short'] = $this->getFieldSubfields('245a', true);
        $data['title_full'] = $this->getFieldSubfields('245');
        $data['title_alt'] = array_values(
            MetadataUtils::array_iunique(
                $this->getFieldsSubfields(
                    '+245ab:*130adfgklnpst:*240a:*246a:*730adfgklnpst:*740a:*979b:*979e',
                    false,
                    true
                )
            )
        ); // 979b and e = component part title and uniform title
        $data['title_old'] = $this->getFieldsSubfields('*780ast');
        $data['title_new'] = $this->getFieldsSubfields('*785ast');
        $data['title_sort'] = $this->getTitle(true);
        if (!$data['title_short']) {
            $data['title_short'] = $this->getFieldSubfields('240anp', true);
            $data['title_full'] = $this->getFieldSubfields('240');
        }

        $data['series'] = $this->getFieldsSubfields('*440ap:*800abcdfpqt:*830ap');
          
        $data['publisher'] = $this->getFieldsSubfields('*260b', false, true);
        $data['publishDate'] = $data['publishDateSort'] = $this->getPublicationYear();
        $data['physical'] = $this->getFieldsSubfields('*300abcefg:*530abcd');
        $data['dateSpan'] = $this->getFieldsSubfields('*362a');
        $data['edition'] = $this->getFieldSubfields('250a', false, true);
        $data['contents'] = $this->getFieldsSubfields('*505a:*505t', false, true);
          
        $data['isbn'] = $this->getISBNs();
        foreach ($this->getFieldsSubfields('773z') as $isbn) {
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
        $data['issn'] = $this->getFieldsSubfields('022a:440x:490x:730x:773x:776x:780x:785x');
        foreach ($data['issn'] as $key => $value) {
            $data['issn'][$key] = str_replace('-', '', $value);
        }

        $data['callnumber'] = strtoupper(str_replace(' ', '', $this->getFirstFieldSubfields('080ab:084ab:050ab')));
        $data['callnumber-a'] = $this->getFirstFieldSubfields('080a:084a:050a');
        $data['callnumber-first-code'] = substr($this->getFirstFieldSubfields('080a:084a:050a'), 0, 1);

        $data['topic'] = $this->getFieldsSubfields('*600abcdefghjklmnopqrstuvxyz:*610abcdefghklmnoprstuvxyz:*611acdefghjklnpqstuvxyz:*630adefghklmnoprstvxyz:*650abcdevxyz');
        $data['genre'] = $this->getFieldsSubfields('*655abcvxyz');
        $data['geographic'] = $this->getFieldsSubfields('*651aevxyz');
        $data['era'] = $this->getFieldsSubfields('*648avxyz');

        $data['topic_facet'] = $this->getFieldsSubfields('600x:610x:611x:630x:648x:650a:650x:651x:655x', false, false, true);
        $data['genre_facet'] = $this->getFieldsSubfields('600v:610v:611v:630v:648v:650v:651v:655a:655v', false, false, true);
        $data['geographic_facet'] = $this->getFieldsSubfields('600z:610z:611z:630z:648z:650z:651a:651z:655z', false, false, true);
        $data['era_facet'] = $this->getFieldsSubfields('600d:610y:611y:630y:648a:648y:650y:651y:655y', false, false, true);

        $data['url'] = $this->getFieldsSubfields('856u');

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
            $marc = new MARCRecord($data, '');
            $title = $marc->getFieldSubfields('245abnp');
            $uniTitle = $marc->getFieldSubfields('240anp');
            $author = $marc->getFieldSubfields('100ae');
            $additionalAuthors = $marc->getFieldsSubfields('700ae:710ae');
            $id = $this->idPrefix . $marc->getID();

            $newField = array(
                'i1' => ' ',
                'i2' => ' ',
                's' => array(
                    array('c' => 'a', 'v' => $id),
                    array('c' => 'b', 'v' => $title),
                    array('c' => 'c', 'v' => $author),
                    array('c' => 'e', 'v' => $uniTitle),
                )
            );
            foreach ($additionalAuthors as $addAuthor) {
                $newField['s'][] = array('c' => 'd', 'v' => $addAuthor);
            }
            
            $key = MetadataUtils::createIdSortKey($marc->getID());
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
        //echo "ID: *" . $this->marcRecord->getField('001')->getData() ."*\n";
        return $this->getField('001');
    }

    /**
     * Set the ID prefix into all the ID fields (ID, host ID and any other fields that reference other records by ID)
     *
     * @param string $prefix The prefix (e.g. "source.")
     * 
     * @return void
     */
    public function setIDPrefix($prefix)
    {
        $this->idPrefix = $prefix;
        //echo "ID: *" . $this->marcRecord->getField('001')->getData() ."*\n";
        $id = $this->getField('001');
        $id = "$prefix$id";
        $this->setField('001', array($id));

        if (isset($this->fields['773'])) {
            foreach ($this->fields['773'] as &$field) {
                foreach ($field['s'] as &$subfield) {
                    if ($subfield['c'] == 'w') {
                        $subfield['v'] = $prefix . $subfield['v'];
                    }
                }
            }
        }
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
        $field773g = $this->getFieldSubfields('773g');
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
        $field773g = $this->getFieldSubfields('773g');
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
        $field773g = $this->getFieldSubfields('773g');
        if (!$field773g) {
            return '';
        }
        
        // Try to parse the data from different versions of 773g
        $matches = array();
        if (preg_match('/,\s*\w\.?\s*([\d,\-]+)/', $field773g, $matches)) {
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
        return $this->getFieldSubfields('773t');
    }

    /**
     * Component parts: get the free-form reference to the part in the container
     *
     * @return string
     */
    public function getContainerReference()
    {
        return $this->getFieldSubfields('773g');
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
        $field = $this->getField('245');
        if (!$field) {
            $field = $this->getField('240');
        }
        if ($field) {
            $title = $this->getSubfield($field, 'a');
            if ($forFiling) {
                $nonfiling = $this->getIndicator($field, 2);
                if ($nonfiling > 0)
                $title = substr($title, $nonfiling);
            }
            $subB = $this->getSubfield($field, 'b');
            if ($subB) {
                if (!MetadataUtils::hasTrailingPunctuation($title)) {
                    $title .= ' :';
                }
                $title .= " $subB";
            }
            $subN = $this->getSubfield($field, 'n');
            if ($subN) {
                if (!MetadataUtils::hasTrailingPunctuation($title)) {
                    $title .= '.';
                }
                $title .= " $subN";
            }
            $subP = $this->getSubfield($field, 'p');
            if ($subP) {
                if (!MetadataUtils::hasTrailingPunctuation($title)) {
                    $title .= '. ';
                }
                $title .= " $subP";
            }
            return MetadataUtils::stripTrailingPunctuation($title);
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
        $f245 = $this->getField('245');
        return $f245;
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
        // Custom predefined type in 977a
        $field977a = $this->getFieldSubfields('977a');
        if ($field977a) {
            return $field977a;
        }
        $field008 = $this->getField('008');
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
                    return 'Software';
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
                    return 'Drawing';
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
                switch($formatCode2) {
                case 'D':
                    return 'SoundDisc';
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
            if ($formatCode == 'C') {
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
                return 'Newspaper';
            case 'P':
                return 'Journal';
            default:
                return 'Serial';
            }
            break;
            
        case 'A':
            // Component part in monograph
            return $formatCode == 'C' ? 'eBookPart' : 'BookPart';
        case 'B':
            // Component part in serial 
            return $formatCode == 'C' ? 'eArticle' : 'Article';
        case 'C':
            // Collection
            return 'Collection';
        case 'D':
            // Component part in collection (sub unit) 
            return 'Subunit';
        case 'I':
            // Integrating resource 
            return 'ContinuouslyUpdatedResource';
        }
        return '';
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
        $field008 = $this->getField('008');
        if (!$field008) {
            return '';
        }
        $year = substr($field008, 7, 4);
        $matches = array();
        if ($year && preg_match('/(\d{4})/', $year, $matches)) {
            return $matches[1];
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
            $this->fields['995'] = array(
                'i1' => ' ',
                'i2' => ' ',
                's' => array(
                    array('c' => 'a', 'v' => $dedupKey)    
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
                $newField['s'][] = array('c' => (string)$subfield['code'], 'v' => (string)$subfield);
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
                    $newField['s'][] = array('c' => $subfield[0], 'v' => substr($subfield, 1));
                }
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
                error_log("Invalid field tag: '$tag', id " . $this->getField('001'));
                continue;
            }
            foreach ($fields as $field) {
                $fieldStr = '';
                if (is_array($field)) {
                    $fieldStr = $field['i1'] . $field['i2'];
                    if (isset($field['s']) && is_array($field['s'])) {
                        foreach ($field['s'] as $subfield) {
                            $fieldStr .= MARCRecord::SUBFIELD_INDICATOR . $subfield['c'] . $subfield['v'];
                        }
                    }
                } else {
                    $fieldStr = $field;
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
     * @return string[]
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
            if ($subfield['c'] == $code) {
                return $subfield['v'];
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
            if (strstr($codes, $subfield['c'])) {
                $data[] = $subfield['v'];
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
     * Get data using a fieldspec
     * 
     * Format of fieldspec: [+*][fieldcode][subfields]:...
     *              + = return only alternate script fields (880 equivalents)
     *              * = return normal and alternate script fields
     * 
     * @param string  $fieldspec                Fields to get
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation from the results
     * 
     * @return string Concatenated subfields (space-separated)
     */
    protected function getFieldSubfields($fieldspec, $stripTrailingPunctuation = false)
    {
        $tag = substr($fieldspec, 0, 3);
        $codes = substr($fieldspec, 3);
        if ($codes) {
            $data = $this->getSubfields($this->getField($tag), $codes);
        } else {
            $data = $this->getAllSubfields($this->getField($tag));
        }
        if ($stripTrailingPunctuation) {
            $data = MetadataUtils::stripTrailingPunctuation($data);
        }
        return $data;
    }

    /**
     * Return an array of fields according to the fieldspecs.
     * 
     * Format of fieldspecs: [+*][fieldcode][subfields]:...
     *              + = return only alternate script fields (880 equivalents)
     *              * = return normal and alternate script fields
     * 
     * @param string  $fieldspecs               Fields to get
     * @param boolean $firstOnly                Return only first matching field
     * @param boolean $stripTrailingPunctuation Whether to strip trailing punctuation from the results
     * @param boolean $splitSubfields           Whether to split subfields to separate array items
     * 
     * @return string[] Subfields
     */
    protected function getFieldsSubfields($fieldspecs, $firstOnly = false, $stripTrailingPunctuation = false, $splitSubfields = false)
    {
        $data = array();
        foreach (explode(':', $fieldspecs) as $fieldspec) {
            $mark = $fieldspec[0];
            if ($mark == '+' || $mark == '*') {
                $tag = $fieldspec[1] . $fieldspec[2] . $fieldspec[3];
                $codes = substr($fieldspec, 4);
            } else {
                $tag = $fieldspec[0] . $fieldspec[1] . $fieldspec[2];
                $codes = substr($fieldspec, 3);
            }

            $idx = 0;
            foreach ($this->getFields($tag) as $field) {
                if ($mark != '+') {
                    // Handle normal field
                    if ($codes) {
                        if ($splitSubfields) {
                            foreach (str_split($codes) as $code) {
                                $data = array_merge($data, $this->getSubfieldsArray($field, $code));
                            }
                        } else {
                            $fieldContents = $this->getSubfields($field, $codes);
                            if ($fieldContents) {
                                $data[] = $fieldContents;
                            }
                        }
                    } else {
                        $fieldContents = $this->getAllSubfields($field);
                        if ($fieldContents) {
                            $data[] = $fieldContents;
                        }
                    }
                }
                if (($mark == '+' || $mark == '*') && ($origSub6 = $this->getSubfield($field, '6'))) {
                    // Handle alternate script field
                    $findSub6 = "$tag-" . substr($origSub6, 4, 2);
                    foreach ($this->getFields('880') as $field) {
                        if (strncmp($this->getSubfield($field, '6'), $findSub6, 6) != 0) {
                            continue;
                        }
                        if ($codes) {
                            if ($splitSubfields) {
                                foreach (str_split($codes) as $code) {
                                    $data = array_merge($data, $this->getSubfieldsArray($field, $code));
                                }
                            } else {
                                $fieldContents = $this->getSubfields($field, $codes);
                                if ($fieldContents) {
                                    $data[] = $fieldContents;
                                }
                            }
                        } else {
                            $fieldContents = $this->getAllSubfields($field);
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
            return array_map(array('MetadataUtils', 'stripTrailingPunctuation'), $data);
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
     * @param string $fieldspecs Field specifications
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
        if (!$field || !isset($field['s']) || !is_array($field['s'])) {
            return '';
        }
        
        $subfields = '';
        foreach ($field['s'] as $subfield) {
            if (isset($filter) && in_array($subfield['c'], $filter)) {
                continue;
            }
            if ($subfields) {
                $subfields .= ' ';
            }
            $subfields .= $subfield['v'];
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

