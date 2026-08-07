# Seller API

Token-authenticated REST API for the seller app. Everything lives under
`/api/seller` and every response is JSON.

## Authentication

Sanctum personal access tokens. Sign in once per device, then send the token on
every request:

```
Authorization: Bearer <token>
Accept: application/json
```

Tokens expire after 30 days of wall-clock time (`SANCTUM_TOKEN_MINUTES`).
A `401` means the token is missing, expired or revoked — sign in again.

### Three tiers of access

| Tier | What it means |
|---|---|
| public | No token. Register, login, password reset. |
| pending-ok | Any signed-in seller, including a store awaiting approval. |
| approved | Trading endpoints. A pending, suspended or rejected store gets `403` with a `store_status` field explaining which. |

A brand-new sign-up lands in `pending` and holds a working token immediately, so
the app can show a "waiting for approval" screen rather than a login wall.

## Errors

| Code | Meaning |
|---|---|
| 401 | No valid token. |
| 403 | Token is fine, but the store cannot do this yet (see `store_status`). |
| 404 | The record does not exist **or** belongs to another store — the API does not distinguish, on purpose. |
| 422 | Validation failed. Laravel's standard `{ "message": ..., "errors": { field: [...] } }`. |
| 429 | Rate limited. Auth endpoints allow 10/min, uploads 60/min. |

## Scoping

Every list and lookup is filtered to the store the token belongs to. No endpoint
reads a vendor id from the caller — passing `vendor_id` in a product payload is
ignored and the product is filed under your own store.

On an order shared with another seller you see **only your own lines**, and the
`totals` block is your share, never the buyer's basket total.

---

## Endpoints

### Public

| Method | Path | Notes |
|---|---|---|
| POST | `/register` | `name, email, password, password_confirmation, store_name, device_name` + optional `phone, store_email, gst_number`. Returns `201` with `token` and `seller`. |
| POST | `/login` | `email, password, device_name`. Signing in again on the same `device_name` replaces that device's token. |
| POST | `/forgot-password` | `email`. Always answers the same, whether or not the address exists. |
| POST | `/reset-password` | `token, email, password, password_confirmation`. Revokes every existing token. |

### Account (pending-ok)

| Method | Path | Notes |
|---|---|---|
| GET | `/me` | Profile plus the store, including `status` and `can_trade`. |
| PUT | `/me` | `name, email, phone`. |
| PUT | `/me/password` | `current_password, password, password_confirmation`. Signs other devices out, keeps this one. |
| GET | `/store` | Store details. |
| PUT | `/store` | Shopfront and payout details. `commission_rate`, `status` and `rating` are read-only and ignored if sent. |
| POST | `/logout` | Revokes this device's token. |
| POST | `/logout-all` | Revokes every token. |
| GET | `/devices` | Tokens currently issued, with `current: true` on the one making the call. |
| DELETE | `/devices/{id}` | Revoke one device. |

### Notifications (pending-ok)

Notifications belong to the **person**, not the store. Two people signing in to
the same store each get their own pile, and a store still waiting for approval
can read them — which is how the seller hears that they were approved.

| Method | Path | Notes |
|---|---|---|
| GET | `/notifications` | `?filter=unread &kind= &per_page=`. Paginated, plus a top-level `unread_count`. |
| GET | `/notifications/unread-count` | Just the badge number. Cheap enough to call on every app resume. |
| POST | `/notifications/read-all` | |
| POST | `/notifications/{id}/read` | `404` if it isn't yours. |
| DELETE | `/notifications/{id}` | |

Each row carries `title`, `body`, `url`, `tone` and `kind`. Route on `url` when
the seller taps it; `kind` is the stable machine name for icons and filtering
(`order-placed`, `low-stock-reached`, `payout-recorded`, …).

Which notifications a seller receives is decided by the marketplace's role
matrix, not by the app. Today a vendor login hears about orders, products,
payouts and analytics — scoped to their own store.

### Browser push (pending-ok)

| Method | Path | Notes |
|---|---|---|
| GET | `/push` | `{ enabled, public_key, devices }`. If `enabled` is false the marketplace has no VAPID keys configured — hide the toggle. |
| POST | `/push` | `endpoint`, `keys.p256dh`, `keys.auth`, optional `content_encoding`. Re-posting the same endpoint updates rather than duplicates. |
| DELETE | `/push` | `endpoint`. |

Subscriptions are per device. Deliveries that a push service rejects as gone
are dropped server-side, so a reinstalled app does not need cleaning up.

### Dashboard and money (approved)

| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard` | `?days=30` (1–365). Totals, a needs-attention block, top 5 products. |
| GET | `/payouts` | `?status=` |
| GET | `/payouts/{id}` | |
| GET | `/payouts/earnings` | Lifetime gross/commission/earning, plus paid, in-progress and unsettled. |
| GET | `/analytics/sales` | `?days=30` (1–365). One point per day for the chart. |

`/analytics/sales` returns `series[]` with `date`, `sales`, `earning`, `orders`
and `units`. Days with no sales are included as zeroes — the series is always
exactly `days` long, so the chart keeps its true shape.

### Catalog reference (approved)

| Method | Path |
|---|---|
| GET | `/catalog/options` |
| GET | `/catalog/categories` |
| GET | `/catalog/attributes` |
| GET | `/catalog/tax-classes` |

`options` returns categories, attributes, tax classes and valid statuses in one
round trip — the product form only needs this one call.

### Products (approved)

| Method | Path | Notes |
|---|---|---|
| GET | `/products` | `?search= &status= &low_stock=1 &sort=name\|price\|stock\|oldest &per_page=` (max 100) |
| POST | `/products` | Full payload, see below. |
| GET | `/products/{id}` | |
| PUT | `/products/{id}` | Full payload — anything omitted is cleared, including images. |
| DELETE | `/products/{id}` | |
| PATCH | `/products/{id}/status` | `status` only. |
| PATCH | `/products/{id}/stock` | `stock_quantity`, optional `low_stock_threshold`. `422` on a variable product — change its variants instead. |
| POST | `/products/bulk` | `action` (`activate`/`draft`/`archive`/`delete`) + `ids[]`, max 200. |

`/products/bulk` answers `{ action, changed, skipped }`. Ids belonging to
another store are counted in `skipped` rather than failing the call, so a stale
multi-select still applies to everything that is genuinely yours. If **nothing**
in the list is yours the call is a `404`.

Minimum to create a product:

```json
{
  "name": "Kanchipuram silk saree",
  "type": "simple",
  "price": 4999,
  "status": "active",
  "stock_quantity": 12
}
```

A variable product carries `variants`, each with its own `price`,
`stock_quantity` and `values` (`attribute_id` + `attribute_value_id`). The
parent's stock is recomputed as the sum of its variants.

Images are attached by path, not by file: upload first, then send the returned
path in `images[].path`.

### Uploads (approved)

| Method | Path | Notes |
|---|---|---|
| POST | `/uploads` | multipart: `file` (image, max 5 MB) + `folder` (`products` or `vendors`). Returns `path` and a preview `url`. |

Each store writes into its own folder on the CDN, so uploads cannot collide
between sellers. Save the returned `path` on the record — not the URL, which is
signed and time-limited.

### Orders (approved)

| Method | Path | Notes |
|---|---|---|
| GET | `/orders` | `?search= &status= &fulfillment_status= &from= &to= &per_page=` |
| GET | `/orders/summary` | Counts per status plus an unfulfilled count. |
| GET | `/orders/{id}` | |
| POST | `/orders/{id}/fulfill` | `items[].id`, `items[].quantity`, optional `tracking_number`, `carrier`. |
| POST | `/orders/{id}/notes` | `note`. Lands on the order timeline the admin panel shows. |

Fulfilment only moves your own lines. The order-level status is recomputed from
**every** line, so a two-vendor order stays `partially_fulfilled` until the
other seller ships too.

Customer data is deliberately thin: a name, a phone and the shipping address —
enough to pack and deliver, nothing that identifies the buyer beyond this order.

### Team (approved)

A **vendor** is the store; a **seller** is a person's login. One store can have
several logins — an owner, someone who packs, someone who answers customers.
They all see identical store data; what stays private to each person is their
password, their devices and their notifications.

| Method | Path | Notes |
|---|---|---|
| GET | `/team` | Every login on this store. `is_you: true` marks the caller. |
| POST | `/team` | `name, email, password, password_confirmation`, optional `phone`. |
| PUT | `/team/{id}` | `name, email`, optional `phone`. |
| PATCH | `/team/{id}/toggle` | Switch a colleague's access off or on. Revokes their tokens. |
| DELETE | `/team/{id}` | |

There is no owner/staff distinction inside a store yet — every login here can
manage the others. Two guards stop that going wrong: you cannot deactivate or
remove **yourself** (`422`), and you cannot leave the store with no active login
(`422`). A login on another store is a `404` like anything else.

`role` and the store are taken from the token, never the payload, so this
endpoint cannot mint a login for someone else's store.

### Shipping (approved)

Zones are the marketplace's and read-only. Rates inside a zone belong to a
store, so each seller prices their own delivery.

| Method | Path | Notes |
|---|---|---|
| GET | `/shipping/zones` | Active zones, with their countries and states. |
| GET | `/shipping/rates` | `?zone=`. Your rates **plus** the marketplace's fallbacks. |
| POST | `/shipping/rates` | `shipping_zone_id, name, type, rate` + the optional bounds. |
| PUT | `/shipping/rates/{id}` | |
| DELETE | `/shipping/rates/{id}` | |

Every rate carries two flags worth reading before drawing the row:

- `is_marketplace` — a fallback the marketplace set, shown so the seller
  understands what applies when they have configured nothing.
- `editable` — false on those fallbacks and on anything not yours. Writing to
  one is a `404`.

`type` is one of `flat`, `free`, `weight_based`, `price_based`, `item_based`.

### Cancellations and refunds (approved)

| Method | Path | Notes |
|---|---|---|
| GET | `/cancellations` | `?status=` |
| GET | `/cancellations/{id}` | |
| POST | `/cancellations/{id}/respond` | `note` — your side of the story, onto the order timeline. |
| GET | `/refunds` | `?status=` |
| GET | `/refunds/{id}` | |
| POST | `/refunds/{id}/respond` | `note` |

Approving or rejecting is the marketplace's call, not the seller's. These
endpoints are read-plus-comment by design.

---

## Pagination

List endpoints return Laravel's standard shape:

```json
{
  "data": [ ... ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 25, "total": 42, "last_page": 2 }
}
```

`per_page` defaults to 25 and is capped at 100.

## Money in JSON

Amounts come back as JSON numbers. JSON does not distinguish `2000` from
`2000.0`, so parse them as decimals client-side rather than relying on the
literal form.
