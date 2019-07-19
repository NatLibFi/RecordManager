<?php
/**
 * Pre-transformation trait
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2017.
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
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Controller;

/**
 * Pre-transformation trait
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
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
        $settings = &$this->dataSourceSettings[$source];
        if (!isset($settings['preXSLT'])) {
            $style = new \DOMDocument();
            $style->load(
                $this->basePath . '/transformations/'
                . $settings['preTransformation']
            );
            $settings['preXSLT'] = new \XSLTProcessor();
            $settings['preXSLT']->importStylesheet($style);
            $settings['preXSLT']->setParameter('', 'source_id', $source);
            $settings['preXSLT']->setParameter(
                '', 'institution', $settings['institution']
            );
            $settings['preXSLT']->setParameter('', 'format', $settings['format']);
            $settings['preXSLT']->setParameter(
                '', 'id_prefix', $settings['idPrefix']
            );
        }
        $saveUseErrors = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $doc = new \DOMDocument();
            if ($doc->loadXML($data, LIBXML_PARSEHUGE) === false) {
                $errors = libxml_get_errors();
                $messageParts = [];
                foreach ($errors as $error) {
                    $messageParts[] = '[' . $error->line . ':' . $error->column
                        . '] Error ' . $error->code . ': ' . $error->message;
                }
                throw new \Exception(implode("\n", $messageParts));
            }
            libxml_use_internal_errors($saveUseErrors);
        } catch (\Exception $e) {
            libxml_use_internal_errors($saveUseErrors);
            throw $e;
        }
        return $settings['preXSLT']->transformToXml($doc);
    }
}
