# Gap list — what is now in the API

Both gap lists the app team raised, answered item by item. Everything marked ✅
is built, tested and in `main`; the full field reference stays in
[`customer-api.md`](customer-api.md) and every change is dated in
[`customer-api-changelog.md`](customer-api-changelog.md).

**Nothing here needs a workaround in the app any more.** Where an item changed
shape rather than appearing from nothing, the migration note is spelled out.

> **Two breaking changes**, both in list 1 item 11 and list 2 "Buy again" —
> read those before shipping.

The customer API is **80 endpoints**. The Postman collection has one request per
route, checked against the router by a test, so
[`customer-api.postman_collection.json`](customer-api.postman_collection.json)
cannot fall behind.

---

## List 1 — "Backend/API Gap List"

| # | Item | Status |
|---|---|---|
| 1 | `selected_address` + `available_coupon_count` on `GET /cart` | ✅ |
| 2 | Richer shipping options (`price_label`, `eta_label`, `note`) | ✅ |
| 3 | Correct default selected address | ✅ |
| 4 | `icon` on payment methods | ✅ |
| 5 | Real payment gateway (intent / verify / webhook) | ✅ |
| 6 | Order detail: `eta`, `transaction_id`, `invoice_url`, `help` | ✅ |
| 7 | Richer `GET /orders/{number}/track` | ✅ |
| 8 | `GET /orders/{number}/invoice` | ✅ |
| 9 | `image` + `emoji` / `icon` everywhere | ✅ |
| 10 | Request/return detail fields | ✅ |
| 11 | Buy again / reorder | ✅ **breaking** |

### 1. Cart

`GET /cart` carries both. The extra `GET /checkout` call for the address strip
can go, and the coupon row's "3 offers available" is now a number from the
server — `available_coupon_count` is exactly how many rows `GET /cart/coupons`
would return.

```json
{ "selected_address": { "id": 1, "label": "Home", "first_name": "Priya",
                        "city": "Chennai", "postcode": "600090" },
  "available_coupon_count": 3 }
```

### 2. Delivery options come display-ready

Every option on **both** `/cart` and `/checkout`:

```json
{ "code": "standard", "name": "Standard delivery", "rate": 0,
  "price_label": "FREE", "eta_label": "Arrives 4–6 Sep", "note": "Free over ₹999" }
```

`delivery_days_min` / `max` are unchanged and still there. Stop building the
text in the app — a rate with no days on it now returns `eta_label: null`
rather than leaving you to invent a fallback.

### 3. Selected address

`GET /checkout` returns `selected_address_id` **and** the whole
`selected_address`. The rule is exactly the one asked for, in this order:

1. an `address_id` sent on the request — and it is **remembered on the basket**,
   so the cart strip and the checkout picker cannot disagree again
2. the basket's saved choice
3. `is_default_shipping = true`
4. the first address

### 4. Payment methods

`icon` is on `/reference` and `/checkout` alike, admin-settable, with a glyph
derived from the code where nobody set one — so a method added tomorrow arrives
with something to draw. UPI ⚡ · card 💳 · net banking 🏦 · COD 💵 · wallet 👛.

Also on each method: `is_pay_on_delivery` (so no matching on code spellings),
and **new** — `is_available` with `unavailable_reason`. Today that has one
cause: a seller in the basket who does not take cash. Grey the option out and
show the reason; `POST /orders` refuses that method for such a basket with a
`422`, so the screen and the rule agree.

### 5. Payments

`POST /payments/create-intent`, `POST /payments/verify`,
`POST /payments/webhook/razorpay`. After `POST /orders`, check
`data.payment_required` — if true, open an intent, show Razorpay Checkout, post
`verify`. The webhook settles the same order if the app dies in between, and
capture is idempotent either way.

Orders carry `payment_status`, `payment_method`, `payment_icon`,
`transaction_id` and `payment_required`.

### 6. Order detail

`eta` ("Arriving 4–6 Sep") is **frozen at checkout** from the option the shopper
chose — the date they were promised, not one recomputed off rates that moved
since. `transaction_id`, `invoice_url` and `help` are all there.

`help.seller_phone` is deliberately `null` on a two-seller order: "the seller"
of a shared basket does not exist, so use `help.sellers[]`, which lists each one
with its own phone and email. `help.chat_url`, `support_email` and
`support_phone` come from the admin panel.

### 7. Track

