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
