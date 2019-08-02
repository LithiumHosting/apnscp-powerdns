<?php declare(strict_types=1);
/**
 * apnscp PowerDNS Module
 *
 * @copyright   Copyright (c) Lithium Hosting, llc 2019
 * @author      Troy Siedsma (tsiedsma@lithiumhosting.com)
 * @license     see included LICENSE file
 */

namespace Opcenter\Dns\Providers\Powerdns;


use GuzzleHttp\Exception\RequestException;
use Opcenter\Dns\Contracts\ServiceProvider;
use Opcenter\Service\ConfigurationContext;

class Validator implements ServiceProvider {
    public function valid(ConfigurationContext $ctx, &$var): bool
    {
        return static::keyValid();
    }

    public static function keyValid(): bool
    {
        try
        {
            (new Api())->do('GET', 'statistics');
        }
        catch (RequestException $e)
        {
			if (null === ($response = $e->getResponse()))
			{
				return error('PowerDNS key check failed: %s', $e->getMessage());
			}
			switch ($response->getStatusCode())
			{
				case 404:
					$reason = 'Endpoint configuration is invalid';
					break;
				case 401:
					$reason = 'Invalid key';
					break;
				default:
					$reason = $response->getReasonPhrase();
			}
			return error('PowerDNS key check failed: %s', $reason);
        }

        return true;
    }
}
