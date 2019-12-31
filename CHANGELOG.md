# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2019-07-17
### Added
- Initial Release

## [1.0.1] - 2019-07-18
### Added
- Added zone_exists method to override parent and prevent issues with zoneAfxr method
### Fixed
- SOA creation defaults to YYYYMMDD01 instead of last two numbers being random
- Record updates will now properly increment the SOA Serial
- Record creation no longer removes same named and type records leaving only the new one
- Record deletion no longer removes all records of the same name and type
- Fixed record updates not updating all the time
### Changed
- Changed license from GPLv3 to MIT to allow for integration into apnscp

## [1.0.2] - 2019-07-24
### Fixed
- Fixed issue with NS definition using wrong constant
- Fixed warning using wrong macro

## [1.0.3] 2019-07-28
### Fixed
- Merged Pull Request to address various issues

## [1.0.4] 2019-12-31
### Added
- Added zone type selection in src/Module.php
- Added extra config param and description in README.md
### Changed
- Changed LICENSE year
### Fixed
- Fixed README.md commands and trailing spaces
