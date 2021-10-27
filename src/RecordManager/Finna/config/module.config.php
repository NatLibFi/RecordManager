<?php
/**
 * Finna module configuration
 *
 * PHP version 7
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
namespace RecordManager\Finna\Module\Config;

return [
    'recordmanager' => [
        'plugin_managers' => [
            'record' => [
                'factories' => [
                    \RecordManager\Finna\Record\Dc::class => \RecordManager\Base\Record\AbstractRecordWithHttpClientManagerFactory::class,
                    \RecordManager\Finna\Record\Eaccpf::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Ead::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Ead3::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Forward::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\ForwardAuthority::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Lido::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Lrmi::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Marc::class => \RecordManager\Finna\Record\MarcFactory::class,
                    \RecordManager\Finna\Record\MarcAuthority::class => \RecordManager\Base\Record\AbstractRecordFactory::class,
                    \RecordManager\Finna\Record\Qdc::class => \RecordManager\Base\Record\AbstractRecordWithHttpClientManagerFactory::class,
                ],
                'aliases' => [
                    \RecordManager\Base\Record\Dc::class => \RecordManager\Finna\Record\Dc::class,
                    \RecordManager\Base\Record\Eaccpf::class => \RecordManager\Finna\Record\Eaccpf::class,
                    \RecordManager\Base\Record\Ead::class => \RecordManager\Finna\Record\Ead::class,
                    \RecordManager\Base\Record\Ead3::class => \RecordManager\Finna\Record\Ead3::class,
                    \RecordManager\Base\Record\Forward::class => \RecordManager\Finna\Record\Forward::class,
                    \RecordManager\Base\Record\ForwardAuthority::class => \RecordManager\Finna\Record\ForwardAuthority::class,
                    \RecordManager\Base\Record\Lido::class => \RecordManager\Finna\Record\Lido::class,
                    \RecordManager\Base\Record\Lrmi::class => \RecordManager\Finna\Record\Lrmi::class,
                    \RecordManager\Base\Record\Marc::class => \RecordManager\Finna\Record\Marc::class,
                    \RecordManager\Base\Record\MarcAuthority::class => \RecordManager\Finna\Record\MarcAuthority::class,
                    \RecordManager\Base\Record\Qdc::class => \RecordManager\Finna\Record\Qdc::class,
                ],
            ],
            'splitter' => [
                'factories' => [
                    \RecordManager\Finna\Splitter\Ead3::class => \RecordManager\Base\Splitter\AbstractBaseFactory::class,
                ],
            ],
        ],
    ],
    'service_manager' => [
        'factories' => [
        ],
    ],
];
