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

47 of the 61 routes are in the last tier.

### Scoping

Every list and lookup is scoped to the token's own customer by
`ScopesToCustomer`. No endpoint takes a customer id from the caller, and
another shopper's order, address, request or cart line is a **404**, never a
`403` — the existence of someone else's data is not something to confirm.

---

## Browsing (no token needed)

| Method | Path | What it does |
|---|---|---|
| GET | `/home` | Everything the home screen draws: categories, deals, top-rated, new arrivals, sellers. One call, because three round trips to paint one screen is two too many. |
| GET | `/categories` | The tree — parents with their children and product counts. |
| GET | `/products` | The listing. Search, filter, sort, paginate. |
| GET | `/products/suggestions?q=` | Type-ahead. Silent under two characters. |
| GET | `/products/{id-or-slug}` | The product page. |
| GET | `/products/{id}/reviews` | Published reviews, newest first. |
| GET | `/sellers/{vendor}` | A storefront and its listings. Approved stores only. |
| GET | `/reference` | Payment methods, couriers, cancellation/refund reasons, refund methods, return window. |

**Only `active`, published products are visible** — by id, by slug, by search
or by category. A draft is a 404 however it is reached.

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
| `sort` | `relevance` (default), `price_low`, `price_high`, `rating`, `discount`, `newest`. |
| `per_page` | 1–60, default 20. |

Paginated in Laravel's standard envelope. Send a token and every card gains
`is_wishlisted`; without one the field is absent rather than a misleading
`false`.

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
- A guest basket built before sign-in can be handed over with an
  `X-Cart-Token` header on the first authenticated call; it is merged into the
  shopper's own basket and then deleted.

---

## Checkout

`GET /checkout` is the review screen: addresses, delivery choices, ways to pay,
and what the combination costs. `POST /orders` places the order.

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
  records the label the shopper saw. Cash on delivery lands `payment_status:
  pending`; everything else is treated as captured until a real gateway is
  wired up.
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

## Orders

| Method | Path | Notes |
|---|---|---|
| GET | `/orders?filter=` | `all`, `open`, `delivered`, `cancelled`. |
| GET | `/orders/{number}` | `1043` or `#1043` — both spellings work. |
| GET | `/orders/{number}/track` | Courier, tracking number and the four milestones. |
| POST | `/orders/{number}/reorder` | Puts the lines back in the basket; returns `added` and `skipped`. |

An order carries `status_label` ("Being packed", "On the way"), its `sellers`,
a `timeline` built from its own timestamps, and `can_cancel` / `can_return` so
the app never offers a button the API will refuse.

The timeline is filtered to what a shopper may read — staff and seller notes
stay internal.

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

- **No payment gateway.** Non-COD orders are marked paid on placement.
- **No SMS.** Login codes are logged, and returned outside production.
- **No push delivery to shoppers.** Tokens are stored and the feed works, but
  nothing sends to them yet; the seller app's FCM path is not shared.
- **Customers are not notified of seller-side status changes.** The order
  timeline is truthful, but no notification fires when a seller ships.
- **No invoice endpoint.** The prototype's "download invoice" has no API.
- **Guest checkout does not exist.** A basket may be built signed out and
  merged on sign-in, but placing an order needs an account.
