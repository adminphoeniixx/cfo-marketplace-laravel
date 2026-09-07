# Customer API

Token-authenticated REST API for the shopper app. Everything lives under
`/api/customer` and every response is JSON.

Its sibling is the [Seller API](seller-api.md) — same shell, opposite side of
the counter. What changed and when:
[`customer-api-changelog.md`](customer-api-changelog.md).

The clickable prototype this was built against is
[`customer-app.html`](customer-app.html): every screen there maps onto endpoints
below, and where the two disagree the API is right.

## Authentication

Sanctum personal access tokens, exactly as the seller app uses them:

```
Authorization: Bearer <token>
Accept: application/json
```

The important difference is **who the token belongs to**. A seller's token is
issued to a `User`; a shopper's is issued to a `Customer` — a different table,
with its own ids. Both are valid Sanctum tokens, so every private endpoint here
sits behind `customer` middleware that insists the token's own model is a
customer. A seller's token on a customer endpoint is `403`, not a peek.

### Two doors in

| Door | Endpoint | When to use it |
|---|---|---|
| Phone + code | `POST /auth/otp` → `POST /auth/otp/verify` | The default. Creates the account on first sign-in. |
| Email + password | `POST /auth/register`, `POST /auth/login` | For shoppers who set a password. |

Both end in the same place: `{ "token": "...", "customer": { … } }`.

There is **no SMS provider wired up yet**. `POST /auth/otp` logs the code and,
outside production, returns it as `debug_code` so the app and the tests can run
end to end. When a provider is added, that one method changes and nothing else.

### Three tiers of access

| Tier | What it means |
|---|---|
| public | No token. Browsing, and the six auth endpoints (throttled 10/min). |
| optional | Browsing endpoints read a token if one is sent — that is the only reason `is_wishlisted` appears. |
| customer | Everything personal. `401` without a token, `403` with a non-customer or blocked one. |

50 of the 66 routes are in the last tier. Two of the public ones are not
browsing at all: the signed invoice link and Razorpay's webhook, both of which
are reached by signature rather than by token.

### Scoping

Every list and lookup is scoped to the token's own customer by
`ScopesToCustomer`. No endpoint takes a customer id from the caller, and
another shopper's order, address, request or cart line is a **404**, never a
`403` — the existence of someone else's data is not something to confirm.

---

## Browsing (no token needed)

| Method | Path | What it does |
|---|---|---|
| GET | `/home` | Everything the home screen draws: `banners`, categories, deals, top-rated, new arrivals, sellers. One call, because three round trips to paint one screen is two too many. |
| GET | `/categories` | The tree — parents with their children and product counts. |
| GET | `/products` | The listing. Search, filter, sort, paginate. |
| GET | `/products/suggestions?q=` | Type-ahead. Silent under two characters. |
| GET | `/products/{id-or-slug}` | The product page. |
| GET | `/products/{id}/reviews` | Published reviews, newest first. |
| GET | `/sellers/{vendor}` | A storefront and its listings. Approved stores only. |
| GET | `/products/filters` | The filter sheet, counted against the catalogue this search actually leaves. |
| GET | `/reference` | Payment methods, couriers, cancellation/refund reasons, refund methods, return window. |
| GET | `/serviceability?pincode=` | Whether anybody delivers there, and whether cash is one of the ways. |
| GET | `/app-config` | Store name, currency, support contacts, the "sell with us" link, and which legal pages exist. |
| GET | `/support/config` | Chat, phone, hours and the answered questions. |
| GET | `/legal`, `/legal/{slug}` | `terms`, `privacy`, `returns`, `licenses`, `grievance-officer`. |

**Only `active`, published products are visible** — by id, by slug, by search
or by category. A draft is a 404 however it is reached.

### Banners, and the words around the shopping

`banners` on `/home` carries `title`, `subtitle`, `image_url`,
`deeplink_route`, `deeplink_params` and `sort_order`. The route name is the
**app's own** — the marketplace carries the instruction without pretending to
know the app's navigation. A banner with dates takes itself down; the list is
empty until an admin adds one.

