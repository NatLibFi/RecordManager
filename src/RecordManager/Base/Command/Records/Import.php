<?php
/**
 * Import
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Import extends AbstractBase
{
    use \RecordManager\Base\Command\StoreRecordTrait;
    use \RecordManager\Base\Record\PreTransformationTrait;

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Import records')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Data source'
            )->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Input file or files (may contain wildcards)'
            )->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Mark the imported records deleted'
            );
    }

    /**
     * Load records into the database from a file
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $source = $input->getArgument('source');
        $files = $input->getArgument('file');
        $delete = $input->getOption('delete');

        if (!isset($this->dataSourceConfig[$source])) {
            $this->logger->logFatal(
                'import',
                "Settings not found for data source $source"
            );
            throw new \Exception("Error: settings not found for $source\n");
        }
        $settings = &$this->dataSourceConfig[$source];
        $count = 0;
        $filelist = glob($files);
        if (empty($filelist)) {
            $this->logger->logWarning('import', 'No matching files found');
            return Command::FAILURE;
        }
        foreach ($filelist as $file) {
            $this->logger->logInfo(
                'import',
                "Loading records from '$file' into '$source'"
            );

            if (preg_match('/^[\/\w_:-]+$/', $settings['recordXPath'])) {
                $count += $this->streamingLoad($file, $source, $delete);
            } else {
                $this->logger->logInfo(
                    'import',
                    "Complex recordXPath, cannot use streaming loader"
                );
                $count += $this->fullLoad($file, $source, $delete);
            }

            $this->logger->logInfo('import', "$count records loaded");
        }

        $this->logger->logInfo('import', "Total $count records loaded");
        return Command::SUCCESS;
    }

    /**
     * Load XML by streaming (minimal memory usage)
     *
     * @param string $file   File name
     * @param string $source Source ID
     * @param bool   $delete Whether to delete records
     *
     * @throws \Exception
     * @return int Number of processed records
     */
    protected function streamingLoad($file, $source, $delete)
    {
        $settings = $this->dataSourceConfig[$source];
        $xml = new \XMLReader();
        $result = $xml->open($file);
        if (false === $result) {
            throw new \Exception("Could not parse '$file'");
        }
        $recordXPath = $settings['recordXPath'];
        $count = 0;
        $currentPath = [];
        while ($xml->read()) {
            if ($xml->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            $currentPath = array_slice($currentPath, 0, $xml->depth);
            $currentPath[] = $xml->name;

            // Compare XPath
            $currentPathString = '/' . implode('/', $currentPath);
            if (!$this->matchXPath($currentPathString, $recordXPath)) {
                continue;
            }
            $data = $xml->readOuterXML();
            if ($settings['preTransformation']) {
                $this->logger->writelnDebug("Executing pretransformation");
                $data = $this->pretransform($data, $source);
            }

            $oaiID = '';
            if ($settings['oaiIDXPath']) {
                $doc = new \DOMDocument();
                $doc->loadXML($data);
                $xpath = new \DOMXpath($doc);
                $xNodes = $xpath->query($settings['oaiIDXPath']);
                if ($xNodes->length == 0 || !$xNodes->item(0)->nodeValue) {
                    $this->logger->logFatal(
                        'streamingLoad',
                        "No OAI ID found with XPath '{$settings['oaiIDXPath']}'"
                            . ", record: $data"
                    );
                    throw new \Exception(
                        "No OAI ID found with XPath '{$settings['oaiIDXPath']}'"
                    );
                }
                $oaiID = $xNodes->item(0)->nodeValue;
            }

            $count += $this->storeRecord($source, $oaiID, $delete, $data);
            $this->logger->writelnDebug("Stored records: $count");
            if ($count % 1000 === 0) {
                $this->logger->logInfo('import', "$count records loaded");
            }
        }
        return $count;
    }

    /**
     * Load XML by loading the full file into memory
     *
     * @param string $file   File name
     * @param string $source Source ID
     * @param bool   $delete Whether to delete records
     *
     * @throws \Exception
     * @return int Number of processed records
     */
    protected function fullLoad($file, $source, $delete)
    {
        $settings = $this->dataSourceConfig[$source];
        $data = file_get_contents($file);
        if ($data === false) {
            throw new \Exception("Could not read file '$file'");
        }

        if ($settings['preTransformation']) {
            $this->logger->writelnDebug('Executing pretransformation');
            $data = $this->pretransform($data, $source);
        }

        $this->logger->writelnDebug('Creating File splitter');
        $params = [];
        if (!empty($settings['recordXPath'])) {
            $params['recordXPath'] = $settings['recordXPath'];
        }
        if (!empty($settings['oaiIDXPath'])) {
            $params['oaiIDXPath'] = $settings['oaiIDXPath'];
        }
        $splitter = $this->splitterPluginManager
            ->get(\RecordManager\Base\Splitter\File::class);
        $splitter->init($params);
        $splitter->setData($data);

        $this->logger->writelnDebug('Storing records');
        $count = 0;
        while (!$splitter->getEOF()) {
            $data = $splitter->getNextRecord();
            $count += $this->storeRecord(
                $source,
                $data['additionalData']['oaiId'] ?? '',
                $delete,
                $data['metadata']
            );
            $this->logger->writelnDebug("Stored records: $count");
        }
        return $count;
    }

    /**
     * Check if path matches an XPath. Only works for very simple XPaths.
     *
     * @param string $path  Path to check
     * @param string $xpath XPath expression
     *
     * @return bool
     */
    protected function matchXPath($path, $xpath)
    {
        if ($path === $xpath) {
            return true;
        }
        if (strncmp('//', $xpath, 2) === 0) {
            $xpath = substr($xpath, 1);
            return substr($path, -strlen($xpath)) == $xpath;
        }
        return false;
    }
}
