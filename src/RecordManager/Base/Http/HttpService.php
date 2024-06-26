<?php

/**
 * HTTP service
 *
 * PHP version 8
 *
 * Copyright (c) The National Library of Finland 2020-2024.
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

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

use function array_key_exists;
use function in_array;

/**
 * HTTP service
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class HttpService
{
    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Mappings from HTTP_Request2 options
     *
     * @var array
     */
    protected $legacySettingsMappings = [
        'adapter' => 'handler',
        'follow_redirects' => 'allow_redirects',
        'ssl_verify_peer' => 'verify',
    ];

    /**
     * Constructor
     *
     * @param array $config Main configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->mapSettings();
        $this->mapLegacySettings();
    }

    /**
     * Create a client
     *
     * @param string $url     Request URL
     * @param array  $options Configuration options
     *
     * @return Client
     */
    public function createClient(
        string $url,
        array $options = []
    ): Client {
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

        if ($handler = $config['handler'] ?? 'Curl') {
            if (!class_exists($handler)) {
                $handler = "\\GuzzleHttp\\Handler\\{$handler}Handler";
            }
            if (!class_exists($handler)) {
                throw new \Exception("HTTP handler class $handler not found");
            }
            $config['handler'] = new HandlerStack(new $handler());
        }
        $config['headers']['User-Agent'] ??= 'RecordManager';

        return new Client($config);
    }

    /**
     * Append query parameters to a URL.
     *
     * Any existing query parameters with the same value are overridden.
     *
     * @param string $url   URL
     * @param array  $query Query params
     *
     * @return string
     */
    public function appendQueryParams(string $url, array $query): string
    {
        $parts = explode('#', $url);
        $url = $parts[0];
        $hash = isset($parts[1]) ? '#' . $parts[1] : '';
        $parts = explode('?', $url);
        $url = $parts[0];
        if ($oldQuery = $parts[1] ?? null) {
            parse_str($oldQuery, $oldQueryParams);
            $query = array_merge($oldQueryParams, $query);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query) . $hash;
    }

    /**
     * Map settings to Guzzle settings
     *
     * @return void
     */
    protected function mapSettings(): void
    {
        if ($this->config['HTTP']['tcp_keepalive'] ?? false) {
            $this->config['HTTP']['curl'][\CURLOPT_TCP_KEEPALIVE] = true;
            if ($interval = $this->config['HTTP']['tcp_keepalive_interval'] ?? null) {
                $this->config['HTTP']['curl'][\CURLOPT_TCP_KEEPINTVL] = (int)$interval;
            }
        }
    }

    /**
     * Map HTTP_Request2 settings to Guzzle settings
     *
     * @return void
     */
    protected function mapLegacySettings(): void
    {
        if (isset($this->config['HTTP'])) {
            foreach ($this->legacySettingsMappings as $src => $dst) {
                if (array_key_exists($src, $this->config['HTTP'])) {
                    if (!array_key_exists($dst, $this->config['HTTP'])) {
                        $this->config['HTTP'][$dst] = $this->config['HTTP'][$src];
                    }
                    unset($this->config['HTTP'][$src]);
                }
            }
            if ('HTTP_Request2_Adapter_Curl' === ($this->config['HTTP']['handler'] ?? null)) {
                $this->config['HTTP']['handler'] = 'Curl';
            }
        }
    }
}
