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

### Sections

On top of the tiers, every trading endpoint is gated by the marketplace's role
matrix — the same one that decides what a vendor login may open in the admin
panel. Turning "Payouts" off for the vendor role closes the panel section and
`/payouts` together, so the app is never a way around it.

`GET /me` returns the list as `sections`. Hide a screen the seller does not
hold rather than letting them open it and collect a `403`:

```json
{ "sections": ["analytics", "orders", "cancellations", "refunds",
               "products", "shipping", "payouts", "team"] }
```

A withheld section answers `403` with the key that was missing:

```json
{ "message": "The marketplace has turned this off for sellers.", "section": "payouts" }
```

Account-level endpoints are never gated: `/me`, `/store`, `/notifications`,
`/push`, `/devices`, `/refresh`, `/dashboard` and `/uploads` keep working
whatever the matrix says. They belong to the person, not to a section.

The same row is read more narrowly by the admin panel. Every query in this API
is scoped to the store, so the whole grant applies here; the panel only scopes
some of its screens that way, so a vendor login there is held to
`Roles::VENDOR_PANEL_SECTIONS`. Granting a seller "Refunds" therefore opens the
app's Requests screen without handing them every other seller's refunds in the
panel.

## Errors

| Code | Meaning |
|---|---|
| 401 | No valid token. |
| 403 | Token is fine, but the store cannot do this yet (see `store_status`), or the marketplace has withheld this section (see `section`). |
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
| POST | `/refresh` | Trades a working token for a fresh one and revokes the old. Optional `device_name` renames the device. |
| POST | `/logout` | Revokes this device's token. |
| POST | `/logout-all` | Revokes every token. |
| GET | `/devices` | Tokens currently issued, with `current: true` on the one making the call. |
| DELETE | `/devices/{id}` | Revoke one device. |

Tokens expire on wall-clock age (`SANCTUM_TOKEN_MINUTES`, 30 days), not on
idleness, so an app used daily is still thrown out once a month unless it
refreshes. Call `POST /refresh` on resume, well before the token ages out — an
expired token cannot reach the endpoint, and the only way back is a fresh login.
The old token dies the moment the new one is issued, so a stolen copy stops
working as soon as the real device refreshes. Other devices are untouched.

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
the seller taps it; `kind` is the stable machine name for icons and filtering.

| `kind` | Sent when |
|---|---|
| `store-status-changed` | The store is approved, rejected or suspended. `url` is `/store` — the app's own store screen, not an admin path. |
| `order-placed` | An order lands with one of your lines on it. |
| `order-status-changed` | The marketplace cancels, holds, or marks delivered an order of yours. Shipping is not announced — that is normally your own doing. |
| `low-stock-reached` | An order pushes a tracked product to its low-stock threshold. |
| `cancellation-requested` | A cancellation is raised on one of your orders. |
| `cancellation-decided` | The marketplace approves or rejects one. |
| `refund-requested` | A refund is raised on one of your orders. |
| `refund-decided` | It is approved, rejected, or actually paid out. |
| `payout-recorded` | A payout is raised for your store. |
| `payout-status-changed` | It is paid, fails, or goes for processing. |
| `team-member-changed` | A login on your store is added, deactivated or removed. |

On an order shared with another seller, **both** stores are told, and each is
told only about itself: `order-placed` quotes your own share of the basket, and
a `cancellation-*` or `refund-*` on the other seller's lines never reaches you.

Which notifications a seller receives is decided by the marketplace's role
matrix, not by the app — `cancellation-*` needs `cancellations`, `payout-*`
needs `payouts`, and so on — and every one is scoped to your own store.
`store-status-changed` is the exception: it is addressed to the store rather
than to a section, so it arrives whatever the matrix says. That is what makes
the "waiting for approval" screen work — a pending store keeps a working token
precisely so it can be told the answer.

### Browser push (pending-ok)

| Method | Path | Notes |
|---|---|---|
| GET | `/push` | `{ enabled, public_key, devices, firebase: { enabled, project_id, devices } }`. Check the block for your transport: `enabled` false means no VAPID keys, `firebase.enabled` false means no service account — hide the toggle either way. |
| POST | `/push` | `endpoint`, `keys.p256dh`, `keys.auth`, optional `content_encoding`. Re-posting the same endpoint updates rather than duplicates. |
| DELETE | `/push` | `endpoint`. |

Subscriptions are per device. Deliveries that a push service rejects as gone
are dropped server-side, so a reinstalled app does not need cleaning up.

### Firebase push (pending-ok)

The phone app uses FCM rather than VAPID. Firebase project `cfo-hub-5e4fb`.

