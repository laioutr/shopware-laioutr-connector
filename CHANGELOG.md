# Changelog

## [1.1.0](https://github.com/laioutr/shopware-laioutr-connector/compare/v1.0.0...v1.1.0) (2026-08-25)


### ⚠ BREAKING CHANGES

* `lockdownAdditionalAllowedRoutes` is replaced by `lockdownAdditionalBlockedRoutes`. The key is renamed rather than reused because its meaning inverts — every route an admin had allowed would otherwise have become blocked. Re-enter any values under the new setting.

### Features

* return the shopper to laioutr after a checkout that left the frame ([3e8e3f7](https://github.com/laioutr/shopware-laioutr-connector/commit/3e8e3f790102d653656cf6d3cd1697f45b80c8b9))
* split lockdown from embedded mode and block only Laioutr-owned routes ([a76c847](https://github.com/laioutr/shopware-laioutr-connector/commit/a76c84757d5e56075229ad2423a9f6b8f29ae284))
* submit a payment retry from the top-level window too ([ac7f132](https://github.com/laioutr/shopware-laioutr-connector/commit/ac7f1323292a50f7d331590bb3010162c9a960f2))


### Bug Fixes

* consume the auth code from the storefront URL after announcing it ([508e1dd](https://github.com/laioutr/shopware-laioutr-connector/commit/508e1dd1a8cfcfa108b715c704bfa2b827ec209d))
* leave the frame's own navigation to the retry route in place ([6740f48](https://github.com/laioutr/shopware-laioutr-connector/commit/6740f486824fe6b5488aa0ed574d2eb328245c8d))


### Miscellaneous Chores

* release 1.1.0 ([bdb50d2](https://github.com/laioutr/shopware-laioutr-connector/commit/bdb50d22172a17ed9dc99452ef2e046953508614))

## 1.0.0 (2026-07-22)


### Features

* add AuthBridgeNotifier to signal storefront auth changes over the bridge ([5ba54fa](https://github.com/laioutr/shopware-laioutr-connector/commit/5ba54fa769a7ed2fb70f21a0162161ad8e781955))
* add standalone Shopware connector ([ecc1e90](https://github.com/laioutr/shopware-laioutr-connector/commit/ecc1e90cc143c2a9d5c3d149a99c31656d8c1085))
* add store-api session-adopt endpoint to redeem a handoff code for its token ([8cab363](https://github.com/laioutr/shopware-laioutr-connector/commit/8cab363d9f62eb0f6eea47e3869d537a362ad5dd))
* allow storefront widget/AJAX fragment routes under embedded-mode lockdown ([f909cb5](https://github.com/laioutr/shopware-laioutr-connector/commit/f909cb535bcb76c23163da4b06a9dba6a1587377))
* emit laioutr:auth-changed from the storefront bridge on login/logout ([0d5708f](https://github.com/laioutr/shopware-laioutr-connector/commit/0d5708f3a698f29166f2189ba3d8cdcc28fd4e94))
* exchange session handoff via single-use code ([418d982](https://github.com/laioutr/shopware-laioutr-connector/commit/418d982c26fdd96225966be3c8de13d7c585d8ba))
* lock down storefront and bridge it to Laioutr in embedded mode ([a4ef762](https://github.com/laioutr/shopware-laioutr-connector/commit/a4ef76278cd0eaa3c7eae5fed9b78dc71d54df76))
* route storefront auth callbacks through the bridge in embedded mode ([1d80a88](https://github.com/laioutr/shopware-laioutr-connector/commit/1d80a888aadf924307f39a586e207844bbf944b7))


### Bug Fixes

* correct lockdown allowlist and hide the cookie bar in embedded mode ([b6ece0b](https://github.com/laioutr/shopware-laioutr-connector/commit/b6ece0b7ff0d2d9c2ac79aee049bd6514913fc3b))


### Miscellaneous Chores

* standardize plugin development setup ([90e6ea6](https://github.com/laioutr/shopware-laioutr-connector/commit/90e6ea6af297122dbc9eb131d31bb4df9d0aea82))
