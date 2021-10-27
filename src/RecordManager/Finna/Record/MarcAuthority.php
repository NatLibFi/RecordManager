<?php
/**
 * Marc authority Record Class
 *
 * PHP version 5
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

/**
 * Marc authority record class
 *
 * This is a class for processing MARC authority records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcAuthority extends \RecordManager\Base\Record\MarcAuthority
{
    /**
     * Delimiter for separating name related subfields.
     *
     * @var string
     */
    protected $nameDelimiter = ', ';

    /**
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array
     */
    public function toSolrArray(Database $db = null)
    {
        $data = parent::toSolrArray($db);

        $data['allfields']
            = array_merge(
                $data['allfields'],
                [$this->getHeading()],
                $this->getAlternativeNames()
            );
        return $data;
    }

    /**
     * Get alternative names.
     *
     * @param array $additional List of additional fields to return
     *
     * @return array
     */
    public function getAlternativeNames($additional = [])
    {
        $result = [];
        foreach (array_merge(['400', '410', '500', '510'], $additional)
            as $code
        ) {
            $subfields = in_array($code, ['400', '500'])
                ? ['a' => 1, 'b' => 1, 'c' => 1]
                : ['a' => 1, 'b' => 1];

            foreach ($this->getFields($code) as $field) {
                $result = array_merge(
                    $result,
                    [
                        implode(
                            $this->nameDelimiter,
                            $this->trimFields(
                                $this->getSubfieldsArray($field, $subfields)
                            )
                        )
                    ]
                );
            }
        }
        return array_unique($this->trimFields($result));
    }

    /**
     * Get heading
     *
     * @return string
     */
    protected function getHeading()
    {
        if ($name = $this->getFieldSubField('100', 'a', true)) {
            $name = $this->metadataUtils->stripTrailingPunctuation($name, '.');
            foreach (['b', 'c'] as $subfield) {
                if ($sub = $this->getFieldSubField('100', $subfield, true)) {
                    $name .= $this->nameDelimiter . $sub;
                }
            }
            return $name;
        }
        return parent::getHeading();
    }
}
