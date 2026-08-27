# Seller app — product form spec

What the **Add / Edit product** screen in the seller app must look like, so it
matches the seller web panel (`/seller/products/create`), which is modelled on
Shopify's product form.

The API already accepts every field listed here. Nothing new has to be built on
the backend — this is purely an app-side UI gap. Endpoints are documented in
[`seller-api.md`](./seller-api.md); the Postman collection is
[`seller-api.postman_collection.json`](./seller-api.postman_collection.json).

---

## 1. The gap today

The current app screen collects **7** of the **28** fields the web form does:

| Currently in the app | Missing |
|---|---|
| Photos (single "+" tile) | Multiple photos with cover / reorder / remove / alt |
| Name | Subtitle (`short_description`) |
| Price | Description (long, rich text) |
| Compare at | Cost per item + live profit & margin |
| Stock | Tax class |
| Low at | Track-quantity toggle |
| Category (picker not wired) | SKU, Barcode, Backorder toggle |
| Type (picker not wired) | Physical-product toggle, Weight, L × W × H |
| | Variants (options → values → generated rows) |
| | Brand |
| | Tags |
| | "Also list under" — extra categories |
| | SEO: page title, meta description, URL handle |
| | Status as a real 3-way field (draft / active / archived) |

Also broken in the current screen: **Category** and **Type** rows open nothing
(`data-act="soon"`), and there is both a top-right **Save** and a bottom
**Publish product** / **Save as draft** pair — three save buttons for one form.

---

## 2. Screen structure

One form, used for both create and edit. On mobile, use **collapsible sections**
— all closed except *Basics*, *Media*, *Pricing*, *Inventory*, exactly the way
the web form hides rare fields behind a disclosure row.

```
┌─────────────────────────────────────────┐
│ ✕   New product                  [Save] │   ← single save action
├─────────────────────────────────────────┤
│  BASICS                                 │
│   Title *            [_________________]│
│   Subtitle           [_________________]│
│   Description        [ rich text ▾     ]│
│                                         │
│  MEDIA                          3/10    │
│   ┌────┐┌────┐┌────┐┌────┐              │
│   │cover││ 2 ││ 3 ││ +  │  drag to move │
│   └────┘└────┘└────┘└────┘              │
│                                         │
│  CATEGORY                               │
│   Category           Sarees          ›  │
│                                         │
│  PRICING                                │
│   Price *            ₹ [____________]   │
│   ▸ Compare-at · Cost · Tax · Margin    │  ← collapsed
│                                         │
│  INVENTORY            Track quantity ●  │
│   Quantity  [____]   Low stock at [___] │
│   ▸ SKU · Barcode · Backorders          │  ← collapsed
│                                         │
│  SHIPPING           Physical product ●  │
│   Weight             [______] kg        │
│   ▸ Length · Width · Height             │  ← collapsed
│                                         │
│  VARIANTS                               │
│   + Add options like size or colour     │
│                                         │
│  ORGANISATION                           │
│   Brand              [_________________]│
│   Tags               [chip] [chip] [+]  │
│   Also list under    2 selected      ›  │
│                                         │
│  SEARCH ENGINE LISTING                  │
│   ▸ Page title · Meta description · URL │  ← collapsed
│                                         │
│  STATUS              Draft           ›  │
└─────────────────────────────────────────┘
```

A clickable version of exactly this screen is in
[`seller-app.html`](./seller-app.html) — open it in a browser, sign in, and tap
**Products → Add a product**.

**One save action, not three.** Status is a field (Draft / Active / Archived);
the save button just saves. If a two-button pattern is preferred on mobile, the
buttons must *set* `status` and then submit — `Publish` → `status: "active"`,
`Save as draft` → `status: "draft"` — and the top-right Save must go.

---

## 3. Every field

`*` = required. All keys are exactly what `POST /api/seller/products` expects.

### Basics

