<?php
/**
 * Import an RDF file into an enrichment collection
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2022.
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
namespace RecordManager\Base\Command\Util;

use ML\JsonLD\Document;
use ML\JsonLD\LanguageTaggedString;
use ML\JsonLD\TypedValue;
use pietercolpaert\hardf\Util;
use RecordManager\Base\Command\AbstractBase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import an RDF file into an enrichment collection
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class ImportRdf extends AbstractBase
{
    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription(
                'Import RDF into the linked data enrichment collection or table'
            )
            ->addArgument('input', InputArgument::REQUIRED, 'Input file')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Input file format (only option at the moment is turtle)'
            );
    }

    /**
     * Import the file
     *
     * Serializes the objects into the database for fast retrieval
     *
     * @param InputInterface  $input  Console input
     * @param OutputInterface $output Console output
     *
     * @return int 0 if everything went fine, or an exit code
     */
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $inputFile = $input->getArgument('input');
        $subjectUri = '';
        $doc = null;
        $quiet = $output->isQuiet();
        $count = 0;

        $if = fopen($inputFile, 'r');
        if (false === $if) {
            $this->logger
                ->logFatal('ImportRdf', "Could not open input file '$inputFile'");
            return Command::FAILURE;
        }

        $tripleCallback = function (
            $error,
            $triple
        ) use (&$doc, &$subjectUri, &$count, $quiet) {
            if (isset($error)) {
                throw $error;
            } elseif (isset($triple)) {
                if ($triple['subject'] !== $subjectUri) {
                    // Flush current doc, if any:
                    if ($doc) {
                        $this->db->saveLinkedDataEnrichment(
                            [
                                '_id' => $subjectUri,
                                'data' => serialize($doc)
                            ]
                        );
                        ++$count;
                        if (!$quiet && $count % 1000 === 0) {
                            $this->logger
                                ->writelnConsole("$count imported");
                        }
                    }
                    $subjectUri = $triple['subject'];
                    $doc = new Document($triple['subject']);
                }
                if (Util::inDefaultGraph($triple)) {
                    $graph = $doc->getGraph();
                } else {
                    $graphName = $triple['graph'] ?? '';
                    if ($doc->containsGraph($graphName)) {
                        $graph = $doc->getGraph($graphName);
                    } else {
                        $graph = $doc->createGraph($graphName);
                    }
                }

                if ($graph->containsNode($triple['subject'])) {
                    $sbj = $graph->getNode($triple['subject']);
                } else {
                    $sbj = $graph->createNode($triple['subject'], true);
                }
                if ($sbj === null) {
                    throw new \Exception('Failed to create subject');
                }

                $object = $triple['object'];
                if (Util::isLiteral($object)) {
                    $objValue = Util::getLiteralValue($object);
                    $objLang = Util::getLiteralLanguage($object);
                    if (!empty($objLang)) {
                        $objNode = new LanguageTaggedString(
                            (string)$objValue,
                            $objLang
                        );
                    } else {
                        $objNode = new TypedValue(
                            (string)$objValue,
                            Util::getLiteralType($object)
                        );
                    }
                } elseif (Util::isBlank($object) || Util::isPrefixedName($object)
                    || Util::isIRI($object)
                ) {
                    if ($graph->containsNode($object)) {
                        $objNode = $graph->getNode($object);
                    } else {
                        $objNode = $graph->createNode($object, true);
                    }
                } else {
                    throw new \Exception("Invalid type to serialize: $object");
                }

                $predValue = $triple['predicate'];
                if ($predValue === \ML\JsonLD\RdfConstants::RDF_TYPE) {
                    if (!($objNode instanceof \ML\JsonLD\Node)) {
                        throw new \Exception(
                            'rdf:type predicate with non-named node object'
                        );
                    }
                    $sbj->addType($objNode);
                } else {
                    $sbj->addPropertyValue($predValue, $objNode);
                }
            } else {
                // Flush current doc, if any:
                if ($doc) {
                    $this->db->saveLinkedDataEnrichment(
                        [
                            '_id' => $subjectUri,
                            'data' => serialize($doc)
                        ]
                    );
                    ++$count;
                }
            }
        };

        $parser = new \pietercolpaert\hardf\TriGParser(
            ['format' => 'turtle'],
            $tripleCallback
        );

        while (false !== ($chunk = fgets($if))) {
            $parser->parseChunk($chunk);
        }
        $parser->end();

        if (!$quiet) {
            $this->logger
                ->writelnConsole("$count imported");
        }

        return Command::SUCCESS;
    }
}
