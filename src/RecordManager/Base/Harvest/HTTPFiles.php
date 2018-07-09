<?php
/**
 * HTTP-based File Harvesting Class
 *
 * PHP version 5
 *
 * Copyright (c) The National Library of Finland 2011-2017.
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
namespace RecordManager\Base\Harvest;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Utils\Logger;

/**
 * HTTPFiles Class
 *
 * This class harvests files via HTTP using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class HTTPFiles extends Base
{
    /**
     * File name prefix
     *
     * @var string
     */
    protected $filePrefix = '';

    /**
     * File name suffix
     *
     * @var string
     */
    protected $fileSuffix = '';

    /**
     * Element to look for in retrieved XML
     *
     * @var string
     */
    protected $recordElem = 'record';

    /**
     * Constructor.
     *
     * @param Database $db       Database
     * @param Logger   $logger   The Logger object used for logging messages
     * @param string   $source   The data source to be harvested
     * @param string   $basePath RecordManager main directory location
     * @param array    $config   Main configuration
     * @param array    $settings Settings from datasources.ini
     *
     * @throws Exception
     */
    public function __construct(Database $db, Logger $logger, $source, $basePath,
        $config, $settings
    ) {
        parent::__construct($db, $logger, $source, $basePath, $config, $settings);

        if (isset($settings['filePrefix'])) {
            $this->filePrefix = $settings['filePrefix'];
        }
        if (isset($settings['fileSuffix'])) {
            $this->fileSuffix = $settings['fileSuffix'];
        }
    }

    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     * @throws Exception
     */
    public function harvest($callback)
    {
        $this->initHarvest($callback);

        if (isset($this->startDate)) {
            $this->message('Incremental harvest from timestamp ' . $this->startDate);
        } else {
            $this->message('Initial harvest for all records');
        }
        $fileList = $this->retrieveFileList();
        $this->message('Files to harvest: ' . count($fileList));
        foreach ($fileList as $file) {
            $data = $this->retrieveFile($file);

            $this->message('Processing the records...', true);

            if (null !== $this->preXslt) {
                $data = $this->preTransform($data);
            }

            $tempFile = $this->getTempFileName('http-harvest-', '.xml');
            if (file_put_contents($tempFile, $data) === false) {
                $this->message(
                    "Could not write to $tempFile\n", false, Logger::FATAL
                );
                throw new \Exception("Could not write to $tempFile");
            }
            $data = '';

            $xml = new \XMLReader();
            $saveUseErrors = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $result = $xml->open($tempFile, null, LIBXML_PARSEHUGE);
            if ($result === false || libxml_get_last_error() !== false) {
                // Assuming it's a character encoding issue, this might help...
                $this->message(
                    'Invalid XML received, trying encoding fix...',
                    false,
                    Logger::WARNING
                );
                $data = file_get_contents($tempFile);
                if (false === $data) {
                    $this->message(
                        "Could not read from $tempFile\n", false, Logger::FATAL
                    );
                    throw new \Exception("Could not read from $tempFile");
                }
                $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
                if (file_put_contents($tempFile, $data) === false) {
                    $this->message(
                        "Could not write to $tempFile\n", false, Logger::FATAL
                    );
                    throw new \Exception("Could not write to $tempFile");
                }
                $data = '';
                libxml_clear_errors();
                $result = $xml->open($tempFile);
            }
            if ($result === false || libxml_get_last_error() !== false) {
                libxml_use_internal_errors($saveUseErrors);
                $errors = '';
                foreach (libxml_get_errors() as $error) {
                    if ($errors) {
                        $errors .= '; ';
                    }
                    $errors .= 'Error ' . $error->code . ' at ' . $error->line
                        . ':' . $error->column . ': ' . $error->message;
                }
                $this->message(
                    "Could not parse XML response: $errors\n", false, Logger::FATAL
                );
                throw new \Exception("Failed to parse XML response");
            }
            libxml_use_internal_errors($saveUseErrors);

            $this->processRecords($xml);
            $xml->close();
            if (!unlink($tempFile)) {
                $this->message(
                    "Could not remove $tempFile\n", false, Logger::ERROR
                );
            }

            $this->reportResults();
        }
        if ($this->trackedEndDate > 0) {
            $this->saveLastHarvestedDate($this->trackedEndDate);
        }
    }

    /**
     * Retrieve list of files to be harvested, filter by date
     *
     * @throws Exception
     * @return array
     */
    protected function retrieveFileList()
    {
        $request = new \HTTP_Request2(
            $this->baseURL,
            \HTTP_Request2::METHOD_GET,
            $this->httpParams
        );
        $request->setHeader('User-Agent', 'RecordManager');

        $url = $request->getURL();
        $urlStr = $url->getURL();
        $this->message("Sending request: $urlStr", true);

        // Perform request and throw an exception on error:
        $response = null;
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (\Exception $e) {
                if ($try < 5) {
                    $this->message(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . "), retrying in 30 seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
                throw $e;
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->message(
                        "Request '$urlStr' failed ($code), "
                        . "retrying in 30 seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = is_null($response) ? 999 : $response->getStatus();
        if ($code >= 300) {
            $this->message("Request '$urlStr' failed: $code", false, Logger::FATAL);
            throw new \Exception("Request failed: $code");
        }

        $responseStr = $response->getBody();

        $matches = [];
        preg_match_all(
            "/href=\"({$this->filePrefix}.*?{$this->fileSuffix})\"/",
            $responseStr,
            $matches,
            PREG_SET_ORDER
        );
        $files = [];
        foreach ($matches as $match) {
            $filename = $match[1];
            $date = $this->getFileDate($filename, $responseStr);
            if ($date === false) {
                $this->message(
                    "Invalid filename date in '$filename'", false, Logger::WARNING
                );
                continue;
            }
            if ($date > $this->startDate
                && (!$this->endDate || $date <= $this->endDate)
            ) {
                $files[] = $filename;
                if (!$this->trackedEndDate || $this->trackedEndDate < $date) {
                    $this->trackedEndDate = $date;
                }
            }
        }
        return $files;
    }

    /**
     * Fetch a file to be harvested
     *
     * @param string $filename File to retrieve
     *
     * @return string xml
     * @throws Exception
     */
    protected function retrieveFile($filename)
    {
        $request = new \HTTP_Request2(
            $this->baseURL . $filename,
            \HTTP_Request2::METHOD_GET,
            $this->httpParams
        );
        $request->setHeader('User-Agent', 'RecordManager');

        $url = $request->getURL();
        $urlStr = $url->getURL();
        $this->message("Sending request: $urlStr", true);

        // Perform request and throw an exception on error:
        $response = null;
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (\Exception $e) {
                if ($try < 5) {
                    $this->message(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . "), retrying in 30 seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
                throw $e;
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->message(
                        "Request '$urlStr' failed ($code), retrying in "
                        . "30 seconds...",
                        false,
                        Logger::WARNING
                    );
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = is_null($response) ? 999 : $response->getStatus();
        if ($code >= 300) {
            $this->message("Request '$urlStr' failed: $code", false, Logger::FATAL);
            throw new \Exception("Request failed: $code");
        }

        return $response->getBody();
    }

    /**
     * Process the records xml
     *
     * @param XMLReader $xml XML File of records
     *
     * @return void
     */
    protected function processRecords(&$xml)
    {
        while ($xml->read() && $xml->name !== $this->recordElem) {
        }
        $count = 0;
        $doc = new \DOMDocument;
        while ($xml->name == $this->recordElem) {
            ++$count;
            $expanded = $xml->expand();
            if ($expanded === false) {
                $this->message(
                    'Failed to expand node: ' . $xml->readOuterXml(),
                    false,
                    Logger::ERROR
                );
            } else {
                $this->processRecord(
                    simplexml_import_dom($doc->importNode($expanded, true)), $count
                );
                if ($count % 1000 == 0) {
                    $this->message("$count records processed", true);
                }
            }
            $xml->next($this->recordElem);
        }
    }

    /**
     * Save a harvested record.
     *
     * @param SimpleXMLElement $record Record
     * @param int              $recNum Record number in the file (1-based)
     *
     * @return void
     */
    protected function processRecord($record, $recNum)
    {
        $id = $this->extractID($record);
        if ($id === false) {
            $this->message(
                "No ID found in record $recNum: " . $record->asXML(),
                false,
                Logger::ERROR
            );
            return;
        }
        $oaiId = $this->createOaiId($this->source, $id);
        if ($this->isDeleted($record)) {
            call_user_func($this->callback, $this->source, $oaiId, true, null);
            $this->deletedRecords++;
        } elseif ($this->isModified($record)) {
            $this->normalizeRecord($record, $id);
            $this->changedRecords += call_user_func(
                $this->callback, $this->source, $oaiId, false, $record->asXML()
            );
        } else {
            // This assumes the provider may return records that are not changed or
            // deleted.
            $this->unchangedRecords++;
        }
    }

    /**
     * Extract file date from the file name or directory list response data
     *
     * @param string $filename    File name
     * @param string $responseStr Full HTTP directory listing response
     *
     * @return string|false Date in ISO8601 format or false if date could not be
     * determined
     */
    protected function getFileDate($filename, $responseStr)
    {
        $match = preg_match(
            '/(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)/', $filename, $dateparts
        );
        if (!$match) {
            return false;
        }
        $date = $dateparts[1] . '-' . $dateparts[2] . '-' . $dateparts[3] . 'T' .
                $dateparts[4] . ':' . $dateparts[5] . ':' . $dateparts[6];
        return $date;
    }

    /**
     * Normalize a record
     *
     * @param SimpleXMLElement $record Record
     * @param string           $id     Record ID
     *
     * @return void
     */
    protected function normalizeRecord(&$record, $id)
    {
    }
}

