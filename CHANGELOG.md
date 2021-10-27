# Changelog

Notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2021-10-27

- Refactor the code to use Laminas module manager and service manager. This includes a lot of cleaning up as well.
- Remove Finna module (will be moved to the RecordManager-Finna repository).
- Add possibility to configure custom modules easily (see README.md).
- Introduce ./console (based on Symfony console) as the new interface for console tasks. Old scripts remain as a simple compatibility layer but don't provide proper parameter error handling etc. anymore.
- Add possibility to define the base path for configuration files with the RECMAN_BASE_PATH environment variable.

## [1.9.0] - 2021-10-27

- First and last officially released version before the upcoming large refactoring in 2.0.0.
