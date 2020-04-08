<?php
/**
 * Finna record trait.
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
 * Finna record trait.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
trait FinnaRecordTrait
{
    /**
     * Prepend authority ID with namespace.
     *
     * @param string[] $ids  Array of authority ids
     * @param string   $type Authority type
     *
     * @return string[]
     */
    protected function addNamespaceToAuthorityIds($ids, $type = '*')
    {
        $ns = $this->dataSourceSettings[$this->source]['authority'][$type]
            ?? $this->source;

        if (!is_array($ids)) {
            $ids = [$ids];
        }
        return array_map(
            function ($id) use ($ns) {
                return "{$ns}.{$id}";
            },
            $ids
        );
    }

    /**
     * Combine author id and role into a string that can be indexed.
     *
     * @param string $id   Id
     * @param string $role Role
     *
     * @return string
     */
    protected function formatAuthorIdWithRole($id, $role)
    {
        return "{$id}###{$role}";
    }
}
