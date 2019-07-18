# PowerDNS DNS Provider

This is a drop-in provider for [apnscp](https://apnscp.com) to enable DNS support using PowerDNS. This module may use PostgreSQL or MySQL as a backend driver.

## Nameserver installation

Clone the repository into the Bootstrapper addin path. Note this requires either apnscp v3.1 or apnscp v3.0.47 minimum to work.

```bash
upcp
cd /usr/local/apnscp/resources/playbooks
git clone https://github.com/LithiumHosting/apnscp-powerdns.git addins/apnscp-powerdns
ansible-playbook addin.yml --extra-vars=addin=apnscp-powerdns --extra-vars=powerdns_driver=mysql
```

*PostgreSQL can be used by specifying powerdns_driver=pgsql*

PowerDNS is now setup to accept requests on port 8081. Requests require an authorization key that can be found in `/etc/pdns/pdns.conf`

```
# Install jq if not already installed
yum install -y jq
grep api= /etc/pdns/pdns.conf | cut -d= -f2
# This is your API key
curl -v -H 'X-API-Key: APIKEYABOVE' http://127.0.0.1:8081/api/v1/servers/localhost | jq .
```

apnscp provides a DNS-only license class that allows apnscp to run on a server without the capability to host sites. These licenses are free and may be requested via [my.apnscp.com](https://my.apnscp.com). Contact license@apnscp.com if these licenses are not available at time of writing for manual issuance.

### Idempotently changing configuration

PowerDNS may be configured via files in `/etc/pdns/local.d`. In addition to this location, Bootstrapper supports injecting settings via `powerdns_custom_config`. For example,

```bash
cpcmd config:set apnscp.bootstrapper 'powerdns_custom_config' '["allow-axfr-ips":1.2.3.4,"also-notify":1.2.3.4]'
cd /usr/local/apnscp/resources/playbooks
ansible-playbook addin.yml --extra-vars=addin=apnscp-powerdns
```

allow-axfr-ips and also-notify directives will be set whenever the addin plays are run.

### Restricting submission access

In the above example, only local requests may submit DNS modifications to the server. None of the below examples affect querying; DNS queries occur over 53/UDP typically (or 53/TCP if packet size exceeds UDP limits). Depending upon infrastructure, there are a few options to securely accept record submission, *all of which require an API key for submission*.

#### SSL + Apache

Apache's `ProxyPass` directive send requests to the backend. Brute-force attempts are protected by [mod_evasive](https://github.com/apisnetworks/mod_evasive ) bundled with apnscp. Requests over this medium are protected by SSL, without HTTP/2 to ameliorate handshake overhead. In all but the very high volume API request environments, this will be acceptable.

In this situation, the endpoint is https://myserver.apnscp.com/dns. Changes are made to `/etc/httpd/conf/httpd-custom.conf` within the `<VirtualHost ... :443>` bracket (with `SSLEngine On`!). After adding the below changes, `systemctl restart httpd`.

```
<Location /dns>
	ProxyPass http://127.0.0.1:8081
	ProxyPassReverse http://127.0.0.1:8081
</Location>
```

**Downsides**: minor SSL overhead. Dependent upon Apache.  
**Upsides**: easy to setup. Protected by threat deterrence. PowerDNS accessible remotely via an easily controlled URI.  

In the above example, API requests can be made via https://myserver.apnscp.com/dns, e.g. 

```bash
curl -q -H 'X-API-Key: SOMEKEY' https://myserver.apnscp.com/dns/api/v1/servers/localhost 
```

##### Disabling brute-force throttling

As hinted above, placing PowerDNS behind Apache confers brute-force protection by mod_evasive. By default, 10 of the same requests in 2 seconds can trigger a brute-force block. Two solutions exist, either  raise the same-page request threshold or disable mod_evasive.

Working off the example above *<Location /dns> ... </Location>*
```
<Location /dns>
	# Raise threshold to 30 same-page requests in 2 seconds
	DOSPageCount 30
	DOSPageInterval 2

	# Or disable entirely
	DOSEnabled off
</Location>
```

#### Standalone server

PowerDNS can also run by itself on a different port. In this situation, the network is configured to block all external requests to port 8081 except those whitelisted. For example, if the entire 32.12.1.1-32.12.1.255 network can be trusted and under your control, then whitelist the IP range:

```bash
cpcmd rampart:whitelist 32.12.1.1/24
```

Additionally, PowerDNS' whitelist must be updated as well. This can be quickly accomplished using the *apnscp.bootstrapper* Scope:

```
cpcmd config:set apnscp.bootstrapper powerdns_localonly false
cd /usr/local/apnscp/resources/playbooks
ansible-playbook addin.yml --extra-vars=addin=apnscp-powerdns
```

**Downsides**: requires whitelisting IP addresses for access to API server. Must run on port different than Apache.  
**Upsides**: operates independently from Apache.  

The server may be accessed once the source IP has been whitelisted,

```bash
curl -q -H 'X-API-Key: SOMEKEY' http://myserver.apnscp.com/api/v1/servers/localhost 
```


## apnscp DNS provider setup

Every server that runs apnscp may delegate DNS authority to PowerDNS. This is ideal in distributed infrastructures in which coordination allows for seamless [server-to-server migrations](<https://hq.apnscp.com/account-migration-guide/> ).

Taking the **API key** from above, configure `/usr/local/apnscp/config/auth.yaml`. Configuration within this file is secret and is not exposed via apnscp's API.

```yaml
pdns:
  # This url may be different if using running PowerDNS in standalone
  uri: https://myserver.apnscp.com/dns/api/v1
  key: your_api_key_here
  ns: 
    - ns1.yourdomain.com
    - ns2.yourdomain.com
    ## Optional additional nameservers
```
* `uri` value is the hostname of your master PowerDNS server running the HTTP API webserver (without a trailing slash)
* `key` value is the **API Key** in `pdns.conf` on the master nameserver. 
* `ns` value is a list of nameservers as in the example above.  Put nameservers on their own lines prefixed with a hyphen and indented accordingly.  There is not currently a limit for the number of nameservers you may use, 2-5 is typical and should be geographically distributed per RFC 2182.

### Setting as default

PowerDNS may be configured as the default provider for all sites using the `dns.default-provider` [Scope](https://gitlab.com/apisnetworks/apnscp/blob/master/docs/admin/Scopes.md). When adding a site in Nexus or [AddDomain](https://hq.apnscp.com/working-with-cli-helpers/#adddomain) the key will be replaced with "DEFAULT". This is substituted automatically on account creation.

```bash
cpcmd config_set dns.default-provider powerdns
```

> Do not set dns.default-provider-key. API key is configured via `config/auth.yaml`.

## Components

- Module- overrides [Dns_Module](https://github.com/apisnetworks/apnscp-modules/blob/master/modules/dns.php) behavior
- Validator- service validator, checks input with AddDomain/EditDomain helpers

### Minimal module methods

All module methods can be overwritten. The following are the bare minimum that are overwritten for this DNS provider to work:

- `atomicUpdate()` attempts a record modification, which must retain the original record if it fails
- `zoneAxfr()` returns all DNS records
- `add_record()` add a DNS record
- `remove_record()` removes a DNS record
- `get_hosting_nameservers()` returns nameservers for the DNS provider
- `add_zone_backend()` creates DNS zone
- `remove_zone_backend()` removes a DNS zone

See also: [Creating a provider](https://hq.apnscp.com/apnscp-pre-alpha-technical-release/#creatingaprovider) (hq.apnscp.com)

## Contributing

Submit a PR and have fun!