| Method | Path | Notes |
|---|---|---|
| POST | `/push/device` | `token` (the FCM registration token), optional `platform` (`android`, `ios`, `web` — defaults to `android`) and `device_name`. Returns `201`. |
| DELETE | `/push/device` | `token`. |

Call `POST /push/device` after every sign-in **and** from the token-refresh
listener — Firebase rotates tokens on its own and a stale one silently stops
being delivered to. Registering the same token twice updates the row rather
than duplicating it, and a token registered by a second seller on a shared
phone moves across, so nobody receives the previous seller's orders.

Pass the token to `POST /logout` as `device_token` to stop that phone being
pushed to on sign-out. `POST /logout-all` and a password reset drop them all.

Each message arrives as:

```json
{
  "notification": { "title": "New order CFO-1042", "body": "₹4,500.00 from Meera." },
  "data": {
    "url": "/admin/orders/91",
    "kind": "order-placed",
    "tone": "success",
    "click_action": "FLUTTER_NOTIFICATION_CLICK"
  }
}
```

`data.url` is the screen to open on tap, `data.kind` matches the notification
centre's filter tabs, and `data.tone` matches the badge colours. Android
notifications carry the channel id `cfo_alerts` — the app has to create that
channel or Android 8+ drops them silently.

### Dashboard and money (approved)

| Method | Path | Notes |
|---|---|---|
| GET | `/dashboard` | `?days=30` (1–365). Totals, a needs-attention block, top 5 products. |
| GET | `/payouts` | `?status=` |
| GET | `/payouts/{id}` | |
| GET | `/payouts/earnings` | Lifetime gross/commission/earning, plus paid, in-progress and unsettled. |
| GET | `/analytics/sales` | `?days=30` (1–365). One point per day for the chart. |
| GET | `/analytics/report` | `?preset=7\|30\|90\|365`, or `?from=&to=`. The full report screen in one call. |
| GET | `/analytics/export/{report}` | `products\|categories\|customers\|orders`, same filters. Streams CSV. |

`/analytics/sales` returns `series[]` with `date`, `sales`, `earning`, `orders`
and `units`. Days with no sales are included as zeroes — the series is always
exactly `days` long, so the chart keeps its true shape.

`/analytics/report` is the heavier one, and it is the same code the marketplace
runs, narrowed to your store: `metrics` (each one a `{ value, change }` pair,
`change` being the percentage move against the immediately preceding window of
the same length, or `null` when there is nothing to compare against), `series`,
`by_category`, `top_products`, `top_customers`, `by_payment_method` and the
three breakdowns — `status_breakdown`, `payment_breakdown`,
`fulfillment_breakdown`. `window` echoes back the range that was used, so the
app can label the screen with what it actually got.

An explicit `from`/`to` pair wins over `preset`, and a reversed pair is swapped
rather than rejected. An unknown `preset` falls back to 30 days. `window.preset`
is `null` whenever an explicit range was used.

`exports` lists the report names `/analytics/export/{report}` will accept — use
it to build the download menu rather than hard-coding names. There is no vendor
leaderboard on either endpoint: for one store that is a single row of numbers
already on the screen, and the marketplace-wide version is not a seller's to
read. `/analytics/export/vendors` is a 404.

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
| GET | `/orders` | `?search= &status= &fulfillment_status= &needs_packing=1 &from= &to= &per_page=` |
| GET | `/orders/summary` | Counts per status plus `unfulfilled`. |
| GET | `/orders/{id}` | Adds `timeline` to everything the list already returns. |
| POST | `/orders` | Raise an order by hand. Payload below. |
| GET | `/orders/sellable` | Products this store can be billed for, priced, with variants and stock. |
| GET | `/orders/customers` | `?search=`. People who have already bought from this store. |
| POST | `/orders/{id}/fulfill` | `items[].id`, `items[].quantity`, optional `tracking_number`, `carrier`. |
| POST | `/orders/{id}/notes` | `note`. Lands on the order timeline the admin panel shows. |

`carrier` is the courier's **name**, and it has to come from
`GET /delivery-partners` — the marketplace matches on that name to build the
tracking link. A name typed in free-hand is accepted but produces an order whose
`tracking_url` is `null`, so show a picker, not a text box.

Every order carries `carrier`, `tracking_number` and a ready-made
`tracking_url` (null until both a known courier and a tracking number exist).

Fulfilment only moves your own lines. The order-level status is recomputed from
**every** line, so a two-vendor order stays `partially_fulfilled` until the
other seller ships too.

