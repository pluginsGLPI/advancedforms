# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

### Changed

- **Breaking**: the `Table` question's `Min rows` / `Max rows` settings are removed. Row bounds are now declared as validation conditions on the question (`Length is greater than…` / `Length is less than…`, applied to the number of filled rows), and are also usable as visibility criteria. Bounds configured in 1.2.0 are ignored and must be redeclared

### Fixed

- Fixed the `Table` question's column validation: each column is now checked against its own pattern only, values the pattern accepts are no longer rejected, the column type's own format check still applies, and errors are listed once below the table
- Fix string condition operators (equals, contains, length) not being available on hidden questions

## [1.2.0] - 2026-07-28

### Add

- Add Reservation question type
- Add configurable table question type

### Fixed

- Fixed the `Hidden`, `IP address`, and `Hostname` questions to always use the correct value regardless of what was submitted, and restored the missing access check on the tree cascade dropdown children endpoint
- Fixed the `LDAP select` question's autocomplete search to properly handle special characters in the search text
- Fix visibility conditions on tree cascade dropdown questions
- Fixed the `Tree cascade Dropdown` field so that the subtree depth limit is enforced when loading children via AJAX
- Fixed the `Tree cascade Dropdown` question to only show items from the configured custom dropdown instead of all custom dropdowns
- Fixed `Tree cascade Dropdown` question showing items from all custom dropdowns instead of only items from the configured one

## [1.1.1] - 2026-05-27

### Fixed

- Fixed the `Tree cascade Dropdown` field so that it works when it is a required field in single-level responses

## [1.1.0] - 2026-04-27

### Add

- split tree dropdown question

## [1.0.1] - 2026-01-07

### Fixed

- Ip address and hostname question types: add reverse proxy support
- LDAP select question type: inactive LDAP directories can no longer be selected
- LDAP select question type: fix select not working on helpdesk
- LDAP select question type: fix filtering not being applied 
