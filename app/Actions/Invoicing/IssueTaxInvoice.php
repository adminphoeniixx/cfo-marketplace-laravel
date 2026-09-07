<?php

namespace App\Actions\Invoicing;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The tax invoice for the goods: seller to shopper.
 *
 * The marketplace does not sell anything. It lists what sellers sell, takes
 * the money on their behalf and hands it on, and that makes the seller the
 * supplier of record — their name at the top, their GSTIN, their consecutive
 * series. An order spanning two stores is therefore two invoices, not one
 * document with two headings; each is a separate supply between a separate
 * pair of parties.
 *
 * Issued lazily, on the first time anybody asks for it, and then never again:
 * a number that changes is not a number. The unique index on
 * (order, vendor, type) is what makes that true under a double click.
 */
class IssueTaxInvoice
{
    /**
     * This seller's invoice for this order, issuing it if it does not exist.
     *
     * Returns null where there is nothing to invoice — an order still waiting
     * for payment, a cancelled one, or a seller with no lines in it.
     */
    public function handle(Order $order, int $vendorId): ?Invoice
    {
        $existing = Invoice::query()
            ->where('order_id', $order->id)
            ->where('vendor_id', $vendorId)
            ->where('type', Invoice::TAX)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->invoiceable($order)) {
            return null;
        }

        $lines = $order->items->where('vendor_id', $vendorId);

        if ($lines->isEmpty()) {
            return null;
        }

        $vendor = Vendor::find($vendorId);

        if ($vendor === null) {
            return null;
        }

