<?php

/**
 * MetadataUtils Class
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2011-2023.
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

namespace RecordManager\Base\Utils;

use RecordManager\Base\Record\AbstractRecord;

/**
 * MetadataUtils Class
 *
 * This class contains a collection of helper functions for metadata
 * processing
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MetadataUtils
{
    /**
     * Logger
     *
     * @var \RecordManager\Base\Utils\Logger
     */
    protected $logger;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Base path for referenced files
     *
     * @var string
     */
    protected $basePath;

    /**
     * Title prefixes that, when encountered, will cause the full title to be
     * returned as title key.
     *
     * @var array
     */
    protected $fullTitlePrefixes = [];

    /**
     * Abbreviations that require the following period to be retained.
     *
     * @var array
     */
    protected $abbreviations = [];

    /**
     * Articles that should be removed from the beginning of sort keys.
     *
     * @var array
     */
    protected $articles = [];

    /**
     * Non-electronic article formats
     *
     * @var array
     */
    protected $articleFormats = null;

    /**
     * Electronic article formats
     *
     * @var array
     */
    protected $eArticleFormats = null;

    /**
     * All article formats
     *
     * @var array
     */
    protected $allArticleFormats = null;

    /**
     * Unicode normalization form
     *
     * @var string
     */
    protected $unicodeNormalizationForm = '';

    /**
     * Whether to convert all language strings to lowercase
     *
     * @var bool
     */
    protected $lowercaseLanguageStrings = true;

    /**
     * Normalization character folding table
     *
     * @var array
     */
    protected $foldingTable = [
        'Š' => 'S', 'š' => 's', 'Ž' => 'Z', 'ž' => 'z', 'À' => 'A',
        'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'Æ' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E',
        'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
        'Ö' => 'O', 'Ø' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U',
        'Ü' => 'U', 'Ý' => 'Y', 'Þ' => 'B', 'ß' => 'Ss', 'à' => 'a',
        'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'æ' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
        'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ð' => 'o', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
        'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u',
        'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y',
    ];

    /**
     * UNICODE folding rules for keys
     *
     * @var string
     */
    protected $keyFoldingRules
        = ':: NFD; :: lower; :: Latin; :: [^[:letter:] [:number:]] Remove; :: NFKC;';

    /**
     * Transliterator for folding keys
     *
     * @var ?\Transliterator
     */
    protected $keyFoldingTransliterator = null;

    /**
     * Constructor
     *
     * @param string $basePath Base path for referenced files
     * @param array  $config   Main configuration
     * @param Logger $logger   Logger
     *
     * @psalm-suppress DuplicateArrayKey
     */
    public function __construct(
        string $basePath,
        array $config,
        Logger $logger
    ) {
        $this->basePath = $basePath;
        $this->config = $config;
        $this->logger = $logger;

        // Set things up before normalizeKey is used below:
        if (isset($config['Site']['key_folding_rules'])) {
            $this->keyFoldingRules = $config['Site']['key_folding_rules'];
        }

        if (isset($config['Site']['full_title_prefixes'])) {
            $this->fullTitlePrefixes = array_map(
                [$this, 'normalizeKey'],
                file(
                    RECMAN_BASE_PATH
                        . "/conf/{$config['Site']['full_title_prefixes']}",
                    FILE_IGNORE_NEW_LINES
                )
            );
        }

        // Read the abbreviations file
        $this->abbreviations = array_flip(
            $this->readListFile($config['Site']['abbreviations'] ?? '')
        );

        // Read the artices file
        $this->articles = array_map(
            function ($s) {
                return [
                    'article' => mb_strtolower($s, 'UTF-8'),
                    'length' => mb_strlen($s, 'UTF-8'),
                ];
            },
            $this->readListFile($config['Site']['articles'] ?? '')
        );

        $this->articleFormats
            = (array)($config['Solr']['article_formats'] ?? ['Article']);

        $this->eArticleFormats
            = (array)($config['Solr']['earticle_formats'] ?? ['eArticle']);

        $this->allArticleFormats = [
            ...$this->articleFormats,
            ...$this->eArticleFormats,
        ];

        $this->unicodeNormalizationForm
            = $config['Site']['unicode_normalization_form'] ?? '';

        $this->lowercaseLanguageStrings
            = $config['Site']['lowercase_language_strings'] ?? true;

        if (!empty($config['Site']['folding_ignore_characters'])) {
            $chars = preg_split(
                '//u',
                $config['Site']['folding_ignore_characters'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            foreach ($chars as $c) {
                if (isset($this->foldingTable[$c])) {
                    unset($this->foldingTable[$c]);
                }
            }
        }
    }

    /**
     * Set the logger
     *
     * @param Logger $logger Logger
     *
     * @return void
     */
    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Convert ISBN-10 (without dashes) to ISBN-13
     *
     * @param string $isbn ISBN without dashes
     *
     * @return false|string Resulting ISBN or false for invalid ISBN
     */
    public function isbn10to13($isbn)
    {
        if (!preg_match('{^([0-9]{9})[0-9xX]$}', $isbn, $matches)) {
            // Invalid ISBN
            return false;
        }

        // Check that the 10 digit ISBN is valid
        $checkChar = $this->calculateIsbn10CheckChar($isbn);
        if ($isbn[9] !== $checkChar && ($checkChar !== 'X' || $isbn[9] !== 'x')) {
            return false;
        }

        return '978' . $matches[1] . $this->calculateIsbn13CheckDigit($isbn);
    }

    /**
     * Convert coordinates in different formats to decimal.
     *
     * Supported formats (with some intentional leniency):
     * [EWSN]DDDMMSS[.sss]
     * [EWSN+-]DDD.DDDDDD
     * [EWSN]DDDMM.MMMM
     * [EWSN+-]D[...].D[...]
     *
     * @param string $value Coordinates
     *
     * @return float
     */
    public function coordinateToDecimal($value)
    {
        $value = str_replace(' ', '', $value);
        if ($value === '') {
            return NAN;
        }
        $match = preg_match(
            '/^([eEwWnNsS])(\d{3})(\d{2})((\d{2})(\.(\d{3}))?)/',
            $value,
            $matches
        );
        if ($match) {
            $dec = (float)$matches[2] + (float)$matches[3] / 60
                + (float)$matches[4] / 3600;
            if (in_array($matches[1], ['w', 'W', 's', 'S'])) {
                return -$dec;
            }
            return $dec;
        }
        if (preg_match('/^([eEwWnNsS+-])?(\d{3}\.\d+)/', $value, $matches)) {
            $dec = (float)$matches[2];
            if (in_array($matches[1], ['w', 'W', 's', 'S', '-'])) {
                return -$dec;
            }
            return $dec;
        }
        if (
            preg_match('/^([eEwWnNsS])?(\d{3})(\d{2}\.\d+)/', $value, $matches)
        ) {
            $dec = (float)$matches[2] + (float)$matches[3] / 60;
            if (in_array($matches[1], ['w', 'W', 's', 'S'])) {
                return -$dec;
            }
            return $dec;
        }
        if (
            preg_match('/^([eEwWnNsS+-])?(\d+\.\d+)/', $value, $matches)
        ) {
            $dec = (float)$matches[2];
            if (in_array($matches[1], ['w', 'W', 's', 'S', '-'])) {
                return -$dec;
            }
            return $dec;
        }
        // Like the first one, but one last try for a value that's missing leading
        // zeros
        $match = preg_match(
            '/^([eEwWnNsS])(\d+)(\d{2})((\d{2})(\.(\d{3}))?)$/',
            $value,
            $matches
        );
        if ($match) {
            $dec = (float)$matches[2] + (float)$matches[3] / 60
                + (float)$matches[4] / 3600;
            if (in_array($matches[1], ['w', 'W', 's', 'S'])) {
                return -$dec;
            }
            return $dec;
        }
        return (float)$value;
    }

    /**
     * Create a normalized title key for dedup
     *
     * @param string $title Title
     * @param string $form  UNICODE normalization form
     *
     * @return string
     */
    public function createTitleKey($title, $form)
    {
        $full = false;
        if ($this->fullTitlePrefixes) {
            $normalTitle = $this->normalizeKey($title);
            foreach ($this->fullTitlePrefixes as $prefix) {
                if (
                    $prefix
                    && str_starts_with($normalTitle, $prefix)
                ) {
                    $full = true;
                    break;
                }
            }
        }

        $words = explode(' ', $title);
        $longWords = 0;
        $key = '';
        $keyLen = 0;
        foreach ($words as $word) {
            $key .= $word;
            $wordLen = mb_strlen($word);
            if ($wordLen > 3) {
                ++$longWords;
            }
            $keyLen += $wordLen; // significant chars
            if (!$full && ($longWords > 3 || $keyLen > 35)) {
                break;
            } elseif ($full && $keyLen > 100) {
                break;
            }
        }
        // Limit key length to 200 characters
        $key = substr($key, 0, 200);
        return $this->normalizeKey($key, $form);
    }

    /**
     * Normalize a string for comparison while using lowercasing and configured
     * UNICODE normalization form and/or folding rules.
     *
     * @param string $str  String to be normalized
     * @param string $form UNICODE normalization form to use
     *
     * @return string
     */
    public function normalizeKey($str, $form = 'NFKC')
    {
        // If a transliterator is available, use it and ignore everything else:
        if ($transliterator = $this->getKeyFoldingTransliterator()) {
            return $transliterator->transliterate($str);
        }

        $str = strtr($str, $this->foldingTable);
        $str = preg_replace(
            '/[\x00-\x20\x21-\x2F\x3A-\x40,\x5B-\x60,\x7B-\x7F]/',
            '',
            $str
        );
        if ('NFKC' !== $form) {
            $str = $this->normalizeUnicode($str, $form);
        }
        return mb_strtolower(trim($str), 'UTF-8');
    }

    /**
     * Normalize an ISBN to ISBN-13 without dashes
     *
     * @param string $isbn ISBN to normalize
     *
     * @return string Normalized ISBN or empty string
     */
    public function normalizeISBN($isbn)
    {
        $isbn = str_replace('-', '', $isbn);
        if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
            return '';
        }
        $isbn = $matches[1];
        if (strlen($isbn) === 10) {
            $isbn = $this->isbn10to13($isbn) ?: '';
        }
        return $isbn;
    }

    /**
     * Try to match two authors with at least last name and initial letter of first
     * name
     *
     * @param string $a1 LastName FirstName
     * @param string $a2 LastName FirstName
     *
     * @return bool
     */
    public function authorMatch($a1, $a2)
    {
        if ($a1 == $a2) {
            return true;
        }
        $a1l = mb_strlen($a1);
        $a2l = mb_strlen($a2);
        if ($a1l < 6 || $a2l < 6) {
            return false;
        }

        if (strncmp($a1, $a2, min($a1l, $a2l)) === 0) {
            return true;
        }

        $a1a = explode(' ', $a1);
        $a2a = explode(' ', $a2);
        $minCount = min(count($a1a), count($a2a));

        for ($i = 0; $i < $minCount; $i++) {
            if ($a1a[$i] != $a2a[$i]) {
                // First word needs to match
                if ($i == 0) {
                    return false;
                }
                // Otherwise at least the initial letter must match
                if (substr($a1a[$i], 0, 1) != substr($a2a[$i], 0, 1)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Check whether the string contains trailing punctuation characters
     *
     * @param string $str String to check
     *
     * @return boolean
     *
     * @psalm-suppress InvalidLiteralArgument
     */
    public function hasTrailingPunctuation($str)
    {
        $i = strlen($str) - 1;
        if ($i < 0) {
            return false;
        }
        while ($i > 0 && $str[$i] == ' ') {
            --$i;
        }
        $c = $str[$i];
        $punctuation = str_contains('/:;,=([', $c);
        if (!$punctuation) {
            $punctuation = str_ends_with($str, '.') && substr($str, -3, 1) !== ' ';
        }
        return $punctuation;
    }

    /**
     * Strip all punctuation characters from a string
     *
     * @param string  $str                     String to strip
     * @param ?string $punctuation             Regular expression matching
     *                                         punctuation or null for default
     * @param bool    $preservePunctuationOnly Return the original string if it
     *                                         contains only punctuation
     *
     * @return string
     */
    public function stripPunctuation(
        string $str,
        ?string $punctuation = null,
        bool $preservePunctuationOnly = true
    ) {
        $punctuation ??= '[\\t\\p{P}=´`” ̈]+';
        // Use preg_replace for multibyte support
        $result = preg_replace(
            '/' . $punctuation . '/u',
            ' ',
            $str
        );
        if (null === $result) {
            // Possibly invalid UTF-8, log and return:
            $this->logger->logError(
                'stripPunctuation',
                "Failed to replace punctuation for '$str': " . preg_last_error_msg()
            );
            return $str;
        }
        $result = trim($result);
        if ($preservePunctuationOnly && '' === $result) {
            return $str;
        }
        return $result;
    }

    /**
     * Strip trailing spaces and punctuation characters from a string
     *
     * @param string $str                     String to strip
     * @param string $additional              Additional chars to strip
     * @param bool   $preservePunctuationOnly Return the original string if it
     *                                        contains only punctuation
     *
     * @return string
     */
    public function stripTrailingPunctuation(
        string $str,
        string $additional = '',
        bool $preservePunctuationOnly = false
    ): string {
        $originalStr = $str;
        $basic = ' /:;,=([';
        if ($additional) {
            // Use preg_replace for multibyte support
            $str = preg_replace(
                '/[' . preg_quote($basic . $additional, '/') . ']*$/u',
                '',
                $str
            );
            if (null === $str) {
                // Possibly invalid UTF-8, log and return:
                $this->logger->logError(
                    'stripLeadingPunctuation',
                    "Failed to replace punctuation for '$originalStr': "
                    . preg_last_error_msg()
                );
                return $originalStr;
            }
        } else {
            $str = rtrim($str, $basic);
        }

        // Don't replace an initial letter or an abbreviation followed by period
        // (e.g. string "Smith, A.")
        if (substr($str, -1) == '.' && substr($str, -3, 1) != ' ') {
            $p = strrpos($str, ' ');
            if ($p > 0) {
                $lastWord = substr($str, $p + 1, -1);
            } else {
                $lastWord = substr($str, 0, -1);
            }
            if (
                !is_numeric($lastWord)
                && !isset($this->abbreviations[mb_strtolower($lastWord, 'UTF-8')])
            ) {
                $str = substr($str, 0, -1);
            }
        }
        if (substr($str, -3) == '. -') {
            $str = substr($str, 0, -3);
        }
        // Remove trailing parenthesis and square backets if they don't have
        // counterparts
        $last = substr($str, -1);
        if (
            ($last == ')' && !str_contains($str, '('))
            || ($last == ']' && !str_contains($str, '['))
        ) {
            $str = substr($str, 0, -1);
        }

        if ($preservePunctuationOnly && '' === $str) {
            return $originalStr;
        }
        return $str;
    }

    /**
     * Strip leading spaces and punctuation characters from a string
     *
     * @param string  $str                     String to strip
     * @param ?string $punctuation             String of punctuation characters or
     *                                         null for default
     * @param bool    $preservePunctuationOnly Return the original string if it
     *                                         contains only punctuation
     *
     * @return string
     */
    public function stripLeadingPunctuation(
        string $str,
        ?string $punctuation = null,
        bool $preservePunctuationOnly = true
    ) {
        $punctuation ??= " \t\\#*!¡?/:;.,=(['\"´`” ̈";
        // Use preg_replace for multibyte support
        $result = preg_replace(
            '/^[' . preg_quote($punctuation, '/') . ']*/u',
            '',
            $str
        );
        if (null === $result) {
            // Possibly invalid UTF-8, log and return:
            $this->logger->logError(
                'stripLeadingPunctuation',
                "Failed to replace punctuation for '$str': " . preg_last_error_msg()
            );
            return $str;
        }
        if ($preservePunctuationOnly && '' === $result) {
            return $str;
        }
        return $result;
    }

    /**
     * Strip leading article from a title
     *
     * @param string $str Title string
     *
     * @return string Modified title string
     */
    public function stripLeadingArticle($str)
    {
        $str = mb_strtolower($str, 'UTF-8');
        foreach ($this->articles as $article) {
            if (mb_substr($str, 0, $article['length']) === $article['article']) {
                $str = mb_substr($str, $article['length']);
                break;
            }
        }
        return $str;
    }

    /**
     * Create a sort title
     *
     * @param string $title        Title
     * @param bool   $stripArticle Whether to strip any leading article
     *
     * @return string
     */
    public function createSortTitle(string $title, bool $stripArticle = true): string
    {
        if ($stripArticle) {
            $title = $this->stripLeadingArticle($title);
        }
        $titleStart = mb_substr($title, 0, 1, 'UTF-8');
        $title = $this->stripPunctuation($title);
        // Strip article again just in case punctuation made a difference:
        if ($stripArticle && mb_substr($title, 0, 1, 'UTF-8') !== $titleStart) {
            $title = $this->stripLeadingArticle($title);
        }
        $title = mb_strtolower($title, 'UTF-8');
        return $title;
    }

    /**
     * Case-insensitive array_unique
     *
     * @param array $array Array
     *
     * @return array
     */
    // @codingStandardsIgnoreStart
    public function array_iunique($array)
    {
        // This one handles UTF-8 properly, but mb_strtolower is SLOW
        $map = [];
        foreach ($array as $key => $value) {
            $mb = preg_match('/[\x80-\xFF]/', $value); //mb_detect_encoding($value, 'ASCII', true);
            $map[$key] = $mb ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        }
        return array_intersect_key($array, array_unique($map));
        //return array_intersect_key($array, array_unique(array_map('strtolower', $array)));
    }

    // @codingStandardsIgnoreEnd

    /**
     * Try to find the important numeric part from a record ID to sort by
     *
     * @param string $id Record ID
     *
     * @return string Sort key
     */
    public function createIdSortKey($id)
    {
        if (preg_match('/^\w*(\d+)$/', $id, $matches)) {
            return $matches[1];
        }
        return $id;
    }

    /**
     * Validate a date in yyyy-mm-dd format.
     *
     * @param string $date Date to validate
     *
     * @return boolean|int False if invalid, resulting time otherwise
     */
    public function validateDate($date)
    {
        $found = preg_match(
            '/^(\-?\d{4})-(\d{2})-(\d{2})$/',
            $date,
            $parts
        );
        if (!$found) {
            return false;
        }
        if (
            $parts[2] < 1 || $parts[2] > 12
            || $parts[3] < 1 || $parts[3] > 31
        ) {
            return false;
        }
        // Since strtotime is quite clever in interpreting bad dates too, convert
        // back to make sure the interpretation was correct.
        $resultDate = strtotime($date);
        $convertedDate = date('Y-m-d', $resultDate);
        return $convertedDate == $date ? $resultDate : false;
    }

    /**
     * Validate a date in ISO8601 format.
     *
     * @param string $date Date to validate
     *
     * @return boolean|int False if invalid, resulting time otherwise
     */
    public function validateISO8601Date($date)
    {
        $found = preg_match(
            '/^(\-?\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/',
            $date,
            $parts
        );
        if (!$found) {
            return false;
        }
        if (
            $parts[2] < 1 || $parts[2] > 12
            || $parts[3] < 1 || $parts[3] > 31
            || $parts[4] < 0 || $parts[4] > 23
            || $parts[5] < 0 || $parts[5] > 59
            || $parts[6] < 0 || $parts[6] > 59
        ) {
            return false;
        }
        // Since strtotime is quite clever in interpreting bad dates too, convert
        // back to make sure the interpretation was correct.
        $resultDate = strtotime($date);
        return gmdate('Y-m-d\TH:i:s\Z', $resultDate) == $date
            ? $resultDate : false;
    }

    /**
     * Trim whitespace between tags (but not in data)
     *
     * @param string $xml XML string
     *
     * @return string Cleaned string
     */
    public function trimXMLWhitespace($xml)
    {
        return preg_replace('~\s*(<([^>]*)>[^<]*</\2>|<[^>]*>)\s*~', '$1', $xml);
    }

    /**
     * Get record metadata from a database record
     *
     * @param array $record     Database record
     * @param bool  $normalized Whether to return the original (false) or
     *                          normalized (true) record
     *
     * @return string Metadata as a string
     */
    public function getRecordData(&$record, $normalized)
    {
        if ($normalized) {
            $data = $record['normalized_data']
                ? $record['normalized_data']
                : $record['original_data'];
        } else {
            $data = $record['original_data'];
        }
        return is_object($data) ? gzinflate($data->getData()) : $data;
    }

    /**
     * Create a timestamp string from the given unix timestamp
     *
     * @param ?int $timestamp Unix timestamp
     *
     * @return string Formatted string
     */
    public function formatTimestamp($timestamp)
    {
        $date = new \DateTime('', new \DateTimeZone('UTC'));
        $date->setTimeStamp($timestamp ?? 0);
        return $date->format('Y-m-d') . 'T' . $date->format('H:i:s') . 'Z';
    }

    /**
     * Extract year from a date string
     *
     * @param string $str Date string
     *
     * @return string Year
     */
    public function extractYear($str)
    {
        $matches = [];
        if (preg_match('/(\-?\d{4})/', $str, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Convert first character of string to upper case (mb aware)
     *
     * @param string|string[] $str String to be converted
     *
     * @return string|string[] Converted string
     */
    public function ucFirst($str)
    {
        if (is_array($str)) {
            foreach ($str as &$s) {
                $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
            }
            return $str;
        }
        return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
    }

    /**
     * Normalize string to one of the UNICODE normalization forms
     *
     * @param string $str  String to normalize
     * @param string $form Normalization form
     *
     * @return string Normalized string
     *
     * @psalm-suppress TypeDoesNotContainType, RedundantCondition
     */
    public function normalizeUnicode($str, $form)
    {
        $forms = [
            'NFC' => \Normalizer::FORM_C,
            'NFD' => \Normalizer::FORM_D,
            'NFKC' => \Normalizer::FORM_KC,
            'NFKD' => \Normalizer::FORM_KD,
        ];

        if (empty($str)) {
            return $str;
        }
        $result = \Normalizer::normalize($str, $forms[$form] ?? \Normalizer::FORM_C);
        return $result === false ? '' : $result;
    }

    /**
     * Trim for arrays
     *
     * @param string[] $array Array of strings to trim
     * @param string   $chars Characters to trim
     *
     * @return array Trimmed array
     */
    public function arrayTrim($array, $chars = " \t\n\r\0\x0B")
    {
        array_walk(
            $array,
            function (&$val, $key, $chars) {
                $val = trim($val, $chars);
            },
            $chars
        );
        return $array;
    }

    /**
     * Split title to main title and description. Tries to find the first sentence
     * break where the title can be split.
     *
     * @param string $title Title to split
     *
     * @return null|string Null if title was not split, otherwise the initial
     * title part
     */
    public function splitTitle($title)
    {
        $i = 0;
        $parenLevel = 0;
        $bracketLevel = 0;
        // Make sure the title has single spaces for whitespace
        $title = preg_replace('/\s+/', ' ', $title);
        $titleWords = explode(' ', $title);
        foreach ($titleWords as $word) {
            ++$i;
            $parenLevel += substr_count($word, '(');
            $parenLevel -= substr_count($word, ')');
            $bracketLevel += substr_count($word, '[');
            $bracketLevel -= substr_count($word, ']');
            if ($parenLevel == 0 && $bracketLevel == 0) {
                // Try to avoid splitting at short words or the very beginning
                if (
                    substr($word, -1) == '.' && strlen($word) > 2
                    && ($i > 1 || strlen($word) > 4)
                ) {
                    // Verify that the word is strippable (not abbreviation etc.)
                    $leadStripped = $this->stripLeadingPunctuation(
                        $word
                    );
                    $stripped = $this->stripTrailingPunctuation(
                        $leadStripped
                    );
                    $nextFirst = isset($titleWords[$i])
                        ? substr($titleWords[$i], 0, 1)
                        : '';
                    // 1.) There has to be something following this word.
                    // 2.) The trailing period must be strippable or end with a year.
                    // 3.) Next word has to start with a capital or digit
                    // 4.) Not something like 12-p.
                    // 5.) Not initials like A.N.
                    if (
                        $nextFirst
                        && ($leadStripped != $stripped
                        || preg_match('/^\d{4}\.$/', $word))
                        && (is_numeric($nextFirst) || !ctype_lower($nextFirst))
                        && !preg_match('/.+\-\w{1,2}\.$/', $word)
                        && !preg_match('/^\w\.\w\.$/', $word) // initials
                    ) {
                        return $this->stripTrailingPunctuation(
                            implode(' ', array_splice($titleWords, 0, $i))
                        );
                    }
                }
            }
        }
        return null;
    }

    /**
     * Determine if a record is a hidden component part
     *
     * @param array          $settings       Data source settings
     * @param array          $record         Database record
     * @param AbstractRecord $metadataRecord Metadata record
     *
     * @return boolean
     */
    public function isHiddenComponentPart($settings, $record, $metadataRecord)
    {
        if (isset($record['host_record_id'])) {
            if ($settings['componentParts'] == 'merge_all') {
                return true;
            } elseif (
                $settings['componentParts'] == 'merge_non_articles'
                || $settings['componentParts'] == 'merge_non_earticles'
            ) {
                $format = $metadataRecord->getFormat();

                if (!in_array($format, $this->allArticleFormats)) {
                    return true;
                } elseif (in_array($format, $this->articleFormats)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Strip control characters from the string
     *
     * @param string $str String
     *
     * @return string
     */
    public function stripControlCharacters($str)
    {
        return str_replace(["\r", "\n", "\t"], '', $str);
    }

    /**
     * Get center coordinates (lon lat) for any WKT shapes
     *
     * @param string|array $wkt WKT shape(s)
     *
     * @return string Center coordinates
     */
    public function getCenterCoordinates($wkt)
    {
        if (!empty($wkt)) {
            $wkt = is_array($wkt) ? $wkt[0] : $wkt;
            $expr = '/ENVELOPE\s*\((-?[\d\.]+),\s*(-?[\d\.]+),\s*(-?[\d\.]+),'
                . '\s*(-?[\d\.]+)\)/i';
            if (preg_match($expr, $wkt, $matches)) {
                return (((float)$matches[1] + (float)$matches[2]) / 2) . ' '
                    . (((float)$matches[3] + (float)$matches[4]) / 2);
            }
            try {
                $item = \geoPHP::load($wkt, 'wkt');
            } catch (\Exception $e) {
                if (null !== $this->logger) {
                    $this->logger->logError(
                        'getCenterCoordinates',
                        "Could not parse WKT '$wkt': " . $e->getMessage()
                    );
                }
                return '';
            }
            $centroid = $item ? $item->centroid() : null;
            return $centroid ? $centroid->getX() . ' ' . $centroid->getY() : '';
        }
        return '';
    }

    /**
     * Get user-displayable coordinates for a shape
     *
     * @param string|array $wkt WKT shape(s)
     *
     * @return string Center coordinates
     */
    public function getGeoDisplayField($wkt)
    {
        if (!empty($wkt)) {
            $wkt = is_array($wkt) ? $wkt[0] : $wkt;
            $expr = '/ENVELOPE\s*\((-?[\d\.]+),\s*(-?[\d\.]+),\s*(-?[\d\.]+),'
                . '\s*(-?[\d\.]+)\)/i';
            if (preg_match($expr, $wkt, $matches)) {
                return $matches[1] . ' ' . $matches[2] . ' ' . $matches[3]
                    . ' ' . $matches[4];
            }
            try {
                $item = \geoPHP::load($wkt, 'wkt');
            } catch (\Exception $e) {
                if (null !== $this->logger) {
                    $this->logger->logError(
                        'getCenterCoordinates',
                        "Could not parse WKT '$wkt': " . $e->getMessage()
                    );
                }
                return '';
            }
            $centroid = $item ? $item->centroid() : null;
            return $centroid ? $centroid->getX() . ' ' . $centroid->getY() : '';
        }
        return '';
    }

    /**
     * Validate and normalize language strings. Return empty string or array if not
     * valid.
     *
     * @param mixed $languages Language or array of languages
     *
     * @return mixed
     */
    public function normalizeLanguageStrings($languages)
    {
        if (is_array($languages)) {
            foreach ($languages as &$language) {
                $language = $this->normalizeLanguageStrings($language);
            }
            return array_values(array_filter($languages));
        }
        $languages = trim($languages);
        if ($this->lowercaseLanguageStrings) {
            $languages = strtolower($languages);
        }
        return $languages;
    }

    /**
     * Normalize a relator code (role)
     *
     * @param string $relator Relator code
     *
     * @return string
     */
    public function normalizeRelator($relator)
    {
        $relator = trim($relator);
        $relator = preg_replace('/\p{P}+/u', '', $relator);
        $relator = mb_strtolower($relator, 'UTF-8');
        return $relator;
    }

    /**
     * Extract record source from an ID
     *
     * @param string $id Record ID
     *
     * @return string
     */
    public function getSourceFromId($id)
    {
        $parts = explode('.', $id, 2);
        return $parts[0];
    }

    /**
     * Load XML into SimpleXMLElement
     *
     * @param string $xml     XML
     * @param int    $options Additional libxml options (LIBXML_PARSEHUGE and
     *                        LIBXML_COMPACT are set by default)
     * @param string $errors  Any errors encountered
     *
     * @return \SimpleXMLElement
     */
    public function loadSimpleXML(
        $xml,
        $options = 0,
        &$errors = null
    ) {
        $xml = $this->loadXML($xml, null, $options, $errors);
        assert($xml instanceof \SimpleXMLElement);
        return $xml;
    }

    /**
     * Load XML into DOM or SimpleXMLElement (if $dom is null)
     *
     * @param string       $xml     XML
     * @param \DOMDocument $dom     DOM
     * @param int          $options Additional libxml options (LIBXML_PARSEHUGE and
     *                              LIBXML_COMPACT are set by default)
     * @param string       $errors  Any errors encountered
     *
     * @return \SimpleXMLElement|\DOMDocument|bool
     */
    public function loadXML(
        $xml,
        $dom = null,
        $options = 0,
        &$errors = null
    ) {
        $xml = trim($xml);
        $options |= LIBXML_PARSEHUGE | LIBXML_COMPACT;
        if (null === $errors) {
            return XmlSecurity::scan($xml, $dom, $options);
        }

        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $errors = '';
        $result = XmlSecurity::scan($xml, $dom, $options);
        if (false === $result || libxml_get_last_error() !== false) {
            $messageParts = [];
            foreach (libxml_get_errors() as $error) {
                $messageParts[] = '[' . $error->line . ':' . $error->column
                    . '] Error ' . $error->code . ': ' . $error->message;
            }
            $errors = implode('; ', $messageParts);
        }
        libxml_use_internal_errors($saveUseErrors);
        return $result;
    }

    /**
     * Convert author name in "First Last" format to "Last, First"
     *
     * @param string $author Author name
     *
     * @return string
     */
    public function convertAuthorLastFirst($author)
    {
        $p = strrpos($author, ' ');
        if ($p > 0) {
            $author = substr($author, $p + 1) . ', '
                . substr($author, 0, $p);
        }
        return $author;
    }

    /**
     * Get author initials
     *
     * Based on VuFind CreatorTools processInitials
     *
     * @param string $authorName Author name
     *
     * @return string
     */
    public function getAuthorInitials(string $authorName): string
    {
        // we guess that if there is a comma before the end - this is a personal name
        $p = strpos($authorName, ',');
        $isPersonalName = $p && $p < strlen($authorName) - 1;
        // get rid of non-alphabet chars but keep hyphens and accents
        $authorName = mb_strtolower(
            preg_replace('/[^\\p{L} -]/', '', $authorName),
            'UTF-8'
        );
        // Split into tokens on spaces:
        $names = explode(' ', $authorName);
        // If this is a personal name we'll reorganise to put lastname at the end:
        if ($isPersonalName) {
            $lastName = array_shift($names);
            $names[] = $lastName;
        }
        // Put all the initials together in a space separated string:
        $result = '';
        foreach ($names as $name) {
            if ('' !== $name) {
                $initial = mb_substr($name, 0, 1, 'UTF-8');
                // If there is a hyphenated name, use both initials:
                $p = mb_strpos($name, '-', 0, 'UTF-8');
                if ($p && $p < mb_strlen($name, 'UTF-8') - 1) {
                    $initial .= ' ' . mb_substr($name, $p + 1, 1, 'UTF-8');
                }
                $result .= " $initial";
            }
        }
        // Grab all initials and stick them together:
        $smushAll = str_replace(' ', '', $result);
        // If it's a long personal name, get all but the last initials as well
        // e.g. wb for william butler yeats:
        if (count($names) > 2 && $isPersonalName) {
            $smushPers = str_replace(' ', '', mb_substr($result, 0, -1, 'UTF-8'));
            $result .= ' ' . $smushPers;
        }
        // Now we have initials separate and together
        if (trim($result) !== $smushAll) {
            $result .= " $smushAll";
        }
        return trim($result);
    }

    /**
     * Get the transliterator for folding keys
     *
     * @return ?\Transliterator
     */
    protected function getKeyFoldingTransliterator(): ?\Transliterator
    {
        if (!$this->keyFoldingRules) {
            return null;
        }
        if (null === $this->keyFoldingTransliterator) {
            $this->keyFoldingTransliterator = \Transliterator::createFromRules(
                $this->keyFoldingRules
            );
        }
        return $this->keyFoldingTransliterator;
    }

    /**
     * Read a list file into an array
     *
     * @param string $filename List file name
     *
     * @return array
     */
    protected function readListFile($filename)
    {
        if ('' === $filename) {
            return [];
        }
        $filename = $this->basePath . "/conf/$filename";
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \Exception("Could not open list file '$filename'");
        }
        array_walk(
            $lines,
            function (&$value) {
                $start = 0;
                $end = null;
                if (str_starts_with($value, "'")) {
                    $start = 1;
                }
                if (str_ends_with($value, "'")) {
                    $end = -1;
                }
                if ($start || $end) {
                    $value = substr($value, $start, $end);
                }
            }
        );

        return $lines;
    }

    /**
     * Calculate check character for ISBN-10
     *
     * @param string $isbn ISBN-10
     *
     * @return string
     */
    protected function calculateIsbn10CheckChar(string $isbn): string
    {
        $sum = 0;
        for ($pos = 0, $mul = 10; $pos < 9; $pos++, $mul--) {
            $sum += $mul * (int)$isbn[$pos];
        }
        $checkChar = (11 - ($sum) % 11) % 11;
        if ($checkChar === 10) {
            $checkChar = 'X';
        }
        return (string)$checkChar;
    }

    /**
     * Calculate check digit for ISBN-13
     *
     * @param string $isbn ISBN-13
     *
     * @return string
     */
    protected function calculateIsbn13CheckDigit(string $isbn): string
    {
        $sum = 38 + 3 * ((int)$isbn[0] + (int)$isbn[2] + (int)$isbn[4]
            + (int)$isbn[6]
            + (int)$isbn[8])
            + (int)$isbn[1] + (int)$isbn[3] + (int)$isbn[5] + (int)$isbn[7];
        return (string)((10 - ($sum % 10)) % 10);
    }
}
