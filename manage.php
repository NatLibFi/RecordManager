<?php
/**
 * Command line interface for RecordManager
 *
 * PHP version 8
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

convertOptions(
    [
        'func' => [
            'valueMap' => [
                'renormalize' => 'records:renormalize',
                'deduplicate' => 'records:deduplicate',
                'updatesolr' => 'solr:update-index',
                'dump' => 'records:dump',
                'dumpsolr' => 'solr:dump-records',
                'markdeleted' => 'records:mark-deleted',
                'deletesource' => 'records:delete-source',
                'optimizesolr' => 'solr:optimize',
                'count' => 'records:count',
                'checkdedup' => 'records:check-dedup',
                'checksolr' => 'solr:check-index',
                'comparesolr' => 'solr:compare-records',
                'purgedeleted' => 'records:purge-deleted',
                'markforupdate' => 'records:mark-for-update',
                'suppress' => 'records:suppress',
                'unsuppress' => 'records:unsuppress',
            ]
        ],
        'nocommit' => [
            'opt' => 'no-commit'
        ],
        'field' => [
            'arg' => 1,
        ],
        'lockfile' => [
            'opt' => 'lock'
        ],
        'comparelog' => [
            'opt' => 'log'
        ],
        'dumpprefix' => [
            'opt' => 'file-prefix'
        ],
        'daystokeep' => [
            'opt' => 'days-to-keep'
        ],
        'dateperserver' => [
            'opt' => 'date-per-server'
        ]
    ],
);

include __DIR__ . '/console';

