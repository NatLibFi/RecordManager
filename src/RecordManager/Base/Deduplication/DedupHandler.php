<?php
/**
 * Deduplication Handler
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2019.
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
namespace RecordManager\Base\Deduplication;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Record\Factory as RecordFactory;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Deduplication handler
 *
 * This class provides the rules and functions for deduplication of records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class DedupHandler
{
    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * Record Factory
     *
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * Verbose mode
     *
     * @var bool
     */
    protected $verbose;

    /**
     * FieldMapper for format mapping
     *
     * @var FieldMapper
     */
    protected $fieldMapper;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceSettings;

    /**
     * Unicode normalization form for keys
     *
     * @var string
     */
    protected $normalizationForm;

    /**
     * Constructor
     *
     * @param Database      $db            Database
     * @param Logger        $log           Logger object
     * @param boolean       $verbose       Whether verbose output is enabled
     * @param string        $basePath      Base path
     * @param array         $mainConfig    Main configuration
     * @param array         $settings      Data source settings
     * @param RecordFactory $recordFactory Record factory
     */
    public function __construct(Database $db, Logger $log, $verbose, $basePath,
        $mainConfig, $settings, $recordFactory
    ) {
        $this->db = $db;
        $this->log = $log;
        $this->recordFactory = $recordFactory;
        $this->verbose = $verbose;
        $this->dataSourceSettings = $settings;
        $this->normalizationForm
            = isset($mainConfig['Site']['unicode_normalization_form'])
            ? $mainConfig['Site']['unicode_normalization_form'] : 'NFKC';

        $this->fieldMapper = new FieldMapper(
            $basePath,
            array_merge(
                isset($mainConfig['DefaultMappings'])
                ? $mainConfig['DefaultMappings'] : [],
                isset($mainConfig['Default Mappings'])
                ? $mainConfig['Default Mappings'] : []
            ),
            $settings
        );
    }

    /**
     * Verify dedup record consistency
     *
     * @param array $dedupRecord Dedup record
     *
     * @return array An array with a line per fixed record
     */
    public function checkDedupRecord($dedupRecord)
    {
        $results = [];
        $sources = [];
        foreach ((array)$dedupRecord['ids'] as $id) {
            $record = $this->db->getRecord($id);
            $sourceAlreadyExists = false;
            if ($record) {
                $source = $record['source_id'];
                $sourceAlreadyExists = isset($sources[$source]);
                $sources[$source] = true;
            }
            if (!$record
                || $sourceAlreadyExists
                || $dedupRecord['deleted']
                || $record['deleted']
                || count($dedupRecord['ids']) < 2
                || !isset($record['dedup_id'])
                || $record['dedup_id'] != $dedupRecord['_id']
            ) {
                if (!$record) {
                    $reason = 'record does not exist';
                } elseif ($sourceAlreadyExists) {
                    $reason = 'already deduplicated with a record from same source';
                } elseif ($dedupRecord['deleted']) {
                    $reason = 'dedup record deleted';
                } elseif ($record['deleted']) {
                    $reason = 'record deleted';
                } elseif (count($dedupRecord['ids']) < 2) {
                    $reason = 'single record in a dedup group';
                } elseif (!isset($record['dedup_id'])) {
                    $reason = 'record is missing dedup_id';
                } else {
                    $reason
                        = "record linked with dedup record '{$record['dedup_id']}'";
                }
                $this->removeFromDedupRecord($dedupRecord['_id'], $id);
                $this->db->updateRecords(
                    ['_id' => $id, 'deleted' => false],
                    ['update_needed' => true],
                    ['dedup_id' => 1]
                );
                $results[] = "Removed '$id' from dedup record "
                    . "'{$dedupRecord['_id']}' ($reason)";
            }
        }
        return $results;
    }

    /**
     * Verify record links
     *
     * @param array $record Record
     *
     * @return string Fix message or empty string for no problems
     */
    public function checkRecordLinks($record)
    {
        if (empty($record['dedup_id'])) {
            return '';
        }
        $id = $record['_id'];
        $dedupRecord = $this->db->getDedup($record['dedup_id']);

        if (!$dedupRecord) {
            $this->db->updateRecords(
                ['_id' => $id, 'deleted' => false],
                ['update_needed' => true],
                ['dedup_id' => 1]
            );
            return "Removed dedup_id {$record['dedup_id']} from record"
                . " $id (dedup record does not exist)";
        }

        if (!in_array($id, (array)$dedupRecord['ids'])) {
            $this->db->updateRecords(
                ['_id' => $id, 'deleted' => false],
                ['update_needed' => true],
                ['dedup_id' => 1]
            );
            return "Removed dedup_id {$record['dedup_id']} from record"
                . " $id (dedup record does not contain the id)";
        }
        return '';
    }

    /**
     * Update dedup candidate keys for the given record
     *
     * @param array  $record         Database record
     * @param object $metadataRecord Metadata record for the used format
     *
     * @return boolean Whether anything was changed
     */
    public function updateDedupCandidateKeys(&$record, $metadataRecord)
    {
        $result = false;

        $title = $metadataRecord->getTitle(true);
        $author = $metadataRecord->getMainAuthor();
        if ($title && $author) {
            $authorParts = preg_split('/,\s/', $author);
            $keys = [
                MetadataUtils::createTitleKey($title, $this->normalizationForm)
                . ' '
                . MetadataUtils::normalizeKey(
                    $authorParts[0], $this->normalizationForm
                )
            ];
        } else {
            $keys = [];
        }
        if (!isset($record['title_keys'])
            || !is_array($record['title_keys'])
            || array_diff($record['title_keys'], $keys)
        ) {
            $record['title_keys'] = $keys;
            $result = true;
        }
        if (empty($record['title_keys'])) {
            unset($record['title_keys']);
        }

        $keys = $metadataRecord->getISBNs();
        if (!isset($record['isbn_keys'])
            || !is_array($record['isbn_keys'])
            || array_diff($record['isbn_keys'], $keys)
        ) {
            $record['isbn_keys'] = $keys;
            $result = true;
        }
        if (empty($record['isbn_keys'])) {
            unset($record['isbn_keys']);
        }

        $keys = $metadataRecord->getUniqueIDs();
        if (!isset($record['id_keys'])
            || !is_array($record['id_keys'])
            || array_diff($record['id_keys'], $keys)
        ) {
            $record['id_keys'] = $keys;
            $result = true;
        }
        if (empty($record['id_keys'])) {
            unset($record['id_keys']);
        }

        return $result;
    }

    /**
     * Find a single duplicate for the given record and set a common dedup key to
     * both records
     *
     * @param array $record Database record
     *
     * @return boolean Whether a duplicate was found
     */
    public function dedupRecord($record)
    {
        if ($record['deleted']) {
            if (isset($record['dedup_id'])) {
                $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
                unset($record['dedup_id']);
            }
            $record['updated'] = $this->db->getTimestamp();
            $record['update_needed'] = false;
            $this->db->saveRecord($record);
            return false;
        }
        $startTime = microtime(true);
        if ($this->verbose) {
            echo 'Original ' . $record['_id'] . ":\n"
                . MetadataUtils::getRecordData($record, true) . "\n";
        }

        $origRecord = null;
        $matchRecords = [];
        $candidateCount = 0;

        $titleArray = isset($record['title_keys'])
            ? array_values(array_filter((array)$record['title_keys'])) : [];
        $isbnArray = isset($record['isbn_keys'])
            ? array_values(array_filter((array)$record['isbn_keys'])) : [];
        $idArray = isset($record['id_keys'])
            ? array_values(array_filter((array)$record['id_keys'])) : [];

        $rules = [
            [
                'type' => 'isbn_keys',
                'keys' => $isbnArray,
                'filters' => ['dedup_id' => ['$exists' => true]]
            ],
            [
                'type' => 'id_keys',
                'keys' => $idArray,
                'filters' => ['dedup_id' => ['$exists' => true]]
            ],
            [
                'type' => 'isbn_keys',
                'keys' => $isbnArray,
                'filters' => ['dedup_id' => ['$exists' => false]]
            ],
            [
                'type' => 'id_keys',
                'keys' => $idArray,
                'filters' => ['dedup_id' => ['$exists' => false]]
            ],
            [
                'type' => 'title_keys',
                'keys' => $titleArray,
                'filters' => ['dedup_id' => ['$exists' => true]]
            ],
            [
                'type' => 'title_keys',
                'keys' => $titleArray,
                'filters' => ['dedup_id' => ['$exists' => false]]
            ],
        ];

        foreach ($rules as $rule) {
            if (!$rule['keys']) {
                continue;
            }
            $type = $rule['type'];

            if ($this->verbose) {
                echo "Search: $type => [" . implode(', ', $rule['keys']) . "]\n";
            }
            $params = [
                $type => ['$in' => $rule['keys']],
                'deleted' => false,
                'source_id' => ['$ne' => $record['source_id']]
            ];
            if (!empty($rule['filters'])) {
                $params += $rule['filters'];
            }
            $candidates = $this->db->findRecords(
                $params,
                [
                    'sort' => ['created' => 1],
                    'limit' => 101
                ]
            );
            $processed = 0;
            // Go through the candidates, try to match
            foreach ($candidates as $candidate) {
                // Don't bother with id or title dedup if ISBN dedup already
                // failed
                if ($type != 'isbn_keys') {
                    if (isset($candidate['isbn_keys'])) {
                        $sameKeys = array_intersect(
                            $isbnArray, (array)$candidate['isbn_keys']
                        );
                        if ($sameKeys) {
                            continue;
                        }
                    }
                    if ($type != 'id_keys' && isset($candidate['id_keys'])) {
                        $sameKeys = array_intersect(
                            $idArray, (array)$candidate['id_keys']
                        );
                        if ($sameKeys) {
                            continue;
                        }
                    }
                }
                ++$candidateCount;

                // Verify the candidate has not been deduped with this source yet
                $candidateDedupId = (string)($candidate['dedup_id'] ?? '');
                if ($candidateDedupId) {
                    // Check if we already have a candidate with the same dedup id
                    foreach ($matchRecords as $matchRecord) {
                        if (!empty($matchRecord['dedup_id'])
                            && (string)$matchRecord['dedup_id'] === $candidateDedupId
                        ) {
                            continue 2;
                        }
                    }
                    if ($this->db->findRecord(
                        [
                            'dedup_id' => $candidateDedupId,
                            'source_id' => $record['source_id']
                        ]
                    )
                    ) {
                        if ($this->verbose) {
                            echo "Candidate {$candidate['_id']} "
                                . "already deduplicated\n";
                        }
                        continue;
                    }
                }

                if (++$processed > 100) {
                    // Too many candidates, give up..
                    $this->log->log(
                        'dedupRecord',
                        "Too many candidates for record " . $record['_id']
                        . " with $type => [" . implode(', ', $rule['keys']) . ']',
                        Logger::DEBUG
                    );
                    break;
                }

                if (!isset($origRecord)) {
                    $origRecord = $this->recordFactory->createRecord(
                        $record['format'],
                        MetadataUtils::getRecordData($record, true),
                        $record['oai_id'],
                        $record['source_id']
                    );
                }
                if ($this->matchRecords($record, $origRecord, $candidate)) {
                    if ($this->verbose && ($processed > 300
                        || microtime(true) - $startTime > 0.7)
                    ) {
                        echo "Found match $type with candidate "
                            . "$processed in " . (microtime(true) - $startTime)
                            . "\n";
                    }
                    $matchRecords[] = $candidate;
                }
            }
        }

        if ($this->verbose && microtime(true) - $startTime > 0.2) {
            echo "Candidate search among $candidateCount records ("
                . count($matchRecords) . " matches) completed in "
                . (microtime(true) - $startTime) . "\n";
        }

        if ($matchRecords) {
            // Select the candidate with most records in the dedup group (if any)
            $bestMatch = null;
            $bestMatchRecords = 0;
            if (count($matchRecords) > 1) {
                $bestMatchCandidates = [];
                $dedupIdKeys = [];
                foreach ($matchRecords as $matchRecord) {
                    $dedupId = !empty($matchRecord['dedup_id']) ?
                        (string)$matchRecord['dedup_id'] : '';
                    if ($dedupId && !isset($bestMatchCandidates[$dedupId])
                    ) {
                        $bestMatchCandidates[$dedupId] = $matchRecord;
                        $dedupIdKeys[] = $matchRecord['dedup_id'];
                    }
                }
                if (count($bestMatchCandidates) > 1) {
                    $dedupRecords = $this->db->findDedups(
                        [
                            '_id' => ['$in' => $dedupIdKeys],
                            'deleted' => false
                        ]
                    );
                    $bestDedupId = '';
                    foreach ($dedupRecords as $dedupRecord) {
                        $cnt = count($dedupRecord['ids']);
                        $dedupId = (string)$dedupRecord['_id'];
                        if ($cnt > $bestMatchRecords || '' === $bestDedupId
                            || ($cnt === $bestMatchRecords
                            && strcmp($bestDedupId, $dedupId) > 0)
                        ) {
                            $bestMatchRecords = $cnt;
                            $bestDedupId = $dedupId;
                        }
                    }
                    if ($bestDedupId) {
                        $bestMatch = $bestMatchCandidates[$bestDedupId];
                    }
                }
            }
            if ($this->verbose) {
                if ($bestMatchRecords) {
                    echo "DedupRecord among $candidateCount candidates found a match"
                        . " with $bestMatchRecords existing members in "
                        . (microtime(true) - $startTime) . "\n";
                } else {
                    echo "DedupRecord among $candidateCount candidates found a match"
                        . ' in ' . (microtime(true) - $startTime) . "\n";
                }
            }

            if (null === $bestMatch) {
                $bestMatch = $matchRecords[0];
            }
            $this->markDuplicates($record, $bestMatch);

            return true;
        }

        if (isset($record['dedup_id']) || $record['update_needed']) {
            if (isset($record['dedup_id'])) {
                $this->removeFromDedupRecord($record['dedup_id'], $record['_id']);
                unset($record['dedup_id']);
            }
            $record['updated'] = $this->db->getTimestamp();
            $record['update_needed'] = false;
            $this->db->saveRecord($record);
        }

        if ($this->verbose && microtime(true) - $startTime > 0.2) {
            echo "DedupRecord among $candidateCount records did not find a match"
                . " in " . (microtime(true) - $startTime) . "\n";
        }

        return false;
    }

    /**
     * Remove a record from a dedup record
     *
     * @param object $dedupId ObjectID of the dedup record
     * @param string $id      Record ID to remove
     *
     * @return void
     */
    public function removeFromDedupRecord($dedupId, $id)
    {
        $dedupRecord = $this->db->getDedup($dedupId);
        if (!$dedupRecord) {
            $this->log->log(
                'removeFromDedupRecord',
                "Found dangling reference to dedup record $dedupId",
                Logger::ERROR
            );
            return;
        }
        if (in_array($id, (array)$dedupRecord['ids'])) {
            $dedupRecord['ids'] = array_values(
                array_diff((array)$dedupRecord['ids'], [$id])
            );

            // If there is only one record remaining, remove dedup_id from it too
            if (count($dedupRecord['ids']) == 1) {
                $otherId = reset($dedupRecord['ids']);
                $dedupRecord['ids'] = [];
                $dedupRecord['deleted'] = true;

                $this->db->updateRecords(
                    ['_id' => $otherId, 'deleted' => false],
                    ['update_needed' => true],
                    ['dedup_id' => 1]
                );
            } elseif (empty($dedupRecord['ids'])) {
                // No records remaining => just mark dedup record deleted.
                // This shouldn't happen since dedup record should always contain
                // at least two records
                $dedupRecord['deleted'] = true;
            }
            $dedupRecord['changed'] = $this->db->getTimestamp();
            $this->db->saveDedup($dedupRecord);
        }
    }

    /**
     * Check if records are duplicate matches
     *
     * @param array  $record     Mongo record
     * @param object $origRecord Metadata record (from $record)
     * @param array  $candidate  Candidate Mongo record
     *
     * @return boolean
     */
    protected function matchRecords($record, $origRecord, $candidate)
    {
        $cRecord = $this->recordFactory->createRecord(
            $candidate['format'],
            MetadataUtils::getRecordData($candidate, true),
            $candidate['oai_id'],
            $candidate['source_id']
        );
        if ($this->verbose) {
            echo "\nCandidate " . $candidate['_id'] . ":\n"
                . MetadataUtils::getRecordData($candidate, true) . "\n";
        }

        $recordHidden = MetadataUtils::isHiddenComponentPart(
            $this->dataSourceSettings[$record['source_id']], $record, $origRecord
        );
        $candidateHidden = MetadataUtils::isHiddenComponentPart(
            $this->dataSourceSettings[$candidate['source_id']], $candidate, $cRecord
        );

        // Check that both records are hidden component parts or neither is
        if ($recordHidden != $candidateHidden) {
            if ($this->verbose) {
                if ($candidateHidden) {
                    echo "--Candidate is a hidden component part\n";
                } else {
                    echo "--Candidate is not a hidden component part\n";
                }
            }
            return false;
        }

        // Check access restrictions
        if ($cRecord->getAccessRestrictions() != $origRecord->getAccessRestrictions()
        ) {
            if ($this->verbose) {
                echo "--Candidate has different access restrictions\n";
            }
            return false;
        }

        // Check format
        $origFormat = $origRecord->getFormat();
        $cFormat = $cRecord->getFormat();
        $origMapped = $this->fieldMapper->mapFormat(
            $record['source_id'], $origFormat
        );
        $cMapped = $this->fieldMapper->mapFormat($candidate['source_id'], $cFormat);
        if ($origFormat != $cFormat && $origMapped != $cMapped) {
            if ($this->verbose) {
                echo "--Format mismatch: $origFormat != $cFormat "
                    . "and $origMapped != $cMapped\n";
            }
            return false;
        }

        // Check for common ISBN
        $origISBNs = $origRecord->getISBNs();
        $cISBNs = $cRecord->getISBNs();
        $isect = array_intersect($origISBNs, $cISBNs);
        if (!empty($isect)) {
            // Shared ISBN -> match
            if ($this->verbose) {
                echo "++ISBN match:\n";
                print_r($origISBNs);
                print_r($cISBNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true;
        }

        // Check for other common ID (e.g. NBN)
        $origIDs = $origRecord->getUniqueIDs();
        $cIDs = $cRecord->getUniqueIDs();
        $isect = array_intersect($origIDs, $cIDs);
        if (!empty($isect)) {
            // Shared ID -> match
            if ($this->verbose) {
                echo "++ID match:\n";
                print_r($origIDs);
                print_r($cIDs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return true;
        }

        $origISSNs = $origRecord->getISSNs();
        $cISSNs = $cRecord->getISSNs();
        $commonISSNs = array_intersect($origISSNs, $cISSNs);
        if (!empty($origISSNs) && !empty($cISSNs) && empty($commonISSNs)) {
            // Both have ISSNs but none match
            if ($this->verbose) {
                echo "++ISSN mismatch:\n";
                print_r($origISSNs);
                print_r($cISSNs);
                echo $origRecord->getFullTitle() . "\n";
                echo $cRecord->getFullTitle() . "\n";
            }
            return false;
        }

        $origYear = $origRecord->getPublicationYear();
        $cYear = $cRecord->getPublicationYear();
        if ($origYear && $cYear && $origYear != $cYear) {
            if ($this->verbose) {
                echo "--Year mismatch: $origYear != $cYear\n";
            }
            return false;
        }
        $pages = $origRecord->getPageCount();
        $cPages = $cRecord->getPageCount();
        if ($pages && $cPages && abs($pages - $cPages) > 10) {
            if ($this->verbose) {
                echo "--Pages mismatch ($pages != $cPages)\n";
            }
            return false;
        }

        if ($origRecord->getSeriesISSN() != $cRecord->getSeriesISSN()) {
            return false;
        }
        if ($origRecord->getSeriesNumbering() != $cRecord->getSeriesNumbering()) {
            return false;
        }

        $origTitle = MetadataUtils::normalizeKey(
            $origRecord->getTitle(true), $this->normalizationForm
        );
        $cTitle = MetadataUtils::normalizeKey(
            $cRecord->getTitle(true), $this->normalizationForm
        );
        if (!$origTitle || !$cTitle) {
            // No title match without title...
            if ($this->verbose) {
                echo "No title - no further matching\n";
            }
            return false;
        }
        $lev = levenshtein(substr($origTitle, 0, 255), substr($cTitle, 0, 255));
        $lev = $lev / strlen($origTitle) * 100;
        if ($lev >= 10) {
            if ($this->verbose) {
                echo "--Title lev discard: $lev\nOriginal:  $origTitle\n"
                    . "Candidate: $cTitle\n";
            }
            return false;
        }

        $origAuthor = MetadataUtils::normalizeKey(
            $origRecord->getMainAuthor(), $this->normalizationForm
        );
        $cAuthor = MetadataUtils::normalizeKey(
            $cRecord->getMainAuthor(), $this->normalizationForm
        );
        $authorLev = 0;
        if ($origAuthor || $cAuthor) {
            if (!$origAuthor || !$cAuthor) {
                if ($this->verbose) {
                    echo "\nAuthor discard:\nOriginal:  $origAuthor\n"
                        . "Candidate: $cAuthor\n";
                }
                return false;
            }
            if (!MetadataUtils::authorMatch($origAuthor, $cAuthor)) {
                $authorLev = levenshtein(
                    substr($origAuthor, 0, 255), substr($cAuthor, 0, 255)
                );
                $authorLev = $authorLev / mb_strlen($origAuthor) * 100;
                if ($authorLev > 20) {
                    if ($this->verbose) {
                        echo "\nAuthor lev discard (lev: $lev, authorLev: "
                            . "$authorLev):\nOriginal:  $origAuthor\n"
                            . "Candidate: $cAuthor\n";
                    }
                    return false;
                }
            }
        }

        if ($this->verbose) {
            echo "\nTitle match (lev: $lev, authorLev: $authorLev):\n";
            echo $origRecord->getFullTitle() . "\n";
            echo "   $origAuthor - $origTitle.\n";
            echo $cRecord->getFullTitle() . "\n";
            echo "   $cAuthor - $cTitle.\n";
        }
        // We have a match!
        return true;
    }

    /**
     * Mark two records as duplicates
     *
     * @param array $rec1 Mongo record for which a duplicate was searched
     * @param array $rec2 Mongo record for the found duplicate
     *
     * @return void
     */
    protected function markDuplicates($rec1, $rec2)
    {
        // Reread the original record just in case it has changed in the meantime.
        $origRec1 = $rec1;
        $rec1 = $this->db->findRecord(['_id' => $rec1['_id'], 'deleted' => false]);
        if (null === $rec1) {
            $this->log->log(
                'markDuplicates',
                "Record {$origRec1['_id']} is no longer available",
                Logger::WARNING
            );
            return;
        }

        $setValues = [
            'updated' => $this->db->getTimestamp(),
            'update_needed' => false
        ];
        if (!empty($rec2['dedup_id'])) {
            if (!$this->addToDedupRecord($rec2['dedup_id'], $rec1['_id'])) {
                $this->removeFromDedupRecord($rec2['dedup_id'], $rec2['_id']);
                $rec2['dedup_id'] = $this->createDedupRecord(
                    $rec1['_id'], $rec2['_id']
                );
            }
            if (isset($rec1['dedup_id']) && $rec1['dedup_id'] != $rec2['dedup_id']) {
                $this->removeFromDedupRecord($rec1['dedup_id'], $rec1['_id']);
            }
            $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id'];
        } else {
            if (!empty($rec1['dedup_id'])) {
                if (!$this->addToDedupRecord($rec1['dedup_id'], $rec2['_id'])) {
                    $rec1['dedup_id'] = $this->createDedupRecord(
                        $rec1['_id'], $rec2['_id']
                    );
                }
                $setValues['dedup_id'] = $rec2['dedup_id'] = $rec1['dedup_id'];
            } else {
                $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id']
                    = $this->createDedupRecord($rec1['_id'], $rec2['_id']);
            }
        }
        if ($this->verbose) {
            echo "Marking {$rec1['_id']} as duplicate with {$rec2['_id']} "
                . "with dedup id {$rec2['dedup_id']}\n";
        }

        if (!isset($rec1['host_record_id'])) {
            $count = $this->dedupComponentParts($rec1);
            if ($this->verbose && $count > 0) {
                echo "Deduplicated $count component parts for {$rec1['_id']}\n";
            }
        }

        $this->db->updateRecords(
            ['_id' => ['$in' => [$rec1['_id'], $rec2['_id']]]],
            $setValues
        );
    }

    /**
     * Create a new dedup record
     *
     * @param string $id1 ID of first record
     * @param string $id2 ID of second record
     *
     * @return MongoId ID of the dedup record
     */
    protected function createDedupRecord($id1, $id2)
    {
        $record = [
            'changed' => $this->db->getTimestamp(),
            'deleted' => false,
            'ids' => [
                $id1,
                $id2
             ]
        ];
        $record = $this->db->saveDedup($record);
        return $record['_id'];
    }

    /**
     * Add another record to an existing dedup record
     *
     * @param string $dedupId ID of the dedup record
     * @param string $id      Record ID to add
     *
     * @return bool Whether the dedup record was found and updated
     */
    protected function addToDedupRecord($dedupId, $id)
    {
        $record = $this->db->findDedup(['_id' => $dedupId, 'deleted' => false]);
        if (!$record) {
            return false;
        }
        $source = MetadataUtils::getSourceFromId($id);
        foreach ((array)$record['ids'] as $existingId) {
            if ($id !== $existingId
                && $source === MetadataUtils::getSourceFromId($existingId)
            ) {
                return false;
            }
        }
        if (!in_array($id, (array)$record['ids'])) {
            $record['changed'] = $this->db->getTimestamp();
            $record['ids'][] = $id;
            $this->db->saveDedup($record);
        }
        return true;
    }

    /**
     * Deduplicate component parts of a record
     *
     * Component part deduplication is special. It will only go through
     * component parts of other records deduplicated with the host record
     * and stops when it finds a set of component parts that match.
     *
     * @param array $hostRecord Mongo record for the host record
     *
     * @return integer Number of component parts deduplicated
     */
    protected function dedupComponentParts($hostRecord)
    {
        if ($this->verbose) {
            echo "Deduplicating component parts\n";
        }
        if (!$hostRecord['linking_id']) {
            $this->log->log(
                'dedupComponentParts', 'Linking ID missing from record '
                . $hostRecord['_id'], Logger::ERROR
            );
            return 0;
        }
        $components1 = $this->getComponentPartsSorted(
            $hostRecord['source_id'], $hostRecord['linking_id']
        );
        $component1count = count($components1);

        // Go through all other records with same dedup id and see if their
        // component parts match
        $marked = 0;
        $otherRecords = $this->db->findRecords(
            ['dedup_id' => $hostRecord['dedup_id'], 'deleted' => false]
        );
        foreach ($otherRecords as $otherRecord) {
            if ($otherRecord['source_id'] == $hostRecord['source_id']) {
                continue;
            }
            $components2 = $this->getComponentPartsSorted(
                $otherRecord['source_id'], $otherRecord['linking_id']
            );
            $component2count = count($components2);

            if ($component1count != $component2count) {
                $allMatch = false;
            } else {
                $allMatch = true;
                $idx = -1;
                foreach ($components1 as $component1) {
                    $component2 = $components2[++$idx];
                    if ($this->verbose) {
                        echo "Comparing {$component1['_id']} with "
                            . "{$component2['_id']}\n";
                    }
                    if ($this->verbose) {
                        echo 'Original ' . $component1['_id'] . ":\n"
                            . MetadataUtils::getRecordData($component1, true) . "\n";
                    }
                    $metadataComponent1 = $this->recordFactory->createRecord(
                        $component1['format'],
                        MetadataUtils::getRecordData($component1, true),
                        $component1['oai_id'],
                        $component1['source_id']
                    );
                    if (!$this->matchRecords(
                        $component1, $metadataComponent1, $component2
                    )
                    ) {
                        $allMatch = false;
                        break;
                    }
                }
            }

            if ($allMatch) {
                if ($this->verbose) {
                    echo microtime(true) . " All component parts match between "
                        . "{$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
                $idx = -1;
                foreach ($components1 as $component1) {
                    $component2 = $components2[++$idx];
                    $this->markDuplicates($component1, $component2);
                    ++$marked;
                }
                break;
            } else {
                if ($this->verbose) {
                    echo microtime(true) . " Not all component parts match between "
                        . "{$hostRecord['_id']} and {$otherRecord['_id']}\n";
                }
            }
        }
        return $marked;
    }

    /**
     * Get component parts in a sorted array
     *
     * @param string       $sourceId     Source ID
     * @param string|array $hostRecordId Host record IDs (doesn't include source id)
     *
     * @return array Array of component parts
     */
    protected function getComponentPartsSorted($sourceId, $hostRecordId)
    {
        $componentsIter = $this->db->findRecords(
            [
                'source_id' => $sourceId,
                'host_record_id' => ['$in' => (array)$hostRecordId]
            ]
        );
        $components = [];
        foreach ($componentsIter as $component) {
            $components[MetadataUtils::createIdSortKey($component['_id'])]
                = $component;
        }
        ksort($components);
        return array_values($components);
    }
}
