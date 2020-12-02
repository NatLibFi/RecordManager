<?php
/**
 * HTTP client factory
 *
 * PHP version 7
 *
 * Copyright (c) The National Library of Finland 2020.
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
namespace RecordManager\Base\Http;

/**
 * HTTP client factory
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class ClientFactory
{
    /**
     * Create an \HTTP_Request2 instance
     *
     * @param string $url    Request URL
     * @param string $method Request method
     * @param array  $config Configuration for this Request instance
     *
     * @return \HTTP_Request2
     */
    public static function createClient(string $url, string $method, array $config
    ): \HTTP_Request2 {
        if (isset($config['disable_proxy_hosts'])) {
            if ($url && !empty($config['proxy'])) {
                $host = parse_url($url, PHP_URL_HOST);
                if (in_array($host, (array)$config['disable_proxy_hosts'])) {
                    $config['proxy'] = '';
                }
            }
            unset($config['disable_proxy_hosts']);
        }

        $result = new \HTTP_Request2($url, $method, $config);
        $result->setHeader('User-Agent', 'RecordManager');
        return $result;
    }
}