`carrier` object with `support_phone` and `tracking_url`, plus `tracking_number`,
`eta`, `eta_label`, `progress_step`, `progress_total`, `milestones[]`, the
`items` in the box and their `sellers`. **The second call to `/orders/{number}`
is no longer needed.**

`support_phone` is also lifted to the top level, because "call courier" is one
tap and should not have to walk an object that is null until somebody collects
the parcel.

### 8. Invoice

`GET /orders/{number}/invoice` → `{ url, expires_at, content_type }`. Signed
rather than token-authenticated, so it opens in a browser, a download manager or
an email client — none of which carries the bearer token. Valid seven days; the
same link is on every order as `invoice_url`.

It is **HTML, not PDF** (`content_type` says so). One page covers the whole
order with a block per seller, because a shared basket has two sets of tax
registration numbers on it.

### 9. Images and glyphs

`image` and `emoji` on products, basket lines, order lines and return items;
`icon` on categories. **Always filled** — an admin's own choice where they set
one, otherwise a glyph read off the name. Neither field is ever null, so no
client needs a lookup table.

### 10. Request / return detail

`amount`, `method` ("Original payment method"), `reason_label`, `note`, `eta`
("Back in your account by 24 Aug"), `items[]` with `image` and `emoji`, and
`timeline[]`. See list 2 for the `events[]` shape added on top.

### 11. Buy again ⚠️ breaking

```json
{ "added": [ { "cart_item_id": 101, "product_id": 55, "product_variant_id": 12,
               "name": "Kashmiri wool shawl", "requested_quantity": 2,
               "quantity": 1, "status": "partial",
               "warning": "Only 1 of 2 could be added." } ],
  "skipped": [ { "product_id": 77, "product_variant_id": 18, "variant_id": 18,
                 "name": "Old item", "reason": "out_of_stock",
                 "message": "Out of stock" } ],
  "cart": { "…": "exactly what GET /cart returns" },
  "cart_count": 3 }
```

Everything asked for is there: the original variant is restored, quantity is
kept where the shelf allows and trimmed where it does not, an inactive seller or
product lands in `skipped`, and it is always `200` unless the order itself is a
404.

**Migration:** `added` was a count and is now a list — read `added.length`.
`skipped[].reason` was an English sentence and is now a **code**
(`out_of_stock`, `inactive_product`, `variant_missing`, `seller_unavailable`)
with the sentence beside it as `message` — show `message`.

---

## List 2 — "Backend / API Gaps For Exact Customer App UI"

### Product listing filters

| Item | Status |
|---|---|
| `GET /products/filters` with facets | ✅ |
| Counts per facet option | ✅ |
| `assured`, `cod`, `in_stock`, `vendor_id`, `brand`, `min_discount` actually applied | ✅ |
| Unknown `sort` → 422 | ✅ |
| Product card fields | ✅ |
| Pagination metadata | ✅ |

**`GET /products/filters`** takes every parameter `/products` takes and answers
what the sheet should offer for *that* search: `price_ranges`, `rating_ranges`,
`discount_ranges`, `sellers`, `brands`, `boolean_filters`, `sorts` — each option
with a `count` — plus the `total` the current filters leave.

Each facet is counted with every filter **except its own**. Picking one seller
narrows the listing but not the seller list, so the sheet never closes around a
single choice with no way back. Stop shipping chips; draw what this returns.

**`assured` and `cod` are now real.** Both live on the store: "Assured" is the
marketplace's own badge, granted in the admin panel, and a seller who will not
handle cash says so once rather than on every product. `cod` also asks whether
the marketplace is still offering a pay-on-delivery method at all — with it
switched off in the panel the chip matches nothing rather than promising cash
nobody will take.

Every card: `id`, `slug`, `name`, `brand`, `image`, `emoji`, `price`, `mrp`,
`discount_percent`, `rating`, `ratings_count`, `in_stock`, `assured`,
`cod_available`, `vendor { id, name, is_assured }`, and `is_wishlisted` when a
token is sent (absent rather than a misleading `false` without one). Pagination
is Laravel's standard envelope — `total`, `per_page`, `current_page`,
`last_page` beside `data`.

### Home screen

`banners[]` on `GET /home`, with `id`, `title`, `subtitle`, `image_url`,
`deeplink_route`, `deeplink_params`, `sort_order`, `active`. Managed in the
admin panel under **App content**, and dated — a sale banner takes itself down
on its own rather than waiting for somebody to remember.

