<?php

namespace App\Actions\Invoicing;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\VendorPayout;
use Illuminate\Support\Facades\DB;

/**
 * The tax invoice for the platform: marketplace to seller.
 *
 * The one supply the marketplace does make. Commission is the price of a
 * service — listing, checkout, collection, support — sold to the seller, and a
 * service carries its own GST, charged by the marketplace on its own GSTIN
 * against the seller's. It is the mirror of the goods invoice: there the
 * seller bills the shopper, here the marketplace bills the seller.
 *
 * Raised against a payout rather than against an order. A seller does not want
 * one fee invoice per parcel and neither does their accountant; the payout
 * already draws the line around a period and totals the commission inside it,
 * so the invoice is that period, priced.
 */
class IssueCommissionInvoice
{
    /**
     * This payout's commission invoice, issuing it if it does not exist.
     *
     * Returns null where there is nothing to bill — a period in which the
     * marketplace earned no commission — or where the marketplace has not been
     * told its own GSTIN, without which the document would be a letter rather
     * than a tax invoice.
     */
    public function handle(VendorPayout $payout): ?Invoice
    {
        $existing = Invoice::query()
            ->where('vendor_payout_id', $payout->id)
            ->where('type', Invoice::COMMISSION)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $commission = round((float) $payout->commission_amount, 2);

        if ($commission <= 0) {
            return null;
        }

        $gstin = trim((string) Setting::cached('gst_number', ''));

        if ($gstin === '') {
            return null;
        }

        $payout->loadMissing('vendor');
        $vendor = $payout->vendor;

        if ($vendor === null) {
            return null;
        }

        return DB::transaction(function () use ($payout, $vendor, $commission, $gstin) {
            $raced = Invoice::query()
                ->where('vendor_payout_id', $payout->id)
                ->where('type', Invoice::COMMISSION)
                ->lockForUpdate()
                ->first();

            if ($raced !== null) {
                return $raced;
            }

            $issuedAt = $payout->created_at ?? now();
            $year = Invoice::financialYearFor($issuedAt);

            [$number, $sequence] = Invoice::nextInSeries(
                Invoice::COMMISSION,
                $year,
                $vendor->id,
                Setting::cached('commission_invoice_prefix', 'COM'),
            );

            // Here the recipient is the seller, so the place of supply is the
            // seller's state and the marketplace's own state is the origin.
            $marketplaceState = trim((string) Setting::cached('gst_state', ''));
            $sellerState = trim((string) $vendor->state);
            $treatment = $marketplaceState === '' || $sellerState === ''
                ? 'unknown'
                : (strtolower($marketplaceState) === strtolower($sellerState) ? 'intra' : 'inter');

            $rate = (float) Setting::cached('commission_gst_rate', '18');
            $tax = round($commission * $rate / 100, 2);
            $cgst = $treatment === 'intra' ? round($tax / 2, 2) : 0.0;
            $sgst = $treatment === 'intra' ? round($tax - $cgst, 2) : 0.0;
            $igst = $treatment === 'inter' ? $tax : 0.0;

            return Invoice::create([
                'number' => $number,
                'type' => Invoice::COMMISSION,
                'financial_year' => $year,
                'sequence' => $sequence,
                'vendor_id' => $vendor->id,
                'vendor_payout_id' => $payout->id,
                'currency' => 'INR',
                'subtotal' => $commission,
                'cgst_total' => $cgst,
                'sgst_total' => $sgst,
                'igst_total' => $igst,
                'tax_total' => $tax,
                'grand_total' => round($commission + $tax, 2),
                'issued_at' => $issuedAt,
                'snapshot' => [
                    'supplier' => [
                        // The registered name, not the brand — they are often
                        // different strings and only one of them is legal.
                        'name' => Setting::cached('legal_name', '')
                            ?: Setting::cached('store_name', config('app.name')),
                        'gstin' => $gstin,
                        'address' => array_values(array_filter(
                            preg_split('/\r\n|\r|\n/', (string) Setting::cached('address', '')) ?: []
                        )),
                        'state' => $marketplaceState ?: null,
                        'phone' => Setting::cached('store_phone'),
                        'email' => Setting::cached('store_email'),
                    ],
                    'recipient' => [
                        'name' => $vendor->name,
                        'gstin' => $vendor->gst_number,
                        'address' => array_values(array_filter([
                            $vendor->address_line1,
                            $vendor->address_line2,
                        ])),
                        'city' => $vendor->city,
                        'state' => $sellerState ?: null,
                        'postcode' => $vendor->postcode,
                    ],
                    'place_of_supply' => $sellerState ?: null,
                    'tax_treatment' => $treatment,
                    'lines' => [[
                        'name' => 'Marketplace commission',
                        // What the fee was actually charged against, so the
                        // seller can check the number rather than accept it.
                        'description' => sprintf(
                            'Platform fee on %s of sales across %d order%s, %s to %s.',
                            number_format((float) $payout->gross_sales, 2),
                            (int) $payout->orders_count,
                            $payout->orders_count === 1 ? '' : 's',
                            optional($payout->period_start)->format('j M Y'),
                            optional($payout->period_end)->format('j M Y'),
                        ),
                        'quantity' => 1,
                        'taxable_value' => $commission,
                        'tax_rate' => $rate,
                        'cgst' => $cgst,
                        'sgst' => $sgst,
                        'igst' => $igst,
                        'tax_amount' => $tax,
                        'total' => round($commission + $tax, 2),
                    ]],
                    'payout' => [
                        'number' => $payout->number,
                        'period_start' => optional($payout->period_start)->toDateString(),
                        'period_end' => optional($payout->period_end)->toDateString(),
                        'gross_sales' => round((float) $payout->gross_sales, 2),
                        'net_amount' => round((float) $payout->net_amount, 2),
                    ],
                ],
            ]);
        });
    }
}
