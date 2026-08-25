# Seller API — changelog

Every change to the seller API lands here, newest first, so an app team can see
what moved without diffing routes. The full reference stays in
[`seller-api.md`](seller-api.md), and the Postman collection is kept in step
with both.

**Rules for entries**

- One entry per dated release, newest at the top.
- Group each entry under **Added**, **Changed**, **Fixed** or **Removed**.
- Say what an app has to _do_ about it. A change nobody has to act on is worth
  one line; a breaking one gets the migration note spelled out.
- Anything marked **Breaking** must name the old behaviour as well as the new.

---

## 2026-08-25

### Added

- `POST /orders` — raise an order by hand: a phone order, a repeat customer, a
  fix for a checkout that went wrong. Same code path the panels use, so
  pricing, tax, commission and stock come out identical. Returns `201` with the
  order in `/orders/{id}` shape. There is no `vendor_id` in the payload — the
  store comes from the token.
- `GET /orders/sellable` — this store's sellable products with price, stock,
  `track_inventory`, `allow_backorder`, `tax_rate` and variants. Build the
  manual-order picker from this; it lists exactly what `POST /orders` accepts.
- `GET /orders/customers` — people who have already bought from this store,
  capped at 100, `?search=` on name or email. Passing a `customer_id` from here
  attaches the order to that customer and copies their saved address.
- `GET /analytics/report` — the full analytics screen in one call: `metrics`
  (each a `{ value, change }` pair against the preceding window), `series`,
  `by_category`, `top_products`, `top_customers`, `by_payment_method` and the
  status, payment and fulfilment breakdowns. Accepts `?preset=7|30|90|365` or
  `?from=&to=`, the same query string the panel uses.
- `GET /analytics/export/{report}` — streams `products`, `categories`,
  `customers` or `orders` as CSV over the same window. `vendors` is the
  marketplace leaderboard and answers `404` for a seller; read the `exports`
  array from `/analytics/report` rather than hard-coding report names.

### Changed

- `GET /orders` and `GET /orders/{id}` now carry **`to_pack`** — units on this
  order still yours to pack — and **`shared_basket`**, true when the buyer's
  basket also holds another seller's goods. `shared_basket` is why `totals` may
  not match what the buyer paid; worth saying so on the screen.
- `GET /orders/{id}` now carries **`timeline`**, newest first, each entry
  `{ id, type, title, body, by, created_at }`. `by` is `store` for something
  your own store did and `marketplace` for something the marketplace did —
  never a staff name. Events written by another seller on a shared basket are
  not in the list at all. List endpoints do not include `timeline`.

Nothing in this release is breaking: every field named above is additive, and
no existing response changed shape.

---

## Earlier

The API's first release is documented in [`seller-api.md`](seller-api.md);
changes from before this file existed are in the git history.
