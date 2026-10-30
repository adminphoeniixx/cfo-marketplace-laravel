# Pricing change for customers

_Changed on 29 Oct 2026_

**In one line:** every price you see in the shop is now set and checked by the marketplace before the product goes on sale.

---

## What changed

- **Prices are set by the marketplace**, so prices are consistent and checked before a product appears in the shop.
- A product is shown only after it has a proper price. You won't see unpriced or ₹0 items.

## Products leaving your cart

Sometimes a product comes off sale after you add it to your cart, for example while its price is being updated.

- At checkout you'll see: **"{Product name} is no longer available. Remove it to continue."**
- Remove that item and place the order. Nothing is charged for it.

## Coupons

Coupons work exactly as before. The discount you see is the discount you get.

---

## Customer mobile app / API (for developers)

- Placing an order can now return **422** with an error on `items` when a product in the cart is no longer on sale. Show the message and let the customer remove the item.

Full details: `customer-api.md` and `customer-api-changelog.md`.
