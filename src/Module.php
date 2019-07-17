<?php declare(strict_types=1);
/**
 * apnscp PowerDNS Module
 *
 * @copyright   Copyright (c) Lithium Hosting, llc 2019
 * @author      Troy Siedsma (tsiedsma@lithiumhosting.com)
 * @license     see included LICENSE file
 */

namespace Opcenter\Dns\Providers\Powerdns;


use GuzzleHttp\Exception\ClientException;
use Module\Provider\Contracts\ProviderInterface;
use Opcenter\Dns\Record;

class Module extends \Dns_Module implements ProviderInterface {
    use \NamespaceUtilitiesTrait;

    const DNS_TTL = 14400;
    /**
     * apex markers are marked with @
     */
    protected const HAS_ORIGIN_MARKER = true;
    protected static $permitted_records = [
        'A',
        'AAAA',
        'CAA',
        'CNAME',
        'MX',
        'NS',
        'SRV',
        'TXT',
    ];
    protected        $metaCache         = [];

    private $ns;
    private $key;

    public function __construct()
    {
        parent::__construct();
        $this->key = AUTH_PDNS_KEY;
        $this->ns  = AUTH_PDNS;
    }

    /**
     * Add DNS zone to service
     *
     * @param string $domain
     * @param string $ip
     *
     * @return bool
     */
    public function add_zone_backend(string $domain, string $ip): bool
    {
        $domain = rtrim($domain, '\.');
        /**
         * @var Zones $api
         */
        $api = $this->makeApi();
        try
        {
            $resp = $api->do('POST', 'servers/localhost/zones', [
                'account'      => null,
                'kind'         => 'native',
                'soa_edit_api' => 'INCEPTION-INCREMENT',
                'masters'      => [],
                'name'         => $this->makeCanonical($domain),
                'nameservers'  => [],
                'rrsets'       => array_merge($this->createSOA($domain, $this->ns[0], 'hostmaster@' . $domain), $this->createNS($domain, $this->ns)),
            ]);
        }
        catch (ClientException $e)
        {
            return error("Failed to add zone '%s', error: %s", $domain, $this->renderMessage($e));
        }

        return true;
    }

    /**
     * Create a PowerDNS API client
     *
     * @return Api
     */
    private function makeApi(): Api
    {
        return new Api();
    }

    /**
     * returns canonical domain (e.g. always returns root dot)
     *
     * @param string $name
     *
     * @return string
     */
    private function makeCanonical($name)
    {
        if (empty($name)) // Sometimes the name is empty and ltrim throws a fit about it
        {
            return $name;
        }
        $name = trim($name, '.');
        if (substr($name, -1) !== '.')
        {
            return $name . '.';
        }

        return $name;
    }

    /**
     * Create SOA record for specified domain/zone
     *
     * @param $name
     * @param $primary
     * @param $soa_contact
     *
     * @return array
     */
    protected function createSOA($name, $primary, $soa_contact)
    {
        $rrsets = [
            'records' => [
                [
                    'content'  => sprintf(
                    // primary | contact | serial | refresh | retry | expire | ttl
                        '%s %s %s 3600 1800 604800 600',
                        $this->makeCanonical($primary),
                        $this->makeCanonical($soa_contact),
                        date('Ymd') . sprintf('%02d', rand(0, 99))
                    ),
                    'disabled' => false,
                ],
            ],
            'name'    => $this->makeCanonical($name),
            'ttl'     => 86400,
            'type'    => 'SOA',
        ];

        return [$rrsets];
    }

    /**
     * Create NS records for the specified domain/zone
     *
     * @param       $name
     * @param array $nameservers
     *
     * @return array
     */
    protected function createNS($name, array $nameservers): array
    {
        $rrsets = $records = [];

        foreach ($nameservers as $nameserver)
        {
            $records[] = [
                'content'  => $this->makeCanonical($nameserver),
                'disabled' => false,
            ];
        }

        $rrsets[] = [
            'records' => $records,
            'name'    => $this->makeCanonical($name),
            'ttl'     => 86400,
            'type'    => 'NS',
        ];

        return $rrsets;
    }

    /**
     * Extract JSON message if present
     *
     * @param ClientException $e
     *
     * @return string
     */
    private function renderMessage(ClientException $e): string
    {

        $body = \Error_Reporter::silence(function () use ($e) {
            return \json_decode($e->getResponse()->getBody()->getContents(), true);
        });
        if (! $body || ! ($reason = array_get($body, 'errors.0.reason')))
        {
            return $e->getMessage();
        }

        return $reason;
    }

    /**
     * Remove DNS zone from nameserver
     *
     * @param string $domain
     *
     * @return bool
     */
    public function remove_zone_backend(string $domain): bool
    {
        $api = $this->makeApi();
        try
        {
            $api->do('DELETE', 'servers/localhost/zones' . sprintf('/%s', $domain));
        }
        catch (ClientException $e)
        {
            return error("Failed to remove zone '%s', error: %s", $domain, $this->renderMessage($e));
        }

        return true;
    }