`/support/config` gives `chat_enabled` (true only where there is a URL behind
it), `chat_provider`, `chat_session_url`, `phone`, `email`, `hours` and
`faq[]`. `/legal/{slug}` gives `title`, `body` and `updated_at` for one of
`terms`, `privacy`, `returns`, `licenses`, `grievance-officer` — and **404s
until somebody has written it**, which is better than a blank screen under a
legal title. `/legal` lists the ones that exist, and `/app-config` carries the
same list plus `seller_onboarding_url`.

### Tiles without a photograph

Every product carries `emoji` and every category carries `icon`, always
filled — an admin's own choice where they set one, otherwise a glyph read off
the name. The same `emoji` appears on basket lines, order lines and the items
on a return, so **no client needs a lookup table of its own**. A card draws
`image` when it has one and falls back to the glyph when it does not; neither
field is ever null.

### `GET /products` — the filters

| Parameter | Notes |
|---|---|
| `q` | Matches name, brand and short description. |
| `category_id` | The category **and everything under it**. Tapping a parent means the whole family. |
| `vendor_id`, `brand` | |
| `min_price`, `max_price` | |
| `min_rating` | 0–5. |
| `min_discount` | Percent off the compare-at price. |
| `in_stock` | Counts backorderable products as in stock. |
| `assured` | Products from a store the marketplace has vouched for. |
| `cod` | Products from a store that takes cash — **and** only while the marketplace still offers a pay-on-delivery method. With it switched off in the panel this matches nothing, rather than promising cash it will not take. |
| `sort` | `relevance` (default), `price_low`, `price_high`, `rating`, `discount`, `newest`. An unknown value is a `422`, not a silent fallback. |
| `per_page` | 1–60, default 20. |

Paginated in Laravel's standard envelope — `total`, `per_page`, `current_page`,
`last_page` alongside `data`. Send a token and every card gains
`is_wishlisted`; without one the field is absent rather than a misleading
`false`.

Every card carries `id`, `slug`, `name`, `brand`, `image`, `emoji`, `price`,
`mrp`, `discount_percent`, `rating`, `ratings_count`, `in_stock`, `assured`,
`cod_available` and `vendor`.

### `GET /products/filters` — the sheet, not a guess

Takes **every parameter `/products` takes** and answers what the filter sheet
should offer for that search: `price_ranges`, `rating_ranges`,
`discount_ranges`, `sellers`, `brands`, `boolean_filters` and `sorts`, each
option carrying a `count`, plus the `total` the current filters leave.

Each facet is counted with every filter **except its own**. Picking one seller
narrows the listing but not the seller list — otherwise the sheet would close
around a single choice with no way back.

### `GET /products/{id}` — options vs variants

Variants come back twice, on purpose:

- `options` — one entry per attribute (Size, Colour) with each value and
  whether anything is left of it. This is what the pickers draw.
- `variants` — the combinations that actually exist, with their own price and
  stock. This is what a chosen pair resolves to.

The app never has to work out which combinations exist.

---

## The basket

`GET /cart` · `POST /cart/items` · `PATCH /cart/items/{item}` ·
`DELETE /cart/items/{item}` · `DELETE /cart` ·
`POST /cart/items/{item}/save-for-later` · `POST /cart/items/{item}/move-to-cart` ·
`GET /cart/coupons` · `POST /cart/coupon` · `DELETE /cart/coupon`

**Every one of these answers with the whole basket, priced.** Adding a line
moves the total, the coupon's worth and whether delivery is still free;
returning just the line that changed would leave the app guessing at the rest.

