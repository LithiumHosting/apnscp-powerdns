## Issues List: ##
1) Deleting a Site deletes the zone and then tries to delete records but they were already deleted with the zone
2) Creating MX records just doesn't work
3) Deleting records seems to fail at $id = $this->getRecordId($record);



### Issue 1 ###
I know the "ERROR" is generated in the zoneAxfr method, but not sure why it happens during delete.
```ERROR   : Failed to transfer DNS records from PowerDNS - try again later. Response code: 404
DEBUG   : 0.68447: Dns_Module -> _delete
DEBUG   : 0.04320: Mysql_Module -> _delete
DEBUG   : 0.00006: Ipinfo_Module -> _delete
DEBUG   : Service config `billing' disabled for site - disabling calling hook `_delete' on `Billing_Module'
DEBUG   : Service config `pgsql' disabled for site - disabling calling hook `_delete' on `Pgsql_Module'
DEBUG   : 0.00054: Site_Module -> _delete
ERROR   : Failed to transfer DNS records from PowerDNS - try again later. Response code: 404```

Seems to be trying to delete records after deleting the zone which deletes the records...