| Label | Key | Control | Rules |
|---|---|---|---|
| Title * | `name` | text | required, ≤ 200 |
| Subtitle | `short_description` | text | ≤ 500. Helper: "One line shoppers see in listings" |
| Description | `description` | rich text (bold, italic, lists, links) | ≤ 20 000, HTML |

### Media

| Label | Key | Control | Rules |
|---|---|---|---|
| Photos | `images[]` | grid, max 10 | each item `{ id, path, alt }` |

- First image is the **cover** — badge it.
- Long-press / drag to reorder; order in the array *is* the display order.
- Remove button per tile.
- `alt` defaults to the product title; optional edit field.
- Counter: "3/10 added". JPG, PNG, WEBP, GIF, AVIF, max 5 MB each.
- Upload flow is two steps — see §4.

### Category

| Label | Key | Control | Rules |
|---|---|---|---|
| Category | `category_id` | searchable single-select | from `GET /catalog/options` → `categories` |

Children are indented under their parent (`parent_id`). Helper: "Sets the tax
rate and where the product shows up in browse."

### Pricing

| Label | Key | Control | Rules |
|---|---|---|---|
| Price * | `price` | number, prefix ₹ | required, ≥ 0 |
| Compare-at price | `compare_at_price` | number, prefix ₹ | nullable. Helper: "Shown struck through next to the price." |
| Cost per item | `cost_price` | number, prefix ₹ | nullable. Helper: "Only you see this." |
| Tax class | `tax_class_id` | select | nullable, from `options.tax_classes`, plus a "No tax class" row |
| Profit / Margin | — | read-only text | shown only when price and cost are both set |

```
profit = price − cost
margin = ((price − cost) / price) × 100     → one decimal, e.g. "42.9%"
```

Compare-at, cost, tax class and margin live behind the collapsed row.
Collapse the whole pricing disclosure automatically once variants exist.

### Inventory

| Label | Key | Control | Rules |
|---|---|---|---|
| Track quantity | `track_inventory` | toggle, default **on** | section header action |
| Quantity | `stock_quantity` | number | required when tracking, ≥ 0 |
| Low stock alert at | `low_stock_threshold` | number | default **5**, ≥ 0 |
| SKU | `sku` | text | ≤ 80, **unique across the whole marketplace** → expect 422 |
| Barcode | `barcode` | text | ≤ 80. Label: "Barcode (ISBN, UPC, GTIN)" |
| Continue selling when out of stock | `allow_backorder` | checkbox | default off |

Three states for the body of this section:

- tracking, no variants → Quantity + Low stock fields
- variants exist → hide the fields, show *"Quantity is set per variant below — **N** in stock across M variants."*
- tracking off → *"Stock is not tracked, so this never sells out."*

### Shipping

| Label | Key | Control | Rules |
|---|---|---|---|
| Physical product | `requires_shipping` | toggle, default **on** | section header action |
| Weight | `weight` | number, suffix kg | step 0.001, ≥ 0 |
| Length / Width / Height | `length`, `width`, `height` | number, suffix cm | step 0.1, nullable, behind the collapsed row |

Toggle off → hide everything, show *"Digital product — no weight or delivery needed."*

### Variants

Driven by marketplace-wide attributes: `GET /catalog/options` → `attributes`,
**filtered to `is_variant: true`**. If that list is empty, show *"The
marketplace has not set up any variant options yet."* and nothing else.

Flow, same as web:

1. **"Add options like size or colour"** → sheet listing unused attributes
   (`Size · 5 values`).
2. Picking one opens its value chips — the seller taps only the values they
   actually stock. Values with `color_hex` show a colour dot.
3. **Done** regenerates the variant rows = cartesian product of every chosen
   value across every chosen option, **capped at 100 rows**.
4. Rows already edited are preserved across a regenerate (match on the
   attribute/value combination, not on row index).