```json
{ "data": {
  "id": 12,
  "selected_address": { "id": 4, "label": "Home", "first_name": "Priya",
                        "city": "Chennai", "postcode": "600090" },
  "available_coupon_count": 3,
  "coupon": { "code": "FIRST100", "discount": 100, "covers_shipping": false },
  "totals": { "items_count": 3, "mrp_total": 11497, "subtotal": 9000,
              "saved_on_mrp": 2497, "discount_total": 100,
              "shipping_total": 0, "tax_total": 445, "grand_total": 9345 },
  "shipping_options": [ … ],
  "groups": [ { "vendor": { "id": 1, "name": "Meera Textiles" },
                "items_count": 2, "subtotal": 6000, "items": [ … ] } ],
  "saved_for_later": [ … ]
} }
```

Things worth knowing:

- **`selected_address` is the address strip**, so the cart screen does not have
  to call `GET /checkout` to draw it. It is the same address checkout will
  pre-select — see [Where a basket is heading](#where-a-basket-is-heading).
- **`available_coupon_count`** is how many rows `GET /cart/coupons` will
  return. The count and the sheet cannot disagree.
- **Lines are grouped by seller.** A basket across two stores is normal and
  ships as two parcels; nothing may treat the first line's vendor as "the"
  vendor of the order.
- A line carries `in_stock` and `available`, so the cart can say "only 2 left"
  before the shopper reaches payment and gets refused.
- Quantity is capped at 10 per line.
- A variable product cannot be added without a variant — `422` on
  `product_variant_id`.
- **Save for later** keeps the line on the basket rather than moving it to the
  wishlist, so the chosen size survives. Saved lines are not bought at
  checkout.
- Coupons: `flat`, `percent` and `free_shipping`. Under the minimum spend the
  apply is `422` and the message names the shortfall. `GET /cart/coupons`
  judges every live code against the current basket, so the screen can show
  "add ₹400 more to use this" without trying it.
- **A code that would take nothing off is refused, not applied.** A
  `free_shipping` coupon on a basket already over the free-delivery threshold
  saves nothing, and accepting it looks exactly like a broken checkout — so it
  is a `422` naming the reason, and `GET /cart/coupons` marks the row
  `usable: false` with the same words.
- The applied coupon carries `discount` (off the items), `shipping_discount`
  (off delivery) and `savings` — the two added up. **`savings` is the figure
  the "you saved" line should quote**; `discount` alone misses a free-shipping
  code entirely. `totals.shipping_full_total` is what delivery would have cost
  without it.
- A guest basket built before sign-in can be handed over with an
  `X-Cart-Token` header on the first authenticated call; it is merged into the
  shopper's own basket and then deleted.

---

## Checkout

`GET /checkout` is the review screen: addresses, delivery choices, ways to pay,
and what the combination costs. `POST /orders` places the order.

### Where a basket is heading

`GET /checkout` answers with both `selected_address_id` and the whole
`selected_address`, decided by one rule — used by the cart strip too, so the two
screens cannot disagree:

1. `address_id` on the request, if it names one of the shopper's own;
2. the address already chosen for this basket;
3. the default shipping address (`is_default_shipping`);
4. whatever exists.

**Passing `address_id` is what "choosing" means.** It is remembered on the
basket, so it survives the app being closed and `GET /cart` shows the same one.

### Delivery options come display-ready

```json
{ "code": "standard-delivery", "name": "Standard delivery", "rate": 0,
  "price_label": "FREE", "eta_label": "Arrives 4–6 Sep",
  "note": "Free over ₹999", "delivery_days_min": 4, "delivery_days_max": 6 }
```

`price_label`, `eta_label` and `note` are the strings the row draws. The day
counts are still there for anything that would rather compute its own, but the
labels are the API's answer — clients building their own from `delivery_days_*`
is how the checkout screen and the order screen ended up promising different
days for one parcel. `note` is `null` when there is nothing to add.

`full_rate` is the same option before any coupon, which is what makes a
free-shipping code's worth arithmetic rather than a guess.

**Only rates whose basket-value band fits are offered.** A rate carrying
`min_order_amount` / `max_order_amount` in the admin panel is left out of the
list when the basket falls outside it. If nothing at all fits the address, free
standard delivery (3–7 days) is the fallback, so an order can always be taken.

### Ways to pay, and when cash is not one

Each method carries `is_available` and, where it is false, an
`unavailable_reason`. Two things can cause it: **a seller in the basket who does
not handle cash**, and **a pincode the courier will not collect cash from** —
plenty of Indian pincodes take prepaid and not COD. The seller's refusal is
named first, because it is the one a shopper can act on by shopping elsewhere.

Grey the option out and show the reason — an option that vanishes reads as a
bug, one with a sentence beside it reads as an answer.

The screen is not the rule: `POST /orders` refuses a pay-on-delivery method for
either case with a `422` on `payment_method`, so an older build cannot place an
order that would fail at the courier days later.

### Whether it can get there at all

`GET /checkout` carries a `delivery` block for the chosen address:

```json
{ "serviceable": true, "cod_available": false, "checked": true, "carrier": "Delhivery" }
```

`GET /serviceability?pincode=600090` asks the same question **without an
account**, which is what a product page needs — a shopper checks a pincode long
before there is a basket. It answers `serviceable`, `cod_available`,
`prepaid_available`, `checked`, `carrier` and a ready-made `message`.

**`checked` is the field that matters.** False means no courier could be asked,
not that the answer was no; everything else reads `true` so the checkout stays
open, and `message` is `null`. Show nothing in that case — a promise nobody
verified is worse than silence. Only act on `serviceable: false` when `checked`
is `true`.

Answers are cached for twelve hours, so asking on every product view is fine.

### Ways to pay

Each method carries `icon` (an emoji — the admin's own, or one derived from the
code) and `is_pay_on_delivery`, so nothing has to match on code spellings to
know whether a gateway needs opening.

```
POST /orders
{ "address_id": 4, "payment_method": "upi", "shipping_code": "standard",
  "note": "Leave with the neighbour" }
```

- **The quote is re-run server-side at the moment of writing.** The client's
  arithmetic is never trusted, so a price that moved while the app sat open is
  caught here rather than sold at the stale figure.
- Anything that cannot be shipped stops the order with `422` and a message
  naming the line — nothing is half-sold.
- `payment_method` is a **code from `/reference`**, not free text. The order
  records the label the shopper saw. What happens to `payment_status` next
  depends on whether a gateway is configured — see [Paying](#paying).
- One basket across two sellers becomes **one order with two sets of lines**,
  each line carrying its own vendor, commission rate and vendor earning. The
  discount is spread across the lines it came off, so a seller's commission is
  charged on what the basket actually paid them.
- Stock comes down, the coupon's `used_count` goes up, the basket is emptied
  (saved-for-later survives), and every seller involved is notified with their
  own share rather than the shopper's total.

Commission never appears in a customer response. It is between the marketplace
and the seller.

---

## Paying

| Method | Path | Tier | |
|---|---|---|---|
| POST | `/payments/create-intent` | customer | Opens a payment for an order that is waiting for one. |
| POST | `/payments/verify` | customer | Settles it from what the checkout widget handed the app. |
| POST | `/payments/webhook/razorpay` | signature | Settles it from Razorpay's own account of events. |

**Whether there is a gateway at all is a configuration fact.** With
`RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` unset, the marketplace behaves as it
always has: anything but cash on delivery is marked paid the moment it is
placed. Set them and nothing else changes — except that an online order is
written `status: pending, payment_status: pending` and waits for a real capture,
because nothing should be packed against money that has not arrived.

Orders carry `payment_required: true` while they are in that state. The flow:

```
POST /orders                     → 201, data.payment_required = true
POST /payments/create-intent     → 201, { key, gateway_order_id, amount_in_paise, prefill }
   … open Razorpay Checkout with those …
POST /payments/verify            → 200, the order, now paid
```

- **Repeating `create-intent` on the same unpaid order returns the attempt
  already open**, not a second one. Backing out of the gateway sheet and tapping
  Pay again is ordinary, not a new order.
- `verify` is only believed if `razorpay_signature` is the HMAC of
  `order_id|payment_id` under the key secret. A bad one is `422`, and the
  attempt is recorded as failed.
- The webhook is public because a gateway carries no token, and trusted only
  because the body is signed with `RAZORPAY_WEBHOOK_SECRET`. Unsigned is `401`.
  It is also what saves an order when the app dies between paying and saying so.
- **The two settling paths race each other and that is fine.** Capture is
  idempotent: whichever arrives second changes nothing, and a replayed webhook
  writes no second payment event.
- A paid order carries `transaction_id` — the gateway's payment id, which is the
  receipt number the payment card shows.

Amounts cross the wire to Razorpay in **paise**, converted server-side.
`amount_in_paise` is handed to the app already converted so nothing does that
multiplication twice.

---

## Orders

| Method | Path | Notes |
|---|---|---|
| GET | `/orders?filter=` | `all`, `open`, `delivered`, `cancelled`. |
| GET | `/orders/{number}` | `1043` or `#1043` — both spellings work. |
| GET | `/orders/{number}/track` | The whole tracking screen in one call. |
| GET | `/orders/{number}/invoice` | A signed, seven-day link to the invoice. |
| POST | `/orders/{number}/reorder` | Puts the lines back in the basket and hands the basket back. |

An order carries `status_label` ("Being packed", "On the way"), its `sellers`,
a `timeline` built from its own timestamps, and `can_cancel` / `can_return` so
the app never offers a button the API will refuse.

The timeline is filtered to what a shopper may read — staff and seller notes
stay internal.

### Display-ready fields on an order

| Field | |
|---|---|
| `eta` | "Arriving 4–6 Sep", "Delivered 2 Sep", or `null` once it is called off. Frozen at checkout from the delivery option the shopper chose, so it is the date they were promised — not one recomputed off rates that have moved since. |
| `transaction_id` | The gateway's payment id. `null` on cash on delivery and until capture. |
| `payment_icon` | The glyph for the method the order recorded. |
| `payment_required` | Whether a gateway still has to be opened for it. |
| `invoice_url` | The same signed link `GET /orders/{number}/invoice` returns. |
| `help` | `sellers[]` (id, name, phone, email), `seller_phone` — filled in **only** where the order has exactly one seller — plus `support_email`, `support_phone` and `chat_url` from the admin panel. |

`help.seller_phone` is null on a two-seller order on purpose: "the seller" of a
shared basket does not exist, and the app should offer the list.

### `GET /orders/{number}/track`

Everything the courier screen draws, so it no longer needs this call *and*
`GET /orders/{number}`:

```json
{ "data": {
  "eta": "Arriving Mon, 1 Sep",
  "carrier": { "code": "delhivery", "name": "Delhivery",
               "support_phone": "1800 103 6354",
               "tracking_url": "https://…/TRK-88213" },
  "tracking_number": "TRK-88213",
  "progress_step": 3, "progress_total": 5,
  "milestones": [ { "key": "picked_up", "title": "Picked up from the seller",
                    "subtitle": "Meera Textiles, Chennai",
                    "at": "2026-08-31T09:41:00+00:00",
                    "done": true, "current": false } ],
  "support_phone": "1800 103 6354",
  "eta_label": "Arriving Mon, 1 Sep",
  "events": [ { "key": "picked_up", "label": "Picked up from the seller",
                "description": "Meera Textiles, Chennai", "location": "Chennai",
                "happened_at": "2026-08-31T09:41:00+00:00", "state": "done" } ],
  "items": [ … ], "sellers": [ … ], "steps": [ … ]
} }
```

- `events` is `milestones` in the shape a timeline draws: one `state` per row —
  `done`, `current` or `pending` — instead of a flag to combine with a
  position, plus the `location` the parcel was in where the marketplace knows
  it. Use whichever fits; they describe the same steps.
- `support_phone` is lifted out of `carrier` as well, because "call courier" is
  one tap and should not have to walk an object that is `null` until somebody
  collects the parcel.

- `done` is only ever true of something that actually happened, and **"Out for
  delivery" now reaches `done` on the morning it happens** — the courier reports
  its own scans, so the step no longer has to wait for the parcel to arrive to
  be filled in. Its `at` stays `null`: the scan says the state, not the minute.
- **A delivery that was tried and failed says so.** The `out_for_delivery` row's
  description becomes "Delivery was attempted and could not be completed". A
  shopper who thinks a parcel is still on its way does not answer the phone to
  the courier.
- **A parcel coming back replaces "Delivered" rather than sitting above it.**
  Where the courier has started a return, the last row is `returning` — "On its
  way back to the seller", or "Returned to the seller" once it lands — and there
  is no `delivered` row at all. A hollow "Delivered" underneath would read as
  "still coming".
- `current` marks the step the parcel is sitting on; `progress_step` is how many
  are done, which is how many segments of the bar to fill.
- A cancelled order stops at "Order cancelled" instead of pretending the rest is
  still coming.
- `steps` is the original four-key array, kept so nothing that reads it breaks.

### `POST /orders/{number}/reorder`

Partial success is the normal case, not an error — always `200` unless the order
itself is a 404.

```json
{ "added":   [ { "cart_item_id": 101, "product_id": 55, "product_variant_id": 12,
                 "name": "Kashmiri wool shawl", "requested_quantity": 2,
                 "quantity": 1, "status": "partial",
                 "warning": "Only 1 of 2 could be added." } ],
  "skipped": [ { "product_id": 77, "product_variant_id": 18, "variant_id": 18,
                 "name": "Old item", "reason": "out_of_stock",
                 "message": "Out of stock" } ],
  "cart":    { … exactly what GET /cart returns … },
  "cart_count": 3 }
```

- The original variant is restored, not just the product.
- Quantity is kept where the shelf allows it and trimmed where it does not;
  `status` is then `partial` and `warning` says so.
- `reason` is a **code** to branch on — `out_of_stock`, `inactive_product`,
  `variant_missing`, `seller_unavailable` — and `message` is the sentence to
  show. Show `message`; matching on English is how this went wrong before.
- **The whole basket comes back**, plus `cart_count` for a client that only
  wants the badge, so the cart is right the moment the call returns.

### Invoices

`GET /orders/{number}/invoice` returns `{ url, expires_at, content_type }`. The
URL is **signed rather than token-authenticated**, so it opens in a browser, a
download manager or an email client — none of which carries the app's bearer
token — and it expires after seven days.

The document is **HTML, not PDF**: this marketplace has no PDF library, and a
print-ready page renders to PDF in one keystroke everywhere the app runs.
`content_type` says so rather than leaving anyone to guess. One page covers the
whole order, with a block per seller, because a shared basket has two sets of
tax registration numbers on it.

---

## Cancellations and returns

Two tables, one screen. To a shopper these are one queue, so `GET /requests`
merges them and each row carries `kind`.

| Method | Path | Rule |
|---|---|---|
| POST | `/orders/{number}/cancellations` | Only while nothing has shipped. Omit `items` to cancel the whole order. |
| POST | `/orders/{number}/returns` | Only after delivery, within `RETURN_WINDOW_DAYS` (7). `items` required. |
| GET | `/requests`, `/requests/{number}` | Both kinds. |
| POST | `/requests/{number}/withdraw` | Only while still `pending`. |

- Reasons must come from `/reference` — the app must show exactly what the API
  accepts.
- Quantities are clamped to what is still live on the line: asking to cancel
  three of a pair returns two rather than an error.
- A cancellation on a paid order sets `refund_requested`; on cash on delivery
  there is nothing to refund.
- Withdrawing sets status `withdrawn`, a status the panels do not yet filter
  on. It is deliberately not a delete: the request happened.

Each request carries what the refund screen draws: `amount`, `method`,
`reason_label`, `note`, its `items` (with `image` and `emoji`), a `timeline` of
requested → reviewed → refunded with a `done` flag on each, and `eta` — "Back in
your account by 24 Aug" once approved, "Usually 3–5 working days once it is
approved" before that, and `null` where no money is coming back at all.

`events` carries the same steps with one `state` each (`done`, `current`,
`pending`), and the moments are named individually too: `seller_approved_at`,
`refund_issued_at`, `cancelled_at`, `withdrawn_at`. `picked_up_at` is always
`null` — no courier reports a return collection to this marketplace, and a
field that says so beats a step that never fills.

---

## Account

| Method | Path | |
|---|---|---|
| GET/PUT | `/me` | Changing the email un-verifies it; changing the phone un-verifies that. |
| PUT | `/me/password` | `current_password` required only if one is already set. Signs every other device out. |
| GET/POST/PUT/DELETE | `/addresses`, `/addresses/{id}` | |
| PATCH | `/addresses/{id}/default` | |
| GET/POST/DELETE | `/wishlist`, `/wishlist/{product}` | `POST` is idempotent — the heart is a toggle and a flaky tap must not be an error. |
| GET | `/reviews` | What you wrote, and what is still waiting to be rated. |
| POST | `/products/{id}/reviews` | Buyers of a delivered order only. Rating again edits the first one. |
| DELETE | `/reviews/{id}` | |
| GET | `/me/summary` | Every count the account screen draws, in one call. |
| DELETE | `/me` | Closes the account. |
| GET/PUT | `/notification-preferences` | `push_enabled`, `order_updates`, `deals_price_drops`, `email_marketing`, `sms_order_updates`. |
| GET/POST | `/payment-methods` | The shopper's **own** saved cards, handles and wallets. |
| DELETE | `/payment-methods/{id}` | |
| PATCH | `/payment-methods/{id}/default` | |
| GET | `/wallet` | Store credit: balance, currency, when it lapses, and every movement behind it. |
| GET | `/notifications`, `/notifications/unread-count` | The list carries `unread_count` with it. |
| POST | `/notifications/{id}/read`, `/notifications/read-all` | |
| POST/DELETE | `/push/device` | FCM tokens, keyed by the token's hash. |
| GET/POST/DELETE | `/auth/devices`, `/auth/devices/{token}` | Signed-in devices. |

The first address saved becomes the default; deleting the default promotes
whatever is left; the last one cannot be deleted, because orders have to go
somewhere.

Reviews show a first name and a last initial. Only someone who received the
item can write one, and the product's cached `rating` is rewritten on every
change because that column is what listing and search sort on.

### Saved ways to pay

`/payment-methods` is personal — what this shopper saved. `/reference` stays
the marketplace's list of what it accepts from anybody. Do not confuse them.

**A card number never reaches this API.** Tokenise with the gateway and post
back `masked_value` ("•••• 4242", "priya@okhdfc"), a `type`
(`card`, `upi`, `wallet`, `netbanking`), an optional `label`/`provider`, and
the gateway's own token as `gateway_token`. Twelve digits in a row in
`masked_value` is a `422`, whatever the field was called on the way in. A row
counts as `verified` only where a `gateway_token` came with it — saying
`verified` in the payload does not make it one.

The first method saved becomes the default. Deleting the default promotes
another, so checkout never opens on nothing.

### Closing an account

`DELETE /me` revokes every token and registered device, releases the email and
phone so the same person can sign up again, and soft-deletes the row — the
orders behind it are a seller's sales history as much as the shopper's. An
order still in flight is a `422`: there is nobody left to deliver to.

### Notification preferences

Four switches on the marketplace rather than in the phone, so a reinstall does
not turn them all back on and two devices agree. `email_marketing` is the same
flag as the profile's `accepts_marketing`.

---

## Support tickets

| Method | Path | |
|---|---|---|
| GET | `/support/tickets` | `?filter=open` for the unfinished ones. Newest reply first. |
| POST | `/support/tickets` | `subject`, `message`, `category`, optional `order_number`. |
| GET | `/support/tickets/{number}` | The thread. |
| POST | `/support/tickets/{number}/replies` | `message`. Refused on a closed ticket. |
| POST | `/support/tickets/{number}/close` | The shopper is done with it. |

`category` is one of `order`, `delivery`, `return`, `payment`, `product`,
`account`, `other` — the same keys `GET /support/config` will eventually
label. `order_number` attaches the ticket to one of **your own** orders; naming
somebody else's is a `422`, not a silent miss.

### Who answers it

A ticket goes to **one of two places**, and `audience` says which:

- **`vendor`** — send `vendor_id`, or an `order_number` whose order has a
  single seller and the seller is worked out for you. Where is my parcel, does
  this run small: the seller's to answer.
- **`marketplace`** — send neither. Refunds, payments, the account itself.

`audience_label` is the sentence to show ("The seller" / "The marketplace") and
`seller` carries the store's id and name when there is one.

**You may only write to a seller you have bought from.** Any other `vendor_id`
is a `422` — nothing here opens a channel to a stranger's inbox.

Replies from a store are attributed to **the store by name**; replies from the
marketplace come back as **"Support"**. `author_type` is `customer`, `vendor`
or `support`.

**`status` is about who the ticket is waiting on**, and `status_label` says it
in the words to put on screen:

| `status` | `status_label` | Means |
|---|---|---|
| `open` | We are looking into it | Support owes a reply |
| `pending` | Waiting for your reply | The shopper does |
| `resolved` | Resolved | Support believes it is done |
| `closed` | Closed | It is done. `can_reply` is false |

Replying to a `resolved` ticket **reopens it** — support's opinion that it is
finished does not settle it. A `closed` one cannot be reopened; open a new
ticket instead.

Staff answer as **"Support"**, never as a named person, and internal notes
between staff are filtered out in the query — there is no shape of these
endpoints that returns one.

---

## Errors

Standard Laravel shapes.

| Code | Means |
|---|---|
| 401 | No token, or it is expired or revoked. |
| 403 | The token is not a customer's, or the account is blocked. |
| 404 | Not yours, or it does not exist — the API does not distinguish. |
| 422 | Validation. `errors` is keyed by field. |
| 429 | Throttled (auth endpoints allow 10/min). |

---

## What is not built yet

Said plainly, so nobody plans around a hole:

- **One gateway, Razorpay.** Cards, UPI and net banking all go through it, and
  with no credentials configured a non-COD order is still marked paid on
  placement. No other provider is wired up.
- **SMS needs a provider.** The path is wired (`SMS_DRIVER=http` plus
  `SMS_API_KEY`, `SMS_URL`, `SMS_TEMPLATE_ID`, `SMS_SENDER`), and until one is
  configured codes are logged and returned as `debug_code` outside production.
  Configure one before launch, or a shopper cannot sign in.
- **Store credit cannot be spent yet.** `GET /wallet` is truthful and a refund
  settled to store credit lands in it, but checkout does not offer the balance
  as a way to pay.
- **Saved payment methods are stored, not charged.** They are display and
  tokens; `POST /payments/create-intent` still opens a fresh intent.
- **No push delivery to shoppers.** Tokens are stored and the feed works, but
  nothing sends to them yet; the seller app's FCM path is not shared.
- **Customers are not notified of seller-side status changes.** The order
  timeline is truthful, but no notification fires when a seller ships.
- **Invoices are HTML, not PDF**, and one document covers the whole order rather
  than one per seller.
- **Courier milestones are the order's own timestamps**, not the carrier's
  scans. Nothing polls Delhivery or Shiprocket; "Out for delivery" is therefore
  only ever reached retrospectively.
- **Guest checkout does not exist.** A basket may be built signed out and
  merged on sign-in, but placing an order needs an account.
