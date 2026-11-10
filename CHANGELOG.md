# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- `sandbox` given as a string reads `"false"` and `"0"` as false (`Config::bool()`):
  `sandbox: 'false'` called PISTE's sandbox. Requires omnisense/omnilex with `Config::bool()`.