That is why the "to pack" list is **`?needs_packing=1`**, not
`?fulfillment_status=unfulfilled`. `needs_packing` asks whether any of *your*
lines still has quantity outstanding, so an order you have finished drops off
even while the other seller's half keeps the order itself partially fulfilled.
`summary.unfulfilled` and the dashboard's `needs_attention.unfulfilled_orders`
count the same thing, so the badge and the list it opens always agree.

`?fulfillment_status=` still filters on the order's own state, for when that is
genuinely what you want.

Customer data is deliberately thin: a name, a phone and the shipping address —
enough to pack and deliver, nothing that identifies the buyer beyond this order.

Every order also carries `to_pack` — units on this order still yours to pack —
and `shared_basket`, true when the buyer's basket holds another seller's goods
as well. That flag is why `totals` may not match what the buyer paid, and it is
worth saying so on the screen rather than leaving the seller to wonder.

`/orders/{id}` adds `timeline`, newest first: `type`, `title`, `body`, `by` and
`created_at`. `by` is `store` for something your own store did and `marketplace`
for something the marketplace did — never a staff name. Events written by
another seller on a shared basket are not in the list at all.

#### Raising an order by hand

`POST /orders` covers a phone order, a repeat customer, or a fix for a checkout
that went wrong. It is the same code the panels use, so pricing, tax,
commission, stock and notifications all come out identical whichever door the
order came in through.

```json
{
  "customer_id": 12,
  "email": "buyer@example.com",
  "phone": "9876543210",
  "status": "processing",
  "payment_status": "paid",
  "payment_method": "upi",
  "shipping_method": "Standard Delivery",
  "shipping_total": 79,
  "discount_total": 0,
  "coupon_code": null,
  "customer_note": null,
  "admin_note": "Phoned in.",
  "items": [
    { "product_id": 41, "product_variant_id": null, "quantity": 2, "unit_price": 499 }
  ]
}
```

Only `email`, `status`, `payment_status` and at least one item are required.
`unit_price` is optional — leave it out and the product's own price is used;
send it to agree a different price for this one order. `status` and
`payment_status` take the same values the order list filters on.

There is no `vendor_id`. The store comes from the token, and a `vendor_id` in
the payload is ignored, exactly as it is on products.

Two things are refused with a `422`: a `product_id` that is not one of **your**
active products (`items.N.product_id`), and a quantity beyond what is in stock
on a product that tracks inventory and does not allow backorders
(`items.N.quantity`). Nothing is written when either fires — no half-order, no
stock movement. Build the picker from `/orders/sellable`, which lists exactly
what will be accepted, with `price`, `stock_quantity`, `track_inventory`,
`allow_backorder`, `tax_rate` and `variants` already worked out.

`/orders/customers` is the other half of that form. It holds only people who
have bought from you before — the marketplace's wider customer book is not
yours to browse — capped at 100 and filterable with `?search=` on name or
email. Passing a `customer_id` from it attaches the order to that customer and
copies their saved address onto it; leave it out and the order stands on the
`email` and `phone` you send.

A successful call returns `201` with the created order in the same shape as
`/orders/{id}`, so nothing needs re-fetching to show it.

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
| GET | `/delivery-partners` | Couriers the marketplace approved, in the admin's own order: `id`, `name`, `has_tracking`. Feeds the carrier picker on the pack-and-ship screen. |
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
| POST | `/cancellations` | Raise one. `order_id`, `reason`, `items[].order_item_id`, `items[].quantity` + optional `note`, `restock`, `refund_requested`. Returns `201`. |
| GET | `/cancellations/{id}` | |
| POST | `/cancellations/{id}/respond` | `note` — your side of the story, onto the order timeline. |
| GET | `/refunds` | `?status=` |
| GET | `/refunds/{id}` | |
| POST | `/refunds/{id}/respond` | `note` |

Approving or rejecting is the marketplace's call, not the seller's. A seller
states their case; staff decide.

**Raising a cancellation** is for stock that turned out not to exist. It lands
as `pending` with `requested_by` fixed to `vendor` — sending either field
yourself changes nothing. `reason` must be one of the marketplace's keys; ask
for the list from an existing cancellation's `reason`/`reason_label` pair.

What may be cancelled is capped per line, and asking for more is a `422` on
`items.{i}.quantity`:

- quantity already cancelled or refunded is gone,
- quantity already **shipped** cannot be cancelled — that is a return,
- quantity sitting in another cancellation still awaiting review is spoken for,
  which is what stops the same line being raised twice.

A line belonging to another seller on a shared order is a `422`, and another
store's order is a `404`.

Two defaults follow from the situation, and the app may override either:

| Field | Default |
|---|---|
| `restock` | `false` when the reason is `out_of_stock` — stock that never existed must not be handed back — otherwise `true`. |
| `refund_requested` | `true` when the order is already paid. |

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
