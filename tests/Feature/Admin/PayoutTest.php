<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Vendor;
use App\Models\VendorPayout;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage payouts', function () {
    $this->get(route('admin.payouts.index'))->assertRedirect(route('login'));
});

test('payout index lists payouts with counts and summary', function () {
    actingAsAdmin();

    VendorPayout::factory()->create(['net_amount' => 1000, 'commission_amount' => 100]);
    VendorPayout::factory()->paid()->create(['net_amount' => 2000, 'commission_amount' => 200]);

    $this->get(route('admin.payouts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/payouts/Index')
            ->has('payouts.data', 2)
            ->where('counts.all', 2)
            ->where('counts.pending', 1)
            ->where('counts.paid', 1)
            ->where('summary.pending', 1000)
            ->where('summary.paid', 2000)
            ->where('summary.commission_earned', 300)
        );
});

test('payouts can be filtered', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create(['name' => 'Kraft Studio']);
    VendorPayout::factory()->create(['number' => 'PO-90001', 'vendor_id' => $vendor->id]);
    VendorPayout::factory()->paid()->create();

    $this->get(route('admin.payouts.index', ['search' => '90001']))
        ->assertInertia(fn (Assert $page) => $page->has('payouts.data', 1));

    $this->get(route('admin.payouts.index', ['search' => 'kraft']))
        ->assertInertia(fn (Assert $page) => $page->has('payouts.data', 1));

    $this->get(route('admin.payouts.index', ['status' => 'paid']))
        ->assertInertia(fn (Assert $page) => $page->has('payouts.data', 1));

    $this->get(route('admin.payouts.index', ['vendor' => $vendor->id]))
        ->assertInertia(fn (Assert $page) => $page->has('payouts.data', 1));
});

test('a payout is generated from the vendor sales in the period', function () {
    actingAsAdmin();

    $vendor = Vendor::factory()->create(['payout_method' => 'upi']);

    $inPeriod = Order::factory()->create(['placed_at' => now()->subDays(3), 'status' => 'completed']);
    OrderItem::factory()->forOrder($inPeriod)->create([
        'vendor_id' => $vendor->id,
        'total' => 4000,
        'commission_amount' => 400,
    ]);

    $cancelled = Order::factory()->cancelled()->create(['placed_at' => now()->subDays(3)]);
    OrderItem::factory()->forOrder($cancelled)->create([
        'vendor_id' => $vendor->id,
        'total' => 9999,
        'commission_amount' => 999,
    ]);

    $outOfPeriod = Order::factory()->create(['placed_at' => now()->subDays(60)]);
    OrderItem::factory()->forOrder($outOfPeriod)->create(['vendor_id' => $vendor->id, 'total' => 5000]);

    $this->post(route('admin.payouts.store'), [
        'vendor_id' => $vendor->id,
        'period_start' => now()->subDays(7)->toDateString(),
        'period_end' => now()->toDateString(),
        'adjustment_amount' => -100,
        'note' => 'Weekly settlement',
    ])
        ->assertRedirect(route('admin.payouts.index'))
        ->assertSessionHas('success');

    $payout = VendorPayout::first();

    expect((float) $payout->gross_sales)->toBe(4000.0)
        ->and((float) $payout->commission_amount)->toBe(400.0)
        ->and((float) $payout->net_amount)->toBe(3500.0)
        ->and($payout->orders_count)->toBe(1)
        ->and($payout->status)->toBe('pending')
        ->and($payout->method)->toBe('upi');
});

test('payout periods are validated', function () {
    actingAsAdmin();

    $this->post(route('admin.payouts.store'), [
        'vendor_id' => 9999,
        'period_start' => 'not-a-date',
    ])->assertSessionHasErrors(['vendor_id', 'period_start', 'period_end']);

    $vendor = Vendor::factory()->create();

    $this->post(route('admin.payouts.store'), [
        'vendor_id' => $vendor->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->subWeek()->toDateString(),
    ])->assertSessionHasErrors('period_end');
});

test('marking a payout paid stamps the paid date', function () {
    actingAsAdmin();

    $payout = VendorPayout::factory()->create();

    $this->from(route('admin.payouts.index'))
        ->patch(route('admin.payouts.status', $payout), [
            'status' => 'paid',
            'transaction_reference' => 'UTR-55512',
        ])
        ->assertSessionHas('success');

    expect($payout->fresh())
        ->status->toBe('paid')
        ->transaction_reference->toBe('UTR-55512')
        ->paid_at->not->toBeNull();
});

test('payout status must be supported', function () {
    actingAsAdmin();

    $payout = VendorPayout::factory()->create();

    $this->patch(route('admin.payouts.status', $payout), ['status' => 'settled'])
        ->assertSessionHasErrors('status');
});

test('a pending payout can be deleted but a paid one cannot', function () {
    actingAsAdmin();

    $pending = VendorPayout::factory()->create();
    $paid = VendorPayout::factory()->paid()->create();

    $this->from(route('admin.payouts.index'))
        ->delete(route('admin.payouts.destroy', $pending))
        ->assertSessionHas('success');

    $this->assertDatabaseMissing('vendor_payouts', ['id' => $pending->id]);

    $this->delete(route('admin.payouts.destroy', $paid))->assertStatus(422);

    $this->assertDatabaseHas('vendor_payouts', ['id' => $paid->id]);
});
