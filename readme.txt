=== Spectral Dot - Product Reservations for WooCommerce ===
Tags: woocommerce, reservation, product hold, stock management, inventory
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Let logged-in customers reserve WooCommerce products for a limited time while keeping inventory and checkout fulfillment consistent.

== Description ==

Spectral Dot Reservations adds a complete reservation workflow for WooCommerce stores that sell stock-managed simple products.

= Customer features =

* Reserve an eligible product from its product page
* View active and historical reservations in My Account
* Add an active reservation to the cart and complete checkout
* Cancel an eligible reservation
* Receive reservation, approval, denial, and expiration emails when notifications are enabled

= Merchant features =

* Configure reservation and pending-approval durations from 1 to 168 hours
* Limit concurrent open reservations per customer
* Require merchant approval before stock is held
* Search, filter, approve, deny, cancel, or delete reservations
* View product-level active reservations and basic reservation analytics
* Customize reservation modal colors, typography, and border radius
* Allow Administrators and WooCommerce Shop Managers to operate reservations

= Inventory behavior =

An immediate reservation reduces stock once. When approval is required, the request remains pending without changing stock; approval starts the active hold and its duration. Cancellation or expiration releases held stock once. Checkout transfers the held unit to the order without a second stock decrement. Qualifying cancelled orders restore the unit once.

The Free edition supports logged-in customers, simple products with WooCommerce stock management enabled, and one unit per reservation.

= Privacy and external services =

Reservation records are stored only in this website's WordPress database. Spectral Dot Reservations does not send reservation data, telemetry, or usage analytics to the plugin authors or any service selected by the plugin.

If email notifications are enabled, reservation details are passed to the mail delivery system configured by the site owner. That system may be the web host, an SMTP plugin, or another third-party provider and is governed by the site owner's provider terms.

Reservation records remain in the database until the plugin is uninstalled. For closed reservations, WordPress erasure anonymizes the customer identity and free-text denial details while retaining operational reservation, order, and inventory data. Open reservations are retained until their inventory obligation ends; the customer can submit another erasure request after the reservation closes.

== Installation ==

1. Install and activate WooCommerce.
2. Install Spectral Dot Reservations from WordPress.org, or upload the release ZIP through **Plugins > Add New > Upload Plugin**.
3. Activate the plugin.
4. Open **Spectral Dot Reservations > Settings** and enable reservations.
5. Configure durations, limits, notifications, and approval behavior.
6. Ensure reservable products are published simple products with stock management enabled and positive stock.

== Frequently Asked Questions ==

= Do customers need an account? =

Yes. The current Free workflow requires a logged-in WordPress customer.

= Does a pending approval request reduce stock? =

No. Stock is held only when the request is approved. A pending request has its own deadline; the active reservation duration starts at approval.

= What happens when an active reservation expires? =

The reservation becomes expired and its held stock is restored exactly once. If notifications are enabled, the customer receives an expiration email.

= Which products are supported? =

Published, purchasable simple products with WooCommerce stock management enabled and positive stock are supported. Variable, grouped, external, composite, and bundled products are outside the current Free scope.

= Does checkout reduce stock twice? =

No. The reservation owns the held unit until checkout transfers that inventory obligation to the order.

= Does it work with Cart and Checkout Blocks and HPOS? =

Yes. The plugin declares and tests compatibility with WooCommerce Cart/Checkout Blocks and High-Performance Order Storage.

= Do I need an SMTP plugin? =

Not necessarily. Spectral Dot Reservations uses the standard WordPress mail pipeline. Each merchant should decide whether their host delivers mail reliably or whether an SMTP service/plugin is appropriate.

= Can Shop Managers operate reservations? =

Yes. Reservation settings and operations use WooCommerce's management capability, which is available to Administrators and Shop Managers.

= What data does the plugin store? =

Reservation records can contain the customer user ID, first and last name, email address, product ID, quantity, status, creation and expiry times, inventory state, denial details, and a related order ID. The plugin integrates with WordPress personal-data export and erasure tools.

= Does the plugin use an external service or track usage? =

No. The plugin has no author-operated service, telemetry, tracking, advertisements, or remote account requirement. Email is handed to the mail system selected and configured by the site owner.

== Screenshots ==

1. Configure reservation limits, durations, approval workflow, email notifications, and modal appearance.
2. Offer a clear Reserve action alongside WooCommerce purchasing controls.
3. Confirm the hold duration in an accessible, keyboard-friendly reservation dialog.
4. Customers can review deadlines, add reserved items to the cart, or cancel active reservations from My Account.
5. Manage and filter reservation status, expiry, customer, and stock-hold actions from the WordPress dashboard.
6. Review reservation totals, lifecycle outcomes, conversion rate, and recent activity.

== Changelog ==

= 1.0.0 - 2026-09-04 =

* Reworked lifecycle and inventory ownership into transactional, idempotent services
* Added safe cart-before-reservation linkage and checkout fulfillment without double stock reduction
* Added concurrency locking, cron self-healing, inventory diagnostics, and upgrade reconciliation
* Preserved cross-request lock exclusivity on current WordPress versions
* Corrected pending quotas, privacy erasure pagination, order-cancelled handling, and Shop Manager access
* Added stable service, rule, lifecycle, and notification extension contracts
* Added HPOS, classic checkout, block checkout, accessibility, role, lifecycle, and exact-artifact verification
* Completed translatable interface coverage and refreshed the translation catalog
* Added deterministic release packaging, WordPress coding standards, Plugin Check, and CI quality gates
* Expanded privacy exports and removed customer names and free-text denial details during erasure
* Prepared canonical licensing, repository links, and WordPress.org submission documentation
* Added directory artwork, release screenshots, explicit control labels, keyboard-operable settings tabs, and dialog descriptions
* Removed an unnecessary analytics metadata join and corrected single-page reservation pagination on PHP 8.4

== Upgrade Notice ==

= 1.0.0 =

Initial public release of the reservation lifecycle, inventory, checkout, and merchant-management workflow.

== Support ==

Use the plugin's WordPress.org support forum.
