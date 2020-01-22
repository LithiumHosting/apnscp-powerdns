<?php declare(strict_types=1);
/**
 * apnscp PowerDNS Module
 *
 * @copyright   Copyright (c) Lithium Hosting, llc 2019
 * @author      Troy Siedsma (tsiedsma@lithiumhosting.com)
 * @license     see included LICENSE file
 */

namespace Opcenter\Dns\Providers\Powerdns;


use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
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
	 * @var int last destructive action
	 */
    private $lastModification;

	// @var int deadline for Packet Cache queries
	private $deadline;

    /**
     * Api constructor.
     */
    public function __construct()
    {
        $this->key      = AUTH_PDNS_KEY;
        $this->endpoint = AUTH_PDNS_URI;
        $this->client   = new \GuzzleHttp\Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            // disallow misconfigured endpoints that redirect to SSL but are configured without
            'allow_redirects' => [
            'on_redirect' => function (Request $request, Response $response) {
                $newLocation = array_get($response->getHeader('location'), 0, null);
                throw new ServerException('Not following. 3xx status code encountered: ' . $newLocation,
                    $request, $response);
                }
            ],
        ]);
        $this->lastModification = time();
        $this->deadline = defined('AUTH_PDNS_DEADLINE') ? (int)AUTH_PDNS_DEADLINE : 20;
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
			warn("Stripping `/' from endpoint `%s', remove the trailing / from auth.yaml", $endpoint);
			$endpoint = ltrim($endpoint, '/');
		}

		if ($method !== 'GET' && 0 !== strpos('cache/flush?', $endpoint)) {
			$this->lastModification = time();
		}

		if (strpos($endpoint, 'servers/') === false)
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

	/**
	 * Get last modification time
	 *
	 * Used to bypass Packet Cache
	 *
	 * @return int
	 */
    public function getLastModification(): int
	{
		return $this->lastModification;
	}

	public function dirty(): bool
	{
		return (time() - $this->lastModification) <= $this->deadline;
	}
}