<?php declare(strict_types=1);
/**
 * apnscp PowerDNS Module
 *
 * @copyright   Copyright (c) Lithium Hosting, llc 2019
 * @author      Troy Siedsma (tsiedsma@lithiumhosting.com)
 * @license     see included LICENSE file
 */

namespace Opcenter\Dns\Providers\Powerdns;


use GuzzleHttp\Psr7\Response;

class Api {
    protected $endpoint;
    /**
     * @var \GuzzleHttp\Client
     */
    protected $client;
    /**
     * @var string
     */
    protected $key;

    /**
     * @var Response
     */
    protected $lastResponse;

    /**
     * Api constructor.
     */
    public function __construct()
    {
        $this->key      = AUTH_PDNS_KEY;
        $this->endpoint = AUTH_PDNS_URI;
        $this->client   = new \GuzzleHttp\Client([
            'base_uri' => $this->endpoint,
        ]);
    }

    public function do(string $method, string $endpoint, array $params = null): array
    {
        $method = strtoupper($method);
        if (! \in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']))
        {
            error("Unknown method `%s'", $method);

            return [];
        }
        if ($endpoint[0] === '/')
        {
            warn("Stripping `/' from endpoint `%s'", $endpoint);
            $endpoint = ltrim($endpoint, '/');
        }
        if (strpos($endpoint, 'server') === false)
        {
            $endpoint = 'servers/localhost/' . $endpoint;
        }
        $this->lastResponse = $this->client->request($method, $endpoint, [
            'headers' => [
                'User-Agent' => PANEL_BRAND . " " . APNSCP_VERSION,
                'Accept'     => 'application/json',
                'X-API-Key'  => $this->key,
            ],
            'json'    => $params,
        ]);

        return \json_decode($this->lastResponse->getBody()->getContents(), true) ?? [];
    }

    public function getResponse(): Response
    {
        return $this->lastResponse;
    }
}