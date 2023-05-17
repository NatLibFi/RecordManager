<?php
/**
 * OAI-PMH Provider Front-End
 *
 * PHP version 8
 *
 * Copyright (C) Ere Maijala 2021.
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

/**
 * OAI-PMH Provider Front-End
 */
require_once __DIR__ . '/vendor/autoload.php';

define('RECMAN_BASE_PATH', getenv('RECMAN_BASE_PATH') ?: __DIR__);
$app = Laminas\Mvc\Application::init(
    include RECMAN_BASE_PATH . '/conf/application.config.php'
);
$sm = $app->getServiceManager();
$provider = $sm->get(\RecordManager\Base\Controller\OaiPmhProvider::class);
$provider->launch();
