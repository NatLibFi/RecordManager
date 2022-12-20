<?php
/**
 * Pre-transformation trait
 *
 * Prerequisites:
 * - MetadataUtils as $this->metadataUtils
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2021.
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
namespace RecordManager\Base\Record;

/**
 * Pre-transformation trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
trait PreTransformationTrait
{
    /**
     * Execute a pretransformation on data before it is split into records and
     * loaded.
     *
     * @param string $data   The original data
     * @param string $source Source ID
     *
     * @return string Transformed data
     */
    protected function pretransform($data, $source)
    {
        $settings = &$this->dataSourceConfig[$source];
        // Shortcut
        if (empty($settings['preTransformation'])) {
            return $data;
        }

        if (!isset($settings['preXSLT'])) {
            $settings['preXSLT'] = [];
            foreach ((array)$settings['preTransformation'] as $transformation) {
                $style = new \DOMDocument();
                $style->load(
                    RECMAN_BASE_PATH . '/transformations/' . $transformation
                );
                $xslt = new \XSLTProcessor();
                $xslt->importStylesheet($style);
                $xslt->setParameter('', 'source_id', $source);
                $xslt->setParameter('', 'institution', $settings['institution']);
                $xslt->setParameter('', 'format', $settings['format']);
                $xslt->setParameter('', 'id_prefix', $settings['idPrefix'] ?? '');
                $settings['preXSLT'][] = $xslt;
            }
        }
        $doc = new \DOMDocument();
        $errors = '';
        $status = $this->metadataUtils->loadXML($data, $doc, 0, $errors);
        if (false === $status || $errors) {
            throw new \Exception($errors ?: 'Unknown error');
        }

        if (!empty($settings['reParseTransformed'])) {
            foreach ($settings['preXSLT'] as $xslt) {
                $xml = $xslt->transformToXml($doc);
                $doc = new \DOMDocument();
                $errors = '';
                $status = $this->metadataUtils->loadXML($xml, $doc, 0, $errors);
                if (false === $status || $errors) {
                    throw new \Exception($errors ?: 'Unknown error');
                }
            }
        } else {
            foreach ($settings['preXSLT'] as $xslt) {
                $doc = $xslt->transformToDoc($doc);
            }
        }
        return $doc->saveXML();
    }
}
