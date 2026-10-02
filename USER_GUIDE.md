# Spectral Dot Reservations User Guide

This guide applies to Spectral Dot Reservations Free 1.0.0.

## Requirements

- WordPress 6.5 or newer.
- WooCommerce 8.0 or newer.
- PHP 7.4 or newer.
- Published simple products with WooCommerce stock management enabled and positive stock.
- Logged-in customer accounts.

The Free edition reserves one unit per reservation. Variable, grouped, external, composite, and bundled products are not supported.

## Installation

1. Install and activate WooCommerce.
2. Install Spectral Dot Reservations from WordPress.org, or upload the release ZIP under **Plugins > Add New > Upload Plugin**.
3. Activate **Spectral Dot Reservations**.
4. Open **Reservations > Settings**. The Reservations menu sits directly below Products in the WordPress sidebar.
5. Enable reservations and save the settings.

## Settings

### General Settings

- **Enable Reservation** is the global switch for the customer reservation workflow.
- **Max Reservations Per User** limits concurrent pending and active reservations for one customer. The accepted range is 1 to 100.
- **Reservation Duration** controls how long an active stock hold remains open. The accepted range is 1 to 168 hours.
- **Approval Request Duration** controls how long a pending request remains open. The accepted range is 1 to 168 hours. This timer is separate from the active duration.
- **Enable Email Notifications** sends the built-in transactional messages through the normal WordPress mail pipeline.
- **Require Admin Approval** creates a pending request first. Pending requests do not reduce stock; approving one starts the active duration and holds stock.

### Pop-up Customization

The logged-in customer reservation modal supports:

- Border radius from 0 to 50 pixels.
- Background and text colors.
- An allowlisted font family.
- Font size from 10 to 40 pixels.

The modal includes a semantic dialog, keyboard focus containment, Escape-to-close behavior, focus restoration, live notice output, and reduced-motion support. After a successful submission, the confirmation question is replaced by a result heading and message. Select **Done** or close the dialog to refresh the product page. If a request fails, the confirmation form remains available with an inline error.

## Customer Workflow

### Immediate Reservations

1. The customer signs in and opens an eligible product.
2. The customer selects **Reserve** and confirms the request.
3. The reservation becomes **Active** and stock decreases by one.
4. The customer can open **My Account > Reserved products**, cancel the hold, or add it to the cart.
5. Checkout marks the linked reservation **Purchased** without reducing the held unit a second time.
6. If the deadline passes first, the reservation becomes **Expired** and stock is restored once.

### Approval Workflow

1. The customer submits a request and sees a pending-approval confirmation.
2. The request becomes **Pending approval**. Stock is unchanged.
3. A merchant approves or denies the request under **Reservations > Reservations**.
4. Approval starts the active duration and decreases stock by one.
5. Denial closes the request without changing stock.
6. An unanswered request becomes **Expired** when its pending deadline passes.

### Cancellation and Orders

- A customer can cancel an eligible pending or active reservation from My Account.
- A merchant can cancel an eligible reservation from the admin page.
- Cancelling an active hold restores stock exactly once.
- A completed checkout transfers the held inventory obligation to the WooCommerce order.
- A qualifying cancelled/failed order changes the reservation to **Order cancelled** and restores inventory once.

## Merchant Operations

Administrators and WooCommerce Shop Managers can use the plugin.

### Reservations Page

Open **Reservations > Reservations** to:

- Filter by pending, active, expired, cancelled, purchased, denied, or order-cancelled status.
- Search by email, product name, product ID, display name, email, or login.
- Approve or deny pending requests.
- Cancel pending or active reservations.
- Delete terminal reservation records.
- Review cached status totals and paginated results.

Actions are nonce-protected and report the real lifecycle result. If another process changes a reservation first, the interface does not report a false success.

Selecting **Deny** opens an inline form identifying the customer and product. Enter an optional reason, then select **Deny request** to submit or **Keep request** to leave the reservation unchanged. Escape also closes the form and returns focus to the original Deny button. The reason is included in the denial email when notifications are enabled.

### Product Inventory Panel

Edit a product and open its **Inventory** tab to view active reservations for that product.

### Analytics

Open **Reservations > Analytics** to view current totals, status counts, conversion percentage, and recent reservations. This is operational summary data, not historical trend reporting.

### Site Health

WordPress Site Health reports whether:

- The reservation expiration event is scheduled.
- Reservation status and inventory ownership are consistent.

The plugin recreates a missing expiration event during normal health checks. Resolve any reported inventory inconsistency before manually changing WooCommerce stock.

## Email Delivery

When notifications are enabled, the plugin sends built-in messages for creation, pending approval, approval, denial, and expiration. It uses `wp_mail()` through WordPress/WooCommerce mail handling.

An SMTP plugin is not a Spectral Dot Reservations requirement. Each merchant should decide based on hosting mail reliability, delivery logs, and authentication needs. If messages are generated but not delivered, inspect WooCommerce/WordPress mail delivery and the host's outbound-mail policy.

## Status Reference

- **Pending approval:** Waiting for a merchant decision; no stock is held.
- **Active:** One stock unit is held for the customer.
- **Expired:** The relevant deadline passed; held stock was released when applicable.
- **Cancelled:** The customer or merchant cancelled the reservation.
- **Purchased:** Checkout transferred the held unit to an order.
- **Denied:** A merchant rejected a pending request.
- **Order cancelled:** A qualifying order cancellation released the transferred unit.

## Troubleshooting

### Reserve button is missing

Confirm that reservations are enabled, the customer is logged in, and the product is published, purchasable, simple, stock-managed, and has positive stock.

### Customer cannot create another reservation

Check for an existing pending/active reservation for the same product and check the global per-customer limit. Expired pending requests are excluded from the quota even before scheduled cleanup runs.

### Reservations are not expiring

Open **Tools > Site Health** and inspect the Spectral Dot Reservations expiration test. WordPress cron still requires traffic or a server-side cron runner to execute due events reliably.

### Stock appears incorrect

Do not repair the reservation by editing its post meta. Check Site Health, the reservation status, its linked order, and WooCommerce order notes. Lifecycle operations are idempotent; repeating a completed operation should not change stock again.

### Email is not delivered

Enable notifications, confirm the customer address, and test the site's general WordPress mail delivery. Add SMTP only if the merchant's environment requires it.

## Data and Privacy

Reservations can store the customer user ID, first and last name, email address, product ID, quantity, status, creation and expiry times, inventory state, denial details, cancellation metadata, and a related order ID. The data remains in the site's WordPress database. Spectral Dot Reservations does not send it, telemetry, or usage analytics to the plugin authors.

When email notifications are enabled, WordPress passes reservation message content and the customer email address to the mail delivery system configured by the site owner. That system may be operated by the web host, an SMTP plugin, or another third party. Spectral Dot Reservations does not select a provider.

WordPress personal-data exports include reservation identity and operational details. For closed reservations, erasure removes the customer link, name, surname, email address, and free-text denial reason. The operational reservation, status, product, inventory, and order linkage remain to preserve stock and order correctness. Open reservations remain identifiable until their inventory obligation ends; the customer can submit another erasure request after the reservation closes.

Uninstall permanently removes plugin settings and reservation records. Stock is restored only for reservations that still own a held inventory unit. Back up the database before uninstalling from a production store.

## Developer Extensions

Compatible add-ons should use the documented service and hook contracts in [`docs/EXTENSION_API.md`](docs/EXTENSION_API.md). They must not update canonical reservation meta or WooCommerce stock directly.
