# Changelog

Notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 3.0.0 - TBD

**N.B. This version bumps the minimum PHP version to 8.1**

Anything marked with [**BC**] is known to affect backward compatibility with previous versions.

### Changed

- [**BC**] The HTTP client library has been changed from HTTP_Request2 to Guzzle. This has required some changes to how the HTTP client is used. See e.g. src/RecordManager/Base/Harvest/SierraApi.php for usage examples. This also affects the settings in HTTP section of recordmanager.ini. Only the most commonly used legacy settings are automatically mapped to Guzzle's equivalents.

### Removed

- [**BC**] Support for splitting titles of LIDO records has been dropped.
- [**BC**] Updated all dependencies including PHPUnit 10. Some tests may require changes.


## 2.3.0 - 2024-06-24

Anything marked with [**BC**] is known to affect backward compatibility with previous versions.

### Added
- [**BC**] Additional metadata can be stored in records outside of the main metadata record. This affects the setData method and MySQL schema.
- Added support for summed fields for merged records. See `summed_fields` in conf/recordmanager.ini.sample for more information.
- MARC: Added support for filtering fields (see Marc::filterFields).
- LIDO: Added support for filtering repository locations.
- QDC: Added a driver param (preferredFormatTypes) to indicate preferred dc.type fields for format.
- Added optional index hints for PDO databases. Not useful in most cases.
- Added option to keep existing 852 fields in Sierra harvesting.
- Enhanced the export function:
  - Added support for timestamp injection
  - Improved namespace handling
  - Added option to apply an XSL transformation to exported records
- Field processing rules have been enhanced quite significantly with e.g. support for match conditions.


### Changed

- [**BC**] Format of $isbnFields and $issnFields definition arrays in Marc.php have been changed to include the type of the field. It can be used to avoid reporting e.g. invalid ISBNs or extra content in combined fields in the `warnings_field` index field.
- Improved error message when a mapping file cannot be parsed.
- Sierra: Fields to harvest are now defined in a member variable to that they can be overridden as necessary.
- EAD3: First unitid is now used addIdToHierarchyTitle setting if sequenceUnitIdlabel is missing.

### Fixed

- solr:delete command did not encode the request properly.
- Host record linking did not ignore deleted records in all cases.
- DC, QDC, DOAJ: OAI identifiers containing colons were not handled properly.
- Wrong timestamp was used to find dedup records to process in Solr update.
- Deduplication could have used candidate records whose sources had deduplication toggled to disabled without renormalizing the records.
-


## 2.2.0 - 2023-11-13

**N.B. This version bumps the minimum PHP version to 8.0.**

Anything marked with [**BC**] is known to affect backward compatibility with previous versions.

### Added

- Support for PHP 8.2 is now complete.
- Support for filtering location data in Skosmos enrichment.
- Option to inject record ID to a specified field when exporting records.
- Option to specify a file with a list of IDs to export.
- Support for DOAJ article records (oai_doaj format).

### Changed

- [**BC**] Minimum PHP version has been bumped to 8.0.
- DC and QDC: Improved ID handling a bit to support a namespaced identifier.
- EAD3: Fixed and improved author and format handling.
- LIDO: Added repository locations to geographic places.
- LIDO: Added hierarchy title fields from RelatedWorksWrap to allfields.
- MARC: Removed 5xx fields from alternative names.
- Improved XML error handling and reporting in record preview.
- Improved Skosmos enrichment for easier overriding of fields.
- Fixed deduplication of records that were previously marked as deleted but were imported again after that.
- Fixed console logging of verbose messages when log file level is lower.
- Improved null value handling in queries with PDO databases.
- Updated coding style tools.
- [**BC**] Internal: Updated coding style to PSR-12.
- Internal: Cleaned up some ini file handling routines.
- Internal: Replaced a lot of strncmp, strpos etc. with str_starts_with/str_ends_with/str_contains.

### Removed

Nothing


## 2.1.0 - 2023-05-11

Anything marked with [**BC**] is known to affect backward compatibility with previous versions.

### Added

- DOIs are indexed in doi_str_mv field.
- A single record can be harvested by ID.
- Index checking using `solr:check-index` has been enhanced:
    - `--report-only` option can be used to just report inconsistencies.
    - Date and time a record was deleted is now reported when a record that should have been deleted is found in the index.
    - Index check can be limited by a Solr query
- EAC-CPF: Record source is indexed from maintenanceAgency


### Changed

- [**BC**] AbstractRecord no longer implements `toXML` method. Proper implementation is in `XmlRecordTrait.php`.
- The final title is now used for the title_in_hierarchy field for consistency.
- LIDO records
    - Related works missing a relation type are no longer indexed.
    - Handling of titles matching work type has been improved.
