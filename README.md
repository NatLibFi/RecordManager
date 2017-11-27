# RecordManager

RecordManager is a metadata record management system intended to be used in conjunction with VuFind. It can also be used as an OAI-PMH repository and a generic metadata management utility.

See the [RecordManager wiki](https://github.com/NatLibFi/RecordManager/wiki) for more information and OAI-PMH provider setup.

For a stable version, see the stable branch.

## Installation notes on CentOS 7

These are quick instructions on how to set up RecordManager. Please refer to the [wiki pages](https://github.com/NatLibFi/RecordManager/wiki) for more information on the configuration and setup of RecordManager.

- Required PHP packages: php php-pear php-xml php-xsl php-devel php-mbstring php-intl

      yum install php php-pear php-xml php-devel php-mbstring php-intl

- Required PHP packages for polygon simplification in NominatimGeocoder (optional):

      yum install geos geos-php

  Note that as of September 2017 CentOS comes with geo 3.4.2 which has memory leaks
  and problems with polygon simplification, so building at least version 3.6.2 from
  source is recommended (see https://trac.osgeo.org/geos). With PHP 7 a recent
  version of GEOS PHP bindings from https://git.osgeo.org/gogs/geos/php-geos is
  required anyway.
  `yum install geos-devel` will be needed to compile the bindings unless GEOS is
  installed from source.

- Required pecl modules: mongodb

    E.g. remi repos include a package for mongodb:

      yum install php70-php-pecl-mongodb

    Webtatic too:

      yum install php70w-pecl-mongodb

    If there's no package available, use pecl to install mongodb:

      yum install gcc make
      pecl install mongodb

    Either way, make sure it's at least v1.2.0. Earlier versions have problems with
    pcntl.

- Add the extension=mongodb.so line to /etc/php.d/mongodb.ini

- Install MongoDB from 10gen repositories (see http://www.mongodb.org/display/DOCS/CentOS+and+Fedora+Packages)

- Adjust MongoDB settings as needed

- Copy RecordManager to /usr/local/RecordManager/

- Run composer install to install PHP dependencies

- Create indexes with dbscripts/mongo.js

      mongo recman dbscripts/mongo.js

- Copy conf/recordmanager.ini.sample to conf/recordmanager.ini and modify the settings to suit your needs.

- Copy conf/datasources.ini.sample to conf/datasources.ini and modify the settings to suit your needs.

- Start using the system by executing e.g.

      php harvest.php --source=datasource_id

  or

      php import.php --file=filename --source=datasource_id

- Deduplicate harvested records:

      php manage.php --func=deduplicate

- Update Solr index:

      php manage.php --func=updatesolr
