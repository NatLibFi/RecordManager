<?php
/**
 * Logging Utility
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2012.
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
     */
    public function __construct()
    {
        global $configArray;

        $this->logLevel = $configArray['Log']['log_level'];
        $this->logFile = $configArray['Log']['log_file'];
        if (isset($configArray['Log']['max_file_size'])) {
            $this->maxFileSize = $configArray['Log']['max_file_size'];
        }
        if (isset($configArray['Log']['max_file_history'])) {
            $this->maxFileHistory = $configArray['Log']['max_file_history'];
        }
        if (isset($configArray['Log']['error_email'])) {
            $this->errorEmail = $configArray['Log']['error_email'];
        }
    }

    /**
     * Write a message to the log
     * 
     * @param string $context Context of the log message (e.g. current function)
     * @param string $msg     Actual message 
     * @param int    $level   Message level used to filter logged messages. Default is INFO (3)
     * 
     * @return void
     */
    public function log($context, $msg, $level = Logger::INFO)
    {
        if ($this->logLevel < $level) {
            return;
        }
        $msg = date('Y-m-d H:i:s') . ' [' . getmypid() . '] [' . $this->logLevelToStr($level) . "] [$context] $msg\n";
        if ($this->logFile) {
            if ($this->maxFileSize && file_exists($this->logFile) && filesize($this->logFile) > $this->maxFileSize * 1024 * 1024) {
                if (file_exists($this->logFile . '.' . $this->maxFileHistory)) {
                    unlink($this->logFile . '.' . $this->maxFileHistory);
                }
                for ($i = $this->maxFileHistory - 1; $i >= 0; $i--) {
                    if (file_exists($this->logFile . '.' . $i)) {
                        rename($this->logFile . '.' . $i, $this->logFile . '.' . ($i + 1));
                    }
                }
                rename($this->logFile, $this->logFile . '.0');
            }
            file_put_contents($this->logFile, $msg, FILE_APPEND);
        }
        if (strlen($msg) > 4096) {
            // Avoid throwing a large error on the console or in the email
            $msg = substr($msg, 0, 2048) . "\n\n[... Truncated - See log for full message ...]\n\n" . substr($msg, -2048);
        }
        if ($level == Logger::FATAL && $this->errorEmail) {
            $email = "RecordManager encountered the following fatal error: " . PHP_EOL . PHP_EOL . $msg;
            mail($this->errorEmail, 'RecordManager Error Report', $email);
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
     * @param level $level Level to convert
     * 
     * @return string
     */
    protected function logLevelToStr($level)
    {
        switch ($level) {
        case Logger::FATAL: return 'FATAL';
        case Logger::ERROR: return 'ERROR';
        case Logger::WARNING: return 'WARNING';
        case Logger::INFO: return 'INFO';
        case Logger::DEBUG: return 'DEBUG';
        }
        return '???';
    }
}

