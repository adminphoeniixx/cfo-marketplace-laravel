# Customer API — changelog

Every change to the customer API lands here, newest first, so an app team can
see what moved without diffing routes. The full reference stays in
[`customer-api.md`](customer-api.md), and the Postman collection is kept in
step with both.

**Rules for entries**

- One entry per dated release, newest at the top.
- Group each entry under **Added**, **Changed**, **Fixed** or **Removed**.
- Say what an app has to _do_ about it. A change nobody has to act on is worth
  one line; a breaking one gets the migration note spelled out.
- Anything marked **Breaking** must name the old behaviour as well as the new.

---

## 2026-09-04

Closing the gap list the app team raised: everywhere the shopper app was
hardcoding something the server knew, plus the four holes the first release
named as "not built yet". 66 endpoints now.

### Added

- **Payments, for real.** `POST /payments/create-intent`,
  `POST /payments/verify` and `POST /payments/webhook/razorpay`. Configure
  `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET` / `RAZORPAY_WEBHOOK_SECRET` and an
  online order is written `pending` and waits for a real capture; leave them
  unset and nothing changes from before. Orders gained `transaction_id`,
  `payment_required` and `payment_icon`. **What an app must do:** after
  `POST /orders`, check `data.payment_required` — if true, open an intent,
  show Razorpay Checkout, then post `verify`. The webhook settles the same
  order if the app dies in between, and capture is idempotent either way.
- **Invoices.** `GET /orders/{number}/invoice` returns a signed seven-day URL
  to a print-ready HTML invoice, and every order carries the same link as
  `invoice_url`. The prototype's "download invoice" now has an API behind it.
- **`GET /cart` answers the whole cart screen.** `selected_address` (so the
  address strip no longer costs an extra `GET /checkout`) and
  `available_coupon_count` (so "3 offers available" is not a constant in the
  app). The count is exactly how many rows `GET /cart/coupons` returns.
- **Delivery options come display-ready**: `price_label` ("FREE", "₹99"),
  `eta_label` ("Arrives 4–6 Sep") and `note` ("Free over ₹999"), on both
  `/cart` and `/checkout`. `delivery_days_min`/`max` are unchanged and still
  there.
- **`icon` on payment methods** (and `is_pay_on_delivery`), on `/reference` and
  `/checkout` alike — the app's local code→emoji map can go. Admin-settable,
  with a sensible glyph derived from the code where it is not set.
  `/reference` couriers also gained `tracking_url`.
- **`emoji` on products, basket lines, order lines and return items; `icon` on
  categories.** Always filled. Both are admin-settable on a category, derived
  from the name otherwise.
- **`eta` on orders** — "Arriving 4–6 Sep" — frozen at checkout from the option
  the shopper chose, not recomputed later. Orders gained `eta_min_at` /
  `eta_max_at` for it.
- **`help` on orders**: the sellers with their phone numbers, plus the
  marketplace's support email, phone and chat link. `seller_phone` is filled in
  only where the order has exactly one seller — a shared basket has no "the
  seller".
- **`GET /orders/{number}/track` answers the whole screen**: a `carrier` object
  with `support_phone` and `tracking_url`, `eta`, `progress_step`,
  `progress_total`, `milestones[]` with `subtitle`/`done`/`current`, the
  `items` in the box and their `sellers`. The screen no longer needs to call
  `/orders/{number}` as well.
- **`eta` and `timeline` on cancellations and returns**, and `image`/`emoji` on
  their items.
- **What a coupon is actually worth.** The applied coupon now carries
  `shipping_discount` and `savings` (items + delivery), and `totals` gained
  `shipping_full_total`. **What an app must do:** quote `coupon.savings` on the
  "you saved" line — `discount` alone reads as ₹0 for a free-shipping code.
  `GET /cart/coupons` rows gained the same two fields.
- Admin: a **support chat link** setting, an **icon** field on payment methods
  and on categories.

### Changed

- **Breaking — `POST /orders/{number}/reorder`.** `added` was a count and is now
  a list of what went in (`cart_item_id`, `name`, `quantity`,
  `requested_quantity`, `status`, `warning`); `skipped` was a list of names and
  is now a list of objects with a `reason`. The response also carries `cart` —
  the full `GET /cart` payload — so the badge and totals are right immediately.
  **Migration:** read `added.length` where you read `added`, and
  `skipped[].name` where you read `skipped[]`.
