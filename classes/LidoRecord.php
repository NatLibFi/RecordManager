<?php
/**
 * LidoRecord Class
 *
 * PHP version 5
 *
 * Copyright (C) Ere Maijala 2011-2012
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
 * @category VuFind
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 */

require_once 'BaseRecord.php';

/**
 * LidoRecord Class
 *
 * This is a class for processing LIDO records.
 *
 */
class LidoRecord extends BaseRecord
{
    protected $_doc = null;

    /**
     * Constructor
     *
     * @param string $data Record metadata
     * @access public
     */
    public function __construct($data, $oaiID)
    {
        $this->_doc = simplexml_load_string($data);
    }

    /**
     * Return record ID (local)
     *
     * @return string
     * @access public
     */
    public function getID()
    {
		$id = $this->_doc->lido->lidoRecID;
		if(empty($id)) 
			$id = $this->_doc->children('http://www.lido-schema.org')->lido->lidoRecID;
		return $id;
    }

    /**
     * Serialize the record for storing in the database
     *
     * @return string
     * @access public
     */
    public function serialize()
    {
        return $this->_doc->asXML();
    }

    /**
     * Serialize the record into XML for export
     *
     * @return string
     * @access public
     */
    public function toXML()
    {
        return $this->_doc->asXML();
    }

    /**
     * Set the ID prefix into all the ID fields (ID, host ID etc.)
     *
     * @param  string $prefix (e.g. "source.")
     * @return void
     * @access public
     */
    public function setIDPrefix($prefix)
    {
        $this->_doc->lido->lidoRecID = $prefix . $this->_doc->lido->lidoRecID;
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @return array
     * @access public
     */
    public function toSolrArray()
    {
        return '';
    }


}

