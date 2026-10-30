# Pricing change for sellers

_Changed on 29 Oct 2026_

**In one line:** you say what you want to be paid for a product (your **cost**). The marketplace decides what the customer pays (the **price**), and the marketplace puts the product on sale.

---

## What changed

| Before | Now |
|---|---|
| You set the selling price yourself | You set only your **cost per item**: what you want paid for each unit |
| You could set the MRP (compare-at price) | The marketplace sets the MRP |
| You could make a product **Active** (on sale) | Only the marketplace can put a product on sale |
| You were paid the selling price minus a commission % | You are paid **your cost × quantity sold** |

## Adding a product

1. Fill in the product details as usual.
2. In **Cost per item**, enter what you want paid for one unit. If the product has variants (size, colour, etc.), enter a cost for **each variant**.
3. Save with **Send for pricing**.
4. The product now shows **Awaiting price**. Customers can't see it yet.
5. The marketplace sets the customer price and puts the product on sale.
6. You get a notification: **"Your product is live. Shoppers now see it at ₹X."**

Until the marketplace sets a price, the price column says **"Not priced yet"**. Once it's set, you can see the customer price, but you can't change it.

## Editing a live product

- **Changing the name, description, photos, stock, etc.:** the product stays on sale.
- **Changing your cost, or adding a new variant:** the product **comes off sale** and goes back to **Awaiting price** until the marketplace sets the price again. The form warns you before you save.
- **Moving a live product to Draft or Archived:** to put it back on sale later, send it for pricing again and wait for the marketplace.

## How you are paid

You get **your cost × units sold**, nothing deducted.

> **Example:** your cost is ₹700 and the marketplace sells it at ₹1,000.
> A customer buys 2 units → **you get ₹1,400**. The marketplace keeps ₹600.

- **Coupons are paid by the marketplace.** If a customer uses a 10% coupon, you still get the full ₹1,400.
- **Older products with no cost entered** are still paid the old way: selling price minus your commission %.

---

## Seller mobile app / API (for developers)

- Send `cost_price` when creating or updating a product. It is now required (for each variant too).
- `price` and `compare_at_price` are **ignored** if sent. They come back read-only, and are `0` / `null` until the product is priced.
- Sending `status: active` returns `pending` (Awaiting price), unless the product is already live and its cost didn't change.
- New notification kind: `product-went-live`.

Full details: `seller-api.md` and `seller-api-changelog.md`.