- **`POST /orders` with a gateway configured** now writes `status: pending`
  rather than `processing` for online payments, and `payment_status` stays
  `pending` until capture. Cash on delivery is unchanged. With no gateway
  configured, nothing changes at all.
- `GET /checkout` returns `selected_address` alongside `selected_address_id`,
  and **remembers a chosen `address_id` on the basket** — which is what makes
  the cart strip and the checkout picker agree. Selection order: the request's
  `address_id`, then the basket's saved choice, then `is_default_shipping`,
  then the first address.
- `payments`, `carts.selected_address_id`, `orders.eta_min_at`/`eta_max_at`,
  `payment_methods.icon` and `categories.icon` are new schema.

### Fixed

- **A coupon could be applied and change nothing.** `FREESHIP` on a basket
  already over the free-delivery threshold was accepted, and the total sat
  there unmoved — which reads as a broken checkout. Such a code is now `422`
  with the reason, and the coupon sheet greys it out.
- **Shipping rate types were matched on names nothing writes.** `QuoteBasket`
  switched on `per_item` and `weight`; the panel and `ShippingRate::TYPES` use
  `item_based` and `weight_based`, so every rate fell through to a default that
  added the base rate, the per-item rate *and* the per-kg rate together.
  Panel-made rates came out right only because the columns the form hides stay
  at zero — one hand-edited row would have been charged twice over.
- **Basket-value bands were collected and then ignored.** A rate with
  `min_order_amount` / `max_order_amount` was offered on every basket
  regardless. It is now filtered to the band the panel promised.
- The cart priced delivery against the shopper's **default** address while
  checkout priced it against the **selected** one, so the shipping line could
  move between the two screens for no visible reason. Both now go through the
  same rule.

---

## 2026-08-31

First release. 61 endpoints under `/api/customer`, built against the prototype
in [`customer-app.html`](customer-app.html).

### Added

- **Auth.** Phone + OTP (`/auth/otp`, `/auth/otp/verify`) which creates the
  account on first sign-in, and email + password
  (`/auth/register`, `/auth/login`, `/auth/forgot-password`,
  `/auth/reset-password`). Plus `/auth/refresh`, `/auth/logout`,
  `/auth/logout-all` and the device list.
- **Browsing without a token.** `/home`, `/categories`, `/products` (search,
  eight filters, five sorts), `/products/{id-or-slug}`,
  `/products/suggestions`, `/sellers/{vendor}`, `/reference`.
- **Basket.** `/cart` and its lines, save-for-later, coupons. Every response
  is the whole basket, priced and grouped by seller.
- **Checkout.** `/checkout` for the review screen and `POST /orders` to place
  it, re-quoting server-side.
- **Orders.** List with filters, detail, tracking, reorder.
- **Cancellations and returns.** Raised per order, merged into one
  `/requests` queue, withdrawable while pending.
- **Account.** Profile, password, addresses, wishlist, reviews,
  notifications, push tokens.

### Changed

- `customers` gained `password` (nullable — OTP-only shoppers never set one)
  and `phone_verified_at`. `Customer` is now authenticatable and holds Sanctum
  tokens.
- New tables: `carts`, `cart_items`, `wishlist_items`, `product_reviews`,
  `coupons`, `customer_device_tokens`.
- `Order` gained `canBeCancelledByCustomer()`, `canBeReturnedByCustomer()` and
  `RETURN_WINDOW_DAYS`, so one rule decides what the app may offer.

### Fixed

- `Order::recordEvent()` wrote `auth()->id()` into `order_events.user_id`, a
  foreign key to `users`. On a customer request that id belongs to a
  **customer** — a different table — which would have attached the event to
  whichever unrelated staff login shared that number. It now records an actor
  only when one is a `User`.

### Not built yet

Payment gateway, SMS delivery, push to shoppers, notifications on seller-side
status changes, invoices, guest checkout. See the end of
[`customer-api.md`](customer-api.md).
