# Upgrade Instructions

### 1.0.0 -> 1.0.1
There was a change in the domain meta data that requires an update to the MySQL data.
Run this query on your PowerDNS Master server:  
```UPDATE `domainmetadata` SET `content` = 'DEFAULT' WHERE `kind` = 'SOA-EDIT-API';```  
Failure to do this will result in existing / old records not updating their SOA serial properly or at all.

Until this module is adopted by apnscp as a default configuration, to update simply :
```
cd /usr/local/apnscp/resources/playbooks/addins/apnscp-powerdns
git pull
```