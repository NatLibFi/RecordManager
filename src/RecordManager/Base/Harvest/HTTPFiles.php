<?php

/**
 * HTTP-based File Harvesting Class
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2011-2020.
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

namespace RecordManager\Base\Harvest;

use RecordManager\Base\Exception\HttpRequestException;
use RecordManager\Base\Utils\XmlSecurity;

use function call_user_func;
use function count;

/**
 * HTTPFiles Class
 *
 * This class harvests files via HTTP using settings from datasources.ini.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class HTTPFiles extends AbstractBase
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
     * Initialize harvesting
     *
     * @param string $source    Source ID
     * @param bool   $verbose   Verbose mode toggle
     * @param bool   $reharvest Whether running a reharvest
     *
     * @return void
     */
    public function init(string $source, bool $verbose, bool $reharvest): void
    {
        parent::init($source, $verbose, $reharvest);

        $settings = $this->dataSourceConfig[$source] ?? [];
        $this->filePrefix = $settings['filePrefix'] ?? '';
        $this->fileSuffix = $settings['fileSuffix'] ?? '';
    }

    /**
     * Harvest all available documents.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     * @throws \Exception
     */
    public function harvest($callback)
    {
        $this->initHarvest($callback);

        if (null !== $this->startDate) {
            $this->infoMsg('Incremental harvest from timestamp ' . $this->startDate);
        } else {
            $this->infoMsg('Initial harvest for all records');
        }
        $fileList = $this->retrieveFileList();
        $this->infoMsg('Files to harvest: ' . count($fileList));
        foreach ($fileList as $file) {
            $data = $this->retrieveFile($file);
            XmlSecurity::heuristicScanString($data);

            $this->infoMsg('Processing the records...');

            if (null !== $this->preXslt) {
                $data = $this->transform($data);
            }

            $tempFile = $this->getTempFileName('http-harvest-', '.xml');
            if (file_put_contents($tempFile, $data) === false) {
                $this->fatalMsg("Could not write to $tempFile");
                throw new \Exception("Could not write to $tempFile");
            }
            $data = '';

            $xml = new \XMLReader();
            $saveUseErrors = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $result = $xml->open($tempFile, null, LIBXML_PARSEHUGE);
            if ($result === false || libxml_get_last_error() !== false) {
                // Assuming it's a character encoding issue, this might help...
                $this->warningMsg('Invalid XML received, trying encoding fix...');
                $data = file_get_contents($tempFile);
                if (false === $data) {
                    $this->fatalMsg("Could not read from $tempFile");
                    throw new \Exception("Could not read from $tempFile");
                }
                $data = iconv('UTF-8', 'UTF-8//IGNORE', $data);
                if (file_put_contents($tempFile, $data) === false) {
                    $this->fatalMsg("Could not write to $tempFile");
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
                $this->fatalMsg("Could not parse XML response: $errors");
                throw new \Exception('Failed to parse XML response');
            }
            libxml_use_internal_errors($saveUseErrors);

            $this->processRecords($xml);
            $xml->close();
            if (!unlink($tempFile)) {
                $this->errorMsg("Could not remove $tempFile");
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
     * @throws \Exception
     * @return array
     */
    protected function retrieveFileList()
    {
        $request = $this->httpClientManager->createClient(
            $this->baseURL,
            \HTTP_Request2::METHOD_GET
        );

        $urlStr = $request->getURL()->getURL();
        $this->infoMsg("Sending request: $urlStr");

        // Perform request and throw an exception on error:
        $response = null;
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (\Exception $e) {
                if ($try < 5) {
                    $this->warningMsg(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . '), retrying in 30 seconds...'
                    );
                    sleep(30);
                    continue;
                }
                throw HttpRequestException::fromException($e);
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->warningMsg(
                        "Request '$urlStr' failed ($code), "
                        . 'retrying in 30 seconds...'
                    );
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = null === $response ? 999 : $response->getStatus();
        if ($code >= 300) {
            $this->fatalMsg("Request '$urlStr' failed: $code");
            throw new HttpRequestException("Request failed: $code", $code);
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
            $date = $this->getFileDate($filename);
            if ($date === false) {
                $this->warningMsg("Invalid filename date in '$filename'");
                continue;
            }
            if (
                $date > $this->startDate
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
     * @throws \Exception
     */
    protected function retrieveFile($filename)
    {
        $request = $this->httpClientManager->createClient(
            $this->baseURL . $filename,
            \HTTP_Request2::METHOD_GET
        );

        $urlStr = $request->getURL()->getURL();
        $this->infoMsg("Sending request: $urlStr");

        // Perform request and throw an exception on error:
        $response = null;
        for ($try = 1; $try <= 5; $try++) {
            try {
                $response = $request->send();
            } catch (\Exception $e) {
                if ($try < 5) {
                    $this->warningMsg(
                        "Request '$urlStr' failed (" . $e->getMessage()
                        . '), retrying in 30 seconds...'
                    );
                    sleep(30);
                    continue;
                }
                throw HttpRequestException::fromException($e);
            }
            if ($try < 5) {
                $code = $response->getStatus();
                if ($code >= 300) {
                    $this->warningMsg(
                        "Request '$urlStr' failed ($code), retrying in "
                        . '30 seconds...'
                    );
                    sleep(30);
                    continue;
                }
            }
            break;
        }
        $code = null === $response ? 999 : $response->getStatus();
        if ($code >= 300) {
            $this->fatalMsg("Request '$urlStr' failed: $code");
            throw new HttpRequestException("Request failed: $code", $code);
        }

        return $response->getBody();
    }

    /**
     * Process the records xml
     *
     * @param \XMLReader $xml XML File of records
     *
     * @return void
     */
    protected function processRecords(&$xml)
    {
        while ($xml->read() && $xml->name !== $this->recordElem) {
        }
        $count = 0;
        $doc = new \DOMDocument();
        while ($xml->name == $this->recordElem) {
            ++$count;
            $expanded = $xml->expand();
            if ($expanded === false) {
                $this->errorMsg('Failed to expand node: ' . $xml->readOuterXml());
            } else {
                $this->processRecord(
                    simplexml_import_dom($doc->importNode($expanded, true)),
                    $count
                );
                if ($count % 1000 == 0) {
                    $this->infoMsg("$count records processed");
                }
            }
            if (false === $xml->next($this->recordElem)) {
                break;
            }
        }
    }

    /**
     * Save a harvested record.
     *
     * @param \SimpleXMLElement $record Record
     * @param int               $recNum Record number in the file (1-based)
     *
     * @return void
     */
    protected function processRecord($record, $recNum)
    {
        $id = $this->extractID($record);
        if ($id === false) {
            $this->errorMsg("No ID found in record $recNum: " . $record->asXML());
            return;
        }
        $oaiId = $this->createOaiId($this->source, $id);
        if ($this->isDeleted($record)) {
            call_user_func($this->callback, $this->source, $oaiId, true, null);
            $this->deletedRecords++;
        } elseif ($this->isModified($record)) {
            $this->normalizeRecord($record, $id);
            $this->changedRecords += call_user_func(
                $this->callback,
                $this->source,
                $oaiId,
                false,
                $record->asXML()
            );
        } else {
            // This assumes the provider may return records that are not changed or
            // deleted.
            $this->unchangedRecords++;
        }
    }

    /**
     * Check if the record is deleted.
     * This implementation works for MARC records.
     *
     * @param \SimpleXMLElement $record Record
     *
     * @return bool
     */
    protected function isDeleted($record)
    {
        $status = substr($record->leader, 5, 1);
        return $status == 'd';
    }

    /**
     * Extract file date from the file name
     *
     * @param string $filename File name
     *
     * @return string|false Date in ISO8601 format or false if date could not be
     * determined
     */
    protected function getFileDate($filename)
    {
        $match = preg_match(
            '/(\d{4})(\d\d)(\d\d)(\d\d)(\d\d)(\d\d)/',
            $filename,
            $dateparts
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
     * @param \SimpleXMLElement $record Record
     * @param string            $id     Record ID
     *
     * @return void
     */
    protected function normalizeRecord(&$record, $id)
    {
    }
}
