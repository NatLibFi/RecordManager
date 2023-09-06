<?php

/**
 * Send Logs
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2021.
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

namespace RecordManager\Base\Command\Logs;

use RecordManager\Base\Command\AbstractBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Send Logs
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Send extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Send stored logs by email')
            ->addArgument(
                'recipient',
                InputArgument::REQUIRED,
                'Recipient email address'
            );
    }

    /**
     * Send log messages stored in the database
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $recipient = $input->getArgument('recipient');
        // Don't write log messages to database during this process
        $this->logger->setDatabase(null);
        $count = 0;
        do {
            // Send a set of messages at a time.
            $records = $this->db->findLogMessages([], ['limit' => 100]);
            $more = false;
            $messages = [];
            $recordIds = [];
            foreach ($records as $record) {
                $more = true;

                $timestamp = date(
                    'Y-m-d H:i:s',
                    $this->db->getUnixTime($record['timestamp'])
                );
                $logMsg = $timestamp . ' [' . $record['pid'] . '] ['
                    . $this->logger->logLevelToStr($record['level'])
                    . '] [' . $record['context'] . '] ' . $record['message'];

                // Avoid a too long error in the email
                if (mb_strlen($logMsg, 'UTF-8') > 4096 + 50) {
                    $logMsg = mb_substr($logMsg, 0, 2048, 'UTF-8')
                        . "\n\n[... Truncated - See log for full message ...]\n\n"
                        . mb_substr($logMsg, -2048, null, 'UTF-8');
                }

                $messages[] = $logMsg;
                $recordIds[] = $record['_id'];

                ++$count;
            }
            if ($messages) {
                $message = 'RecordManager log summary:' . PHP_EOL . PHP_EOL
                    . implode(PHP_EOL, $messages);
                $result = mail(
                    $recipient,
                    'RecordManager Log Report (' . gethostname() . ')',
                    $message
                );
                if (!$result) {
                    $this->logger->logFatal('SendLogs', 'Failed to send email');
                    return Command::FAILURE;
                }

                foreach ($recordIds as $id) {
                    $this->db->deleteLogMessage($id);
                }

                $this->logger->logInfo('sendLogs', "$count message(s) sent");
            }
        } while ($more);
        $this->logger->logInfo('sendLogs', "Completed with $count message(s) sent");
        return Command::SUCCESS;
    }
}
