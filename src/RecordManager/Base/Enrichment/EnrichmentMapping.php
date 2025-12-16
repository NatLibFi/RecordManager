<?php

/**
 * Enrichment mapping enum
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2025.
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
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Base\Enrichment;

/**
 * Enrichment mapping enum
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
enum EnrichmentMapping: string
{
    case SKOSMOS_ENRICHMENT = 'SkosmosEnrichment';
    case AUTH_ENRICHMENT = 'AuthEnrichment';

    /**
     * Get name of the enrichment from string. This function handles legacy names.
     *
     * @param string $value Value
     *
     * @return string Mapped value or original value
     */
    public static function fromString(string $value): string
    {

        $valueToLower = strtolower($value);
        return match (true) {
            str_ends_with($valueToLower, 'skosmosenrichment') => self::SKOSMOS_ENRICHMENT->value,
            str_ends_with($valueToLower, 'onkilightenrichment') => self::SKOSMOS_ENRICHMENT->value,
            str_ends_with($valueToLower, 'authenrichment') => self::AUTH_ENRICHMENT->value,
            default => $value,
        };
    }
}
