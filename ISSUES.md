## Issues List: ##
1) Deleting a Site deletes the zone and then tries to delete records but they were already deleted with the zone

```ERROR   : Failed to transfer DNS records from PowerDNS - try again later. Response code: 404
DEBUG   : 0.68447: Dns_Module -> _delete
DEBUG   : 0.04320: Mysql_Module -> _delete
DEBUG   : 0.00006: Ipinfo_Module -> _delete
DEBUG   : Service config `billing' disabled for site - disabling calling hook `_delete' on `Billing_Module'
DEBUG   : Service config `pgsql' disabled for site - disabling calling hook `_delete' on `Pgsql_Module'
DEBUG   : 0.00054: Site_Module -> _delete
ERROR   : Failed to transfer DNS records from PowerDNS - try again later. Response code: 404
```

Full Backtrace:
```
DEBUG   : Running hooks for `testdomain.com' (user: `testuser')
DEBUG   : 0.00007: Sql_Module -> _delete
DEBUG   : 0.00285: Php_Module -> _delete
DEBUG   : 0.00042: Letsencrypt_Module -> _delete
DEBUG   : 0.00004: Dav_Module -> _delete
DEBUG   : 0.01276: Majordomo_Module -> _delete
DEBUG   : 0.00329: Crontab_Module -> _delete
DEBUG   : 0.00012: Spamfilter_Module -> _delete
DEBUG   : 0.00008: Email_Module -> _delete
DEBUG   : 0.00009: Ssh_Module -> _delete
DEBUG   : 0.00004: Ftp_Module -> _delete
ERROR   : Auth_Module::_getAPIQueryFragment: cannot get billing invoice for API key
 0. Error_Reporter::add_error("Auth_Module::_getAPIQueryFragment: cannot get billing invoice for API key", )
        [/usr/local/apnscp/lib/log_wrapper.php:63]
 1. error("cannot get billing invoice for API key")
        [/usr/local/apnscp/lib/modules/auth.php:322]
 2. Auth_Module->_getAPIQueryFragment()
        [/usr/local/apnscp/lib/modules/auth.php:950]
 3. Auth_Module->_get_api_keys_real("testuser")
        [/usr/local/apnscp/lib/modules/auth.php:1082]
 4. Auth_Module->get_api_keys()
        [/usr/local/apnscp/lib/modules/auth.php:1057]
 5. Auth_Module->_delete()
        [/usr/local/apnscp/lib/Util/Account/Hooks.php:142]
 6. Util_Account_Hooks::_process("delete", )
        [/usr/local/apnscp/lib/Util/Account/Hooks.php:49]
 7. Util_Account_Hooks::run("delete")
        [/usr/local/apnscp/bin/DeleteDomain:35]

