<?php
/**
* OAI-PMH Provider Front-End
*
* PHP version 5
*
* Copyright (C) Ere Maijala 2012.
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
*/

/**
 * OAI-PMH Provider Front-End
 *
 */

require 'classes/OaiPmhProvider.php';

$basePath = substr(__FILE__, 0, strrpos(__FILE__, DIRECTORY_SEPARATOR));
$configArray = parse_ini_file($basePath . '/conf/recordmanager.ini', true);

function main()
{
    $provider = new OaiPmhProvider();
    $provider->launch(); 
}

main();