    /**
     * Creates Record with Zone Creation (not currently implemented)
     *
     * @param      $name
     * @param null $ip
     *
     * @return array
     */
    protected function createDefaultRecords($name, $ip = null): array
    {
        $rrsets = [];
        $cnames = $this->defaultCnames;

        if (! is_null($ip))
        {
            $records[] = [
                'content'  => $ip,
                'disabled' => false,
            ];

            $rrsets[] = [
                'records' => $records,
                'name'    => $this->makeCanonical($name),
                'ttl'     => 14400,
                'type'    => 'A',
            ];
        }

        foreach ($cnames as $cname)
        {
            $records = [0 => [
                'content'  => $this->makeCanonical($name),
                'disabled' => false,
            ]];

            $rrsets[] = [
                'records' => $records,
                'name'    => $this->makeFqdn($name, $cname, true),
                'ttl'     => 14400,
                'type'    => 'CNAME',
            ];
        }

        return $rrsets;
    }

    /**
     * Return a complete domain name with the subdomain and zone.
     * Optionally returns the canonical domain with trailing period
     *
     * @param      $zone
     * @param      $subdomain
     *
     * @param bool $makeCanonical
     *
     * @return string
     */
    private function makeFqdn($zone, $subdomain, $makeCanonical = false): string
    {
        if (strpos($subdomain, $zone) === false)
        {
            $subdomain = implode('.', [$subdomain, $zone]);
        }

        if ($makeCanonical)
        {
            return $this->makeCanonical($subdomain);
        }

        return $subdomain;
    }

    /**
     * Get raw zone data
     *
     * @param string $domain
     *
     * @return null|string
     */
    protected function zoneAxfr($domain): ?string
    {
        $domain = rtrim($domain, '\.');
        // @todo hold records in cache and synthesize AXFR
        $client = $this->makeApi();

        try
        {
            $records = $client->do('GET', "zones/${domain}");

            if (empty($records['rrsets']))
            {
                // No Records Exist
                return null;
            }
            $soa = array_get($this->get_records_external('', 'soa', $domain, $this->get_hosting_nameservers($domain)), 0, []);

            $ttldef   = (int) array_get(preg_split('/\s+/', $soa['parameter'] ?? ''), 6, static::DNS_TTL);
            $preamble = [];
            if ($soa)
            {
                $preamble = [
                    "${domain}.\t${ttldef}\tIN\tSOA\t${soa['parameter']}",
                ];
            }
            foreach ($this->get_hosting_nameservers($domain) as $ns)
            {
                $preamble[] = "${domain}.\t${ttldef}\tIN\tNS\t${ns}.";
            }
        }
        catch (ClientException $e)
        {
            if ($e->getResponse()->getStatusCode() === 422)
            {
                return null; // This really shouldn't happen
            }
            if ($e->getResponse()->getStatusCode() === 404)
            {
                return null; // No zone here!
            }

            error("Failed to transfer DNS records from PowerDNS - try again later. Response code: %d", $e->getResponse()->getStatusCode());

            return null;
        }
        foreach ($records['rrsets'] as $r)
        {
            foreach ($r['records'] as $record)
            {
                switch ($r['type'])
                {
                    case 'CAA':
                        // @XXX flags always defaults to "0"
                        $parameter = '0 ' . ' ' . $record['content'];
                        break;
                    case 'SRV':
                        $parameter = $record['content'];
                        break;
                    case 'MX':
                        $parameter = $record['content'];
                        break;
                    default:
                        $parameter = $record['content'];
                }
                $preamble[] = $r['name'] . "\t" . $r['ttl'] . "\tIN\t" . $r['type'] . "\t" . $parameter;
            }
        }
        $axfrrec = implode("\n", $preamble);

        return $axfrrec;
    }

    /**
     * Get hosting nameservers
     *
     * @param string|null $domain
     *
     * @return array
     */
    public function get_hosting_nameservers(string $domain = null): array
    {
        return $this->ns;
    }

