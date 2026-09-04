<?php

use App\Models\Category;
use App\Models\Coupon;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Vendor;

/*
| The things the app used to hardcode.
|
| Every test here stands for a line the shopper app was inventing for itself —
| an emoji table, a coupon count, "Arrives 4–6 Sep", an icon per payment code.
| If one of these breaks, the app goes back to guessing.
*/

beforeEach(function () {
    $this->customer = actingAsCustomer();

    $this->home = $this->customer->addresses()->create([
        'label' => 'Home',
        'first_name' => 'Priya',
        'last_name' => 'Nair',
        'address_line1' => '42 Beach Road',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'postcode' => '600090',
        'country' => 'IN',
        'phone' => '9876543210',
        'is_default_shipping' => true,
    ]);

    $this->office = $this->customer->addresses()->create([
        'label' => 'Office',
        'first_name' => 'Priya',
        'address_line1' => '9 Mount Road',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'postcode' => '600002',
        'country' => 'IN',
        'is_default_shipping' => false,
    ]);
});

/** Put one product in the basket. */
function stock(int $productId, int $quantity = 1): void
{
    test()->postJson(route('api.customer.cart.items.store'), [
        'product_id' => $productId,
        'quantity' => $quantity,
    ])->assertOk();
}

test('the cart carries the address it is heading to, and how many offers exist', function () {
    Coupon::factory()->count(3)->create();
    stock(sellableProduct()->id);

    $this->getJson(route('api.customer.cart'))
        ->assertOk()
        // No extra `GET /checkout` to draw the address strip.
        ->assertJsonPath('data.selected_address.id', $this->home->id)
        ->assertJsonPath('data.selected_address.label', 'Home')
        ->assertJsonPath('data.available_coupon_count', 3);
});

test('an address chosen at checkout is the one the cart then shows', function () {
    stock(sellableProduct()->id);

    // The default until somebody says otherwise.
    $this->getJson(route('api.customer.cart'))
        ->assertJsonPath('data.selected_address.id', $this->home->id);

    $this->getJson(route('api.customer.checkout', ['address_id' => $this->office->id]))
        ->assertOk()
        ->assertJsonPath('selected_address_id', $this->office->id)
        ->assertJsonPath('selected_address.label', 'Office');

    // Remembered on the basket, so the two screens cannot disagree.
    $this->getJson(route('api.customer.cart'))
        ->assertJsonPath('data.selected_address.id', $this->office->id);

    $this->getJson(route('api.customer.checkout'))
        ->assertJsonPath('selected_address_id', $this->office->id);
});

test('delivery options arrive display-ready', function () {
    $zone = ShippingZone::create([
        'name' => 'Tamil Nadu',
        'states' => ['Tamil Nadu'],
        'is_active' => true,
        'position' => 0,
    ]);

    ShippingRate::create([
        'shipping_zone_id' => $zone->id,
        'name' => 'Standard delivery',
        'type' => 'flat',
        'rate' => 0,
        'free_above_amount' => 999,
        'delivery_days_min' => 4,
        'delivery_days_max' => 6,
        'is_active' => true,
        'position' => 0,
    ]);

    ShippingRate::create([
        'shipping_zone_id' => $zone->id,
        'name' => 'Express delivery',
        'type' => 'flat',
        'rate' => 99,
        'free_above_amount' => 5000,
        'delivery_days_min' => 2,
        'delivery_days_max' => 2,
        'is_active' => true,
        'position' => 1,
    ]);

    stock(sellableProduct(['price' => 500])->id);

    $options = collect($this->getJson(route('api.customer.checkout'))->json('shipping_options'));

    $standard = $options->firstWhere('code', 'standard-delivery');
    $express = $options->firstWhere('code', 'express-delivery');

    expect($standard['price_label'])->toBe('FREE')
        ->and($standard['eta_label'])->toBe('Arrives '.now()->addDays(4)->format('j').'–'.now()->addDays(6)->format('j M'))
        ->and($express['price_label'])->toBe('₹99')
        // A single day is named in full rather than as a range of one.
        ->and($express['eta_label'])->toBe('Arrives '.now()->addDays(2)->format('D, j M'))
        ->and($express['note'])->toBe('Free over ₹5,000');
});