`deeplink_route` is the **app's own** route name; the marketplace carries the
instruction without pretending to know your navigation. The four banners
currently live use `category` (with `category_id`) and `products` (with `sort`)
— tell us if your route names differ and we will change the rows, not the app.

Badges: `GET /me/summary` answers all of them in one call (below).

### Account summary — `GET /me/summary`

Every field asked for, plus `requests_count`:

```json
{ "data": { "orders_count": 7, "orders_spent": 67715.59, "wishlist_count": 0,
            "coupon_count": 2, "reviews_written_count": 0,
            "reviews_pending_count": 3, "addresses_count": 1,
            "devices_count": 1, "requests_count": 0 } }
```

`coupon_count` is codes usable **today**, and `reviews_pending_count` is
delivered products with no rating yet — the "rate this" pile.

### Saved payment methods

`GET` / `POST /payment-methods`, `DELETE /payment-methods/{id}`,
`PATCH /payment-methods/{id}/default`. Fields: `id`, `type`
(`card`, `upi`, `wallet`, `netbanking`), `label`, `masked_value`, `provider`,
`verified`, `expires_at`, `is_expired`, `is_default`.

**Read this before implementing:** a card number never reaches this API.
Tokenise with the gateway and post back `masked_value` ("•••• 4242",
"priya@okhdfc") plus the gateway's token as `gateway_token`. Twelve digits in a
row in `masked_value` is a `422`, whatever the field was called on the way in,
and there is no column here that could hold a PAN. A row counts as `verified`
only where a `gateway_token` came with it — saying `verified` in the payload
does not make it one.

The first method saved becomes the default without being asked; deleting the
default promotes another, so checkout never opens on nothing.

Not to be confused with `/reference`, which stays the marketplace's list of what
it accepts from anybody.

### Wallet — `GET /wallet`

`balance`, `currency`, `source`, `expires_at`, `transactions[]`. A ledger rather
than a balance column, so "where did this come from" is a question the screen
can answer; lapsed credit stops counting on its own. A refund settled to store
credit now lands here — the panel used to say "refunded" while the wallet said
zero.

### Buy again

See list 1 item 11. `cart_count` is there for a client that only wants the
badge, and the skip reasons are the codes asked for.

### Invoice

See list 1 item 8. ✅

### Track order

Everything asked for, in the shape asked for:

```json
{ "carrier": { "code": "delhivery", "name": "Delhivery",
               "support_phone": "…", "tracking_url": "…" },
  "tracking_number": "DL1234567890",
  "tracking_url": "…", "support_phone": "…",
  "eta": "Arriving Mon, 1 Sep", "eta_label": "Arriving Mon, 1 Sep",
  "events": [ { "key": "picked_up", "label": "Picked up from the seller",
                "description": "Meera Textiles, Chennai", "location": "Chennai",
                "happened_at": "2026-08-31T09:41:00+00:00", "state": "done" } ] }
```

`state` is `done`, `current` or `pending` — one value instead of two booleans to
combine with a position. `milestones[]` and `steps[]` are unchanged beside it;
they describe the same steps, use whichever fits.

**The parcel now reports itself.** Where the marketplace has Delhivery
credentials, fulfilling an order books the waybill and a background job follows
it every fifteen minutes: courier scans become timeline events, and
`shipped_at` / `delivered_at` come from the courier rather than a seller
pressing a button. "Out for delivery" is no longer only reachable in hindsight.

### Cancellation / return detail

Both shapes asked for, so use whichever suits the screen:

- `events[]` — `key`, `label`, `status`, `state` (`done` / `current` /
  `pending`), `happened_at`, `scheduled_at`, `date`
- named fields — `seller_approved_at`, `refund_issued_at`, `cancelled_at`,
  `withdrawn_at`

`picked_up_at` is present and **always null**: no courier reports a return
collection to this marketplace, and a field that says so beats a step that never
fills.

### Support / help centre — `GET /support/config`

`chat_enabled`, `chat_provider`, `chat_session_url`, `phone`, `email`, `hours`,
`faq[]` (`id`, `question`, `answer`, `topic`, `sort_order`). No token needed.

`chat_enabled` is true **only where there is a URL behind it** — a switch that
is on with nothing behind it is a button that does nothing. 14 answers are live;
they are edited in the admin panel, not shipped in a build.

### Legal pages

