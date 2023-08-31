<?php

/**
 * Marc record format calculator
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2017.
 * Copyright (C) The National Library of Finland 2022.
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Eoghan Ó Carragáin <eoghan.ocarragain@gmail.com>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Record\Marc;

use RecordManager\Base\Marc\Marc;

/**
 * Marc record format calculator
 *
 * This is a class for calculating formats for a MARC record. Based on
 * FormatCalculator.java in VuFind.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Eoghan Ó Carragáin <eoghan.ocarragain@gmail.com>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class FormatCalculator
{
    /**
     * Determine Record Format(s)
     *
     * @param Marc $record MARC record
     *
     * @return array<int, string> Record formats
     */
    public function getFormats(Marc $record): array
    {
        // Deduplicate list:
        return array_values(array_unique($this->getFormatsAsList($record)));
    }

    /**
     * Determine Record Format
     *
     * @param Marc $record MARC record
     *
     * @return array First format
     */
    public function getFormat(Marc $record): array
    {
        $result = $this->getFormatsAsList($record);
        // Return the first format as an array:
        return [reset($result)];
    }

    /**
     * Determine whether a record cannot be a book due to findings in 007.
     *
     * @param string $formatCode Format code
     *
     * @return bool
     */
    protected function definitelyNotBookBasedOn007(string $formatCode): bool
    {
        // Things that are not books: filmstrips/transparencies (g),
        // pictures (k) and videos/films (m, v):
        return in_array($formatCode, ['g', 'k', 'm', 'v']);
    }

    /**
     * Determine whether a record cannot be a book due to findings in leader
     * and fixed fields (008).
     *
     * @param string $recordType Record type
     * @param string $marc008    008 field
     *
     * @return bool
     */
    protected function definitelyNotBookBasedOnRecordType(
        string $recordType,
        string $marc008
    ): bool {
        switch ($recordType) {
            // Computer file
            case 'm':
                // Check the type of computer file:
                // If it is 'Document', 'Interactive multimedia', 'Combination',
                // 'Unknown', 'Other', it could be a book; otherwise, it is not a book:
                $fileType = $this->get008Value($marc008, 26);
                if (in_array($fileType, ['d', 'i', 'm', 'u', 'z'])) {
                    return false;
                }
                return true;
            case 'e':   // Cartographic material
            case 'f':   // Manuscript cartographic material
            case 'g':   // Projected medium
            case 'i':   // Nonmusical sound recording
            case 'j':   // Musical sound recording
            case 'k':   // 2-D nonprojectable graphic
            case 'r':   // 3-D artifact or naturally occurring object
                // None of these things are books:
                return true;
        }
        return false;
    }

    /**
     * Return the best format string based on codes extracted from 007; return
     * blank string for ambiguous/irrelevant results.
     *
     * @param string $formatCode   Format code
     * @param string $formatString Format string
     *
     * @return string
     */
    protected function getFormatFrom007(
        string $formatCode,
        string $formatString
    ): string {
        $formatCode2 = substr($formatString, 1, 1) ?: ' ';
        switch ($formatCode) {
            case 'a':
                return $formatCode2 == 'd' ? 'Atlas' : 'Map';
            case 'c':
                switch ($formatCode2) {
                    case 'a':
                        return 'TapeCartridge';
                    case 'b':
                        return 'ChipCartridge';
                    case 'c':
                        return 'DiscCartridge';
                    case 'f':
                        return 'TapeCassette';
                    case 'h':
                        return 'TapeReel';
                    case 'j':
                        return 'FloppyDisk';
                    case 'm':
                    case 'o':
                        return 'CDROM';
                    case 'r':
                        // Do not return anything - otherwise anything with an
                        // 856 field would be labeled as "Electronic"
                        return '';
                }
                return 'ElectronicResource';
            case 'd':
                return 'Globe';
            case 'f':
                return 'Braille';
            case 'g':
                switch ($formatCode2) {
                    case 'c': // Filmstrip cartridge
                    case 'd': // Filmslip
                    case 'f': // Filmstrip, type unspecified
                    case 'o': // Filmstrip roll
                        return 'Filmstrip';
                    case 't':
                        return 'Transparency';
                }
                return 'Slide';
            case 'h':
                return 'Microfilm';
            case 'k':
                switch ($formatCode2) {
                    case 'c':
                        return 'Collage';
                    case 'd':
                        return 'Drawing';
                    case 'e':
                        return 'Painting';
                    case 'f': // Photomechanical print
                        return 'Print';
                    case 'g':
                        return 'Photonegative';
                    case 'j':
                        return 'Print';
                    case 'k':
                        return 'Poster';
                    case 'l':
                        return 'Drawing';
                    case 'n':
                        return 'Chart';
                    case 'o':
                        return 'FlashCard';
                    case 'p':
                        return 'Postcard';
                    case 's': // Study print
                        return 'Print';
                }
                return 'Photo';
            case 'm':
                switch ($formatCode2) {
                    case 'f':
                        return 'VideoCassette';
                    case 'r':
                        return 'Filmstrip';
                }
                return 'MotionPicture';
            case 'o':
                return 'Kit';
            case 'q':
                return 'MusicalScore';
            case 'r':
                return 'SensorImage';
            case 's':
                switch ($formatCode2) {
                    case 'd':
                        return 'SoundDisc';
                    case 's':
                        return 'SoundCassette';
                }
                return 'SoundRecording';
            case 'v':
                switch ($formatCode2) {
                    case 'c':
                        return 'VideoCartridge';
                    case 'd':
                        $formatCode5 = substr($formatString, 4, 1) ?: ' ';
                        return $formatCode5 === 's' ? 'BRDisc' : 'VideoDisc';
                    case 'f':
                        return 'VideoCassette';
                    case 'r':
                        return 'VideoReel';
                }
                // assume other video is online:
                return 'VideoOnline';
        }
        return '';
    }

    /**
     * Return the best format string based on bib level in leader; return
     * blank string for ambiguous/irrelevant results.
     *
     * @param Marc   $record         MARC record
     * @param string $recordType     Record type
     * @param string $bibLevel       Bibliographic level
     * @param string $marc008        008 field
     * @param bool   $couldBeBook    Whether this could be a book
     * @param array  $formatCodes007 Format codes from 007 fields
     *
     * @return string
     */
    protected function getFormatFromBibLevel(
        Marc $record,
        string $recordType,
        string $bibLevel,
        string $marc008,
        bool $couldBeBook,
        array $formatCodes007
    ): string {
        switch ($bibLevel) {
            // Component parts
            case 'a':
                return ($this->hasSerialHost($record))
                ? 'Article' : 'BookComponentPart';
            case 'b':
                return 'SerialComponentPart';
                // Collection and sub-unit will be mapped to 'Kit' below if no other
                // format can be found. For now return an empty string here.
            case 'c': // Collection
            case 'd': // Sub-unit
                return '';
                // Integrating resources (e.g. loose-leaf binders, databases)
            case 'i':
                // Look in 008 to determine type of electronic IntegratingResource
                // Check 008/21 Type of continuing resource
                // Make sure we have the applicable LDR/06: Language Material
                if ($recordType === 'a') {
                    switch ($this->get008Value($marc008, 21)) {
                        case 'h': // Blog
                        case 'w': // Updating Web site
                            return 'Website';
                        default:
                            break;
                    }
                    // Check 008/22 Form of original item
                    switch ($this->get008Value($marc008, 22)) {
                        case 'o': // Online
                        case 'q': // Direct electronic
                        case 's': // Electronic
                            return 'OnlineIntegratingResource';
                        default:
                            break;
                    }
                }
                return 'PhysicalIntegratingResource';
                // Monograph
            case 'm':
                if ($couldBeBook) {
                    // Check 008/23 Form of item
                    // Make sure we have the applicable LDR/06: Language Material;
                    // Manuscript Language Material;
                    if ($recordType === 'a' || $recordType === 't') {
                        switch ($this->get008Value($marc008, 23)) {
                            case 'o': // Online
                            case 'q': // Direct electronic
                            case 's': // Electronic
                                return 'eBook';
                            default:
                                break;
                        }
                    } elseif ($recordType === 'm') {
                        // If we made it here and it is a Computer file, set to eBook
                        // Note: specific types of Computer file, e.g. Video Game, have
                        // already been excluded in definitelyNotBookBasedOnRecordType()
                        return 'eBook';
                    }
                    // If we made it here, it should be Book
                    return 'Book';
                }
                break;
                // Serial
            case 's':
                // Look in 008 to determine what type of Continuing Resource
                // Make sure we have the applicable LDR/06: Language Material
                if ($recordType === 'a') {
                    switch ($this->get008Value($marc008, 21)) {
                        case 'n':
                            return 'Newspaper';
                        case 'p':
                            return 'Journal';
                        default:
                            break;
                    }
                }
                // Default to serial even if 008 is missing
                if (!$this->isConferenceProceeding($record)) {
                    return 'Serial';
                }
                break;
        }
        return '';
    }

    /**
     * Return the best format string based on record type in leader; return
     * blank string for ambiguous/irrelevant results.
     *
     * @param Marc   $record         MARC record
     * @param string $recordType     Record type
     * @param string $marc008        008 field
     * @param array  $formatCodes007 Format codes from 007 fields
     *
     * @return string
     */
    protected function getFormatFromRecordType(
        Marc $record,
        string $recordType,
        string $marc008,
        array $formatCodes007
    ) {
        switch ($recordType) {
            // Language material is mapped to 'Text' below if no other
            // format can be found. For now return an empty string here.
            case 'a':
                return '';
            case 'c':
            case 'd':
                return 'MusicalScore';
            case 'e':
            case 'f':
                // Check 008/25 Type of cartographic material
                switch ($this->get008Value($marc008, 25)) {
                    case 'd':
                        return 'Globe';
                    case 'e':
                        return 'Atlas';
                    default:
                        break;
                }
                return 'Map';
            case 'g':
                // Check 008/33 Type of visual material
                switch ($this->get008Value($marc008, 33)) {
                    case 'f':
                        return 'Filmstrip';
                    case 't':
                        return 'Transparency';
                    case 'm':
                        return 'MotionPicture';
                    case 'v': // Videorecording
                        return 'Video';
                    default:
                        break;
                }
                // Check 008/34 Technique
                // If set, this is a video rather than a slide
                switch ($this->get008Value($marc008, 34)) {
                    case 'a': // Animation
                    case 'c': // Animation and live action
                    case 'l': // Live action
                    case 'u': // Unknown
                    case 'z': // Other
                        return 'Video';
                    default:
                        break;
                }
                // Insufficient info in LDR and 008 to distinguish still from moving
                // images.
                // If there is a 007 for either "Projected Graphic", "Motion Picture", or
                // "Videorecording" that should contain more information, so return
                // nothing here.
                // If no such 007 exists, fall back to "ProjectedMedium".
                if (
                    in_array('g', $formatCodes007)
                    || in_array('m', $formatCodes007)
                    || in_array('v', $formatCodes007)
                ) {
                    return '';
                }
                return 'ProjectedMedium';
            case 'i':
                return 'SoundRecording';
            case 'j':
                return 'MusicRecording';
            case 'k':
                // Check 008/33 Type of visual material
                switch ($this->get008Value($marc008, 33)) {
                    case 'l': // Technical drawing
                        return 'Drawing';
                    case 'n':
                        return 'Chart';
                    case 'o':
                        return 'FlashCard';
                    default:
                        break;
                }
                // Insufficient info in LDR and 008 to distinguish image types
                // If there is a 007 for Nonprojected Graphic, it should have more info,
                // so return nothing here.
                // If there is no 007 for Nonprojected Graphic, fall back to "Image"
                return in_array('k', $formatCodes007) ? '' : 'Image';
                // Computer file
            case 'm':
                // All computer files return a format of Electronic in isElectronic()
                // Only set more specific formats here
                // Check 008/26 Type of computer file
                switch ($this->get008Value($marc008, 26)) {
                    case 'a': // Numeric data
                        return 'DataSet';
                    case 'b': // Computer program
                        return 'Software';
                    case 'c': // Representational
                        return 'Image';
                    case 'd': // Document
                        // Document is too vague and often confusing when combined
                        // with formats derived from elsewhere in the record
                        break;
                    case 'e': //Bibliographic data
                        return 'DataSet';
                    case 'f': // Font
                        return 'Font';
                    case 'g': // Game
                        return 'VideoGame';
                    case 'h': // Sound
                        return 'SoundRecording';
                    case 'i': // Interactive multimedia
                        return 'InteractiveMultimedia';
                    default:
                        break;
                }
                // If we got here, don't return anything
                break;
            case 'o':
            case 'p':
                return 'Kit';
            case 'r':
                return 'PhysicalObject';
            case 't':
                if (!$this->isThesis($record)) {
                    return 'Manuscript';
                }
                break;
        }
        return '';
    }

    /**
     * Extract value at a specific position in 008 field
     *
     * @param string $marc008  008 field
     * @param int    $position Position
     *
     * @return string
     */
    protected function get008Value(
        string $marc008,
        int $position
    ): string {
        return strtolower(substr($marc008, $position, 1) ?: ' ');
    }

    /**
     * Determine whether a record is a conference proceeding.
     *
     * @param Marc $record Record
     *
     * @return bool
     */
    protected function isConferenceProceeding(Marc $record): bool
    {
        // Is there a main entry meeting name?
        if ($record->getField('111')) {
            return true;
        }
        // The 711 could possibly have more than one entry, although probably
        // unlikely
        if ($record->getField('711')) {
            return true;
        }
        return false;
    }

    /**
     * Determine whether a record is electronic in format.
     *
     * @param Marc   $record     MARC record
     * @param string $recordType Record type
     *
     * @return bool
     */
    protected function isElectronic(Marc $record, string $recordType): bool
    {
        /* Example from Villanova of how to use holdings locations to detect online
         * status; You can override this method in a subclass if you wish to use this
         * approach.
        foreach ($record->getFields('852') as $holdingsField) {
            $holdingsLocation = $record->getSubfield($holdingsField, 'b');
            if (in_array($holdingsLocation, ['www', 'e-ref'])) {
                return true;
            }
        }
        */
        $title = $record->getField('245');
        if ($title) {
            if ($subH = $record->getSubfield($title, 'h')) {
                if (stripos($subH, '[electronic resource]') !== false) {
                    return true;
                }
            }
        }
        // Is this a computer file of some sort?
        // If so it is electronic
        if ($recordType === 'm') {
            return true;
        }

        if ($this->isOnlineAccordingTo338($record)) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether a record is a government document.
     *
     * @param Marc $record MARC record
     *
     * @return bool
     */
    protected function isGovernmentDocument(Marc $record): bool
    {
        // Is there a SuDoc number? If so, it's a government document.
        return !empty($record->getField('086'));
    }

    /**
     * Determine whether a record is a thesis.
     *
     * @param Marc $record MARC record
     *
     * @return bool
     */
    protected function isThesis(Marc $record): bool
    {
        // Is there a dissertation note? If so, it's a thesis.
        return !empty($record->getField('502'));
    }

    /**
     * Determine whether a record has a host item that is a serial.
     *
     * @param Marc $record MARC record
     *
     * @return bool
     */
    protected function hasSerialHost(Marc $record): bool
    {
        // The 773 could possibly have more then one entry, although probably
        // unlikely.
        // If any contain a subfield 'g' return true to indicate the host is a serial
        // see https://www.oclc.org/bibformats/en/specialcataloging.html
        // #relatedpartsandpublications
        foreach ($record->getFields('773') as $hostField) {
            if ($record->getSubfield($hostField, 'g')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get contents of a subfield or a default value
     *
     * @param Marc   $record       MARC record
     * @param array  $field        Field
     * @param string $subfieldCode Subfield code
     * @param string $defaultValue Default value
     *
     * @return string
     */
    protected function getSubfieldOrDefault(
        Marc $record,
        array $field,
        string $subfieldCode,
        string $defaultValue
    ): string {
        $result = $record->getSubfield($field, $subfieldCode);
        return $result !== '' ? $result : $defaultValue;
    }

    /**
     * Determine whether a record is online according to 338 field.
     *
     * @param Marc $record MARC record
     *
     * @return bool
     */
    protected function isOnlineAccordingTo338(Marc $record): bool
    {
        // Does the RDA carrier indicate that this is online?
        foreach ($record->getFields('338') as $carrierField) {
            $desc = $this->getSubfieldOrDefault($record, $carrierField, 'a', '');
            $code = $this->getSubfieldOrDefault($record, $carrierField, 'b', '');
            $source = $this->getSubfieldOrDefault($record, $carrierField, '2', '');
            if (
                ('online resource' === $desc || 'cr' === $code)
                && 'rdacarrier' === $source
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Determines record formats using 33x fields.
     *
     * This is not currently comprehensive; it is designed to supplement but not
     * replace existing support for 007 analysis and can be expanded in future.
     *
     * @param Marc $record MARC record
     *
     * @return array<int, string> Format(s) of record
     */
    protected function getFormatsFrom33xFields(Marc $record): array
    {
        $isOnline = $this->isOnlineAccordingTo338($record);
        $video = false;
        $videoOnline = false;
        foreach ($record->getFields('336') as $typeField) {
            $desc = $this->getSubfieldOrDefault($record, $typeField, 'a', '');
            $code = $this->getSubfieldOrDefault($record, $typeField, 'b', '');
            $source = $this->getSubfieldOrDefault($record, $typeField, '2', '');
            if (
                ('two-dimensional moving image' === $desc || 'tdi' === $code)
                && 'rdacontent' === $source
            ) {
                $video = true;
                if ($isOnline) {
                    $videoOnline = true;
                }
            }
        }
        $formats = [];
        if ($video) {
            $formats[] = 'Video';
            if ($videoOnline) {
                $formats[] = 'VideoOnline';
            }
        }
        return $formats;
    }

    /**
     * Determine Record Format(s)
     *
     * @param Marc $record MARC record
     *
     * @return array<int, string> Format(s) of record
     */
    protected function getFormatsAsList(Marc $record)
    {
        $result = [];
        $leader = $record->getLeader();
        $marc008 = $record->getField('008') ?: '';
        $formatCode = ' ';
        $recordType = strtolower(substr($leader, 6, 1));
        $bibLevel = strtolower(substr($leader, 7, 1));

        // This record could be a book... until we prove otherwise!
        $couldBeBook = true;

        // Some format-specific special cases:
        if ($this->isGovernmentDocument($record)) {
            $result[] = 'GovernmentDocument';
        }
        if ($this->isThesis($record)) {
            $result[] = 'Thesis';
        }
        if ($this->isElectronic($record, $recordType)) {
            $result[] = 'Electronic';
        }
        if ($this->isConferenceProceeding($record)) {
            $result[] = 'ConferenceProceeding';
        }

        // check the 33x fields; these may give us clear information in newer
        // records; in current partial implementation of getFormatsFrom33xFields(),
        // if we find something here, it indicates non-book content.
        $formatsFrom33x = $this->getFormatsFrom33xFields($record);
        if ($formatsFrom33x) {
            $couldBeBook = false;
            $result = [...$result, ...$formatsFrom33x];
        }

        // check the 007 - this is a repeating field
        $formatCodes007 = [];
        foreach ($record->getFields('007') as $formatField) {
            $formatString = strtolower($formatField);
            $formatCode = substr($formatString, 0, 1) ?: ' ';
            $formatCodes007[] = $formatCode;
            if ($this->definitelyNotBookBasedOn007($formatCode)) {
                $couldBeBook = false;
            }
            if ($formatCode === 'v') {
                // All video content should get flagged as video; we will also
                // add a more detailed value in getFormatFrom007 to distinguish
                // different types of video.
                $result[] = 'Video';
            }
            $formatFrom007 = $this->getFormatFrom007($formatCode, $formatString);
            if ($formatFrom007) {
                $result[] = $formatFrom007;
            }
        }

        // check the Leader at position 6
        if ($this->definitelyNotBookBasedOnRecordType($recordType, $marc008)) {
            $couldBeBook = false;
        }
        // If we already have 33x results, skip the record type:
        $formatFromRecordType = $formatsFrom33x
            ? ''
            : $this->getFormatFromRecordType(
                $record,
                $recordType,
                $marc008,
                $formatCodes007
            );
        if ($formatFromRecordType) {
            $result[] = $formatFromRecordType;
        }

        // check the Leader at position 7
        $formatFromBibLevel = $this->getFormatFromBibLevel(
            $record,
            $recordType,
            $bibLevel,
            $marc008,
            $couldBeBook,
            $formatCodes007
        );
        if ($formatFromBibLevel) {
            $result[] = $formatFromBibLevel;
        }

        // Nothing worked -- time to set up a value of last resort!
        if (!$result) {
            // If LDR/07 indicates a "Collection" or "Sub-Unit," treat it as a kit
            // for now;
            // this is a rare case but helps cut down on the number of unknowns.
            if ($bibLevel === 'c' || $bibLevel === 'd') {
                $result[] = 'Kit';
            } elseif ($recordType === 'a') {
                // If LDR/06 indicates "Language material," map to "Text";
                // this helps cut down on the number of unknowns.
                $result[] = 'Text';
            } else {
                $result[] = 'Unknown';
            }
        }

        return $result;
    }
}
