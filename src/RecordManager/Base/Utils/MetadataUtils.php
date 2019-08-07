<?php
/**
 * MetadataUtils Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2018.
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
namespace RecordManager\Base\Utils;

/**
 * MetadataUtils Class
 *
 * This class contains a collection of static helper functions for metadata
 * processing
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class MetadataUtils
{
    /**
     * Logger
     *
     * @var \RecordManager\Base\Utils\Logger
     */
    protected static $logger = null;

    /**
     * Title prefixes that, when encountered, will cause the full title to be
     * returned as title key.
     *
     * @var array
     */
    protected static $fullTitlePrefixes = null;

    /**
     * Abbreviations that require the following period to be retained.
     *
     * @var array
     */
    protected static $abbreviations = null;

    /**
     * Articles that should be removed from the beginning of sort keys.
     *
     * @var array
     */
    protected static $articles = null;

    /**
     * Non-electronic article formats
     *
     * @var array
     */
    protected static $articleFormats = null;

    /**
     * All article formats
     *
     * @var array
     */
    protected static $allArticleFormats = null;

    /**
     * Unicode normalization form
     *
     * @var string
     */
    protected static $unicodeNormalizationForm = '';

    /**
     * Whether to convert all language strings to lowercase
     *
     * @var bool
     */
    protected static $lowercaseLanguageStrings = true;

    /**
     * Normalization character folding table
     *
     * @var array
     */
    protected static $foldingTable = [
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
        'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'b', 'ÿ' => 'y'
    ];

    /**
     * Set the logger
     *
     * @param \RecordManager\Base\Utils\Logger $logger Logger
     *
     * @return void
     */
    public static function setLogger(\RecordManager\Base\Utils\Logger $logger)
    {
        self::$logger = $logger;
    }

    /**
     * Set the configuration
     *
     * @param array  $config   Main configuration
     * @param string $basePath Base path for finding config files
     *
     * @return void
     */
    public static function setConfig($config, $basePath)
    {
        if (isset($config['Site']['full_title_prefixes'])) {
            self::$fullTitlePrefixes = array_map(
                ['\RecordManager\Base\Utils\MetadataUtils', 'normalizeKey'],
                file(
                    "$basePath/conf/{$config['Site']['full_title_prefixes']}",
                    FILE_IGNORE_NEW_LINES
                )
            );
        }

        // Read the abbreviations file
        self::$abbreviations = isset($config['Site']['abbreviations'])
            ? self::readListFile($basePath, $config['Site']['abbreviations']) : [];

        // Read the artices file
        self::$articles = isset($config['Site']['articles'])
            ? self::readListFile($basePath, $config['Site']['articles']) : [];

        self::$articleFormats
            = isset($config['Solr']['article_formats'])
            ? $config['Solr']['article_formats']
            : ['Article'];

        $eArticleFormats
            = isset($config['Solr']['earticle_formats'])
            ? $config['Solr']['earticle_formats']
            : ['eArticle'];

        self::$allArticleFormats = array_merge(
            self::$articleFormats, $eArticleFormats
        );

        self::$unicodeNormalizationForm
            = isset($config['Solr']['unicode_normalization_form'])
            ? $config['Solr']['unicode_normalization_form']
            : '';

        self::$lowercaseLanguageStrings
            = isset($config['Site']['lowercase_language_strings'])
            ? $config['Site']['lowercase_language_strings']
            : true;

        if (!empty($config['Site']['folding_ignore_characters'])) {
            $chars = preg_split(
                '//u',
                $config['Site']['folding_ignore_characters'],
                null,
                PREG_SPLIT_NO_EMPTY
            );
            foreach ($chars as $c) {
                if (isset(self::$foldingTable[$c])) {
                    unset(self::$foldingTable[$c]);
                }
            }
        }
    }

    /**
     * Convert ISBN-10 (without dashes) to ISBN-13
     *
     * @param string $isbn ISBN
     *
     * @return boolean|string Resulting ISBN or false for invalid string
     */
    public static function isbn10to13($isbn)
    {
        if (!preg_match('{^([0-9]{9})[0-9xX]$}', $isbn, $matches)) {
            // number is not 10 digits
            return false;
        }

        $sum_of_digits = 38 + 3 * ($isbn{0} + $isbn{2} + $isbn{4} + $isbn{6}
            + $isbn{8}) + $isbn{1}
        + $isbn{3}
        + $isbn{5}
        + $isbn{7};

        $check_digit = (10 - ($sum_of_digits % 10)) % 10;

        return '978' . $matches[1] . $check_digit;
    }

    /**
     * Convert coordinates in [EWSN]DDDMMSS format to decimal
     *
     * @param string $value Coordinates
     *
     * @return float
     */
    public static function coordinateToDecimal($value)
    {
        if ($value === '') {
            return (float)NAN;
        }
        if (preg_match('/^([eEwWnNsS])(\d{3})(\d{2})(\d{2})/', $value, $matches)) {
            $dec = $matches[2] + $matches[3] / 60 + $matches[4] / 3600;
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
    public static function createTitleKey($title, $form)
    {
        $full = false;
        if (isset(MetadataUtils::$fullTitlePrefixes)) {
            $normalTitle = MetadataUtils::normalizeKey($title);
            foreach (MetadataUtils::$fullTitlePrefixes as $prefix) {
                if ($prefix
                    && strncmp($normalTitle, $prefix, strlen($prefix)) === 0
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
        return MetadataUtils::normalizeKey($key, $form);
    }

    /**
     * Normalize a string for comparison while using lowercasing and configured
     * UNICODE normalization form.
     *
     * @param string $str  String to be normalized
     * @param string $form UNICODE normalization form to use
     *
     * @return string
     */
    public static function normalizeKey($str, $form = 'NFKC')
    {
        $str = MetadataUtils::normalizeUnicode($str, 'NFKC');
        $str = strtr($str, self::$foldingTable);
        $str = preg_replace(
            '/[\x00-\x20\x21-\x2F\x3A-\x40,\x5B-\x60,\x7B-\x7F]/', '', $str
        );
        if ('NFKC' !== $form) {
            $str = MetadataUtils::normalizeUnicode($str, $form);
        }
        $str = mb_strtolower(trim($str), 'UTF-8');
        return $str;
    }

    /**
     * Normalize an ISBN to ISBN-13 without dashes
     *
     * @param string $isbn ISBN to normalize
     *
     * @return string Normalized ISBN or empty string
     */
    public static function normalizeISBN($isbn)
    {
        $isbn = str_replace('-', '', $isbn);
        if (!preg_match('{([0-9]{9,12}[0-9xX])}', $isbn, $matches)) {
            return '';
        }
        $isbn = $matches[1];
        if (strlen($isbn) == 10) {
            $isbn = MetadataUtils::isbn10to13($isbn);
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
    public static function authorMatch($a1, $a2)
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

        for ($i = 0; $i < min(count($a1a), count($a2a)); $i++) {
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
     */
    public static function hasTrailingPunctuation($str)
    {
        $i = strlen($str) - 1;
        if ($i < 0) {
            return false;
        }
        while ($i > 0 && $str[$i] == ' ') {
            --$i;
        }
        $c = $str[$i];
        $punctuation = strstr('/:;,=([', $c) !== false;
        if (!$punctuation) {
            $punctuation = substr($str, -1) == '.' && !substr($str, -3, 1) != ' ';
        }
        return $punctuation;
    }

    /**
     * Strip trailing spaces and punctuation characters from a string
     *
     * @param string $str        String to strip
     * @param string $additional Additional chars to strip
     *
     * @return string
     */
    public static function stripTrailingPunctuation($str, $additional = '')
    {
        $str = rtrim($str, ' /:;,=([' . $additional);

        // Don't replace an initial letter followed by period
        // (e.g. string "Smith, A.")
        if (substr($str, -1) == '.' && substr($str, -3, 1) != ' ') {
            $p = strrpos($str, ' ');
            if ($p > 0) {
                $lastWord = substr($str, $p + 1, -1);
            } else {
                $lastWord = substr($str, 0, -1);
            }
            if (!is_numeric($lastWord)
                && !in_array(strtolower($lastWord), MetadataUtils::$abbreviations)
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
        if (($last == ')' && strstr($str, '(') === false)
            || ($last == ']' && strstr($str, '[') === false)
        ) {
            $str = substr($str, 0, -1);
        }

        return $str;
    }

    /**
     * Strip leading spaces and punctuation characters from a string
     *
     * @param string $str         String to strip
     * @param string $punctuation String of punctuation characters
     *
     * @return string
     */
    public static function stripLeadingPunctuation(
        $str,
        $punctuation = " \t\\#*!¡?/:;.,=(['\"´`” ̈"
    ) {
        return ltrim($str, $punctuation);
    }

    /**
     * Strip leading article from a title
     *
     * @param string $str Title string
     *
     * @return string Modified title string
     */
    public static function stripLeadingArticle($str)
    {
        foreach (MetadataUtils::$articles as $article) {
            $len = strlen($article);
            if (strncasecmp($article, $str, $len) == 0) {
                $str = substr($str, $len);
                break;
            }
        }
        return $str;
    }

    /**
     * Case-insensitive array_unique
     *
     * @param array $array Array
     *
     * @return array
     */
    // @codingStandardsIgnoreStart
    public static function array_iunique($array)
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
    public static function createIdSortKey($id)
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
    public static function validateDate($date)
    {
        if (true
            && preg_match(
                '/^(\-?\d{4})-(\d{2})-(\d{2})$/',
                $date,
                $parts
            )
        ) {
            if ($parts[2] < 1 || $parts[2] > 12
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
        return false;
    }

    /**
     * Validate a date in ISO8601 format.
     *
     * @param string $date Date to validate
     *
     * @return boolean|int False if invalid, resulting time otherwise
     */
    public static function validateISO8601Date($date)
    {
        if (true
            && preg_match(
                '/^(\-?\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/',
                $date,
                $parts
            )
        ) {
            if ($parts[2] < 1 || $parts[2] > 12
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
        return false;
    }

    /**
     * Convert a date range to a Solr date range string,
     * e.g. [1970-01-01 TO 1981-01-01]
     *
     * @param array $range Start and end date
     *
     * @return string Start and end date in Solr format
     * @throws Exception
     */
    public static function dateRangeToStr($range)
    {
        if (!isset($range)) {
            return null;
        }
        $oldTZ = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $start = date('Y-m-d', strtotime($range[0]));
            $end = date('Y-m-d', strtotime($range[1]));
        } catch (\Exception $e) {
            date_default_timezone_set($oldTZ);
            throw $e;
        }
        date_default_timezone_set($oldTZ);

        return $start === $end ? $start : "[$start TO $end]";
    }

    /**
     * Trim whitespace between tags (but not in data)
     *
     * @param string $xml XML string
     *
     * @return string Cleaned string
     */
    public static function trimXMLWhitespace($xml)
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
    public static function getRecordData(&$record, $normalized)
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
     * @param int $timestamp Unix timestamp
     *
     * @return string Formatted string
     */
    public static function formatTimestamp($timestamp)
    {
        $date = new \DateTime('', new \DateTimeZone('UTC'));
        $date->setTimeStamp($timestamp);
        return $date->format('Y-m-d') . 'T' . $date->format('H:i:s') . 'Z';
    }

    /**
     * Extract year from a date string
     *
     * @param string $str Date string
     *
     * @return string Year
     */
    public static function extractYear($str)
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
    public static function ucFirst($str)
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
     */
    public static function normalizeUnicode($str, $form)
    {
        switch ($form) {
        case 'NFC':
            $str = \Normalizer::normalize($str, \Normalizer::FORM_C);
            break;
        case 'NFD':
            $str = \Normalizer::normalize($str, \Normalizer::FORM_D);
            break;
        case 'NFKC':
            $str = \Normalizer::normalize($str, \Normalizer::FORM_KC);
            break;
        case 'NFKD':
            $str = \Normalizer::normalize($str, \Normalizer::FORM_KD);
            break;
        }
        return $str === false ? '' : $str;
    }

    /**
     * Trim for arrays
     *
     * @param string[] $array Array of strings to trim
     * @param string   $chars Characters to trim
     *
     * @return array Trimmed array
     */
    public static function arrayTrim($array, $chars = " \t\n\r\0\x0B")
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
    public static function splitTitle($title)
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
                if (substr($word, -1) == '.' && strlen($word) > 2
                    && ($i > 1 || strlen($word) > 4)
                ) {
                    // Verify that the word is strippable (not abbreviation etc.)
                    $leadStripped = MetadataUtils::stripLeadingPunctuation(
                        $word
                    );
                    $stripped = MetadataUtils::stripTrailingPunctuation(
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
                    if ($nextFirst
                        && ($leadStripped != $stripped
                        || preg_match('/^\d{4}\.$/', $word))
                        && (is_numeric($nextFirst) || !ctype_lower($nextFirst))
                        && !preg_match('/.+\-\w{1,2}\.$/', $word)
                        && !preg_match('/^\w\.\w\.$/', $word) // initials
                    ) {
                        return  metadataUtils::stripTrailingPunctuation(
                            implode(' ', array_splice($titleWords, 0, $i))
                        );
                    }
                }
            }
        }
        return null;
    }

    /**
     * Make a string numerically sortable
     *
     * @param string $str String
     *
     * @return string
     */
    public static function createSortableString($str)
    {
        $str = preg_replace_callback(
            '/(\d+)/',
            function ($matches) {
                return strlen((int)$matches[1]) . $matches[1];
            },
            strtoupper($str)
        );
        return preg_replace('/\s{2,}/', ' ', $str);
    }

    /**
     * Determine if a record is a hidden component part
     *
     * @param array       $settings       Data source settings
     * @param array       $record         Mongo record
     * @param \BaseRecord $metadataRecord Metadata record
     *
     * @return boolean
     */
    public static function isHiddenComponentPart($settings, $record, $metadataRecord)
    {
        if (isset($record['host_record_id'])) {
            if ($settings['componentParts'] == 'merge_all') {
                return true;
            } elseif ($settings['componentParts'] == 'merge_non_articles'
                || $settings['componentParts'] == 'merge_non_earticles'
            ) {
                $format = $metadataRecord->getFormat();

                if (!in_array($format, MetadataUtils::$allArticleFormats)) {
                    return true;
                } elseif (in_array($format, MetadataUtils::$articleFormats)) {
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
    public static function stripControlCharacters($str)
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
    public static function getCenterCoordinates($wkt)
    {
        if (!empty($wkt)) {
            $wkt = is_array($wkt) ? $wkt[0] : $wkt;
            $expr = '/ENVELOPE\s*\((-?[\d\.]+),\s*(-?[\d\.]+),\s*(-?[\d\.]+),'
                . '\s*(-?[\d\.]+)\)/i';
            if (preg_match($expr, $wkt, $matches)) {
                return (($matches[1] + $matches[2]) / 2) . ' '
                    . (($matches[3] + $matches[4]) / 2);
            }
            try {
                $item = \geoPHP::load($wkt, 'wkt');
            } catch (\Exception $e) {
                if (null !== self::$logger) {
                    self::$logger->log(
                        'getCenterCoordinates',
                        "Could not parse WKT '$wkt': " . $e->getMessage(),
                        \RecordManager\Base\Utils\Logger::ERROR
                    );
                }
                return [];
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
    public static function normalizeLanguageStrings($languages)
    {
        if (is_array($languages)) {
            foreach ($languages as &$language) {
                $language = self::normalizeLanguageStrings($language);
            }
            return array_values(array_filter($languages));
        }
        $languages = trim($languages);
        if (self::$lowercaseLanguageStrings) {
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
    public static function normalizeRelator($relator)
    {
        $relator = trim($relator);
        $relator = preg_replace('/\p{P}+/u', '', $relator);
        $relator = mb_strtolower($relator, 'UTF-8');
        return $relator;
    }

    /**
     * Read a list file into an array
     *
     * @param string $basePath Base path
     * @param string $filename List file name
     *
     * @return array
     */
    protected static function readListFile($basePath, $filename)
    {
        $filename = "$basePath/conf/$filename";
        $lines = file($filename, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \Exception("Could not open list file '$filename'");
        }
        array_walk(
            $lines,
            function (&$value) {
                $value = trim($value, "'");
            }
        );

        return $lines;
    }
}
