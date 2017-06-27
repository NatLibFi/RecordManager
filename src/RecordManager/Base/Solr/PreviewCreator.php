<?php
/**
 * Preview Class
 *
 * PHP version 5
 *
 * Copyright (C) Eero Heikkinen, The National Board of Antiquities 2013.
 * Copyright (C) The National Library of Finland 2012-2014.
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
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Solr;

use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Record\Factory as RecordFactory;

/**
 * Preview Class
 *
 * This is a class for getting realtime previews of metadata normalization.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Eero Heikkinen <eero.heikkinen@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class PreviewCreator extends SolrUpdater
{
    /**
     * Create a preview of the given record
     *
     * @param array $record Record
     *
     * @return array Solr record fields
     */
    public function create($record)
    {
        $components = [];
        return $this->createSolrArray($record, $components);
    }
}