5. Tapping an existing option re-opens its chips; **Delete** removes the option
   and regenerates.

Each generated row is editable: **Variant name** (read-only label, e.g. `S / Red`),
**Price**, **Available** (`stock_quantity`), **SKU**, and a remove ✕.
On mobile, render one card per variant rather than a table.

New-row defaults:

```
name  = "S / Red"                      (values joined with " / ")
sku   = parent SKU ? "{sku}-S-RED" : ""
price = parent price
compare_at_price = parent compare-at
stock_quantity   = 0
weight = parent weight
is_active = true
values = [{ attribute_id, attribute_value_id }, …]
```

Footer line: *"Total inventory: N available"*.

Two rules the app must honour:

- `type` is **not** a user-facing picker. It is derived: variants present →
  `"variable"`, otherwise `"simple"`. Remove the "Type" row from the screen.
- `PATCH /products/{id}/stock` returns **422** on a variable product. Edit its
  variants instead.

### Organisation

| Label | Key | Control | Rules |
|---|---|---|---|
| Brand | `brand` | text | ≤ 120 |
| Tags | `tags[]` | chip input | each ≤ 40; add on Enter or comma; ✕ to remove; no duplicates |
| Also list under | `category_ids[]` | multi-select list | extra categories on top of `category_id` |

### Search engine listing

| Label | Key | Control | Rules |
|---|---|---|---|
| Page title | `seo_title` | text | ≤ 180. Counter: "N of 70 characters used" |
| Meta description | `seo_description` | textarea, 3 rows | ≤ 400 |
| URL handle | `slug` | text, prefix `/products/` | ≤ 220. Helper: "Leave blank to build one from the title." |

Collapsed by default; the closed row previews `seo_title || name ||
"Add a title and description to see how this product might appear in search"`.

### Status

| Label | Key | Control | Rules |
|---|---|---|---|
| Status * | `status` | select | `draft` \| `active` \| `archived` (from `options.statuses`) |

Helper: *"Only **Active** products are visible to shoppers."*
Default on a new product: `draft`.

### Edit screen extras

- Header shows the product name + a status badge.
- Sales snapshot strip above the form: **Units sold**, **Revenue**, **Orders**
  (`GET /products/{id}` response, if the app surfaces stats).
- **Delete this product** at the bottom, confirm first.
- Open the *Pricing* disclosure on arrival when the product already has a
  compare-at or cost price, so nothing the seller set is hidden behind a tap.

---

## 4. Media upload — two steps

Images are attached **by path, not by file**. The product payload never carries
a file.

```
POST /api/seller/uploads          (multipart, throttle 60/min)
  file:   <image>            jpg|jpeg|png|webp|gif|avif, max 5 MB
  folder: "products"

201 → { "path": "cfo/products/13/abc123.webp",
        "url":  "https://…signed…" }
```

Then send the returned **`path`** in the product payload:

```json
"images": [
  { "id": null, "path": "cfo/products/13/abc123.webp", "alt": "Front" }
]
```

- Save the `path` on the record. The `url` is signed and time-limited — never
  persist it, use it only to render the preview.
- On edit, keep the `id` of images that already existed. Any existing image
  **not** present in the array is deleted.
- Array order = `position`. Index 0 is the cover.
- Upload as the seller picks each photo, with a per-tile progress state, so the
  save itself stays fast.

---

## 5. Saving

| Action | Call |
|---|---|
| Create | `POST /api/seller/products` → `201 { data: {...} }` |
| Update | `PUT /api/seller/products/{id}` → `200 { data: {...} }` |
| Status only | `PATCH /api/seller/products/{id}/status` |
| Stock only | `PATCH /api/seller/products/{id}/stock` |
| Delete | `DELETE /api/seller/products/{id}` |

> **`PUT` is a full replace.** Anything omitted is cleared — including images,
> variants, tags and categories. Always send the complete form state, built from
> the `GET /products/{id}` response. Do not send partial payloads.