        return DB::transaction(function () use ($order, $vendor, $lines) {
            // Re-read inside the lock: two tabs asking at once must not both
            // decide the invoice is missing and both claim a number.
            $raced = Invoice::query()
                ->where('order_id', $order->id)
                ->where('vendor_id', $vendor->id)
                ->where('type', Invoice::TAX)
                ->lockForUpdate()
                ->first();

            if ($raced !== null) {
                return $raced;
            }

            $issuedAt = $order->placed_at ?? $order->created_at ?? now();
            $year = Invoice::financialYearFor($issuedAt);

            [$number, $sequence] = Invoice::nextInSeries(
                Invoice::TAX,
                $year,
                $vendor->id,
                Setting::cached('invoice_prefix', 'INV'),
            );

            $shipping = (array) ($order->shipping_address ?? []);
            $placeOfSupply = trim((string) ($shipping['state'] ?? '')) ?: null;

            // Same state, one supply taxed half as the centre's and half as
            // the state's; different states, the whole of it as IGST. Where
            // either state is unknown we cannot tell, and guessing wrong is
            // worse than saying so — the invoice prints the tax as one line
            // and the treatment as `unknown`.
            $treatment = $this->treatment($vendor->state, $placeOfSupply);

            $subtotal = 0.0;
            $discount = 0.0;
            $taxTotal = 0.0;
            $rendered = [];

            foreach ($lines as $line) {
                $gross = (float) $line->total;
                $lineDiscount = (float) $line->discount_amount;
                $taxable = round($gross - $lineDiscount, 2);
                $tax = (float) $line->tax_amount;

                $subtotal += $gross;
                $discount += $lineDiscount;
                $taxTotal += $tax;

                $rendered[] = [
                    'name' => $line->name,
                    'sku' => $line->sku,
                    'options' => $line->options,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => round((float) $line->unit_price, 2),
                    'discount_amount' => round($lineDiscount, 2),
                    'taxable_value' => $taxable,
                    'tax_rate' => (float) $line->tax_rate,
                    'cgst' => $treatment === 'intra' ? round($tax / 2, 2) : 0.0,
                    'sgst' => $treatment === 'intra' ? round($tax - round($tax / 2, 2), 2) : 0.0,
                    'igst' => $treatment === 'inter' ? round($tax, 2) : 0.0,
                    'tax_amount' => round($tax, 2),
                    'total' => round($taxable + $tax, 2),
                ];
            }

            // Delivery is quoted for the basket, not per store, so a shared
            // basket has no seller-specific figure to print. Rather than
            // inventing one, it is spread across the sellers in proportion to
            // what each was paid — which is what the money actually did.
            $orderNet = max((float) $order->subtotal - (float) $order->discount_total, 0);
            $share = $orderNet > 0 ? round($subtotal - $discount, 2) / $orderNet : 0;
            $shippingShare = round((float) $order->shipping_total * $share, 2);

            $cgst = round(array_sum(array_column($rendered, 'cgst')), 2);
            $sgst = round(array_sum(array_column($rendered, 'sgst')), 2);
            $igst = round(array_sum(array_column($rendered, 'igst')), 2);

            return Invoice::create([
                'number' => $number,
                'type' => Invoice::TAX,
                'financial_year' => $year,
                'sequence' => $sequence,
                'order_id' => $order->id,
                'vendor_id' => $vendor->id,
                'currency' => $order->currency ?: 'INR',
                'subtotal' => round($subtotal, 2),
                'discount_total' => round($discount, 2),
                'shipping_total' => $shippingShare,
                'cgst_total' => $cgst,
                'sgst_total' => $sgst,
                'igst_total' => $igst,
                'tax_total' => round($taxTotal, 2),
                'grand_total' => round($subtotal - $discount + $taxTotal + $shippingShare, 2),
                'issued_at' => $issuedAt,
                'snapshot' => [
                    'supplier' => [
                        'name' => $vendor->name,
                        'gstin' => $vendor->gst_number,
                        'address' => array_values(array_filter([
                            $vendor->address_line1,
                            $vendor->address_line2,
                        ])),
                        'city' => $vendor->city,
                        'state' => $vendor->state,
                        'postcode' => $vendor->postcode,
                        'phone' => $vendor->phone,
                        'email' => $vendor->store_email,
                    ],
                    'recipient' => [
                        'name' => trim(($shipping['first_name'] ?? '').' '.($shipping['last_name'] ?? ''))
                            ?: ($order->customer->name ?? ''),
                        'address' => array_values(array_filter([
                            $shipping['address_line1'] ?? null,
                            $shipping['address_line2'] ?? null,
                        ])),
                        'city' => $shipping['city'] ?? null,
                        'state' => $placeOfSupply,
                        'postcode' => $shipping['postcode'] ?? null,
                        'phone' => $shipping['phone'] ?? null,
                    ],
                    'place_of_supply' => $placeOfSupply,
                    'tax_treatment' => $treatment,
                    'lines' => $rendered,
                    'order' => [
                        'number' => $order->number,
                        'placed_at' => optional($order->placed_at ?? $order->created_at)->toIso8601String(),
                        'payment_method' => $order->payment_method,
                        'payment_status' => $order->payment_status,
                    ],
                    // The marketplace is not the supplier here, but it is who
                    // the shopper paid and who they will write to first.
                    'collected_by' => Setting::cached('store_name', config('app.name')),
                ],
            ]);
        });
    }

    /**
     * Every seller's invoice for an order, in one call.
     *
     * @return Collection<int, Invoice>
     */
    public function forOrder(Order $order): Collection
    {
        return $order->items
            ->pluck('vendor_id')
            ->filter()
            ->unique()
            ->values()
            ->map(fn ($vendorId) => $this->handle($order, (int) $vendorId))
            ->filter()
            ->values();
    }

    /**
     * An order nobody has paid for is not a supply yet, and a cancelled one
     * never became one.
     */
    private function invoiceable(Order $order): bool
    {
        return ! in_array($order->status, ['pending', 'cancelled'], true);
    }

    private function treatment(?string $supplierState, ?string $placeOfSupply): string
    {
        $supplier = strtolower(trim((string) $supplierState));
        $buyer = strtolower(trim((string) $placeOfSupply));

        if ($supplier === '' || $buyer === '') {
            return 'unknown';
        }

        return $supplier === $buyer ? 'intra' : 'inter';
    }
}
