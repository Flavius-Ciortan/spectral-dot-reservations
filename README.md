# Spectral Dot Reservations

Spectral Dot Reservations is a WooCommerce extension that lets logged-in customers reserve eligible simple products for a limited time before purchasing them.

## Free Features

- Immediate reservations or an optional merchant-approval workflow.
- Transactional stock holds, releases, expiration, and checkout fulfillment.
- Customer reservation management in My Account.
- Merchant settings, reservation search and filtering, individual actions, and basic analytics.
- Transactional email notifications through WordPress/WooCommerce mail delivery.
- Configurable reservation limits, active and pending durations, and modal appearance.
- Privacy export/erase integration, Shop Manager access, HPOS support, and Cart/Checkout Blocks support.
- Stable extension hooks and service access for compatible add-ons.

The supported Free scope is logged-in customers, one unit per reservation, simple products, and WooCommerce-managed stock.

## Requirements

- WordPress 6.5 or newer.
- WooCommerce 8.0 or newer.
- PHP 7.4 or newer.

## Installation

1. Install and activate WooCommerce.
2. Upload the release ZIP through **Plugins > Add New > Upload Plugin**, or install the WordPress.org release.
3. Activate **Spectral Dot Reservations**.
4. Open **Spectral Dot Reservations > Settings** and enable reservations.
5. Ensure each reservable product is a published simple product with WooCommerce stock management enabled and positive stock.

SMTP is optional. The plugin uses the standard WordPress mail pipeline, so each merchant can choose whether their hosting mail service is sufficient or an SMTP provider/plugin is needed.

## Data and Privacy

Reservation records are stored in the site's WordPress database. The plugin does not send data or telemetry to the authors and does not require an external account. If email notifications are enabled, WordPress passes the message to the delivery system selected by the site owner.

The plugin registers with the WordPress personal-data exporter and eraser. Erasure anonymizes closed records while retaining operational order and inventory data; open reservations remain identifiable until their inventory obligation ends. Uninstall removes reservation records. See [`USER_GUIDE.md`](USER_GUIDE.md) for the complete behavior.

## Development

Install development dependencies with Composer, then run:

```bash
vendor/bin/phpcs
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
find assets -type f -name '*.js' -print0 | xargs -0 -n1 node --check
```

The integration, concurrency, HPOS, lifecycle, and exact-artifact gates are defined in [`tests`](tests) and [`.github/workflows/quality.yml`](.github/workflows/quality.yml). Build a deterministic release ZIP with:

```bash
bash bin/build-release.sh
```

See [`docs/EXTENSION_API.md`](docs/EXTENSION_API.md) for supported add-on contracts and [`USER_GUIDE.md`](USER_GUIDE.md) for merchant usage.

## License

GPL-3.0-or-later. See [`LICENSE`](LICENSE).

## Support and Security

Use the repository issue tracker for reproducible bugs and feature requests. Report security vulnerabilities privately through the repository's Security tab.
