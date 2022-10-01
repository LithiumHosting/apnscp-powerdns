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
	use GuzzleHttp\Exception\ConnectException;
	use GuzzleHttp\Exception\RequestException;
	use GuzzleHttp\Exception\ServerException;
	use Module\Provider\Contracts\ProviderInterface;
	use Opcenter\Dns\Record as RecordBase;

	class Module extends \Dns_Module implements ProviderInterface
	{
		use \NamespaceUtilitiesTrait;

		const ZONE_TYPE = 'native'; // Enables backend replication via MySQL Replication or MariaDB Galera replication

		/**
		 * apex markers are marked with @
		 */
		protected const HAS_ORIGIN_MARKER = true;

		protected static $permitted_zone_types = [
			'master',
			'slave',
			'native',
		];

		protected static $permitted_records = [
			'A',
			'AAAA',
			'CAA',
			'CERT',
			'CNAME',
			//'DS',
			//'LOC',
			'MX',
			'NAPTR',
			'NS',
			'PTR',
			'SOA',
			'SMIMEA',
			'SPF',
			'SRV',
			//'SSHFP',
			'TXT',
			'URI',
		];

		protected $metaCache = [];

		/**
		 * @var Api pdns API client
		 */
		private $api;
		private $ns;
		private $records = [];

		public function __construct()
		{
			parent::__construct();

			if (!$this->hasCnameApexRestriction()) {
				static::$permitted_records[] = 'ALIAS';
			}

			$this->ns = \defined('AUTH_PDNS_NS') ? AUTH_PDNS_NS : AUTH_PDNS; // Backwards compatible
		}

		/**
		 * Required for serializing afi instance
		 *
		 * @return int[]|string[]
		 */
		public function __sleep()
		{
			$this->api = null;

			return array_keys(get_object_vars($this));
		}


		/**
		 * CNAME cannot be present in root
		 *
		 * @return bool
		 */
		protected function hasCnameApexRestriction(): bool
		{
			// ALIAS record mitigates this
			if (!\defined('AUTH_DNS_RECURSION')) {
				return true;
			}

			return !AUTH_PDNS_RECURSION;
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
			try {
				$nsNames = $this->get_hosting_nameservers($domain);
				$api = $this->makeApi();
				$api->do('POST', 'servers/localhost/zones', [
					'kind'        => $this->getZoneType(),
					'name'        => $this->makeCanonical($domain),
					'nameservers' => [], // Required to provision but allowed to be empty since we provide the NS rrsets
					'rrsets'      => array_merge($this->createSOA($domain, $this->ns[0], $this->getSOAContact($domain)),
						$this->createNS($domain, $nsNames)),
				]);
			} catch (ConnectException $e) {
				return error("Failed to connect to PowerDNS API service", $e->getMessage());
			} catch (ServerException $e) {
				return error('PowerDNS master reported internal error. Check PowerDNS log.');
			} catch (ClientException $e) {
				return error("Failed to add zone '%(zone)s', error: %(err)s",
					['zone' => $domain, 'err' => $this->renderMessage($e)]);
			}

			return $api->getResponse()->getStatusCode() === 201; // Returns 201 Created on success.
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
		 * Create a PowerDNS API client
		 *
		 * @param string $zone per-zone tracking
		 * @return Api
		 */
		private function makeApi(): Api
		{
			if (!isset($this->api)) {
				return $this->api = new Api();
			}

			return $this->api;
		}

		/**
		 * Get zone replication type
		 *
		 * @return string
		 */
		protected function getZoneType(): string
		{
			if (!\defined('AUTH_PDNS_TYPE')) {
				return static::ZONE_TYPE;
			}

			if (!\in_array(AUTH_PDNS_TYPE, static::$permitted_zone_types, true)) {
				fatal("Unknown PowerDNS server type '%s'", AUTH_PDNS_TYPE);
			}

			return AUTH_PDNS_TYPE;
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
			if (substr($name, -1) !== '.') {
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
			$soa = $this->makeCanonical($soa_contact);
			if (false !== strpos($soa, '@')) {
				$soa = str_replace('\\.', '.', $soa);
				$pos = strpos($soa, '@');
				$soa = str_replace('.', '\\.', substr($soa, 0, $pos)) . '.' . substr($soa, $pos+1);
			}
			$rrsets = [
				'records' => [
					[
						'content'  => sprintf(
							// primary | contact | serial | refresh | retry | expire | ttl
							'%s %s %s 3600 1800 604800 600',
							$this->makeCanonical($primary),
							$soa,
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
		 * Get SOA authority for a given domain
		 *
		 * This may be overriden via auth.yml
		 *
		 * @param string $domain
		 * @return string
		 */
		protected function getSOAContact(string $domain): string
		{
			if (\defined('AUTH_PDNS_SOA') && !empty(AUTH_PDNS_SOA)) {
				return \ArgumentFormatter::format(AUTH_PDNS_SOA, [
					'domain' => $domain,
				]);
			}

			return 'hostmaster@' . $domain;
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

			foreach ($nameservers as $nameserver) {
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

			$body = (array)\Error_Reporter::silence(static function () use ($e) {
				return \json_decode($e->getResponse()->getBody()->getContents(), true);
			});

			if (!($reason = array_get($body, 'error'))) {
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
			try {
				$api = $this->makeApi();
				$api->do('DELETE', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($domain)));
			} catch (ConnectException $e) {
				return error("Failed to connect to PowerDNS API service: %s", $e->getMessage());
			} catch (ClientException $e) {
				return error("Failed to remove zone '%(zone)s', error: %(err)s",
					['zone' => $domain, 'err' => $this->renderMessage($e)]);
			}

			return $api->getResponse()->getStatusCode() === 204; // Returns 204 No Content on success.;
		}

		/**
		 * @inheritDoc
		 */
		public function record_exists(string $zone, string $subdomain, string $rr = 'ANY', string $parameter = ''): bool
		{
			$api = $this->makeApi();
			if ($api->dirty()) {
				// Bust Packet Cache. Domain must be fqdn
				$this->flush($this->makeFqdn($zone, $subdomain));
			}

			return parent::record_exists($zone, $subdomain, $rr, $parameter);
		}

		public function flush(string $domain): bool
		{
			// chop trailing dot
			if (!filter_var($domain, FILTER_VALIDATE_DOMAIN)) {
				return error("Invalid domain");
			}
			try {
				$this->makeApi()->do('PUT', 'cache/flush?domain=' . $this->makeFqdn($domain, '', true));
				return true;
			} catch (RequestException $e) {
				$json = json_decode($e->getResponse()->getBody()->getContents(), true);
				return error($json['error'] ?? "Unknown error");
			}
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
			if (strpos($subdomain, $zone) === false) {
				$subdomain = rtrim(implode('.', array_filter([$subdomain, $zone])), '.');
			}

			if ($makeCanonical) {
				return $this->makeCanonical($subdomain);
			}

			return $subdomain;
		}

		public function zone_exists(string $zone): bool
		{
			$zone = rtrim($zone, '\.');
			try {
				$api = $this->makeApi();
				$api->do('GET', "zones/${zone}");
			} catch (ConnectException $e) {
				return error("Failed to connect to PowerDNS API service");
			} catch (ClientException $e) {
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
		public function add_record(
			string $zone,
			string $subdomain,
			string $rr,
			string $param,
			int $ttl = self::DNS_TTL
		): bool {
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}

			if (!$this->owned_zone($zone)) {
				return error("Domain `%s' not owned by account", $zone);
			}

			$record = new Record($zone, [
				'name'      => $subdomain,
				'rr'        => $rr,
				'parameter' => $param,
				'ttl'       => $ttl,
			]);

			if ($record['rr'] === 'SOA') {
				return error("Cannot add/remove SOA record directly");
			}

			try {
				$api = $this->makeApi();
				// Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
				$zoneData = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($zone)));
				$this->records = $zoneData['rrsets'];

				$rrsets = $this->addRecords($record);

				$api->do('PATCH', 'zones' . sprintf('/%s', $this->makeCanonical($zone)), ['rrsets' => $rrsets]);
			} catch (ConnectException $e) {
				return error("Failed to connect to PowerDNS API service");
			} catch (ClientException $e) {
				return error("Failed to create record '%(record)s': %(err)s",
					['record' => (string)$record, 'err' => $this->renderMessage($e)]
				);
			}

			return $api->getResponse()->getStatusCode() === 204; // Returns 204 No Content on success.
		}

		/**
		 * Parse existing records for zone, add to records to ensure same named records are not removed
		 * Refer to https://doc.powerdns.com/authoritative/http-api/zone.html#rrset under "changetype"
		 *
		 * @param Record $record
		 *
		 * @return array
		 */
		private function addRecords(Record $record): array
		{
			$return = [];

			$name = $name = $this->replaceMarker($record['zone'], $record['name']);

			foreach ($this->records as $rrset) {
				if ($rrset['name'] === $name && $rrset['type'] === $record['rr']) {
					$rrset['changetype'] = 'REPLACE';
					// filter duplicate
					foreach ($rrset['records'] as $chk) {
						if ($record->is(new Record($record->getZone(), [
							'name'      => (string)$record['name'],
							'rr'        => $rrset['type'],
							'parameter' => $chk['content']
						]))) {
							$return[] = $rrset;
							// dupe, leave this damned maze
							break 2;
						}
					}
					$rrset['records'][] = ['content' => $this->parseParameter($record), 'disabled' => false];
					$return[] = $rrset;
				}
			}

			// No records match the name and type, let's create a new record set
			if (empty($return)) {
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
			if ($name === '@') {
				$name = $this->makeCanonical($zone);
			} else {
				$name = $this->makeFqdn($zone, $name, true);
			}

			return $name;
		}

		/**
		 * Parse a Record and return the content for the RRSET
		 *
		 * @param Record $r
		 *
		 * @return string
		 */
		private function parseParameter(Record $r): string
		{
			$type = strtoupper($r['rr']);

			switch ($type) {
				case 'CNAME':
					if ($r['parameter'] === '@' || $r['parameter'] === '127.0.0.1')
					{
						// If 127.0.0.1, the user hit submit with an empty field that was pre-saved with the default A record value!
						$r['parameter'] = $r['zone'];
					}

					return $this->makeCanonical($r['parameter']);
				case 'CAA':
					return sprintf(
					// flags | tag | target
						'%d %s "%s"',
						$r->getMeta('flags'),
						$r->getMeta('tag'),
						trim($r->getMeta('data'), '"')
					);
				default:
					return str_replace("\t", ' ', (string)$r['parameter']);
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

			$priority = null;
			$name = null;

			$content = $this->parseParameter($r);

			if ($r['name'] === '@') {
				$r['name'] = '';
			}

			if ($r['name'] === '' && $r['rr'] === 'CNAME' && !$this->hasCnameApexRestriction()) {
				info('Implicitly converted apex CNAME to ALIAS synthetic record');
				$type = $r['rr'] = 'ALIAS';
			}

			$rrset = [
				'records'    => [
					[
						'content'  => $content,
						'disabled' => false,
					]
				],
				'name'       => $name ?? $this->makeFqdn($r['zone'], $r['name'], true),
				'ttl'        => $r['ttl'] ?? null,
				'type'       => $type,
				'changetype' => 'REPLACE',
			];

			return $rrset;
		}

		/**
		 * @inheritDoc
		 *
		 * @param string $zone
		 * @param string $subdomain
		 * @param string $rr
		 * @param string $param
		 *
		 * @return bool
		 */
		public function remove_record(string $zone, string $subdomain, string $rr, string $param = ''): bool
		{
			if (null === $param) {
				$param = '';
			}

			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param)) {
				return false;
			}

			if (!$this->owned_zone($zone)) {
				return error("Domain `%s' not owned by account", $zone);
			}

			$record = new Record($zone, [
				'name'      => $subdomain,
				'rr'        => $rr,
				'parameter' => $param,
			]);

			if ($record['rr'] === 'SOA') {
				return error("Cannot add/remove SOA record directly");
			}

			try {
				$api = $this->makeApi();
				// Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
				$zoneData = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($zone)));
				$this->records = $zoneData['rrsets'];

				$rrsets = $this->removeRecords($record);
				$api->do('PATCH', 'zones' . sprintf('/%s', $this->makeCanonical($zone)), ['rrsets' => $rrsets]);
			} catch (ConnectException $e) {
				return error("Failed to connect to PowerDNS API service");
			} catch (ClientException $e) {
				$fqdn = $this->makeFqdn($zone, $subdomain);

				return error("Failed to delete record '%s' type %s - Reason: %s", $fqdn, $rr, $this->renderMessage($e));
			}

			return $api->getResponse()->getStatusCode() === 204; // Returns 204 No Content on success.
		}

		/**
		 * Parse existing records for zone, ensure only the deleted record is removed from the same named records
		 * Refer to https://doc.powerdns.com/authoritative/http-api/zone.html#rrset under "changetype"
		 *
		 * @param Record $record
		 *
		 * @return array
		 */
		private function removeRecords(Record $record): array
		{
			$return = [];

			$name = $this->replaceMarker($record['zone'], $record['name']);

			foreach ($this->records as $rrset) {
				if ($rrset['name'] === $name && $rrset['type'] === $record['rr']) {
					foreach ($rrset['records'] as $k => $rrec) {
						if (!$record['parameter'] || $rrec['content'] === $record['parameter']) {
							unset($rrset['records'][$k]);
							$rrset['records'] = array_values($rrset['records']);
							break;
						}
					}
					$rrset['changetype'] = 'REPLACE';
					unset($rrset['comments']);

					$return[] = $rrset;
				}
			}
			// No records match the name and type, let's create a new record set
			if (empty($return) || empty(current($return)['records'])) {
				$return = [
					[
						'records'    => '',
						'name'       => $name,
						'changetype' => 'DELETE',
						'type'       => $record['rr'],
					]
				];
			}

			return $return;
		}

		/**
		 * @inheritDoc
		 *
		 * @return array
		 */
		public function get_all_domains(): array
		{
			try {
				$api = $this->makeApi();
				// Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
				$zoneData = $api->do('GET', 'servers/localhost/zones');

				return array_map(static function ($domain) {
					return rtrim($domain, '.');
				}, array_column($zoneData, 'name'));
			} catch (ConnectException $e) {
				error("Failed to connect to PowerDNS API service: %s", $e->getMessage());

				return [];
			} catch (ServerException|ClientException $e) {
				error("Failed to transfer domains: %s", $e->getMessage());

				return [];
			}

			return [];
		}

		protected function canonicalizeRecord(
			string &$zone,
			string &$subdomain,
			string &$rr,
			string &$param,
			int &$ttl = null
		): bool {
			if (!parent::canonicalizeRecord($zone, $subdomain, $rr, $param,
				$ttl))
			{
				return false;
			}
			if ($rr === 'SOA' && $param) {
				$parts = preg_split('/\s+/', $param);
				for ($i = 0; $i < 2; $i++) {
					$parts[$i] = rtrim($parts[$i], '.') . '.';
				}
				$param = implode(' ', $parts);
			}

			return true;
		}


		/**
		 * Get raw zone data
		 *
		 * @param string $domain
		 *
		 * @return null|string
		 */

		protected function zoneAxfr(string $domain): ?string
		{
			try {
				$api = $this->makeApi();
				$axfrrec = $api->do('GET',
					'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($domain)) . '/export');
			} catch (ConnectException $e) {
				error("Failed to connect to PowerDNS API service");

				return null;
			} catch (ClientException $e) {
				// ignore zone does not exist
				warn('Failed to transfer DNS records from PowerDNS - try again later. Response code: %d',
					$e->getResponse()->getStatusCode());

				return null;
			} catch (ServerException $e) {
				error('Failed to transfer DNS records from PowerDNS. PowerDNS server reported internal error. Response code: %d',
					$e->getResponse()->getStatusCode());

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
		protected function atomicUpdate(string $zone, RecordBase $old, RecordBase $new): bool
		{
			if (!$this->canonicalizeRecord($zone, $old['name'], $old['rr'], $old['parameter'], $old['ttl'])) {
				return false;
			}

			if (!$this->canonicalizeRecord($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl'])) {
				return false;
			}

			if (!($this->permission_level & PRIVILEGE_ADMIN) && ($old['rr'] === 'SOA' || $new['rr'] === 'SOA')) {
				return error("Cannot edit SOA record as non-admin");
			}

			try {
				$api = $this->makeApi();
				// Get zone and rrsets, need to parse the existing rrsets to ensure proper addition of new records
				$zoneData = $api->do('GET', 'servers/localhost/zones' . sprintf('/%s', $this->makeCanonical($zone)));
				$this->records = $zoneData['rrsets'];
				$rrsets = $this->changeRecords($old, $new);
				$api->do('PATCH', 'zones' . sprintf('/%s', $this->makeCanonical($zone)), ['rrsets' => $rrsets]);
			} catch (ConnectException $e) {
				return error("Failed to connect to PowerDNS API service");
			} catch (ClientException $e) {
				return error("Failed to update record '%s' on zone '%s' (old - rr: '%s', param: '%s'; new - name: '%s' rr: '%s', param: '%s'): %s",
					$old['name'],
					$zone,
					$old['rr'],
					$old['parameter'],
					$new['name'] ?? $old['name'],
					$new['rr'] ?? $old['rr'],
					$new['parameter'] ?? $old['parameter'],
					$this->renderMessage($e)
				);
			}

			return true;
		}

		/**
		 * Parse existing records for zone, find and remove the old record from the list and add the new one.
		 * Refer to https://doc.powerdns.com/authoritative/http-api/zone.html#rrset under "changetype"
		 *
		 * Previous record MUST exist
		 *
		 * @param Record $old
		 * @param Record $new
		 *
		 * @return array
		 */
		private function changeRecords(Record $old, Record $new): array
		{
			$oldName = $this->replaceMarker($old['zone'], $old['name']);
			$newName = $this->replaceMarker($new['zone'], $new['name']);

			$remove = [];
			$add = [];

			// @TODO rewrite to use Record::is()
			$paramMatch = $this->parseParameter($old);
			foreach ($this->records as $rrset) {
				if ($rrset['name'] === $oldName && $rrset['type'] === $old['rr']) {
					// enumerate old records to determine change set
					if ($old['ttl'] && $rrset['ttl'] !== $old['ttl']) {
						continue;
					}
					if (null === $new['ttl']) {
						$new['ttl'] = $rrset['ttl'];
					}
					// remove record
					$remove = $rrset['records'];
					foreach ($remove as $idx => $r) {
						if ($r['content'] !== $paramMatch) {
							continue;
						}
						unset($remove[$idx]);
					}

					// has more records
					$remove = [
						'name'       => $rrset['name'],
						'records'    => $remove,
						'ttl'        => $rrset['ttl'],
						'comments'   => $rrset['comments'],
						'type'       => $rrset['type'],
						'changetype' => empty($remove) ? 'DELETE' : 'REPLACE'
					];
					if (empty($remove['records'])) {
						array_forget($remove, ['comments', 'ttl']);
					}
				} else if ($rrset['name'] === $newName && $rrset['type'] === $new['rr']) {
					if ($new['ttl'] && $rrset['ttl'] !== $new['ttl']) {
						continue;
					}
					$add = $rrset;
				}
			}

			if (!$add) {
				// records updated in same set
				$add = $this->formatRecord($new);
				if (empty($remove['records']) && $add['name'] === $remove['name'] && $add['type'] === $remove['type']) {
					return [$add];
				}

				return [$add, $remove];
			}

			// merge cherry-picked records into existing record set
			$newRec = $this->formatRecord($new);
			$records = [
				array_replace([
					'name'       => $this->makeFqdn($new->getZone(), $new['name'], true),
					'type'       => $new['rr'],
					'records'    => $add,
					'changetype' => 'REPLACE'
				], $newRec)
			];
			if (!empty($remove)) {
				array_unshift($records, [
						'name'       => $this->makeFqdn($old->getZone(), $old['name'], true),
						'type'       => $old['rr'],
						'ttl'        => $remove['ttl'],
						'comments'   => $remove['comments'],
						'changetype' => empty($remove) ? 'DELETE' : 'REPLACE',
					] + $remove);
			}

			return $records;
		}
	}