test('ways to pay carry their own icon', function () {
    $methods = collect($this->getJson(route('api.customer.reference'))->json('payment_methods'));

    expect($methods->firstWhere('code', 'upi')['icon'])->toBe('⚡')
        ->and($methods->firstWhere('code', 'credit-card')['icon'])->toBe('💳')
        ->and($methods->firstWhere('code', 'net-banking')['icon'])->toBe('🏦')
        ->and($methods->firstWhere('code', 'cash-on-delivery')['icon'])->toBe('💵')
        ->and($methods->firstWhere('code', 'cash-on-delivery')['is_pay_on_delivery'])->toBeTrue();

    // A method the admin invents still arrives with something to draw.
    PaymentMethod::create(['name' => 'Store credit', 'code' => 'store-credit', 'is_active' => true, 'position' => 9]);

    expect(collect($this->getJson(route('api.customer.reference'))->json('payment_methods'))
        ->firstWhere('code', 'store-credit')['icon'])->toBe('💰');
});

test('products and categories draw something when there is no photograph', function () {
    $category = Category::factory()->create(['name' => 'Shawls', 'is_active' => true]);
    sellableProduct(['name' => 'Kashmiri wool shawl', 'category_id' => $category->id]);

    $this->getJson(route('api.customer.products'))
        ->assertOk()
        ->assertJsonPath('data.0.emoji', '🧶');

    $this->getJson(route('api.customer.categories'))
        ->assertOk()
        ->assertJsonPath('data.0.icon', '🧶');

    // An admin's own choice wins over the one read off the name.
    $category->update(['icon' => '🎁']);

    $this->getJson(route('api.customer.categories'))->assertJsonPath('data.0.icon', '🎁');
});

test('an order says when it is coming, what it cost and who to ask', function () {
    Setting::put('store_phone', '1800 000 111');
    Setting::put('support_chat_url', 'https://wa.me/919000000000');

    $vendor = Vendor::factory()->create(['status' => 'approved', 'phone' => '9812345678']);
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'processing',
        'payment_status' => 'paid',
        'payment_method' => 'UPI',
        'transaction_id' => 'pay_ABC123',
        'placed_at' => now(),
        'eta_min_at' => now()->addDays(4),
        'eta_max_at' => now()->addDays(6),
    ]);

    $order->items()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Kanchipuram silk saree',
        'unit_price' => 1000,
        'quantity' => 1,
        'total' => 1000,
    ]);

    $this->getJson(route('api.customer.orders.show', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('data.transaction_id', 'pay_ABC123')
        ->assertJsonPath('data.payment_icon', '⚡')
        ->assertJsonPath('data.eta', 'Arriving '.now()->addDays(4)->format('j').'–'.now()->addDays(6)->format('j M'))
        // One seller on this order, so the row is unambiguous.
        ->assertJsonPath('data.help.seller_phone', '9812345678')
        ->assertJsonPath('data.help.support_phone', '1800 000 111')
        ->assertJsonPath('data.help.chat_url', 'https://wa.me/919000000000')
        ->assertJsonPath('data.items.0.emoji', '🥻');
});

test('the invoice link opens without a token and refuses without a signature', function () {
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'processing',
        'payment_status' => 'paid',
        'grand_total' => 1180,
        'subtotal' => 1000,
        'tax_total' => 180,
        'placed_at' => now(),
        'shipping_address' => ['first_name' => 'Priya', 'city' => 'Chennai', 'postcode' => '600090'],
    ]);

    $order->items()->create([
        'vendor_id' => Vendor::factory()->create(['status' => 'approved'])->id,
        'name' => 'Kashmiri wool shawl',
        'unit_price' => 1000,
        'quantity' => 1,
        'total' => 1000,
    ]);

    $url = $this->getJson(route('api.customer.orders.invoice', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('data.number', $order->number)
        ->json('data.url');

    // Signed, so it works with no Authorization header at all.
    $this->withHeaders(['Authorization' => ''])->get($url)
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee('Kashmiri wool shawl');

    $this->get(route('api.customer.invoices.show', ['order' => $order->id]))->assertForbidden();
});