`GET /legal` lists what exists; `GET /legal/{slug}` returns `slug`, `title`,
`body`, `updated_at` for `terms`, `privacy`, `returns`, `licenses`,
`grievance-officer`. No token needed.

A page **404s until somebody has written it**, which is better than a blank
screen under a legal title — so drive the list from `GET /legal` (or
`app-config.legal_pages`) rather than hardcoding five rows. `returns` and
`licenses` are live now; see "Still needed" below for the other three.

### Account deletion — `DELETE /me`

Revokes every token and registered device, releases the email and phone so the
same person can sign up again, and soft-deletes the row — the orders behind it
are a seller's sales history as much as the shopper's. Returns
`{ deleted: true, message }`.

An order still in flight is a `422` on `account`: there is nobody left to
deliver to.

### Notification settings

`GET` / `PUT /notification-preferences` with exactly the five fields asked for:
`push_enabled`, `order_updates`, `deals_price_drops`, `email_marketing`,
`sms_order_updates`. Kept on the marketplace, so a reinstall no longer turns
them all back on and two devices agree. `email_marketing` is the profile's
`accepts_marketing` under the name the screen uses.

### Seller onboarding — `GET /app-config`

`seller_onboarding_url`, plus `store_name`, `currency`, `support_email`,
`support_phone` and `legal_pages`. No token needed.

The link had nowhere to point, so **`/sell` was built**: a public page with the
commission, when payouts land, and an application form. Applying signs the
seller in and shows a "waiting for approval" screen; the panel stays shut until
somebody approves the store.

### OTP / SMS

`App\Services\Sms` is wired into `POST /auth/otp`. With a provider configured
the code is texted and **`debug_code` stops coming back**; with none it is
logged and returned outside production, as before. `POST /auth/otp` gained
`delivered`, which says whether the provider took it — `sent` stays `true`
either way, because telling a caller which numbers are registered helps whoever
is probing more than it helps the shopper. The code is no longer written to the
production log.

⚠️ **The provider is not configured yet** — see below.

---

## Bugs found and fixed along the way

- **`GET /orders` was a 500 in production.** The deployed code queried
  `payment_methods.icon` while the live database had never run the migration
  that adds it. Migrated; and the deploy step now has to run migrations, which
  is what went wrong.
- **`POST /auth/reset-password` was a 500, always.** The reset wrote a
  `remember_token` and `customers` has no such column, so every password reset
  died on the one screen a locked-out user cannot retry their way out of. Found
  by a new test that calls all 80 endpoints once with real data behind them.
- **Cash on delivery was offered for baskets no seller would take cash for.**
  The flag filtered the catalogue and nothing else, so the order could be
  placed and the first anybody heard of it would be a courier at the door.

---

## Still needed — mentioned, not built

Nothing below blocks the app; each is either a decision or a credential.

**Configuration, before launch**

- **SMS provider.** Until `SMS_DRIVER=http` plus `SMS_API_KEY` / `SMS_URL` /
  `SMS_TEMPLATE_ID` / `SMS_SENDER` are set, login codes only reach the log —
  **no shopper outside the server can sign in.** This is the one that matters.
- **Delhivery credentials** (`DELHIVERY_API_TOKEN`, `DELHIVERY_PICKUP_NAME`).
  Without them the seller types a waybill in by hand, as before.
- **`support_chat_url`** is empty, so `chat_enabled` is `false`. Hide the chat
  row until it is set — the API already tells you.
- **Courier support phones** are empty on all five delivery partners, so
  `track.support_phone` is `null`. Keep "call courier" hidden while it is.
- **Terms, Privacy and Grievance officer** are written but unpublished: they
  need a registered company name, an address and a named officer, and none of
  those may be invented. They 404 until published, and `GET /legal` will not
  list them, so the app needs no change when they go live.

**Deliberately not built**

- **Store credit cannot be spent at checkout.** `GET /wallet` is truthful and
  refunds land in it, but the balance is not offered as a way to pay.
- **Saved payment methods are not charged.** They are display plus a gateway
  token; `POST /payments/create-intent` still opens a fresh intent each time.
- **Invoices are HTML, not PDF**, and one document covers the whole order rather
  than one per seller.
- **`price_changed` is never a skip reason on reorder.** A price that moved does
  not stop the line going back in the basket; it is added at today's price.
- **Guest checkout does not exist.** A basket can be built signed out and merged
  on sign-in, but placing an order needs an account.
- **Delhivery pickup is one marketplace warehouse**, not one per seller.
