<?php

/**
 * Deduplication Handler
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
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Deduplication;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Record\PluginManager as RecordPluginManager;
use RecordManager\Base\Utils\FieldMapper;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

use function count;
use function in_array;
use function sprintf;
use function strlen;

/**
 * Deduplication handler
 *
 * This class provides the rules and functions for deduplication of records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class DedupHandler implements DedupHandlerInterface
{
    use \RecordManager\Base\Record\CreateRecordTrait;

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
     * Record plugin manager
     *
     * @var RecordPluginManager
     */
    protected $recordPluginManager;

    /**
     * FieldMapper for format mapping
     *
     * @var FieldMapper
     */
    protected $fieldMapper;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Data source settings
     *
     * @var array
     */
    protected $dataSourceConfig;

    /**
     * Unicode normalization form for keys
     *
     * @var string
     */
    protected $normalizationForm;

    /**
     * Identifiers ignored in deduplication
     *
     * @var array
     */
    protected $ignoredIds = [];

    /**
     * Identifiers+titles ignored in deduplication
     *
     * @var array
     */
    protected $ignoredIdsAndTitles = [];

    /**
     * Metadata utilities
     *
     * @var MetadataUtils
     */
    protected $metadataUtils;

    /**
     * Constructor
     *
     * @param array               $config              Main configuration
     * @param array               $datasourceConfig    Data source settings
     * @param Database            $db                  Database
     * @param Logger              $log                 Logger object
     * @param RecordPluginManager $recordPluginManager Record plugin manager
     * @param FieldMapper         $fieldMapper         Field mapper
     * @param MetadataUtils       $metadataUtils       Metadata utilities
     */
    public function __construct(
        array $config,
        array $datasourceConfig,
        Database $db,
        Logger $log,
        RecordPluginManager $recordPluginManager,
        FieldMapper $fieldMapper,
        MetadataUtils $metadataUtils
    ) {
        $this->config = $config;
        $this->dataSourceConfig = $datasourceConfig;
        $this->db = $db;
        $this->log = $log;
        $this->recordPluginManager = $recordPluginManager;
        $this->fieldMapper = $fieldMapper;
        $this->metadataUtils = $metadataUtils;

        $this->normalizationForm
            = $config['Site']['unicode_normalization_form'] ?? 'NFKC';
        foreach ((array)($config['Deduplication']['ignored_ids'] ?? []) as $ignored) {
            $parts = explode('|', $ignored);
            $this->ignoredIds[] = $parts[0];
            $this->ignoredIdsAndTitles[] = [
                'id' => $parts[0],
                'title' => $parts[1] ?? '',
            ];
        }
    }

    /**
     * Verify dedup record consistency
     *
     * @param array $dedupRecord Dedup record
     * @param bool  $strictCheck Whether to do a thorough check for compatibility
     *                           between member records
     *
     * @return array An array with a line per fixed record
     */
    public function checkDedupRecord($dedupRecord, bool $strictCheck = false)
    {
        $results = [];
        $sources = [];
        if (!$dedupRecord['deleted'] && empty($dedupRecord['ids'])) {
            $dedupRecord['deleted'] = true;
            $dedupRecord['changed'] = $this->db->getTimestamp();
            $this->db->saveDedup($dedupRecord);
            return [
                "Marked dedup record '{$dedupRecord['_id']}' deleted (no records in"
                . ' non-deleted dedup record)',
            ];
        }
        $removed = [];
        $recordCache = [];
        $getCachedRecord = function ($id) use (&$recordCache) {
            if (!isset($recordCache[$id])) {
                $recordCache[$id] = $this->db->getRecord($id);
            }
            return $recordCache[$id];
        };
        foreach ((array)($dedupRecord['ids'] ?? []) as $id) {
            $problem = '';
            if (!isset($recordCache[$id])) {
                $recordCache[$id] = $this->db->getRecord($id);
            }
            $record = $getCachedRecord($id);
            $sourceAlreadyExists = false;
            if ($record) {
                $source = $record['source_id'];
                $sourceAlreadyExists = isset($sources[$source]);
                $sources[$source] = true;
            }

            if (!$record) {
                $problem = 'record does not exist';
            } elseif ($sourceAlreadyExists) {
                $problem = 'already deduplicated with a record from same source';
            } elseif ($dedupRecord['deleted']) {
                $problem = 'dedup record deleted';
            } elseif ($record['deleted']) {
                $problem = 'record deleted';
            } elseif (count($dedupRecord['ids']) < 2) {
                $problem = 'single record in a dedup group';
            } elseif (!isset($record['dedup_id'])) {
                $problem = 'record is missing dedup_id';
            } elseif ($record['dedup_id'] != $dedupRecord['_id']) {
                $problem
                    = "record linked with dedup record '{$record['dedup_id']}'";
            } elseif ($strictCheck) {
                // This is slower, so check only if there are no other issues:
                $metadataRecord = $this->createRecordFromDbRecord($record);

                foreach ((array)($dedupRecord['ids'] ?? []) as $otherId) {
                    if ($otherId === $id or in_array($otherId, $removed)) {
                        continue;
                    }
                    $otherRec = $getCachedRecord($otherId);
                    if (!$otherRec || $otherRec['deleted']) {
                        continue;
                    }
                    if (!$this->matchRecords($record, $metadataRecord, $otherRec)) {
                        $problem = "record does not match '{$otherRec['_id']}' in"
                            . ' dedup group';
                        break;
                    }
                }
            }

            if ($problem) {
                // Update records first to remove links to the dedup record
                $this->db->updateRecords(
                    ['_id' => $id, 'deleted' => false],
                    ['update_needed' => true],
                    ['dedup_id' => 1]
                );
                // Now update dedup record
                $this->removeFromDedupRecord($dedupRecord['_id'], $id);
                if (
                    isset($record['dedup_id'])
                    && $record['dedup_id'] != $dedupRecord['_id']
                ) {
                    $this->removeFromDedupRecord($record['dedup_id'], $id);
                }
                $results[] = "Removed '$id' from dedup record "
                    . "'{$dedupRecord['_id']}' ($problem)";
                $removed[] = $id;
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

        if (!in_array($id, (array)($dedupRecord['ids'] ?? []))) {
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
                $this->metadataUtils
                    ->createTitleKey($title, $this->normalizationForm)
                . ' '
                . $this->metadataUtils->normalizeKey(
                    $authorParts[0],
                    $this->normalizationForm
                ),
            ];
        } else {
            $keys = [];
        }
        $oldKeys = (array)($record['title_keys'] ?? []);
        if (count($oldKeys) !== count($keys) || array_diff($oldKeys, $keys)) {
            $record['title_keys'] = $keys;
            $result = true;
        }
        if (isset($record['title_keys']) && empty($record['title_keys'])) {
            unset($record['title_keys']);
        }

        $keys = $metadataRecord->getISBNs();
        $oldKeys = (array)($record['isbn_keys'] ?? []);
        if (count($oldKeys) !== count($keys) || array_diff($oldKeys, $keys)) {
            $record['isbn_keys'] = $keys;
            $result = true;
        }
        if (isset($record['isbn_keys']) && empty($record['isbn_keys'])) {
            unset($record['isbn_keys']);
        }

        $keys = $metadataRecord->getUniqueIDs();
        // Make sure bad metadata doesn't result in overly long keys
        $keys = array_map(
            function ($s) {
                return substr($s, 0, 200);
            },
            $keys
        );
        $oldKeys = (array)($record['id_keys'] ?? []);
        if (count($oldKeys) !== count($keys) || array_diff($oldKeys, $keys)) {
            $record['id_keys'] = $keys;
            $result = true;
        }
        if (isset($record['id_keys']) && empty($record['id_keys'])) {
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
        if (
            $record['deleted']
            || ($record['suppressed'] ?? false)
            || empty($this->dataSourceConfig[$record['source_id']]['dedup'])
        ) {
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
        $this->log->writelnVerbose('Deduplicating ' . $record['_id']);
        $this->log->writelnDebug(
            function () use ($record) {
                return $this->metadataUtils->getRecordData($record, true);
            }
        );

        $origRecord = null;
        $matchRecords = [];
        $noMatchRecordIds = [];
        $candidateCount = 0;

        $titleArray = $this->getTitleKeys($record);
        $isbnArray = $this->getIsbnKeys($record);
        $idArray = $this->getIdKeys($record);

        $rules = [
            [
                'type' => 'isbn_keys',
                'keys' => $isbnArray,
                'filters' => ['dedup_id' => ['$exists' => true]],
            ],
            [
                'type' => 'id_keys',
                'keys' => $idArray,
                'filters' => ['dedup_id' => ['$exists' => true]],
            ],
            [
                'type' => 'isbn_keys',
                'keys' => $isbnArray,
                'filters' => ['dedup_id' => ['$exists' => false]],
            ],
            [
                'type' => 'id_keys',
                'keys' => $idArray,
                'filters' => ['dedup_id' => ['$exists' => false]],
            ],
            [
                'type' => 'title_keys',
                'keys' => $titleArray,
                'filters' => ['dedup_id' => ['$exists' => true]],
            ],
            [
                'type' => 'title_keys',
                'keys' => $titleArray,
                'filters' => ['dedup_id' => ['$exists' => false]],
            ],
        ];

        foreach ($rules as $rule) {
            if (!$rule['keys']) {
                continue;
            }
            $type = $rule['type'];

            $this->log->writelnVerbose(
                "Search: $type => [" . implode(', ', $rule['keys']) . ']'
            );
            $params = [
                $type => ['$in' => $rule['keys']],
                'deleted' => false,
                'suppressed' => ['$in' => [null, false]],
                'source_id' => ['$ne' => $record['source_id']],
            ];
            $params += $rule['filters'];
            $candidates = $this->db->findRecords(
                $params,
                [
                    'sort' => ['created' => 1],
                    'limit' => 101,
                ]
            );
            $processed = 0;
            // Go through the candidates, try to match
            foreach ($candidates as $candidate) {
                // Check that the candidate is in a source that is configured for deduplication
                if (empty($this->dataSourceConfig[$candidate['source_id']]['dedup'])) {
                    continue;
                }

                // Check that we haven't already tried this candidate
                if (isset($noMatchRecordIds[$candidate['_id']])) {
                    continue;
                }
                ++$candidateCount;

                // Verify the candidate has not been deduped with this source yet
                $candidateDedupId = (string)($candidate['dedup_id'] ?? '');
                if ($candidateDedupId) {
                    // Check if we already have a candidate with the same dedup id
                    foreach ($matchRecords as $matchRecord) {
                        if (
                            !empty($matchRecord['dedup_id'])
                            && (string)$matchRecord['dedup_id'] === $candidateDedupId
                        ) {
                            continue 2;
                        }
                    }
                    // Check if the candidate is deduplicated with the same source:
                    $existingDuplicate = $this->db->findRecord(
                        [
                            'dedup_id' => $candidate['dedup_id'],
                            'source_id' => $record['source_id'],
                            '_id' => ['$ne' => $record['_id']],
                        ]
                    );
                    if ($existingDuplicate) {
                        $this->log->writelnVerbose(
                            "Candidate {$candidate['_id']}" . ' already deduplicated'
                            . ' with ' . $existingDuplicate['_id']
                        );
                        continue;
                    }
                }

                if (++$processed > 1000) {
                    // Too many candidates, give up..
                    $this->log->logDebug(
                        'dedupRecord',
                        'Too many candidates for record ' . $record['_id']
                            . " with $type => [" . implode(', ', $rule['keys']) . ']'
                    );
                    break;
                }

                if (null === $origRecord) {
                    $origRecord = $this->createRecordFromDbRecord($record);
                }
                if ($this->matchRecords($record, $origRecord, $candidate)) {
                    $msg = sprintf(
                        'Found match %s with candidate %s in %0.5f',
                        $type,
                        $processed,
                        microtime(true) - $startTime
                    );
                    if ($processed > 300 || microtime(true) - $startTime > 0.7) {
                        $this->log->writelnVerbose($msg);
                    } else {
                        $this->log->writelnVeryVerbose($msg);
                    }
                    $matchRecords[] = $candidate;
                } else {
                    $noMatchRecordIds[$candidate['_id']] = 1;
                }
            }
            if ($matchRecords) {
                break;
            }
        }

        $msg = sprintf(
            'Candidate search among %d records (%d) matches) completed in %0.5f',
            $candidateCount,
            count($matchRecords),
            microtime(true) - $startTime
        );
        if (microtime(true) - $startTime > 0.2) {
            $this->log->writelnVerbose($msg);
        } else {
            $this->log->writelnVeryVerbose($msg);
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
                    if ($dedupId && !isset($bestMatchCandidates[$dedupId])) {
                        $bestMatchCandidates[$dedupId] = $matchRecord;
                        $dedupIdKeys[] = $matchRecord['dedup_id'];
                    }
                }
                if (count($bestMatchCandidates) > 1) {
                    $bestDedupId = '';
                    $this->db->iterateDedups(
                        [
                            '_id' => ['$in' => $dedupIdKeys],
                            'deleted' => false,
                        ],
                        [],
                        function ($dedupRecord) use (
                            &$bestMatchRecords,
                            &$bestDedupId
                        ) {
                            $cnt = count($dedupRecord['ids']);
                            $dedupId = (string)$dedupRecord['_id'];
                            if (
                                $cnt > $bestMatchRecords
                                || '' === $bestDedupId
                                || ($cnt === $bestMatchRecords
                                && strcmp($bestDedupId, $dedupId) > 0)
                            ) {
                                $bestMatchRecords = $cnt;
                                $bestDedupId = $dedupId;
                            }
                        }
                    );
                    if ($bestDedupId) {
                        $bestMatch = $bestMatchCandidates[$bestDedupId];
                    }
                }
            }

            if ($bestMatchRecords) {
                $this->log->writelnVerbose(
                    sprintf(
                        'Match with %d existing members found in %0.5f among %d'
                        . ' candidates',
                        count($matchRecords),
                        microtime(true) - $startTime,
                        $candidateCount
                    )
                );
            } else {
                $this->log->writelnVerbose(
                    sprintf(
                        'Match found in %0.5f among %d candidates',
                        microtime(true) - $startTime,
                        $candidateCount
                    )
                );
            }

            if (null === $bestMatch) {
                $bestMatch = $matchRecords[0];
            }
            $this->markDuplicates($record['_id'], $bestMatch['_id']);

            return true;
        }

        // No match found
        if (isset($record['dedup_id']) || $record['update_needed']) {
            $oldDedupId = null;
            if (isset($record['dedup_id'])) {
                $oldDedupId = $record['dedup_id'];
                unset($record['dedup_id']);
            }
            $record['updated'] = $this->db->getTimestamp();
            $record['update_needed'] = false;
            $this->db->saveRecord($record);

            // Update dedup record after record is updated
            if (null !== $oldDedupId) {
                $this->removeFromDedupRecord($oldDedupId, $record['_id']);
            }
        }

        $msg = sprintf(
            'No match found in %0.5f among %d candidates',
            $candidateCount,
            microtime(true) - $startTime
        );
        if (microtime(true) - $startTime > 0.2) {
            $this->log->writelnVerbose($msg);
        } else {
            $this->log->writelnVeryVerbose($msg);
        }

        return false;
    }

    /**
     * Remove a record from a dedup record
     *
     * @param string|object $dedupId ObjectID of the dedup record
     * @param string        $id      Record ID to remove
     *
     * @return void
     */
    public function removeFromDedupRecord($dedupId, $id)
    {
        $dedupRecord = $this->db->getDedup($dedupId);
        if (!$dedupRecord) {
            $this->log->logError(
                'removeFromDedupRecord',
                "Found dangling reference to dedup record $dedupId in $id"
            );
            return;
        }
        if ($dedupRecord['deleted']) {
            $this->log->logError(
                'removeFromDedupRecord',
                "Found reference to deleted dedup record $dedupId in $id"
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

                if (null !== ($otherRecord = $this->db->getRecord($otherId))) {
                    if (isset($otherRecord['dedup_id'])) {
                        unset($otherRecord['dedup_id']);
                    }
                    if (!$otherRecord['deleted'] && empty($otherRecord['suppressed'])) {
                        $otherRecord['update_needed'] = true;
                    }
                    $this->db->saveRecord($otherRecord);
                }
            } elseif (empty($dedupRecord['ids'])) {
                // No records remaining => just mark dedup record deleted.
                // This shouldn't happen since a dedup record should always contain
                // at least two records
                $dedupRecord['deleted'] = true;
            }
            $dedupRecord['changed'] = $this->db->getTimestamp();
            $this->db->saveDedup($dedupRecord);

            if (!empty($dedupRecord['ids'])) {
                // Mark other records in the group to be checked since the update
                // could affect the preferred dedup group
                $this->db->updateRecords(
                    [
                        '_id' => ['$in' => $dedupRecord['ids']],
                        'deleted' => false,
                        'suppressed' => ['$in' => [null, false]],
                    ],
                    ['update_needed' => true]
                );
            }
        }
    }

    /**
     * Check if records are duplicate matches
     *
     * @param array  $origDbRecord      Database record
     * @param object $origRecord        Metadata record (from $origDbRecord)
     * @param array  $candidateDbRecord Candidate database record
     *
     * @return bool
     */
    protected function matchRecords($origDbRecord, $origRecord, $candidateDbRecord)
    {
        $candidateRecord = $this->createRecordFromDbRecord($candidateDbRecord);
        $this->log->writelnVeryVerbose('Check candidate ' . $candidateDbRecord['_id']);
        $this->log->writelnDebug(
            function () use ($candidateDbRecord) {
                return $this->metadataUtils->getRecordData($candidateDbRecord, true);
            }
        );

        $recordHidden = $this->metadataUtils->isHiddenComponentPart(
            $this->dataSourceConfig[$origDbRecord['source_id']],
            $origDbRecord,
            $origRecord
        );
        $candidateHidden = $this->metadataUtils->isHiddenComponentPart(
            $this->dataSourceConfig[$candidateDbRecord['source_id']],
            $candidateDbRecord,
            $candidateRecord
        );

        // Check that both records are hidden component parts or neither is
        if ($recordHidden != $candidateHidden) {
            if ($candidateHidden) {
                $this->log->writelnVeryVerbose(
                    '--Candidate is a hidden component part'
                );
            } else {
                $this->log->writelnVeryVerbose(
                    '--Candidate is not a hidden component part'
                );
            }
            return false;
        }

        // Check access restrictions
        $candidateRestrictions = $candidateRecord->getAccessRestrictions();
        if ($candidateRestrictions != $origRecord->getAccessRestrictions()) {
            $this->log->writelnVeryVerbose(
                '--Candidate has different access restrictions'
            );
            return false;
        }

        // Check format
        $origFormat = (array)$origRecord->getFormat();
        $candidateFormat = (array)$candidateRecord->getFormat();
        $origMapped = $this->fieldMapper->mapFormat(
            $origDbRecord['source_id'],
            $origFormat
        );
        $candidateMapped = $this->fieldMapper->mapFormat(
            $candidateDbRecord['source_id'],
            $candidateFormat
        );
        sort($origFormat);
        sort($candidateFormat);
        sort($origMapped);
        sort($candidateMapped);
        if ($origFormat != $candidateFormat && $origMapped != $candidateMapped) {
            $this->log->writelnVeryVerbose(
                '--Format mismatch: ' . implode(',', $origFormat) . ' != ' .
                implode(',', $candidateFormat) . ' and ' . implode(',', $origMapped)
                . ' != ' . implode(',', $candidateMapped)
            );
            return false;
        }

        // Check for common ISBN
        $origISBNs = $this->filterIds($origRecord->getISBNs(), $origDbRecord);
        $candidateISBNs
            = $this->filterIds($candidateRecord->getISBNs(), $candidateDbRecord);
        $isect = array_intersect($origISBNs, $candidateISBNs);
        if (!empty($isect)) {
            // Shared ISBN -> match
            $this->log->writelnVeryVerbose(
                function () use (
                    $origISBNs,
                    $candidateISBNs,
                    $origRecord,
                    $candidateRecord
                ) {
                    return '++ISBN match:' . PHP_EOL
                        . print_r($origISBNs, true) . PHP_EOL
                        . print_r($candidateISBNs, true) . PHP_EOL
                        . $origRecord->getFullTitleForDebugging() . PHP_EOL
                        . $candidateRecord->getFullTitleForDebugging();
                }
            );
            return true;
        }

        // Check for other common ID (e.g. NBN)
        $origIDs = $this->filterIds($origRecord->getUniqueIDs(), $origDbRecord);
        $candidateIDs = $candidateRecord->getUniqueIDs();
        $isect = array_intersect($origIDs, $candidateIDs);
        if (!empty($isect)) {
            // Shared ID -> match
            $this->log->writelnVeryVerbose(
                function () use (
                    $origIDs,
                    $candidateIDs,
                    $origRecord,
                    $candidateRecord
                ) {
                    return '++ID match:' . PHP_EOL
                        . print_r($origIDs, true) . PHP_EOL
                        . print_r($candidateIDs, true) . PHP_EOL
                        . $origRecord->getFullTitleForDebugging() . PHP_EOL
                        . $candidateRecord->getFullTitleForDebugging();
                }
            );
            return true;
        }

        $origISSNs = $this->filterIds($origRecord->getISSNs(), $origDbRecord);
        $candidateISSNs = $candidateRecord->getISSNs();
        $commonISSNs = array_intersect($origISSNs, $candidateISSNs);
        if (!empty($origISSNs) && !empty($candidateISSNs) && empty($commonISSNs)) {
            // Both have ISSNs but none match
            $this->log->writelnVeryVerbose(
                function () use (
                    $origISSNs,
                    $candidateISSNs,
                    $origRecord,
                    $candidateRecord
                ) {
                    return '--ISSN mismatch:' . PHP_EOL
                        . print_r($origISSNs, true) . PHP_EOL
                        . print_r($candidateISSNs, true) . PHP_EOL
                        . $origRecord->getFullTitleForDebugging() . PHP_EOL
                        . $candidateRecord->getFullTitleForDebugging();
                }
            );
            return false;
        }

        $origYear = $origRecord->getPublicationYear();
        $candidateYear = $candidateRecord->getPublicationYear();
        if ($origYear && $candidateYear && $origYear != $candidateYear) {
            $this->log
                ->writelnVeryVerbose("--Year mismatch: $origYear != $candidateYear");
            return false;
        }
        $pages = $origRecord->getPageCount();
        $candidatePages = $candidateRecord->getPageCount();
        if ($pages && $candidatePages && abs($pages - $candidatePages) > 10) {
            $this->log
                ->writelnVeryVerbose("--Pages mismatch ($pages != $candidatePages)");
            return false;
        }

        if ($origRecord->getSeriesISSN() != $candidateRecord->getSeriesISSN()) {
            return false;
        }
        $candidateNumbering = $candidateRecord->getSeriesNumbering();
        if ($origRecord->getSeriesNumbering() != $candidateNumbering) {
            return false;
        }

        $origTitle = $this->metadataUtils->normalizeKey(
            $origRecord->getTitle(true),
            $this->normalizationForm
        );
        $candidateTitle = $this->metadataUtils->normalizeKey(
            $candidateRecord->getTitle(true),
            $this->normalizationForm
        );
        if (!$origTitle || !$candidateTitle) {
            // No title match without title...
            $this->log->writelnVeryVerbose('--No title - no further matching');
            return false;
        }
        $lev = levenshtein(
            substr($origTitle, 0, 255),
            substr($candidateTitle, 0, 255)
        );
        $lev = $lev / strlen($origTitle) * 100;
        if ($lev >= 10) {
            $this->log->writelnVeryVerbose(
                "--Title distance discard: $lev" . PHP_EOL
                . "Original:  $origTitle" . PHP_EOL
                . "Candidate: $candidateTitle"
            );
            return false;
        }

        $origAuthor = $this->metadataUtils->normalizeKey(
            $origRecord->getMainAuthor(),
            $this->normalizationForm
        );
        $candidateAuthor = $this->metadataUtils->normalizeKey(
            $candidateRecord->getMainAuthor(),
            $this->normalizationForm
        );
        $authorLev = 0;
        if ($origAuthor || $candidateAuthor) {
            if (!$origAuthor || !$candidateAuthor) {
                $this->log->writelnVeryVerbose(
                    '--Author discard:' . PHP_EOL
                    . "Original:  $origAuthor" . PHP_EOL
                    . "Candidate: $candidateAuthor"
                );
                return false;
            }
            if (!$this->metadataUtils->authorMatch($origAuthor, $candidateAuthor)) {
                $authorLev = levenshtein(
                    substr($origAuthor, 0, 255),
                    substr($candidateAuthor, 0, 255)
                );
                $authorLev = $authorLev / mb_strlen($origAuthor) * 100;
                if ($authorLev > 20) {
                    $this->log->writelnVeryVerbose(
                        "--Author distance discard: $authorLev" . PHP_EOL
                        . "Original:  $origAuthor" . PHP_EOL
                        . "Candidate: $candidateAuthor"
                    );
                    return false;
                }
            }
        }

        $this->log->writelnVeryVerbose(
            function () use (
                $lev,
                $authorLev,
                $origRecord,
                $origAuthor,
                $origTitle,
                $candidateRecord,
                $candidateAuthor,
                $candidateTitle
            ) {
                return "++Title match (distance: $lev, author distance: $authorLev):"
                    . PHP_EOL
                    . $origRecord->getFullTitleForDebugging() . PHP_EOL
                    . "   $origAuthor - $origTitle." . PHP_EOL
                    . $candidateRecord->getFullTitleForDebugging() . PHP_EOL
                    . "   $candidateAuthor - $candidateTitle.";
            }
        );
        // We have a match!
        return true;
    }

    /**
     * Get title keys from a database record
     *
     * @param array|\ArrayAccess $record Database record
     *
     * @return array
     */
    protected function getTitleKeys($record): array
    {
        return isset($record['title_keys'])
            ? array_values(array_filter((array)$record['title_keys'])) : [];
    }

    /**
     * Get ISBN keys from a database record
     *
     * @param array|\ArrayAccess $record Database record
     *
     * @return array
     */
    protected function getISBNKeys($record): array
    {
        $result = isset($record['isbn_keys'])
            ? array_values(array_filter((array)$record['isbn_keys'])) : [];
        return $this->filterIds($result, $record);
    }

    /**
     * Get ID keys from a database record
     *
     * @param array|\ArrayAccess $record Database record
     *
     * @return array
     */
    protected function getIDKeys($record): array
    {
        $result = isset($record['id_keys'])
            ? array_values(array_filter((array)$record['id_keys'])) : [];
        return $this->filterIds($result, $record);
    }

    /**
     * Filter blocked identifiers from a list
     *
     * @param array              $ids    Identifiers
     * @param array|\ArrayAccess $record Database record
     *
     * @return array
     */
    protected function filterIds(array $ids, $record): array
    {
        // First check quickly if we have matching identifiers:
        $result = $ids;
        if (array_diff($ids, $this->ignoredIds) !== $ids) {
            $recordTitleKeys = $this->getTitleKeys($record);
            foreach ($this->ignoredIdsAndTitles as $ignored) {
                if (false === ($key = array_search($ignored['id'], $result))) {
                    continue;
                }
                // Check title keys:
                $titleKey = $ignored['title'] ?
                    $this->metadataUtils->createTitleKey(
                        $ignored['title'],
                        $this->normalizationForm
                    ) : '';

                if (
                    !$titleKey
                    || array_filter(
                        $recordTitleKeys,
                        function ($s) use ($titleKey) {
                            return str_starts_with($s, $titleKey);
                        }
                    )
                ) {
                    // No title rule or title match, remove id:
                    unset($result[$key]);
                    if (!$result) {
                        break;
                    }
                }
            }

            if ($result !== $ids) {
                $this->log->writelnVerbose(
                    'ID ignored: '
                    . implode(',', array_diff($ids, $result))
                );
            }
            $result = array_values($result);
        }
        return $result;
    }

    /**
     * Mark two records as duplicates
     *
     * @param string $id1 Database record id for which a duplicate was searched
     * @param string $id2 Database record id for the found duplicate
     *
     * @return void
     */
    protected function markDuplicates($id1, $id2)
    {
        // Reread the original record just in case it has changed in the meantime.
        $rec1 = $this->db->getRecord($id1);
        $rec2 = $this->db->getRecord($id2);
        if (null === $rec1) {
            $this->log->logWarning(
                'markDuplicates',
                "Record $id1 is no longer available"
            );
            return;
        }
        if ($rec1['deleted'] || ($rec1['suppressed'] ?? false)) {
            $this->log->logWarning(
                'markDuplicates',
                "Record $id1 has been deleted or suppressed in the meanwhile"
            );
            return;
        }
        if (null === $rec2) {
            $this->log->logWarning(
                'markDuplicates',
                "Record $id1 is no longer available"
            );
            return;
        }
        if ($rec2['deleted'] || ($rec2['suppressed'] ?? false)) {
            $this->log->logWarning(
                'markDuplicates',
                "Record $id2 has been deleted or suppressed in the meanwhile"
            );
            return;
        }

        $setValues = [
            'updated' => $this->db->getTimestamp(),
            'update_needed' => false,
        ];
        // Deferred removal to keep database checks happy
        $removeFromDedup = [];
        if (!empty($rec2['dedup_id'])) {
            // Record 2 is already deduplicated, try to add to it:
            if (!$this->addToDedupRecord($rec2['dedup_id'], $rec1['_id'])) {
                $removeFromDedup[] = [
                    'dedup_id' => $rec2['dedup_id'],
                    '_id' => $rec2['_id'],
                ];
                $rec2['dedup_id'] = $this->createDedupRecord(
                    $rec1['_id'],
                    $rec2['_id']
                );
            }
            // If record 1 was previously deduplicated, remove it from that group:
            if (isset($rec1['dedup_id']) && $rec1['dedup_id'] != $rec2['dedup_id']) {
                $removeFromDedup[] = [
                    'dedup_id' => $rec1['dedup_id'],
                    '_id' => $rec1['_id'],
                ];
            }
            $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id'];
        } elseif (!empty($rec1['dedup_id'])) {
            // Record 1 is already deduplicated, try to add to it:
            if (!$this->addToDedupRecord($rec1['dedup_id'], $rec2['_id'])) {
                $removeFromDedup[] = [
                    'dedup_id' => $rec1['dedup_id'],
                    '_id' => $rec1['_id'],
                ];
                $rec1['dedup_id'] = $this->createDedupRecord(
                    $rec1['_id'],
                    $rec2['_id']
                );
            }
            $setValues['dedup_id'] = $rec2['dedup_id'] = $rec1['dedup_id'];
        } else {
            // Create a new dedup record:
            $setValues['dedup_id'] = $rec1['dedup_id'] = $rec2['dedup_id']
                = $this->createDedupRecord($rec1['_id'], $rec2['_id']);
        }
        $this->log->writelnVerbose(
            "Marking {$rec1['_id']} as duplicate with {$rec2['_id']} "
            . "with dedup id {$rec2['dedup_id']}"
        );

        $this->db->updateRecords(
            ['_id' => ['$in' => [$rec1['_id'], $rec2['_id']]]],
            $setValues
        );

        foreach ($removeFromDedup as $current) {
            $this->removeFromDedupRecord($current['dedup_id'], $current['_id']);
        }

        if (!isset($rec1['host_record_id'])) {
            $count = $this->dedupComponentParts($rec1);
            if ($count > 0) {
                $this->log->writelnVerbose(
                    "Deduplicated $count component parts for {$rec1['_id']}"
                );
            }
        }
    }

    /**
     * Create a new dedup record
     *
     * @param string $id1 ID of first record
     * @param string $id2 ID of second record
     *
     * @return mixed ID of the dedup record
     */
    protected function createDedupRecord($id1, $id2)
    {
        $record = [
            'changed' => $this->db->getTimestamp(),
            'deleted' => false,
            'ids' => [
                $id1,
                $id2,
             ],
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
        $source = $this->metadataUtils->getSourceFromId($id);
        foreach ((array)$record['ids'] as $existingId) {
            if (
                $id !== $existingId
                && $source === $this->metadataUtils->getSourceFromId($existingId)
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
     * @param array $hostRecord Database record for the host record
     *
     * @return integer Number of component parts deduplicated
     */
    protected function dedupComponentParts($hostRecord)
    {
        if (!$hostRecord['linking_id']) {
            $this->log->logError(
                'dedupComponentParts',
                'Linking ID missing from record ' . $hostRecord['_id']
            );
            return 0;
        }
        $components1 = $this->getComponentPartsSorted(
            $hostRecord['source_id'],
            $hostRecord['linking_id']
        );
        $component1count = count($components1);
        if ($component1count === 0) {
            return 0;
        }

        $this->log->writelnVerbose('Deduplicating component parts');

        // Go through all other records with same dedup id and see if their
        // component parts match
        $marked = 0;
        $this->db->iterateRecords(
            [
                'dedup_id' => $hostRecord['dedup_id'],
                'deleted' => false,
                'suppressed' => ['$in' => [null, false]],
            ],
            [],
            function ($otherRecord) use (
                $components1,
                $component1count,
                &$marked,
                $hostRecord
            ) {
                if ($otherRecord['source_id'] == $hostRecord['source_id']) {
                    return true;
                }
                $components2 = $this->getComponentPartsSorted(
                    $otherRecord['source_id'],
                    $otherRecord['linking_id']
                );
                $component2count = count($components2);

                if ($component1count != $component2count) {
                    $allMatch = false;
                } else {
                    $allMatch = true;
                    $idx = -1;
                    foreach ($components1 as $component1) {
                        $component2 = $components2[++$idx];
                        $this->log->writelnVerbose(
                            "Comparing {$component1['_id']} with "
                            . $component2['_id']
                        );
                        $this->log->writelnDebug(
                            function () use ($component1) {
                                return $this->metadataUtils
                                    ->getRecordData($component1, true);
                            }
                        );
                        $metadataComponent1 = $this->createRecordFromDbRecord($component1);
                        if (
                            !$this->matchRecords(
                                $component1,
                                $metadataComponent1,
                                $component2
                            )
                        ) {
                            $allMatch = false;
                            break;
                        }
                    }
                }

                if ($allMatch) {
                    $this->log->writelnVerbose(
                        "All component parts match between {$hostRecord['_id']}"
                        . " and {$otherRecord['_id']}"
                    );
                    $idx = -1;
                    foreach ($components1 as $component1) {
                        $component2 = $components2[++$idx];
                        $this
                            ->markDuplicates($component1['_id'], $component2['_id']);
                        ++$marked;
                    }
                    // Stop processing further records:
                    return false;
                } else {
                    $this->log->writelnVerbose(
                        "Not all component parts match between {$hostRecord['_id']}"
                        . " and {$otherRecord['_id']}"
                    );
                }
            }
        );

        // phpcs:ignore
        /** @psalm-suppress RedundantCondition */
        if (0 === $marked) {
            // Make sure the components part don't remain deduplicated with anything
            foreach ($components1 as $component) {
                if (isset($component['dedup_id'])) {
                    $this->removeFromDedupRecord(
                        $component['dedup_id'],
                        $component['_id']
                    );
                    unset($component['dedup_id']);
                    $component['updated'] = $this->db->getTimestamp();
                    $this->db->saveRecord($component);
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
        $components = [];
        $this->db->iterateRecords(
            [
                'source_id' => $sourceId,
                'host_record_id' => [
                    '$in' => array_values((array)$hostRecordId),
                ],
                'deleted' => false,
                'suppressed' => ['$in' => [null, false]],
            ],
            [],
            function ($component) use (&$components) {
                $components[$this->metadataUtils->createIdSortKey($component['_id'])]
                    = $component;
            }
        );
        ksort($components);
        return array_values($components);
    }
}
