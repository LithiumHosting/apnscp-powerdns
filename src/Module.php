<?php declare(strict_types=1);
/**
 * apnscp PowerDNS Module
 *
 * @copyright   Copyright (c) Lithium Hosting, llc 2019
 * @author      Troy Siedsma (tsiedsma@lithiumhosting.com)
 * @license     see included LICENSE file
 */

namespace Opcenter\Dns\Providers\Powerdns;


use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
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
    private $records;

    public function __construct()
    {
        parent::__construct();
        $this->key     = AUTH_PDNS_KEY;
        $this->ns      = defined('AUTH_PDNS_NS') ? AUTH_PDNS_NS : AUTH_PDNS; // Backwards compatible
        $this->records = [];
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
        try
        {
            $api = $this->makeApi();
            $api->do('POST', 'servers/localhost/zones', [
                'kind'        => 'native', // Enables backend replication via MySQL Replication or MariaDB Galera replication
                'name'        => $this->makeCanonical($domain),
                'nameservers' => [], // Required to provision but allowed to be empty since we provide the NS rrsets
                'rrsets'      => array_merge($this->createSOA($domain, $this->ns[0], 'hostmaster@' . $domain), $this->createNS($domain, $this->ns)),
            ]);
        }
        catch (ServerException $e)
		{
			return error('PowerDNS master reported internal error. Check PowerDNS log.');
		}
		catch (ClientException $e)
		{
			return error("Failed to add zone '%s', error: %s", $domain, $this->renderMessage($e));
		}

        return $api->getResponse()->getStatusCode() === 201; // Returns 201 Created on success.
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
                        date('Ymd') . '01'
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
    private function renderMessage(BadResponseException $e): string
    {

        $body = (array)\Error_Reporter::silence(function () use ($e) {
            return \json_decode($e->getResponse()->getBody()->getContents(), true);
        });

        if (! ($reason = array_get($body, 'error')) )
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
        try
        {
            $api = $this->makeApi();
            $api->do('DELETE', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($domain)));
        }
        catch (ClientException $e)
        {
            return error("Failed to remove zone '%s', error: %s", $domain, $this->renderMessage($e));
        }

        return $api->getResponse()->getStatusCode() === 204; // Returns 204 No Content on success.;
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

    public function zone_exists(string $zone): bool
    {
        $zone = rtrim($zone, '\.');
        try
        {
            $api = $this->makeApi();
            $api->do('GET', "zones/${zone}");
        }
        catch (ClientException $e)
        {
            return false;
        }

        return $api->getResponse()->getStatusCode() === 200;
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
            $api = $this->makeApi();
            // Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
            $zoneData      = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($zone)));
            $this->records = $zoneData['rrsets'];

            $rrsets = $this->addRecords($record);

            $api->do('PATCH', 'zones' . sprintf('/%s', $this->makeCanonical($zone)), ['rrsets' => $rrsets]);
        }
        catch (ClientException $e)
        {
            return error("Failed to create record '%s': %s", (string) $record, $this->renderMessage($e));
        }

        return $api->getResponse()->getStatusCode() === 204; // Returns 204 No Content on success.
    }

    /**
     * Parse existing records for zone, add to records to ensure same named records are not removed
     * Refer to https://doc.powerdns.com/authoritative/http-api/zone.html#rrset under "changetype"
     *
     * @param \Opcenter\Dns\Record $record
     *
     * @return array
     */
    private function addRecords(Record $record): array
    {
        $return = [];

        $name = $name = $this->replaceMarker($record['zone'], $record['name']);

        foreach ($this->records as $rrset)
        {
            if ($rrset['name'] === $name && $rrset['type'] === $record['rr'])
            {
                $rrset['records'][]  = ['content' => $this->parseRecord($record), 'disabled' => false];
                $rrset['changetype'] = 'REPLACE';
                $return[]            = $rrset;
            }
        }

        // No records match the name and type, let's create a new record set
        if (empty($return))
        {
            $return[] = $this->formatRecord($record);
        }

        return $return;
    }

    /**
     * Replaces the @ with the fqdn
     *
     * @param $zone
     * @param $name
     *
     * @return string
     */
    protected function replaceMarker($zone, $name): string
    {
        if ($name === '@')
        {
            $name = $this->makeCanonical($zone);
        }
        else
        {
            $name = $this->makeFqdn($zone, $name, true);
        }

        return $name;
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
     * Parse a Record and return the content for the RRSET
     *
     * @param \Opcenter\Dns\Record $r
     *
     * @return string
     */
    private function parseRecord(Record $r): string
    {
        $type    = strtoupper($r['rr']);
        $content = '';

        switch ($type)
        {
            case 'A':
                $content = $r['parameter'];
                break;
            case 'AAAA':
                $content = $r['parameter'];
                break;
            case 'CNAME':
                if ($r['parameter'] === '@' || $r['parameter'] === '127.0.0.1') // If 127.0.0.1, the user hit submit with an empty field that was pre-saved with the default A record value!
                {
                    $r['parameter'] = $r['zone'];
                }

                $content = $this->makeCanonical($r['parameter']);
                break;
            case 'TXT':
                $content = $r['parameter'];
                break;
            case 'NS':
                $content = $r['parameter'];
                break;
            case 'PTR':
                $content = $r['parameter'];
                break;
            case 'MX':
                $content = sprintf(
                    '%d %s',
                    $r->getMeta('priority'),
                    $this->makeCanonical($r->getMeta('data'))
                );
                break;
            case 'SRV':
                // priority | weight | port | hostname/target
                $content = sprintf(
                    '%d %d %d %s',
                    (int) $r->getMeta('priority'),
                    (int) $r->getMeta('weight'),
                    (int) $r->getMeta('port'),
                    $this->makeCanonical($r->getMeta('data'))
                );
                break;
            case 'CAA':
                $content = sprintf(
                // flags | tag | target
                    '%d %s %s',
                    $r->getMeta('flags'),
                    $r->getMeta('tag'),
                    $r->getMeta('data')
                );
                break;
            default:
                fatal("Unsupported DNS RR type '%s'", $type);
        }

        return $content;
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

        $priority = null;
        $name     = null;

        $content = $this->parseRecord($r);

        if ($r['name'] === '@')
        {
            $r['name'] = '';
        }

        $rrset = [
            'records'    => [0 => [
                'content'  => $content,
                'disabled' => false,
            ]],
            'name'       => $name ?? $this->makeFqdn($r['zone'], $r['name'], true),
            'ttl'        => $ttl,
            'type'       => $type,
            'changetype' => 'REPLACE',
        ];

        return $rrset;
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
        if (! $this->canonicalizeRecord($zone, $subdomain, $rr, $param))
        {
            return false;
        }

        $record = new Record($zone, [
            'name'      => $subdomain,
            'rr'        => $rr,
            'parameter' => $param,
        ]);

        try
        {
            $api = $this->makeApi();
            // Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
            $zoneData      = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($zone)));
            $this->records = $zoneData['rrsets'];

            $rrsets = $this->removeRecords($record);

            $ret = $api->do('PATCH', 'zones' . sprintf('/%s', $this->makeCanonical($zone)), ['rrsets' => $rrsets]);
        }
        catch (ClientException $e)
        {
            $fqdn = $this->makeFqdn($zone, $subdomain);

            return error("Failed to delete record '%s' type %s - Reason: %s", $fqdn, $rr, $this->renderMessage($e));
        }

        return $api->getResponse()->getStatusCode() === 204; // Returns 204 No Content on success.
    }

    /**
     * Parse existing records for zone, ensure only the deleted record is removed from the same named records
     * Refer to https://doc.powerdns.com/authoritative/http-api/zone.html#rrset under "changetype"
     *
     * @param \Opcenter\Dns\Record $record
     *
     * @return array
     */
    private function removeRecords(Record $record): array
    {
        $return = [];

        $name = $this->replaceMarker($record['zone'], $record['name']);

        foreach ($this->records as $rrset)
        {
            if ($rrset['name'] === $name && $rrset['type'] === $record['rr'])
            {
                foreach ($rrset['records'] as $k => $rrec)
                {
                    if ($rrec['content'] === $record['parameter'])
                    {
                        unset($rrset['records'][ $k ]);
                        $rrset['records'] = array_values($rrset['records']);
                    }
                }
                $rrset['changetype'] = 'REPLACE';
                unset($rrset['comments']);

                $return[] = $rrset;
            }
        }

        // No records match the name and type, let's create a new record set
        if (empty($return))
        {
            $return[] = [
                'records'    => '',
                'name'       => $name,
                'changetype' => 'DELETE',
                'type'       => $record['rr'],
            ];
        }

        return $return;
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
     * Get raw zone data
     *
     * @param string $domain
     *
     * @return null|string
     */

    protected function zoneAxfr($domain): ?string
    {
        try
        {
            $api     = $this->makeApi();
            $axfrrec = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($domain)) . '/export');
        }
        catch (ClientException $e)
        {
			// ignore zone does not exist
			warn("Failed to transfer DNS records from PowerDNS - try again later. Response code: %d", $e->getResponse()->getStatusCode());
			return null;
		}
        return $axfrrec['zone'];
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

        if (! $this->canonicalizeRecord($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl']))
        {
            return false;
        }

        try
        {
            $api = $this->makeApi();
            // Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
            $zoneData      = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($zone)));
            $this->records = $zoneData['rrsets'];

            $rrsets = $this->changeRecords($old, $new);

            $api->do('PATCH', 'zones' . sprintf('/%s', $this->makeCanonical($zone)), ['rrsets' => $rrsets]);
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
     * Parse existing records for zone, find and remove the old record from the list and add the new one.
     * Refer to https://doc.powerdns.com/authoritative/http-api/zone.html#rrset under "changetype"
     *
     * @param \Opcenter\Dns\Record $old
     * @param \Opcenter\Dns\Record $new
     *
     * @return array
     */
    private function changeRecords(Record $old, Record $new): array
    {
        $return = [];

        $oldName = $this->replaceMarker($old['zone'], $old['name']);
        $newName = $this->replaceMarker($new['zone'], $new['name']);

        $added = false; // Did we add the record to an existing rrset?

        foreach ($this->records as $rrset)
        {
            if ($rrset['name'] === $oldName && $rrset['type'] === $old['rr'])
            {
                if (count($rrset['records']) > 1) // More than one record, loop through and delete just the record
                {
                    foreach ($rrset['records'] as $k => $rrec)
                    {
                        if ($rrec['content'] === $old['parameter'])
                        {
                            unset($rrset['records'][ $k ]);
                            $rrset['records'] = array_values($rrset['records']);
                        }
                    }
                }
                else
                {
                    // Only 1 record, just delete the rrset.
                    $return[] = [
                        'records'    => '',
                        'name'       => $oldName,
                        'changetype' => 'DELETE',
                        'type'       => $old['rr'],
                    ];
                }
            }

            if ($rrset['name'] === $newName && $rrset['type'] === $new['rr'])
            {
                $rrset['records'][]  = ['content' => $this->parseRecord($new), 'disabled' => false];
                $rrset['changetype'] = 'REPLACE';
                $return[]            = $rrset;

                $added = true;
            }
        }

        // No records match the name and type, let's create a new record set
        if (true !== $added)
        {
            $return[] = $this->formatRecord($new);
        }

        return $return;
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
