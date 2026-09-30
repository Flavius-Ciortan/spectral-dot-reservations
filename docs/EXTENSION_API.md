# Spectral Dot Reservations Extension API

This document describes the public contracts available to compatible add-ons in Spectral Dot Reservations Free 1.0.0. Add-ons should require Free, wait for `sdpr_plugin_loaded`, and use these contracts instead of duplicating reservation or inventory logic.

## Bootstrap

```php
add_action(
	'sdpr_plugin_loaded',
	function ( SDPR_Plugin $plugin, SDPR_Service_Container $services ) {
		$lifecycle = $services->get( 'lifecycle' );
		if ( ! $lifecycle instanceof SDPR_Reservation_Lifecycle_Interface ) {
			return;
		}

		// Register the add-on after all Free services are ready.
	}
);
```

The same service can be retrieved with `$plugin->get_service( 'lifecycle' )`.

Stable service IDs:

- `lifecycle`: implements `SDPR_Reservation_Lifecycle_Interface`.
- `repository`: implements `SDPR_Reservation_Repository_Interface`.
- `rules`: resolves the filtered Free reservation rules.
- `notifications`: dispatches canonical reservation events.
- `dependency_notices`: registers admin notices for add-on dependency failures.

Inventory, locking, expiration, privacy, and cart/order services are exposed for internal coordination but are not independent public APIs. Add-ons should use the lifecycle interface for state changes.

## Lifecycle Interface

`SDPR_Reservation_Lifecycle_Interface` exposes:

```php
request( $product_id, $user_id, $requested_quantity = 1 );
create( $product_id, $user_id = 0, $guest_email = '', $quantity = 1 );
approve( $reservation_id );
deny( $reservation_id, $reason = '' );
cancel( $reservation_id );
extend( $reservation_id, $additional_hours, $source = 'extension' );
```

Methods return their documented success value or `WP_Error`. Callers must preserve the returned error and must not update reservation status or WooCommerce stock directly.

`extend()` changes an unexpired open reservation deadline while holding the same product/customer locks used by lifecycle transitions. Add-ons must use it instead of writing `_sdpr_expires_at` directly.

Free resolves every request to one unit. Add-ons may use the quantity and inventory-support filters below to enable multi-unit or variation reservations. The resolved quantity is stored in canonical reservation metadata and remains visible when an add-on is disabled.

## Rule Filters

### `sdpr_product_is_reservable`

```php
apply_filters( 'sdpr_product_is_reservable', bool $reservable, ?WC_Product $product, int $user_id );
```

Extends eligibility after Free has checked its global switch, customer identity, product type, published/purchasable state, managed stock, and positive quantity.

### `sdpr_product_supports_reservation_inventory`

```php
apply_filters( 'sdpr_product_supports_reservation_inventory', bool $supported, ?WC_Product $product );
```

Extends the product types whose managed stock can participate in lifecycle transactions. Free supports simple products. An add-on enabling another type must ensure the returned product owns a concrete WooCommerce stock quantity.

### `sdpr_reservation_quantity`

```php
apply_filters( 'sdpr_reservation_quantity', int $quantity, int $requested_quantity, int $product_id, int $user_id );
```

Resolves the quantity before stock is checked and the reservation is created. Free returns one. Add-ons may return a bounded positive quantity according to their rules; the lifecycle applies a final safety bound of 1 to 10,000 units.

### `sdpr_reservation_form_fields`

```php
do_action( 'sdpr_reservation_form_fields', WC_Product $displayed_product );
```

Renders add-on fields inside the reservation form. A numeric field named `quantity` is submitted to the lifecycle automatically. Free submits one when the field is absent.

### `sdpr_customer_reservation_limit`

```php
apply_filters( 'sdpr_customer_reservation_limit', int $limit, int $user_id, int $product_id );
```

Changes the concurrent open-reservation limit for a customer in the context of the requested product. `$product_id` is zero when no product context is available. Return an integer of at least one.

### `sdpr_reservation_requires_approval`