    /**
     * Modify a DNS record
     *
     * @param string $zone
     * @param Record $old
     * @param Record $new
     *
     * @return bool
     */
    protected function atomicUpdate(string $zone, Record $old, Record $new): bool
    {
        if (! $this->canonicalizeRecord($zone, $old['name'], $old['rr'], $old['parameter'], $old['ttl']))
        {
            return false;
        }

        $old['ttl'] = null;

        if (! $this->canonicalizeRecord($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl']))
        {
            return false;
        }

        try
        {
            $merged = clone $old;
            $new    = $merged->merge($new);

            $this->add_record($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl']);
            $this->remove_record($zone, $old['name'], $new['rr'], $new['parameter']);
        }
        catch (ClientException $e)
        {
            return error("Failed to update record '%s' on zone '%s' (old - rr: '%s', param: '%s'; new - rr: '%s', param: '%s'): %s",
                $old['name'],
                $zone,
                $old['rr'],
                $old['parameter'], $new['name'] ?? $old['name'], $new['parameter'] ?? $old['parameter'],
                $this->renderMessage($e)
            );
        }

        return true;
    }

    /**
     * Add a DNS record
     *
     * @param string $zone
     * @param string $subdomain
     * @param string $rr
     * @param string $param
     * @param int    $ttl
     *
     * @return bool
     */
    public function add_record(string $zone, string $subdomain, string $rr, string $param, int $ttl = self::DNS_TTL): bool
    {
        if (! $this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl))
        {
            return false;
        }

        $record = new Record($zone, [
            'name'      => $subdomain,
            'rr'        => $rr,
            'parameter' => $param,
            'ttl'       => $ttl,
        ]);

        try
        {
            $api    = $this->makeApi();
            $rrsets = $this->formatRecord($record);
            $ret    = $api->do('PATCH', 'zones/' . $this->makeCanonical($zone), ['rrsets' => $rrsets]); // returns empty or zero???
        }
        catch (ClientException $e)
        {
//            info(json_encode(['rec' => $record->toArray(), 'rrsets' => $rrsets], JSON_PRETTY_PRINT));
//            info($e->getMessage());

            return error("Failed to create record '%s': %s", (string) $record, $this->renderMessage($e));
        }

        return true; // this sucks...
    }

    /**
     * Format a PowerDNS record prior to sending
     *
     * @param Record $r
     *
     * @return array
     */
    protected function formatRecord(Record $r): ?array
    {
        $type = strtoupper($r['rr']);
        $ttl  = $r['ttl'] ?? static::DNS_TTL;

        $content  = '';
        $priority = null;
        $name     = null;

        switch ($type)
        {
            case 'A':
            case 'AAAA':
            case 'CNAME':
            case 'TXT':
            case 'NS':
            case 'PTR':
                $content = $r['parameter'];
                break;
            case 'MX':
                $priority = (int) $r->getMeta('priority');
                $content  = sprintf(
                    '%d %s',
                    $r->getMeta('priority'),
                    $this->makeCanonical($r->getMeta('data'))
                );
                break;
            case 'SRV':
                $content = sprintf(
                // protocol | service | target | priority | weight | port
                    '%s %s %s %d %d %d',
                    $r->getMeta('protocol'),
                    $r->getMeta('service'),
                    $r->getMeta('data'),
                    (int) $r->getMeta('priority'),
                    (int) $r->getMeta('weight'),
                    (int) $r->getMeta('port')
                );
                break;
            case 'CAA':
                $content = sprintf(
                // tag | target
                    '%s %s',
                    $r->getMeta('tag'),
                    $r->getMeta('data')
                );
                break;
            default:
                fatal("Unsupported DNS RR type '%s'", $type);
        }

        if ($r['name'] === '@')
        {
            $r['name'] = '';
        }

        $rrsets[] = [
            'records'    => [0 => [
                'content'  => $content,
                'disabled' => false,
            ]],
            'name'       => $name ?? $this->makeFqdn($r['zone'], $r['name'], true),
            'ttl'        => $ttl,
            'type'       => $type,
            'prio'       => $priority ?? 0,
            'changetype' => 'REPLACE',
        ];

        return $rrsets;
    }

    /**
     * Remove a DNS record
     *
     * @param string      $zone
     * @param string      $subdomain
     * @param string      $rr
     * @param string|null $param
     *
     * @return bool
     */
    public function remove_record(string $zone, string $subdomain, string $rr, string $param = null): bool
    {
        $zone = rtrim($zone, '\.');
        if (! $canonicalZone = $this->canonicalizeRecord($zone, $subdomain, $rr, $param))
        {
            return false;
        }

        $record = new Record($zone, [
            'name'      => $subdomain,
            'rr'        => $rr,
            'parameter' => $param,
        ]);

        if ($record['name'] === '@')
        {
            $name = $this->makeCanonical($zone);
        }
        else
        {
            $name = $this->makeFqdn($zone, $subdomain, true);
        }

        $rrsets[] = [
            'records'    => '',
            'name'       => $name,
            'changetype' => 'DELETE',
            'type'       => $record['rr'],
        ];

        try
        {
            $api = $this->makeApi();
            $ret = $api->do('PATCH', "zones/${zone}", ['rrsets' => $rrsets]);
        }
        catch (ClientException $e)
        {
            $fqdn = $this->makeFqdn($zone, $subdomain);

            return error("Failed to delete record '%s' type %s", $fqdn, $rr);
        }

        return $api->getResponse()->getStatusCode() === 200;
    }

    /**
     * CNAME cannot be present in root
     *
     * @return bool
     */
    protected function hasCnameApexRestriction(): bool
    {
        return true;
    }

    /**
     * Strip the zone and trailing . from a subdomain/name entry
     *
     * @param $zone
     * @param $subdomain
     *
     * @return string
     */
    private function stripName($zone, $subdomain): string
    {
        $zone      = rtrim($zone, '\.');
        $subdomain = rtrim($subdomain, '\.');

        if (strpos($subdomain, $zone) !== false)
        {
            $subdomain = str_replace($zone, '', $subdomain);
        }

        return rtrim($subdomain, '\.');
    }
}
