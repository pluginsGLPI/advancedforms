# Change Log

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## Unreleased

### Add

- Add configurable table question type

### Fixed

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
