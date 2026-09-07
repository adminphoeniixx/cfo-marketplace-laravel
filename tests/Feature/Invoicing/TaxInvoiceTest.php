<?php

use App\Actions\Invoicing\IssueTaxInvoice;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Vendor;

/*
| The invoice for the goods, which is the seller's and not the marketplace's.
|
| A marketplace lists what other people sell and collects the money for them.
| That makes the seller the supplier of record, so the tax invoice carries the
| seller's name, the seller's GSTIN and the seller's own consecutive series —
| and a basket across two stores is two invoices, because it is two supplies
| between two different pairs of parties.
*/

/**
 * An order carrying lines from each of the given stores.
 *
 * @param  array<int, Vendor>  $vendors
 */
function orderAcross(array $vendors, array $attributes = [], ?Customer $customer = null): Order
{
    $order = Order::factory()->create([
        'status' => 'processing',
        'payment_status' => 'paid',
        'customer_id' => $customer?->id,
        'subtotal' => 1000 * count($vendors),
        'discount_total' => 0,
        'tax_total' => 180 * count($vendors),
        'shipping_total' => 100,
        'shipping_address' => [
            'first_name' => 'Asha', 'last_name' => 'Rao',
            'address_line1' => '4 Palm Grove', 'city' => 'Chennai',
            'state' => 'Tamil Nadu', 'postcode' => '600001', 'country' => 'IN',
        ],
        ...$attributes,
    ]);

    foreach ($vendors as $vendor) {
        $order->items()->create([
            'vendor_id' => $vendor->id,
            'name' => $vendor->name.' saree',
            'sku' => 'SKU-'.$vendor->id,
            'unit_price' => 1000,
            'quantity' => 1,
            'discount_amount' => 0,
            'tax_rate' => 18,
            'tax_amount' => 180,
            'total' => 1000,
            'commission_rate' => 10,
            'commission_amount' => 100,
            'vendor_earning' => 900,
        ]);
    }

    return $order->load('items');
}

test('an order across two stores is two invoices, each holding only its own lines', function () {
    $one = Vendor::factory()->create(['name' => 'Silk House', 'state' => 'Tamil Nadu', 'gst_number' => '33AABCU9603R1ZM']);
    $two = Vendor::factory()->create(['name' => 'Cotton Co', 'state' => 'Karnataka', 'gst_number' => '29AABCU9603R1ZX']);

    $invoices = app(IssueTaxInvoice::class)->forOrder(orderAcross([$one, $two]));

    expect($invoices)->toHaveCount(2);

    $first = $invoices->firstWhere('vendor_id', $one->id);
    $second = $invoices->firstWhere('vendor_id', $two->id);

    expect($first->snapshot['supplier']['name'])->toBe('Silk House')
        ->and($first->snapshot['supplier']['gstin'])->toBe('33AABCU9603R1ZM')
        ->and($first->snapshot['lines'])->toHaveCount(1)
        ->and($first->snapshot['lines'][0]['name'])->toBe('Silk House saree')
        // The marketplace is named as the collector, never as the supplier.
        ->and($second->snapshot['supplier']['name'])->toBe('Cotton Co')
        ->and($second->snapshot['lines'][0]['name'])->toBe('Cotton Co saree');
});

test('the series is per seller, so two stores both start at one', function () {
    $one = Vendor::factory()->create(['state' => 'Tamil Nadu']);
    $two = Vendor::factory()->create(['state' => 'Tamil Nadu']);

    $invoices = app(IssueTaxInvoice::class)->forOrder(orderAcross([$one, $two]));

    expect($invoices->pluck('sequence')->all())->toBe([1, 1]);

    // And the next order moves each seller's own series on by one.
    $next = app(IssueTaxInvoice::class)->forOrder(orderAcross([$one]));

    expect($next->first()->sequence)->toBe(2)
        ->and($next->first()->number)->toContain('V'.$one->id)
        ->and($next->first()->number)->toContain(Invoice::financialYearFor(now()));
});

test('asking twice returns the same document, never a second number', function () {
    $vendor = Vendor::factory()->create(['state' => 'Tamil Nadu']);
    $order = orderAcross([$vendor]);

    $first = app(IssueTaxInvoice::class)->handle($order, $vendor->id);
    $second = app(IssueTaxInvoice::class)->handle($order->fresh('items'), $vendor->id);

    expect($second->id)->toBe($first->id)
        ->and($second->number)->toBe($first->number)
        ->and(Invoice::where('type', Invoice::TAX)->count())->toBe(1);
});

test('same state is CGST plus SGST, another state is IGST', function () {
    $local = Vendor::factory()->create(['state' => 'Tamil Nadu']);
    $away = Vendor::factory()->create(['state' => 'Karnataka']);

    // The shopper is in Tamil Nadu, per orderAcross().
    $invoices = app(IssueTaxInvoice::class)->forOrder(orderAcross([$local, $away]));

    $intra = $invoices->firstWhere('vendor_id', $local->id);
    $inter = $invoices->firstWhere('vendor_id', $away->id);

    expect($intra->snapshot['tax_treatment'])->toBe('intra')
        ->and((float) $intra->cgst_total)->toBe(90.0)
        ->and((float) $intra->sgst_total)->toBe(90.0)
        ->and((float) $intra->igst_total)->toBe(0.0)
        ->and($inter->snapshot['tax_treatment'])->toBe('inter')
        ->and((float) $inter->igst_total)->toBe(180.0)
        ->and((float) $inter->cgst_total)->toBe(0.0);
});

