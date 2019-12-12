<?php
/**
 * Forward authority Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2019.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Finna\Record;

/**
 * Forward authority Record Class
 *
 * This is a class for processing Forward records for an authority index.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class ForwardAuthority extends \RecordManager\Base\Record\ForwardAuthority
{
    use ForwardRecordTrait;

    /**
     * Get occupations
     *
     * @return array
     */
    protected function getOccupations()
    {
        $doc = $this->getMainElement();

        $result = [];
        if (isset($doc->ProfessionalAffiliation)) {
            $label = '';
            if (isset($doc->ProfessionalAffiliation->Affiliation)) {
                $label = (string)$doc->ProfessionalAffiliation->Affiliation;
                $attr = $doc->ProfessionalAffiliation->attributes();
                if (isset($attr->{'henkilo-kokoonpano-tyyppi'})) {
                    $label .=
                        ' (' . (string)$attr->{'henkilo-kokoonpano-tyyppi'} . ')';
                }
            }
            if (isset($doc->ProfessionalAffiliation->ProfessionalPosition)) {
                $position
                    = (string)$doc->ProfessionalAffiliation->ProfessionalPosition;
                $label = $label
                    ? $label .= ": $position"
                    : $position;
            }
            $result[] = $label;
        }
        return $result;
    }
}
