# RecordManager

RecordManager is a metadata record management system intended to be used in conjunction with VuFind. It can also be used as an OAI-PMH repository and a generic metadata management utility.

See the [RecordManager wiki](https://github.com/NatLibFi/RecordManager/wiki) for more information and OAI-PMH provider setup.

For a stable version, see the stable branch.

## General Installation

- Minimum supported PHP version is 7.3.
- Composer is required for dependencies. Run `composer install` (or `php /path/to/composer.phar install`) in the directory where RecordManager is installed.
- The following PHP modules are required: xml, xslt, mbstring, intl
- The following PECL module is required for MongoDB support: mongodb
- With MongoDB, the minimum supported version is 3.6. Older versions may still work, but `session = false` needs to be specified in recordmanager.ini.

## Upgrading

Generally upgrading should be straightforward by replacing the old version with the new one and running
`composer install` (or `php /path/to/composer.phar install`).
With MongoDB you need to manually check that all indexes are present (see dbscripts/mongo.js).
With MySQL/MariaDB make sure all tables are present (see dbscripts/mysql.sql).

Note that since 8 Jul 2021 there is a new method for tracking updates of deduplicated records. Since RecordManager no longer uses the old method, there may be old tracking collections left dangling. With Mongo shell with the correct database active, you can use the following script to remove them:

    var count = 0;
    db.getCollectionNames().forEach(function(c) {
        if (c.match("^tmp_mr_record") || c.match("^mr_record")) {
            db.getCollection(c).drop();
            count++;
        }
    });
    print(count + " collections dropped");

With MySQL/MariaDB you can identify the tables with the following SQL query:

    show tables like '%mr_record_%';

You can then use the `drop table` command to remove them.

## Installation Notes on CentOS 7

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

- MongoDB support

  RecordManager supports both MongoDB (recommended) and any MySQL compatible
  database. You may opt to skip the MongoDB requirements if you only use MySQL.

  - Required pecl modules for MongoDB support: mongodb

    E.g. remi repos include a package for mongodb:

      yum install php74-php-pecl-mongodb

    Webtatic too:

      yum install php74w-pecl-mongodb

    If there's no package available, use pecl to install mongodb:

      yum install gcc make
      pecl install mongodb

    Either way, make sure it's at least v1.2.0. Earlier versions have problems with
    pcntl.

  - Add the extension=mongodb.so line to /etc/php.d/mongodb.ini

  - Install MongoDB from 10gen repositories (see
    http://www.mongodb.org/display/DOCS/CentOS+and+Fedora+Packages)

  - Adjust MongoDB settings as needed

- Copy RecordManager to /usr/local/RecordManager/

- Run `composer install` to install PHP dependencies. If you did not install the
  mongodb module above, you can also use `composer install --ignore-platform-reqs` to
  force package installation even if the underlying dependencies are missing.

- MongoDB: Create indexes with dbscripts/mongo.js

      mongo recman dbscripts/mongo.js

- MySQL: Create tables and indexes with dbscripts/mysql.sql and add a user

      mysql
      create database recman;
      use recman
      source dbscripts/mysql.sql;
      create user 'recman'@'localhost' identified by '<password>';
      grant all on recman.* to 'recman'@'localhost';

- Copy conf/recordmanager.ini.sample to conf/recordmanager.ini and modify the settings to suit your needs.

- Copy conf/datasources.ini.sample to conf/datasources.ini and modify the settings to suit your needs.

- Start using the system by executing e.g.

      ./console records:harvest --source=datasource_id

  or

      ./console records:import datasource_id filename

- Deduplicate the records:

      ./console records:deduplicate

- Update Solr index:

      ./console solr:update-index

## Creating Additional Modules

RecordManager supports modules that can modify and add new
functionality. Active modules are specified in `conf/modules.config.php`. You can copy the provided `conf/modules.config.php.sample` to `conf/modules.config.php` and modify it accordingly.

A minimal module ("Sample" in this example) consists of the following file:

`src/RecordManager/Sample/Module.php`

The file needs to contain a Module class that provides the module configuration:

    <?php
    namespace RecordManager\Sample;

    class Module
    {
        public function getConfig()
        {
            return [];
        }
    }

This, alone, doesn't really do anything. Please see the [Customizing RecordManager](https://github.com/NatLibFi/RecordManager/wiki/Customizing-RecordManager) wiki page for further information. See also the [Finna module](https://github.com/NatLibFi/RecordManager-Finna/blob/dev/src/RecordManager/Finna/) for an example of a module that does a number of different things.