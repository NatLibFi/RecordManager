<?php
/**
 * XSL Transformation Handler
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2011-2012.
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


/**
 * XslTransformation Class
 *
 * Class to manage XSL Transformations. Config file is compatible with VuFind's import-xsl.php.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class XslTransformation
{
    protected $xslt = null;

    /**
     * Constructor
     * 
     * @param string $basePath   Base path of transformation files
     * @param string $configFile Configuration (properties) file
     * @param array  $params     Parameters passed to the xslt
     * 
     * @throws Exception
     */
    public function __construct($basePath, $configFile, $params = null)
    {
        $options = parse_ini_file("$basePath/$configFile", true);
        $this->xslt = new XSLTProcessor();

        // Register any PHP functions
        if (isset($options['General']['php_function'])) {
            $this->xslt->registerPHPFunctions($options['General']['php_function']);
        }

        // Register any custom classes
        if (isset($options['General']['custom_class'])) {
            $classes = is_array($options['General']['custom_class']) ? $options['General']['custom_class'] : array($options['General']['custom_class']);
            foreach ($classes as $class) {
                // Find the file containing the class; if necessary, be forgiving
                // about filename case.
                $fullPath = $basePath . '/' . $class . '.php';
                if (!file_exists($fullPath)) {
                    $fullPath = $basePath . '/' . strtolower($class) . '.php';
                }
                include_once $fullPath;
                $methods = get_class_methods($class);
                foreach ($methods as $method) {
                    $this->xslt->registerPHPFunctions($class . '::' . $method);
                }
            }
        }

        // Load parameters
        if (isset($params)) {
            $this->xslt->setParameter('', $params);
        }
        if (isset($options['Parameters'])) {
            $this->xslt->setParameter('', $options['Parameters']);
        }

        $style = new DOMDocument();
        if ($style->load($basePath . '/' . $options['General']['xslt']) === false) {
            throw new Exception($basePath . '/' . $options['General']['xslt']);
        }
        $this->xslt->importStylesheet($style);
    }

    /**
     * Do a transformation
     * 
     * @param string $data   XML string
     * @param array  $params Parameters passed to the xslt
     * 
     * @return string XML
     * @throws Exception
     */
    public function transform($data, $params = null)
    {
        $doc = new DOMDocument();
        if ($doc->loadXML($data) === false) {
            throw new Exception("Invalid XML: $data");
        }
        if (isset($params)) {
            $this->xslt->setParameter('', $params);
        }
        $result = $this->xslt->transformToXml($doc);
        if (isset($params)) {
            foreach ($params as $key => $value) {
                $this->xslt->setParameter('', $key, '');
            }
        }
        return $result;
    }

    /**
     * Do a transformation resulting in an array
     * 
     * @param string $data   XML string
     * @param array  $params Parameters passed to the xslt
     * 
     * @return array
     * @throws Exception
     */
    public function transformToSolrArray($data, $params = null)
    {
        if (isset($params)) {
            $this->xslt->setParameter('', $params);
        }
        $doc = new DOMDocument();
        $doc->loadXML($data);
        $transformedDoc = $this->xslt->transformToDoc($doc);
        if ($transformedDoc === false) {
            throw new Exception("XSLTransformation: failed transformation: " . libxml_get_last_error());
        }

        $arr = array();
        $fieldNodes = $transformedDoc->getElementsByTagName('field');
        foreach ($fieldNodes as $node) {
            $key = $node->attributes->getNamedItem('name')->nodeValue;
            $value = $node->nodeValue;
            if (isset($arr[$key])) {
                if (!is_array($arr[$key])) {
                    $arr[$key] = array($arr[$key]);
                }
                $arr[$key][] = $value;
            } else {
                $arr[$key] = $value;
            }
        }
        return $arr;
    }
}