test('a seller with no state recorded is told the treatment is unknown rather than guessed at', function () {
    $vendor = Vendor::factory()->create(['state' => null]);

    $invoice = app(IssueTaxInvoice::class)->handle(orderAcross([$vendor]), $vendor->id);

    expect($invoice->snapshot['tax_treatment'])->toBe('unknown')
        ->and((float) $invoice->tax_total)->toBe(180.0)
        ->and((float) $invoice->cgst_total + (float) $invoice->igst_total)->toBe(0.0);
});

test('delivery is split across the sellers in proportion to what each was paid', function () {
    $one = Vendor::factory()->create(['state' => 'Tamil Nadu']);
    $two = Vendor::factory()->create(['state' => 'Tamil Nadu']);

    $invoices = app(IssueTaxInvoice::class)->forOrder(orderAcross([$one, $two]));

    // ₹100 of delivery over two equal halves of the basket.
    expect($invoices->sum(fn ($invoice) => (float) $invoice->shipping_total))->toBe(100.0);
});

test('an order still awaiting payment, or cancelled, has no invoice', function () {
    $vendor = Vendor::factory()->create(['state' => 'Tamil Nadu']);

    expect(app(IssueTaxInvoice::class)->handle(orderAcross([$vendor], ['status' => 'pending']), $vendor->id))->toBeNull()
        ->and(app(IssueTaxInvoice::class)->handle(orderAcross([$vendor], ['status' => 'cancelled']), $vendor->id))->toBeNull()
        ->and(Invoice::count())->toBe(0);
});

test('a seller who moves premises does not rewrite an invoice already issued', function () {
    $vendor = Vendor::factory()->create(['name' => 'Silk House', 'city' => 'Chennai', 'state' => 'Tamil Nadu']);
    $invoice = app(IssueTaxInvoice::class)->handle(orderAcross([$vendor]), $vendor->id);

    $vendor->update(['name' => 'Silk House Exports', 'city' => 'Coimbatore']);

    expect($invoice->fresh()->snapshot['supplier']['name'])->toBe('Silk House')
        ->and($invoice->fresh()->snapshot['supplier']['city'])->toBe('Chennai');
});

test('the shopper is handed one signed link per seller, and each one renders', function () {
    $customer = actingAsCustomer();
    $one = Vendor::factory()->create(['name' => 'Silk House', 'state' => 'Tamil Nadu']);
    $two = Vendor::factory()->create(['name' => 'Cotton Co', 'state' => 'Karnataka']);

    $order = orderAcross([$one, $two], [], $customer);

    $number = ltrim($order->number, '#');

    $response = $this->getJson("/api/customer/orders/{$number}/invoices")->assertOk();

    expect($response->json('meta.count'))->toBe(2)
        ->and($response->json('data.0.seller'))->toBe('Silk House');

    // The document itself carries no token — the signature is the authority.
    $this->withHeaders([])->get($response->json('data.0.url'))
        ->assertOk()
        ->assertSee('Silk House')
        ->assertSee($response->json('data.0.number'));
});

test('the combined order summary is untouched beside it', function () {
    $customer = actingAsCustomer();
    $vendor = Vendor::factory()->create(['state' => 'Tamil Nadu']);
    $order = orderAcross([$vendor], [], $customer);

    $number = ltrim($order->number, '#');

    $this->getJson("/api/customer/orders/{$number}/invoice")
        ->assertOk()
        ->assertJsonPath('data.number', $order->number);
});

test('another shopper cannot ask for these invoices', function () {
    $vendor = Vendor::factory()->create(['state' => 'Tamil Nadu']);
    $order = orderAcross([$vendor], [], Customer::factory()->create(['status' => 'active']));

    $number = ltrim($order->number, '#');

    actingAsCustomer();

    $this->getJson("/api/customer/orders/{$number}/invoices")->assertNotFound();
});

test('the seller API hands a store its own invoice and nobody else s', function () {
    [, $store] = actingAsSeller(['state' => 'Tamil Nadu']);
    $other = Vendor::factory()->create(['name' => 'Cotton Co', 'state' => 'Karnataka']);

    $shared = orderAcross([$store, $other]);

    $response = $this->getJson("/api/seller/orders/{$shared->id}/invoice")->assertOk();

    expect($response->json('data.type'))->toBe('tax')
        // Their line only: ₹1000 plus ₹180 tax plus their half of delivery.
        ->and((float) $response->json('data.total'))->toBe(1230.0);

    // And an order they have no line in is not theirs to invoice.
    $theirs = orderAcross([$other]);
    $this->getJson("/api/seller/orders/{$theirs->id}/invoice")->assertNotFound();
});

test('a seller asking about an unpaid order is told there is nothing yet, not given an error page', function () {
    [, $store] = actingAsSeller(['state' => 'Tamil Nadu']);
    $order = orderAcross([$store], ['status' => 'pending']);

    $this->getJson("/api/seller/orders/{$order->id}/invoice")
        ->assertStatus(409)
        ->assertJsonPath('message', 'No invoice has been raised for this order yet.');
});
