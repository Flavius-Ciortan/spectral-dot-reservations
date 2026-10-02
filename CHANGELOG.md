# Changelog

All notable changes to Spectral Dot Reservations are documented here. The project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and semantic versioning.

## 1.0.0 (Unreleased)

Initial public release under the Spectral Dot branding. This is a fresh installation, not an upgrade or migration from a previously published plugin.

### Added

- Focused lifecycle, repository, inventory, cart/order, expiration, privacy, notification, rules, locking, and service-container components.
- Stable add-on contracts for bootstrap, rules, lifecycle transitions, notifications, capabilities, repositories, and dependency notices.
- Site Health reporting for expiration scheduling and inconsistent inventory ownership.
- Deterministic release ZIP generation with a checksum.
- WordPress Coding Standards, PHP compatibility, Plugin Check, and GitHub Actions quality gates.
- Automated integration, concurrency, activation/deactivation/upgrade/uninstall, HPOS, and exact-artifact test harnesses.
- A repeatable 5,000-record reservation metadata performance profile.
- Live verification fixtures for classic checkout, block checkout, customer flow, Shop Manager access, and Administrator access.
- Reduced-motion handling for frontend and admin plugin controls.
- Complete WordPress.org submission metadata, privacy disclosures, and canonical licensing.
- Complete translation coverage and an up-to-date `spectral-dot-reservations` POT catalog.
- WordPress.org directory icons, banners, and release screenshots with matching readme captions.

### Changed

- Made status and inventory transitions transactional, locked, idempotent, and centrally validated.
- Made active holds explicit inventory obligations that transfer to orders at checkout.
- Linked cart items that existed before a reservation was created.
- Allowed Shop Managers to configure and operate reservations through `manage_woocommerce`.
- Centralized canonical statuses, metadata keys, labels, summaries, and cached status counts.
- Moved admin interactions from inline scripts to localized, versioned assets.
- Standardized the plugin directory, bootstrap, text domain, owned identifiers and release package around `spectral-dot-reservations` and the `sdpr` namespace.
- Clarified pending-request and active-reservation deadlines throughout the interface.
- Updated release and user documentation to describe only implemented behavior.
- Positioned the concise Reserve action beside Add to cart on desktop and directly below it on narrow screens, including block-based product templates.
- Associated settings, filters, and dialog descriptions with their controls for assistive technology, and added keyboard navigation to settings tabs.
- Removed an unnecessary metadata join from the recent-reservations analytics query.

### Fixed

- Prevented checkout from decrementing stock a second time for a held unit.
- Prevented duplicate last-unit holds under concurrent requests.
- Preserved cross-request lock exclusivity after WordPress changed duplicate option insertion behavior.
- Prevented interrupted cancellation or expiration from leaving status and stock out of sync.
- Recreated a missing expiration schedule during normal health checks.
- Excluded expired pending requests from customer quota calculations.
- Prevented privacy erasure pagination from skipping reservation records.
- Preserved inventory obligations when a customer account is deleted.
- Represented cancelled-order reservations consistently in customer, admin, and analytics views.
- Made admin transition notices reflect the actual lifecycle result.
- Corrected settings validation feedback and product-search return types.
- Corrected pending and active frontend success messages and their visible inline timing.
- Restored stock during uninstall only when the reservation still owned a held unit.
- Included customer names and related reservation identifiers in privacy exports.
- Removed stored customer names and free-text denial details during privacy erasure.
- Avoided passing an absent single-page pagination result into WordPress HTML sanitization on PHP 8.4.
- Refreshed reservation counts, filtered rows and approval deadlines after admin actions, with visible dismissible feedback.
- Corrected responsive admin filter/table layouts, content alignment and text/focus contrast.
- Separated reservation confirmation content from successful results, with a dedicated close-control header and translated result headings.
- Pinned the CI command-line runtime and made failed tool setup stop before release verification.
- Aligned the plugin settings-save capability with its merchant menu capability so Shop Managers can save settings without access to unrelated WordPress options.
- Replaced the native denial prompt with an accessible inline reason form, explicit submit/cancel controls and focus restoration.
- Kept customer reservation actions separated when they wrap or stack, and rendered admin urgency indicators as compact badges rather than styled table cells.
