<?php
/**
 * Authority handling support trait.
 *
 * PHP version 7
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
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Finna\Record;

/**
 * Authority handling support trait.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait AuthoritySupportTrait
{
    /**
     * Get authority record namespace.
     *
     * @param string $type Authority type
     *
     * @return string
     */
    protected function getAuthorityNamespace($type = '*')
    {
        return $this->dataSourceConfig[$this->source]['authority'][$type] ?? '';
    }

    /**
     * Prepend authority ID with namespace.
     * The ids that do not pass validation are discarded.
     *
     * @param string[] $ids  Array of authority ids
     * @param string   $type Authority type
     *
     * @return string[]
     */
    protected function addNamespaceToAuthorityIds($ids, $type = '*')
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $ids = array_filter(
            $ids,
            function ($id) use ($type) {
                return $this->allowAuthorityIdRegex($id, $type);
            }
        );
        $ns = $this->getAuthorityNamespace($type);
        $result = [];
        foreach ($ids as $id) {
            if (preg_match('/^https?:/', $id)) {
                // Never prefix http(s) url's
                $result[] = $id;
                continue;
            }
            $id = addcslashes($id, '\\.');
            $result[] = $ns ? "$ns.$id" : $id;
        }
        return $result;
    }

    /**
     * Check if the given authority is allowed to be used in linking.
     *
     * @param string $id   Authority id
     * @param string $type Authority type
     *
     * @return bool
     */
    protected function allowAuthorityIdRegex($id, $type)
    {
        if (!($regex = $this->getAuthorityIdRegex($type))) {
            return true;
        }
        if (preg_match('/^https?:/', $id)) {
            // Always allow http(s) url's
            return true;
        }
        return 1 === preg_match($regex, $id);
    }

    /**
     * Get regex used to validate authority ids.
     *
     * @param string $type Authority type
     *
     * @return string|null
     */
    protected function getAuthorityIdRegex($type = '*')
    {
        return $this->dataSourceConfig[$this->source]['authority_id_regex'][$type]
            ?? $this->dataSourceConfig[$this->source]['authority_id_regex']['*']
            ?? null;
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
