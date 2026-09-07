<?php

use App\Actions\Invoicing\IssueCommissionInvoice;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Vendor;
use App\Models\VendorPayout;

/*
| The invoice pointing the other way.
|
| Commission is not a discount the marketplace takes off a seller's money; it
| is the price of a service the marketplace sold them, and a service carries
| its own GST on the marketplace's own GSTIN. Without this document a seller
| cannot claim that GST back, which makes the fee quietly more expensive than
| the rate they agreed to.
|
| Raised against a payout rather than an order: the payout already draws the
| line around a period and totals the commission inside it.
*/

beforeEach(function () {
    Setting::put('legal_name', 'CFO Retail Private Limited');
    Setting::put('gst_number', '09AABCU9603R1ZM');
    Setting::put('gst_state', 'Uttar Pradesh');
    Setting::put('commission_gst_rate', '18');
});

function payoutFor(Vendor $vendor, array $attributes = []): VendorPayout
{
    return VendorPayout::create([
        'number' => VendorPayout::nextNumber(),
        'vendor_id' => $vendor->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'gross_sales' => 10000,
        'commission_amount' => 1000,
        'adjustment_amount' => 0,
        'net_amount' => 9000,
        'orders_count' => 4,
        'status' => 'pending',
        'method' => 'bank',
        ...$attributes,
    ]);
}

test('the marketplace bills the seller, not the other way round', function () {
    $vendor = Vendor::factory()->create(['name' => 'Silk House', 'state' => 'Uttar Pradesh', 'gst_number' => '09AABCU9603R1ZX']);

    $invoice = app(IssueCommissionInvoice::class)->handle(payoutFor($vendor));

    expect($invoice->type)->toBe(Invoice::COMMISSION)
        ->and($invoice->snapshot['supplier']['name'])->toBe('CFO Retail Private Limited')
        ->and($invoice->snapshot['supplier']['gstin'])->toBe('09AABCU9603R1ZM')
        ->and($invoice->snapshot['recipient']['name'])->toBe('Silk House')
        ->and($invoice->snapshot['recipient']['gstin'])->toBe('09AABCU9603R1ZX');
});

test('GST is charged on the commission, not on the sales it came off', function () {
    $vendor = Vendor::factory()->create(['state' => 'Uttar Pradesh']);

    $invoice = app(IssueCommissionInvoice::class)->handle(payoutFor($vendor));

    // ₹1,000 of commission on ₹10,000 of sales, plus 18% of the ₹1,000.
    expect((float) $invoice->subtotal)->toBe(1000.0)
        ->and((float) $invoice->tax_total)->toBe(180.0)
        ->and((float) $invoice->grand_total)->toBe(1180.0)
        // Same state as the marketplace, so it splits.
        ->and((float) $invoice->cgst_total)->toBe(90.0)
        ->and((float) $invoice->sgst_total)->toBe(90.0);
});

test('a seller in another state is charged IGST instead', function () {
    $vendor = Vendor::factory()->create(['state' => 'Tamil Nadu']);

    $invoice = app(IssueCommissionInvoice::class)->handle(payoutFor($vendor));

    expect($invoice->snapshot['tax_treatment'])->toBe('inter')
        ->and((float) $invoice->igst_total)->toBe(180.0)
        ->and((float) $invoice->cgst_total)->toBe(0.0);
});

test('the marketplace series runs across sellers, unlike a seller s own', function () {
    $one = Vendor::factory()->create(['state' => 'Uttar Pradesh']);
    $two = Vendor::factory()->create(['state' => 'Tamil Nadu']);

    $first = app(IssueCommissionInvoice::class)->handle(payoutFor($one));
    $second = app(IssueCommissionInvoice::class)->handle(payoutFor($two));

    // One marketplace, one book: 1 then 2, not 1 then 1.
    expect($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($second->number)->toStartWith('COM/');
});

test('asking twice returns the same document', function () {
    $vendor = Vendor::factory()->create(['state' => 'Uttar Pradesh']);
    $payout = payoutFor($vendor);

    $first = app(IssueCommissionInvoice::class)->handle($payout);
    $second = app(IssueCommissionInvoice::class)->handle($payout->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::where('type', Invoice::COMMISSION)->count())->toBe(1);
});

test('without its own GSTIN the marketplace raises nothing rather than a document it cannot stand behind', function () {
    Setting::put('gst_number', '');

    $vendor = Vendor::factory()->create(['state' => 'Uttar Pradesh']);

    expect(app(IssueCommissionInvoice::class)->handle(payoutFor($vendor)))->toBeNull()
        ->and(Invoice::count())->toBe(0);
});

test('a period that earned no commission is not billed for', function () {
    $vendor = Vendor::factory()->create(['state' => 'Uttar Pradesh']);

    expect(app(IssueCommissionInvoice::class)->handle(payoutFor($vendor, ['commission_amount' => 0])))->toBeNull();
});

test('recording a payout raises its commission invoice with it', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create(['status' => 'approved', 'state' => 'Uttar Pradesh']);

    $this->post('/admin/payouts', [
        'vendor_id' => $vendor->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
    ])->assertRedirect();

    $payout = VendorPayout::firstOrFail();

    // No sales in the window, so no commission and no invoice — but the
    // payout is still recorded, which is the point: the paper is a
    // consequence of the payout, never a condition of it.
    expect($payout->vendor_id)->toBe($vendor->id)
        ->and(Invoice::where('vendor_payout_id', $payout->id)->exists())
        ->toBe((float) $payout->commission_amount > 0);
});

test('a seller can fetch the fee invoice raised against them, and nobody else s', function () {
    [, $store] = actingAsSeller(['state' => 'Uttar Pradesh']);
    $other = Vendor::factory()->create(['state' => 'Tamil Nadu']);

    $mine = payoutFor($store);
    $theirs = payoutFor($other);

    $response = $this->getJson("/api/seller/payouts/{$mine->id}/commission-invoice")->assertOk();

    expect($response->json('data.type'))->toBe('commission')
        ->and((float) $response->json('data.total'))->toBe(1180.0);

    $this->getJson("/api/seller/payouts/{$theirs->id}/commission-invoice")->assertNotFound();

    // And the signed link renders the marketplace as the supplier.
    $this->get($response->json('data.url'))
        ->assertOk()
        ->assertSee('CFO Retail Private Limited')
        ->assertSee('Marketplace commission');
});