```php
apply_filters( 'sdpr_reservation_requires_approval', bool $required, int $product_id, int $user_id );
```

Determines whether a request remains pending or becomes active immediately.

### `sdpr_reservation_duration_hours`

```php
apply_filters( 'sdpr_reservation_duration_hours', int $hours, string $context, int $product_id, int $user_id );
```

`$context` is `pending` or `active`. Return an integer of at least one.

### `sdpr_manage_reservations_capability`

```php
apply_filters( 'sdpr_manage_reservations_capability', string $capability );
```

Changes the capability used by settings and reservation operations. The default is `manage_woocommerce`.

## Transition Contracts

### `sdpr_reservation_transition_allowed`

```php
apply_filters(
	'sdpr_reservation_transition_allowed',
	bool $allowed,
	int $reservation_id,
	string $from_status,
	string $to_status,
	string $source
);
```

Runs before a lifecycle transaction changes status or inventory. Return `false` to veto the transition. A veto must be deterministic and fast.

### `sdpr_reservation_transitioned`

```php
do_action(
	'sdpr_reservation_transitioned',
	array(
		'reservation_id' => 123,
		'from'           => 'pending_approval',
		'to'             => 'active',
		'source'         => 'approve',
		'occurred_at'    => 1788134400,
	)
);
```

Runs only after a successful transition. Treat the payload as read-only. Handlers should be idempotent because external delivery can be retried by an add-on.

Canonical public statuses are defined by `SDPR_Reservation_Status`: `pending_approval`, `active`, `expired`, `cancelled`, `fulfilled`, `denied`, and `order_cancelled`.

## Notification Contract

```php
do_action( 'sdpr_reservation_event', string $event, int $reservation_id, string $email, array $context );
```

Canonical events are `created`, `pending`, `approved`, `expired`, and `denied`. This event is the preferred integration point for custom email, webhook, or messaging providers. Free also emits the corresponding prefixed event-specific hooks.

### `sdpr_email_content`

```php
apply_filters( 'sdpr_email_content', array $content, string $event, int $reservation_id );
```

Filters Free's final transactional email once, immediately before `wp_mail()`. The content keys are `to`, `subject`, `body`, and `headers`. Returning an invalid recipient suppresses delivery. `sdpr_email_sent` runs afterward with the boolean mail result, event, reservation ID, and final content.

### `sdpr_reservation_extended`

Runs after a locked deadline extension and receives a read-only array containing `reservation_id`, `previous_expiry`, `new_expiry`, `additional_hours`, `source`, and `occurred_at`.

## Dependency Notices

An add-on that cannot initialize should register a clear notice instead of deactivating Free:

```php
add_action(
	'sdpr_plugin_loaded',
	function ( SDPR_Plugin $plugin ) {
		$notices = $plugin->get_service( 'dependency_notices' );
		if ( $notices instanceof SDPR_Dependency_Notices ) {
			$notices->add( 'my-addon-version', 'My Add-on requires a newer Spectral Dot Reservations Free version.' );
		}
	}
);
```

## Compatibility Rules

Notices default to this plugin's settings, reservations, and analytics screens. The optional fourth argument `plugins` additionally permits actionable dependency notices on the Plugins screen, not the general dashboard. All notices are dismissible. Dismissal is per user and site, lasts up to one day, and is invalidated if the message changes. Register translated messages on or after `init`, not during early bootstrap.

This is the first Spectral Dot release. Only add-ons built for the `SDPR_` contracts and `spectral-dot-reservations` dependency are supported. There are no aliases or automatic imports for another plugin's identifiers or records.

- Require a compatible `SDPR_VERSION` before registering modules.
- Never write canonical reservation meta or product stock to emulate a lifecycle transition.
- Never replace or subclass the main Free plugin class.
- Keep add-on data readable when the add-on is disabled or its license expires.
- Use the canonical filters for product, customer, approval, and duration rules.
- Use lifecycle methods for approve, deny, and cancel operations.
- Keep callbacks idempotent and avoid remote requests inside inventory transactions.
