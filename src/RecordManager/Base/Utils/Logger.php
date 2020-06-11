<?php
/**
 * Logging Utility
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2020.
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
 * Logger
 *
 * This class provides a logging facility for RecordManager with the ability
 * to report fatal errors by email.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Logger
{
    const FATAL = 0;
    const ERROR = 1;
    const WARNING = 2;
    const INFO = 3;
    const DEBUG = 4;

    public $logLevel = 0;
    public $logFile = '';
    public $maxFileSize = 0;
    public $maxFileHistory = 5;
    public $logToConsole = false;
    public $errorEmail = '';

    /**
     * Constructor
     *
     * @param array $config Main configuration
     */
    public function __construct($config)
    {
        $this->logLevel = $config['Log']['log_level'] ?? 0;
        $this->logFile = $config['Log']['log_file'] ?? '';
        if (isset($config['Log']['max_file_size'])) {
            $this->maxFileSize = $config['Log']['max_file_size'];
        }
        if (isset($config['Log']['max_file_history'])) {
            $this->maxFileHistory = $config['Log']['max_file_history'];
        }
        if (isset($config['Log']['error_email'])) {
            $this->errorEmail = $config['Log']['error_email'];
        }
    }

    /**
     * Write a debug message to the log
     *
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message
     *
     * @return void
     */
    public function logDebug($context, $msg)
    {
        $this->log($context, $msg, Logger::DEBUG);
    }

    /**
     * Write an error message to the log
     *
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message
     *
     * @return void
     */
    public function logError($context, $msg)
    {
        $this->log($context, $msg, Logger::ERROR);
    }

    /**
     * Write a fatal error message to the log
     *
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message
     *
     * @return void
     */
    public function logFatal($context, $msg)
    {
        $this->log($context, $msg, Logger::FATAL);
    }

    /**
     * Write an info message to the log
     *
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message
     *
     * @return void
     */
    public function logInfo($context, $msg)
    {
        $this->log($context, $msg, Logger::INFO);
    }

    /**
     * Write a warning message to the log
     *
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message
     *
     * @return void
     */
    public function logWarning($context, $msg)
    {
        $this->log($context, $msg, Logger::WARNING);
    }

    /**
     * Write a message to the log
     *
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message
     * @param int    $level   Message level used to filter logged messages. Default
     *                        is INFO (3)
     *
     * @return void
     */
    protected function log($context, $msg, $level = Logger::INFO)
    {
        if ($this->logLevel < $level) {
            return;
        }
        $msg = date('Y-m-d H:i:s') . ' [' . getmypid() . '] ['
            . $this->logLevelToStr($level) . "] [$context] $msg\n";
        if ($this->logFile) {
            if ($this->maxFileSize && file_exists($this->logFile)
                && filesize($this->logFile) > $this->maxFileSize * 1024 * 1024
            ) {
                if (file_exists($this->logFile . '.' . $this->maxFileHistory)) {
                    unlink($this->logFile . '.' . $this->maxFileHistory);
                }
                for ($i = $this->maxFileHistory - 1; $i >= 0; $i--) {
                    $logFileName = $this->logFile . '.' . $i;
                    if (file_exists($logFileName)) {
                        $newLogFileName = $this->logFile . '.' . ($i + 1);
                        rename($logFileName, $newLogFileName);
                    }
                }
                rename($this->logFile, $this->logFile . '.0');
            }
            file_put_contents($this->logFile, $msg, FILE_APPEND);
        }
        if (strlen($msg) > 4096) {
            // Avoid throwing a large error on the console or in the email
            $msg = substr($msg, 0, 2048)
                . "\n\n[... Truncated - See log for full message ...]\n\n"
                . substr($msg, -2048);
        }
        if ($level == Logger::FATAL && $this->errorEmail) {
            $email = "RecordManager encountered the following fatal error: "
                . PHP_EOL . PHP_EOL . $msg;
            mail(
                $this->errorEmail,
                'RecordManager Error Report (' . gethostname() . ')',
                $email
            );
        }
        if ($this->logToConsole) {
            if ($level == Logger::INFO) {
                echo $msg;
            } else {
                file_put_contents('php://stderr', $msg, FILE_APPEND);
            }
        }
    }

    /**
     * Convert log level to string
     *
     * @param int $level Level to convert
     *
     * @return string
     */
    protected function logLevelToStr($level)
    {
        switch ($level) {
        case Logger::FATAL:
            return 'FATAL';
        case Logger::ERROR:
            return 'ERROR';
        case Logger::WARNING:
            return 'WARNING';
        case Logger::INFO:
            return 'INFO';
        case Logger::DEBUG:
            return 'DEBUG';
        }
        return '???';
    }
}
