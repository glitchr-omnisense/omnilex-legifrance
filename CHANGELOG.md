# Changelog

## [Unreleased]

### Fixed

- `sandbox` given as a string reads `"false"` and `"0"` as false (`Config::bool()`):
  `sandbox: 'false'` called PISTE's sandbox. Requires omnisense/omnilex with `Config::bool()`.
