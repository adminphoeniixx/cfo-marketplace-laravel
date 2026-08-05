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

### Dashboard and money (approved)

| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard` | `?days=30` (1–365). Totals, a needs-attention block, top 5 products. |
| GET | `/payouts` | `?status=` |
| GET | `/payouts/{id}` | |
| GET | `/payouts/earnings` | Lifetime gross/commission/earning, plus paid, in-progress and unsettled. |

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
