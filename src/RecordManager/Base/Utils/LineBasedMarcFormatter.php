<?php

/**
 * Line-based MARC formatter
 *
 * PHP version 8
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Utils;

/**
 * Line-based MARC formatter
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class LineBasedMarcFormatter
{
    /**
     * Count of bad characters encountered during most recent conversion.
     *
     * @var int
     */
    protected $badChars = 0;

    /**
     * Format definitions for line based MARC formats
     *
     * @var array
     */
    protected $lineBasedMarcFormats = [
        [
            'subfieldRegExp' => '/\$([a-z0-9])/',
        ],
        [
            'subfieldRegExp' => '/\|([a-z0-9]) /',
        ],
        [
            'subfieldRegExp' => '/‡([a-z0-9]) /',
        ],
    ];

    /**
     * Constructor
     *
     * @param ?array $formats Line-based MARC formats to recognize (null for default)
     */
    public function __construct(?array $formats = null)
    {
        if (!empty($formats)) {
            $this->lineBasedMarcFormats = $formats;
        }
    }

    /**
     * Get count of bad characters encountered during the last call to
     * convertLineBasedMarcToXml().
     *
     * @return int
     */
    public function getIllegalXmlCharacterCount(): int
    {
        return $this->badChars;
    }

    /**
     * Convert a line-based MARC record ("tagged" output) to MARCXML
     *
     * Supports formats from Alma MARC record view and OCLC tagged output
     *
     * @param string $metadata Metadata
     *
     * @return string
     */
    public function convertLineBasedMarcToXml(string $metadata): string
    {
        $xml = simplexml_load_string(
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n\n"
            . '<collection><record></record></collection>'
        );
        $record = $xml->record[0];

        // Determine subfield format:
        $delimCount = 0;
        $format = null;
        foreach ($this->lineBasedMarcFormats as $current) {
            preg_match_all($current['subfieldRegExp'] . 's', $metadata, $matches);
            $cnt = count($matches[1] ?? []);
            if (null === $format || $cnt > $delimCount) {
                $format = $current;
                $delimCount = $cnt;
            }
        }

        // Set up offsets from format config (if available):
        $contentOffset = $format['contentOffset'] ?? 4;
        $leaderOffset = $format['leaderOffset'] ?? 0;
        $ind1Offset = $format['ind1Offset'] ?? 4;
        $ind2Offset = $format['ind2Offset'] ?? 5;
        $firstSubfieldOffset = $format['firstSubfieldOffset'] ?? 7;

        foreach (explode("\n", $metadata) as $line) {
            $line = trim($line);
            if (isset($format['endOfLineMarker'])) {
                if (substr($line, -1) === $format['endOfLineMarker']) {
                    $line = substr($line, 0, strlen($line) - 1);
                }
            }
            if (!$line) {
                continue;
            }
            $tag = mb_substr($line, 0, 3, 'UTF-8');
            $content = mb_substr($line, $contentOffset, null, 'UTF-8');
            if (
                mb_substr($content, 0, 1, 'UTF-8') === "'"
                && mb_substr($content, -1, null, 'UTF-8') === "'"
            ) {
                $content = mb_substr($content, 1, -1, 'UTF-8');
            }
            if ('LDR' === $tag || '000' === $tag) {
                // Make sure leader is 24 characters:
                $leader = mb_substr($content, $leaderOffset, 24, 'UTF-8');
                while (mb_strlen($leader, 'UTF-8') < 24) {
                    $leader .= ' ';
                }
                $record->addChild('leader', htmlspecialchars($leader));
            } elseif (intval($tag) < 10) {
                $field = $record->addChild(
                    'controlfield',
                    htmlspecialchars($content, ENT_NOQUOTES)
                );
                $field->addAttribute('tag', $tag);
            } else {
                $ind1 = mb_substr($line, $ind1Offset, 1, 'UTF-8');
                if ('_' === $ind1) {
                    $ind1 = ' ';
                }
                $ind2 = mb_substr($line, $ind2Offset, 1, 'UTF-8');
                if ('_' === $ind2) {
                    $ind2 = ' ';
                }
                $field = $record->addChild('datafield');
                $field->addAttribute('tag', $tag);
                $field->addAttribute('ind1', $ind1);
                $field->addAttribute('ind2', $ind2);

                $subs = preg_split(
                    $format['subfieldRegExp'],
                    substr($content, $firstSubfieldOffset - $contentOffset),
                    -1,
                    PREG_SPLIT_DELIM_CAPTURE
                );
                array_shift($subs);
                while ($subs) {
                    $code = array_shift($subs);
                    $value = array_shift($subs);
                    if ('' === $value) {
                        continue;
                    }
                    $subfield = $field->addChild(
                        'subfield',
                        htmlspecialchars($value, ENT_NOQUOTES)
                    );
                    $subfield->addAttribute('code', $code);
                }
            }
        }
        // Strip illegal characters from XML, and save a count for reference:
        return preg_replace(
            '/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u',
            '',
            $record->asXML(),
            -1,
            $this->badChars
        );
    }
}
