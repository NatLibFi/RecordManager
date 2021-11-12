# Changelog

Notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

This is a major release that contains a lot of refactoring and underlying changes. The basic directory structure remains the same, but the introduction of Laminas module manager and service manager change how objects are instantiated.

### Added

- Add possibility to configure custom modules easily (see README.md). Additionally, plugin managers are now used for the following to allow easily plugging additional ones (see module.config.php):
  - Enrichments
  - Harvesters
  - Record drivers
  - Record splitters
- Introduce ./console (based on Symfony console) as the new interface for console tasks. Old scripts remain as a simple compatibility layer but don't provide proper parameter error handling etc. anymore.
- Add possibility to define the base path for configuration files with the RECMAN_BASE_PATH environment variable.

### Changed

- Refactor the code to use Laminas module manager and service manager. This includes a lot of cleaning up as well.
- Deduplicated record update mechanism has been simplified. Setting `threaded_merged_record_update` no longer exists. Instead there's an optional new setting for controlling the number of workers for deduplicated records.

### Removed

- Remove Finna module (moved to the [RecordManager-Finna](https://github.com/NatLibFi/RecordManager-Finna) repository).
- The MySQL/MariaDB database no longer uses a trigger to check dedup record deletion as it did not work correctly. It is recommended to drop this trigger in `mysql` if it exists: `DROP TRIGGER if exists dedup_before_update;`

## [1.9.0] - 2021-10-27

- First and last officially released version before the upcoming large refactoring in 2.0.0.