test('tracking answers the whole screen in one call', function () {
    DeliveryPartner::updateOrCreate(
        ['code' => 'delhivery'],
        ['name' => 'Delhivery', 'support_phone' => '1800 103 6354', 'tracking_url' => 'https://track.test/{tracking}', 'is_active' => true],
    );

    $vendor = Vendor::factory()->create(['status' => 'approved', 'name' => 'Meera Textiles', 'city' => 'Chennai']);
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'shipped',
        'payment_status' => 'paid',
        'payment_method' => 'UPI',
        'carrier' => 'delhivery',
        'tracking_number' => 'TRK-88213',
        'placed_at' => now()->subDays(2),
        'paid_at' => now()->subDays(2),
        'shipped_at' => now()->subDay(),
        'eta_min_at' => now()->addDay(),
        'eta_max_at' => now()->addDay(),
    ]);

    $order->items()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Kanchipuram silk saree',
        'unit_price' => 1000,
        'quantity' => 1,
        'total' => 1000,
    ]);

    $response = $this->getJson(route('api.customer.orders.track', ltrim($order->number, '#')))
        ->assertOk()
        ->assertJsonPath('data.carrier.name', 'Delhivery')
        ->assertJsonPath('data.carrier.support_phone', '1800 103 6354')
        ->assertJsonPath('data.carrier.tracking_url', 'https://track.test/TRK-88213')
        ->assertJsonPath('data.eta', 'Arriving '.now()->addDay()->format('D, j M'))
        // Placed, paid and picked up are behind it; out for delivery is not.
        ->assertJsonPath('data.progress_step', 3)
        ->assertJsonPath('data.milestones.2.title', 'Picked up from the seller')
        ->assertJsonPath('data.milestones.2.subtitle', 'Meera Textiles, Chennai')
        ->assertJsonPath('data.milestones.2.done', true)
        ->assertJsonPath('data.milestones.3.current', true)
        // The same steps as events: one state per row, and the city the
        // parcel was in, rather than two booleans to combine.
        ->assertJsonPath('data.events.2.label', 'Picked up from the seller')
        ->assertJsonPath('data.events.2.location', 'Chennai')
        ->assertJsonPath('data.events.2.state', 'done')
        ->assertJsonPath('data.events.3.state', 'current')
        ->assertJsonPath('data.events.4.state', 'pending')
        ->assertJsonPath('data.support_phone', '1800 103 6354')
        ->assertJsonPath('data.eta_label', 'Arriving '.now()->addDay()->format('D, j M'))
        // The lines in the box, so no second call to `/orders/{number}`.
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.sellers.0.name', 'Meera Textiles');

    expect($response->json('data.items.0.emoji'))->toBe('🥻');
});

test('a return says what is coming back and where it has got to', function () {
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'completed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'fulfilled',
        'grand_total' => 3499,
        'subtotal' => 3499,
        'placed_at' => now()->subDays(10),
        'delivered_at' => now()->subDays(2),
    ]);

    $item = $order->items()->create([
        'vendor_id' => $vendor->id,
        'name' => 'Kanchipuram silk saree',
        'unit_price' => 3499,
        'quantity' => 1,
        'quantity_fulfilled' => 1,
        'total' => 3499,
    ]);

    $number = $this->postJson(route('api.customer.orders.returns', ltrim($order->number, '#')), [
        'reason' => 'damaged',
        'method' => 'original',
        'note' => 'Pallu had a tear near the border.',
        'items' => [['order_item_id' => $item->id, 'quantity' => 1]],
    ])->assertCreated()->json('data.number');

    $this->getJson(route('api.customer.requests.show', $number))
        ->assertOk()
        ->assertJsonPath('data.amount', 3499)
        ->assertJsonPath('data.method', 'Original payment method')
        ->assertJsonPath('data.reason_label', 'Item arrived damaged')
        ->assertJsonPath('data.note', 'Pallu had a tear near the border.')
        ->assertJsonPath('data.eta', 'Usually 3–5 working days once it is approved')
        ->assertJsonPath('data.timeline.0.title', 'Return requested')
        ->assertJsonPath('data.timeline.0.done', true)
        ->assertJsonPath('data.timeline.2.title', 'Refunded to your payment method')
        ->assertJsonPath('data.timeline.2.done', false)
        // The same steps as events, and the moments named one by one.
        ->assertJsonPath('data.events.0.state', 'done')
        ->assertJsonPath('data.events.1.state', 'current')
        ->assertJsonPath('data.events.2.state', 'pending')
        ->assertJsonPath('data.seller_approved_at', null)
        ->assertJsonPath('data.refund_issued_at', null)
        ->assertJsonPath('data.items.0.emoji', '🥻');
});
