<?php

/**
 * HTTP client manager
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2020-2021.
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

namespace RecordManager\Base\Http;

use function in_array;

/**
 * HTTP client manager
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class ClientManager
{
    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor
     *
     * @param array $config Main configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Create an \HTTP_Request2 instance
     *
     * @param string $url     Request URL
     * @param string $method  Request method
     * @param array  $options Configuration options
     *
     * @return \HTTP_Request2
     */
    public function createClient(
        string $url,
        string $method,
        array $options = []
    ): \HTTP_Request2 {
        $config = $options + ($this->config['HTTP'] ?? []);
        if (isset($config['disable_proxy_hosts'])) {
            if ($url && !empty($config['proxy'])) {
                $host = parse_url($url, PHP_URL_HOST);
                if (in_array($host, (array)$config['disable_proxy_hosts'])) {
                    $config['proxy'] = '';
                }
            }
            unset($config['disable_proxy_hosts']);
        }

        $request = new \HTTP_Request2($url, $method, $config);
        $request->setHeader('User-Agent', 'RecordManager');
        return $request;
    }
}