DEBUG   : 0.00313: Auth_Module -> _delete
DEBUG   : 0.00060: Aliases_Module -> _delete
DEBUG   : 0.00004: Diskquota_Module -> _delete
DEBUG   : Service config `tomcat' disabled for site - disabling calling hook `_delete' on `Tomcat_Module'
DEBUG   : 0.00736: User_Module -> _delete
DEBUG   : 0.00011: Bandwidth_Module -> _delete
DEBUG   : 0.00006: Ssl_Module -> _delete
DEBUG   : 0.00166: Cgroup_Module -> _delete
DEBUG   : 0.03925: Web_Module -> _delete
DEBUG   : 0.63373: Dns_Module -> _delete
DEBUG   : 0.02480: Mysql_Module -> _delete
DEBUG   : 0.00006: Ipinfo_Module -> _delete
DEBUG   : Service config `billing' disabled for site - disabling calling hook `_delete' on `Billing_Module'
DEBUG   : Service config `pgsql' disabled for site - disabling calling hook `_delete' on `Pgsql_Module'
DEBUG   : 0.00035: Site_Module -> _delete
ERROR   : Opcenter\Dns\Providers\Powerdns\Module::zoneAxfr: Failed to transfer DNS records from PowerDNS - try again later. Response code: 404
 0. Error_Reporter::add_error("Opcenter\Dns\Providers\Powerdns\Module::zoneAxfr: Failed to transfer DNS records from PowerDNS - try again later. Response code: %d", [404])
        [/usr/local/apnscp/lib/log_wrapper.php:63]
 1. error("Failed to transfer DNS records from PowerDNS - try again later. Response code: %d", 404)
        [/usr/local/apnscp/resources/playbooks/addins/apnscp-powerdns/src/Module.php:477]
 2. Opcenter\Dns\Providers\Powerdns\Module->zoneAxfr("testdomain.com")
        [/usr/local/apnscp/lib/modules/dns.php:295]
 3. Dns_Module->zone_exists("testdomain.com")
        [/usr/local/apnscp/lib/Module/Skeleton/Standard.php:144]
 4. Module\Skeleton\Standard->_invoke("zone_exists", ["testdomain.com"])
        [/usr/local/apnscp/lib/Module/Skeleton/Webhooks.php:35]
 5. Module\Skeleton\Webhooks->_invoke("zone_exists", ["testdomain.com"])
        [/usr/local/apnscp/lib/apnscpfunction.php:734]
 6. apnscpFunctionInterceptor->call("dns_zone_exists", ["testdomain.com"])
        [/usr/local/apnscp/lib/apnscpFunctionInterceptorTrait.php:34]
 7. Module\Skeleton\Standard->__call("dns_zone_exists", ["testdomain.com"])
        [/usr/local/apnscp/lib/modules/email.php:959]
 8. Email_Module->remove_virtual_transport("testdomain.com")
        [/usr/local/apnscp/lib/Module/Skeleton/Standard.php:144]
 9. Module\Skeleton\Standard->_invoke("remove_virtual_transport", ["testdomain.com"])
        [/usr/local/apnscp/lib/Module/Skeleton/Webhooks.php:35]
10. Module\Skeleton\Webhooks->_invoke("remove_virtual_transport", ["testdomain.com"])
        [/usr/local/apnscp/lib/apnscpfunction.php:734]
11. apnscpFunctionInterceptor->call("email_remove_virtual_transport", ["testdomain.com"])
        [/usr/local/apnscp/lib/apnscpfunction.php:685]
12. apnscpFunctionInterceptor->__call("email_remove_virtual_transport", ["testdomain.com"])
        [/usr/local/apnscp/lib/Opcenter/Service/Validators/Mail/Enabled.php:57]
13. Opcenter\Service\Validators\Mail\Enabled->depopulate(Opcenter\SiteConfiguration)
        [/usr/local/apnscp/lib/Opcenter/Account/Delete.php:110]
14. Opcenter\Account\Delete->exec()
        [/usr/local/apnscp/bin/DeleteDomain:43]

INFO    : Removing port `1' assigned to `site1'
 0. Error_Reporter::add_info("Removing port `%d' assigned to `%s'", [1, "site1"])
        [/usr/local/apnscp/lib/log_wrapper.php:87]
 1. info("Removing port `%d' assigned to `%s'", 1, "site1")
        [/usr/local/apnscp/lib/Opcenter/Service/Validators/Ssh/PortIndex.php:97]
 2. Opcenter\Service\Validators\Ssh\PortIndex->depopulate(Opcenter\SiteConfiguration)
        [/usr/local/apnscp/lib/Opcenter/Account/Delete.php:110]
 3. Opcenter\Account\Delete->exec()
        [/usr/local/apnscp/bin/DeleteDomain:43]

WARNING : Opcenter\Service\Validators\Pgsql\Enabled::depopulate(): unable to lookup postgresql user for `site1'
 0. Error_Reporter::add_warning("Opcenter\Service\Validators\Pgsql\Enabled::depopulate(): unable to lookup postgresql user for `%s'", ["site1"])
        [/usr/local/apnscp/lib/log_wrapper.php:75]
 1. warn("unable to lookup postgresql user for `%s'", "site1")
        [/usr/local/apnscp/lib/Opcenter/Service/Validators/Pgsql/Enabled.php:90]
 2. Opcenter\Service\Validators\Pgsql\Enabled->depopulate(Opcenter\SiteConfiguration)
        [/usr/local/apnscp/lib/Opcenter/Account/Delete.php:110]
 3. Opcenter\Account\Delete->exec()
        [/usr/local/apnscp/bin/DeleteDomain:43]
```

Seems to be trying to delete records after deleting the zone which deletes the records...  The 404 is a result of the zone not existing in PowerDNS anymore.