Minimum viable create payload:

```json
{
  "name": "Kanchipuram silk saree",
  "type": "simple",
  "price": 4999,
  "status": "active",
  "stock_quantity": 12
}
```

Full payload shape:

```json
{
  "name": "Kanchipuram silk saree",
  "slug": "",
  "sku": "SAR-001",
  "barcode": "8901234567890",
  "type": "variable",
  "category_id": 12,
  "tax_class_id": 3,
  "short_description": "Handwoven, gold zari border",
  "description": "<p>…</p>",
  "price": 4999,
  "compare_at_price": 6499,
  "cost_price": 3100,
  "track_inventory": true,
  "stock_quantity": 12,
  "low_stock_threshold": 5,
  "allow_backorder": false,
  "weight": 0.65,
  "length": 30, "width": 22, "height": 6,
  "requires_shipping": true,
  "status": "active",
  "brand": "Kanchi Looms",
  "tags": ["silk", "wedding"],
  "seo_title": "Kanchipuram silk saree",
  "seo_description": "Handwoven silk saree with gold zari border.",
  "category_ids": [12, 40],
  "images": [{ "id": null, "path": "cfo/products/13/abc.webp", "alt": "Front" }],
  "attribute_ids": [1, 2],
  "variants": [
    {
      "id": null,
      "name": "S / Red",
      "sku": "SAR-001-S-RED",
      "price": 4999,
      "compare_at_price": 6499,
      "stock_quantity": 4,
      "weight": 0.65,
      "is_active": true,
      "values": [
        { "attribute_id": 1, "attribute_value_id": 7 },
        { "attribute_id": 2, "attribute_value_id": 15 }
      ]
    }
  ]
}
```

`vendor_id` is never sent — the store comes from the token. Sending one is
ignored.

### Reference data

One call fills every picker on the screen; cache it for the session:

```
GET /api/seller/catalog/options
→ { categories: [{id, name, parent_id}],
    attributes: [{id, name, type, is_variant, values:[{id, value, color_hex}]}],
    tax_classes: [{id, name}],
    statuses: ["draft", "active", "archived"] }
```

### Errors

`422` returns Laravel's standard shape — map each key to the field that owns it
and scroll to the first error, including nested variant keys:

```json
{
  "message": "The sku has already been taken.",
  "errors": {
    "sku": ["The sku has already been taken."],
    "variants.0.price": ["The variants.0.price field is required."]
  }
}
```

Other codes: `401` no token · `403` store not approved yet, or the `products`
section is withheld (`section` in the body) · `404` not yours · `429` rate
limited.

### Unsaved changes

Track dirtiness. Closing the screen with unsaved edits opens a confirm sheet —
*"Leave page with unsaved changes? Leaving this page will delete all unsaved
changes."* → **Stay** / **Leave page**.

---

## 6. Acceptance checklist

- [ ] All 28 fields above are present and round-trip through save → reopen.
- [ ] Up to 10 photos: add, reorder, remove, cover badge, alt text.
- [ ] Uploads go through `POST /uploads`; only the returned `path` is saved.
- [ ] Category picker is wired to `catalog/options` and searchable.
- [ ] Profit and margin update live from price and cost.
- [ ] Track-quantity off hides the quantity fields.
- [ ] Variants: add option → pick values → rows generate; edits survive a
      regenerate; cap of 100 respected.
- [ ] `type` is derived from variants, never picked by hand.
- [ ] Inventory section shows the summed variant stock when variants exist.
- [ ] Tags add on Enter and comma, dedupe, and remove.
- [ ] SEO section collapsed, with the character counter on page title.
- [ ] Status is a 3-way field; exactly one save action on the screen.
- [ ] `PUT` always sends the complete payload.
- [ ] 422 errors land on the right fields, including `variants.N.field`.
- [ ] Leaving with unsaved changes prompts first.
