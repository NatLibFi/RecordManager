<?php
/**
 * Command line interface for exporting records
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
require_once __DIR__ . '/cmdline.php';

/**
 * Main function
 *
 * @param string[] $argv Program parameters
 *
 * @return void
 */
function main($argv)
{
    $params = parseArgs($argv);
    if (empty($params['file'])) {
        echo <<<EOT
Usage: $argv[0] --file=... [...]

Parameters:

--file=...          The file for records
--deleted=...       The file for deleted record IDs
--from=...          Update date (and optional time) where to start the export
                    (e.g. --from="2017-01-01 17:00")
--until=...         Update date (and optional time) where to end the export
                    (e.g. --until="2017-01-01 23:59:59")
--createdfrom=...   Creation date (and optional time) where to start the export
                    (e.g. --createdfrom="2017-01-01 17:00")
--createduntil=...  Creation date (and optional time) where to end the export
                    (e.g. --createduntil="2017-01-01 23:59:59")
--verbose           Enable verbose output
--quiet             Quiet, no output apart from the data
--skip=...          Skip x records to export only a "representative" subset
--source=...        Export only the given source(s)
                    (separate multiple sources with commas)
--single=...        Export single record with the given id
--xpath=...         Export only records matching the XPath expression
--config.section.name=...
                    Set configuration directive to given value overriding
                    any setting in recordmanager.ini
--sortdedup         Sort export file by dedup id
--dedupid=...       deduped = Add dedup id's to records that have duplicates
                    always  = Always add dedup id's to the records
                    Otherwise dedup id's are not added to the records
--basepath=path     Use path as the base directory for conf, mappings and
                    transformations directories. Normally automatically determined.


EOT;
        exit(1);
    }

    $app = bootstrap($params);
    $sm = $app->getServiceManager();

    $export = $sm->get(\RecordManager\Base\Controller\Export::class);
    $export->launch(
        $params['file'],
        $params['deleted'] ?? '',
        $params['from'] ?? '',
        $params['until'] ?? '',
        $params['createdfrom'] ?? '',
        $params['createduntil'] ?? '',
        $params['skip'] ?? 0,
        $params['source'] ?? '',
        $params['single'] ?? '',
        $params['xpath'] ?? '',
        $params['sortdedup'] ?? false,
        $params['dedupid'] ?? ''
    );
}

main($argv);