- Empty responses are now handled properly when harvesting OAI-PMH sources.
- QDC: Fixed indexing of publication year ranges that use slash as the separator.
- Fixed MARC export to not create multiple collection nodes.
- Fixed Nominatim Geocoder to handle empty WKT properly.
- Changed Skosmos enrichment to work with all record formats.
- Fixed record consistency check to mark a dedup record deleted instead of deleting it directly.
- Default retention time for deleted records has been changed from 0 to 14 days.
- Deferred removal is now used in all cases in deduplication handler.
- Performance of purging of deleted records has been improved particularly with large Mongo databases.
- title_sort field is now created in a unified way for all record formats.
- Abbrevian and leading article handling now supports UTF-8 properly.
- Enrichment cache life times can now be set individually.
- EAC-CPF: Language code is now properly indexed.


### Removed

Nothing


## 2.0.1 - 2023-01-13

### Added

Nothing

### Changed

- Fixed dedup merge record deletion from Solr when using MongoDB.

### Removed

Nothing

## 2.0.0 - 2023-01-10

This is a major release that contains a lot of refactoring and underlying changes. The basic directory structure remains the same, but the introduction of Laminas module manager and service manager change how objects are instantiated.

MongoDB is now optional. If MongoDB is used, the PECL library minimum version has been raised to 1.15.0.

Anything marked with [**BC**] is known to affect backward compatibility with previous versions.

### Added

- [**BC**] Added possibility to configure custom modules easily (see README.md). Additionally, plugin managers are now used for the following to allow easily plugging additional ones (see module.config.php):
  - Enrichments
  - Harvesters
  - Record drivers
  - Record splitters
- Introduced ./console (based on Symfony console) as the new interface for console tasks. Old scripts remain as a simple compatibility layer but don't provide proper parameter error handling etc. anymore.
- Added possibility to define the base path for configuration files with the RECMAN_BASE_PATH environment variable.
- Colorized output by message type.
- Added support for hierarchical categories based on [HILCC](https://www1.columbia.edu/sec/cu/libraries/bts/hilcc/). See [useHILCC driver param](https://github.com/NatLibFi/RecordManager/wiki/Data-Source-Configuration#possible-settings-for-driverparams) for more information.
- [**BC**] Added support for UNICODE folding of key fields. Enabled by default and replaces the internal folding table, but can be disabled or configured with the `Site/key_folding_rules` setting in recordmanager.ini. It is recommended that `./console records:renormalize` is run to update all keys in the database to use the rules.
- Added support for specifying rules (fieldRules[] in datasources.ini) for copying, moving and deleting fields before they are sent to Solr.

### Changed

- [**BC**] Refactored the code to use Laminas module manager and service manager. This includes a lot of cleaning up as well.
- Simplified the deduplicated record update mechanism. Setting `threaded_merged_record_update` no longer exists. Instead there's an optional new setting `Solr/dedup_workers` for controlling the number of workers for deduplicated records.
- [**BC**] Switched to vufind-org/vufind-marc for MARC record handling. Note that also the internal format for storing MARC records has been changed to [MARC-in-JSON](https://web.archive.org/web/20151112001548/http://dilettantes.code4lib.org/blog/2010/09/a-proposal-to-serialize-marc-in-json/). While RecordManager can still read records added to the database by previous versions, any older version will not be able to read the new format.
- Made different verbosity levels have an effect on output of many commands.
- [**BC**] Renamed OnkiLightEnrichment and its subclasses to ...SkosmosEnrichment and refactored to use proper JSON-LD for offline and online enrichment. ldEnrichment collection/table replaces the existing ontologyEnrichment collection/table in the database. The old ontologyEnrichment collection/table can be dropped.
- [**BC**] The `getFormat` method now returns an array of formats, and deduplication handler requires that all formats match for records to be deduplicated.
- Marc record uses Marc\FormatCalculator to determine formats.

### Removed

- Removed the Finna module (moved to the [RecordManager-Finna](https://github.com/NatLibFi/RecordManager-Finna) repository).
- The MySQL/MariaDB database no longer uses a trigger to check dedup record deletion as it did not work correctly. It is recommended to drop this trigger in `mysql` if it exists: `DROP TRIGGER if exists dedup_before_update;`
- Removed the requirement for GEOS. Nominatim enrichment now requests simplified polygons from the server if simplification_tolerance is set. simplification_max_length parameter is no longer supported.

## [1.9.0] - 2021-10-27

First and last officially released version before the upcoming large refactoring in 2.0.0.

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


