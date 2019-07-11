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
            (new Api())->do('GET', '/servers');
        }
        catch (RequestException $e)
        {
            $response = \json_decode($e->getResponse()->getBody()->getContents(), true);
            $reason   = array_get($response, 'errors.0.reason', "Invalid key");

            return error("PowerDNS key failed: %s", $reason);
        }

        return true;
    }
}
