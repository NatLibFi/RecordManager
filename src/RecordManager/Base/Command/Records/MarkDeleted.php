<?php
/**
 * Mark Records Deleted
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
namespace RecordManager\Base\Command\Records;

use RecordManager\Base\Command\AbstractBase;
use RecordManager\Base\Utils\PerformanceCounter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mark Records Deleted
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarkDeleted extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Mark records of a data source deleted')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separated list of data sources to mark deleted'
            )->addOption(
                'single',
                null,
                InputOption::VALUE_REQUIRED,
                'Mark only the specified record deleted'
            )->addOption(
                'id-file',
                null,
                InputOption::VALUE_REQUIRED,
                'A file of record id\'s (one per line) to mark deleted'
            );
    }

    /**
     * Mark records deleted
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $sourceId = $input->getOption('source');
        $singleId = $input->getOption('single') ?: '';
        $idFile = $input->getOption('id-file') ?: '';

        if (empty($sourceId) && empty($singleId) && empty($idFile)) {
            $this->logger->logFatal(
                'markDeleted',
                'No source, record id or id-file specified'
            );
            return Command::INVALID;
        }

        if (!empty($idFile)) {
            if (!file_exists($idFile)) {
                $this->logger->logFatal(
                    'markDeleted',
                    "ID file $idFile does not exist"
                );
                return Command::INVALID;
            }
            $this->logger->logInfo(
                'markDeleted',
                "Marking records from ID file $idFile deleted"
            );
            return $this->markDeletedFromIdFile($idFile)
                ? Command::SUCCESS : Command::FAILURE;
        }

        if (empty($sourceId)) {
            $this->logger
                ->logInfo('markDeleted', "Marking record $singleId deleted");
            $this->markDeleted('', $singleId);
            return Command::SUCCESS;
        }

        foreach (explode(',', $sourceId) as $source) {
            $this->logger
                ->logInfo('markDeleted', "Marking $source deleted");
            $this->markDeleted($source, $singleId);
        }

        return Command::SUCCESS;
    }

    /**
     * Mark record(s) deleted
     *
     * @param string $sourceId Data source id
     * @param string $singleId Single record id
     *
     * @return void
     */
    protected function markDeleted(string $sourceId, string $singleId): void
    {
        $params = ['deleted' => false];
        if ($sourceId) {
            $params['source_id'] = $sourceId;
        }
        if ($singleId) {
            $params['_id'] = $singleId;
        }
        $total = $this->db->countRecords($params);
        $count = 0;

        $this->logger->logInfo('markDeleted', "Marking $total record(s) deleted");
        $pc = new PerformanceCounter();

        do {
            // Fetch a set of records at a time since the remaining set will be
            // changing during this process.
            $records = $this->db->findRecords($params, ['limit' => 1000]);
            $more = false;
            foreach ($records as $record) {
                $more = true;
                if (isset($record['dedup_id'])) {
                    $this->dedupHandler->removeFromDedupRecord(
                        $record['dedup_id'],
                        $record['_id']
                    );
                    unset($record['dedup_id']);
                }
                $record['deleted'] = true;
                $record['updated'] = $this->db->getTimestamp();
                $this->db->saveRecord($record);

                ++$count;
                if ($count % 1000 == 0) {
                    $pc->add($count);
                    $avg = $pc->getSpeed();
                    $this->logger->logInfo(
                        'markDeleted',
                        "$count records marked deleted from '$sourceId', "
                        . "$avg records/sec"
                    );
                }
            }
        } while ($more);

        $this->logger->logInfo(
            'markDeleted',
            "Completed with $count records marked deleted"
        );

        if (!$singleId) {
            $this->logger->logInfo(
                'markDeleted',
                "Deleting last harvest date from data source '$sourceId'"
            );
            $this->db->deleteState("Last Harvest Date $sourceId");
        }

        $this->logger->logInfo('markDeleted', 'Marking of record deleted completed');
    }

    /**
     * Mark record(s) deleted based on an id file
     *
     * @param string $idFile File name
     *
     * @return bool
     */
    protected function markDeletedFromIdFile(string $idFile): bool
    {
        $count = 0;
        $pc = new PerformanceCounter();
        $f = fopen($idFile, 'r');
        if (false === $f) {
            $this->logger->logFatal(
                'markDeleted',
                "Could not open ID file $idFile"
            );
        }

        while (!feof($f)) {
            $id = trim(fgets($f));
            if ('' === $id) {
                continue;
            }
            $record = $this->db->getRecord($id);
            if (null === $record) {
                $this->logger->writelnVerbose("Record $id not found");
                continue;
            }
            if ($record['deleted']) {
                $this->logger->writelnVeryVerbose("Record $id already deleted");
                continue;
            }
            if (isset($record['dedup_id'])) {
                $this->dedupHandler->removeFromDedupRecord(
                    $record['dedup_id'],
                    $record['_id']
                );
                unset($record['dedup_id']);
            }
            $record['deleted'] = true;
            $record['updated'] = $this->db->getTimestamp();
            $this->db->saveRecord($record);
            $this->logger->writelnVeryVerbose("Record $id deleted");

            ++$count;
            if ($count % 1000 == 0) {
                $pc->add($count);
                $avg = $pc->getSpeed();
                $this->logger->logInfo(
                    'markDeleted',
                    "$count records marked deleted, $avg records/sec"
                );
            }
        }
        $this->logger->logInfo(
            'markDeleted',
            "Completed with $count records marked deleted"
        );
        return true;
    }
}
