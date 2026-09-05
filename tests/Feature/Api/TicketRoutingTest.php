<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\Vendor;
use App\Notifications\TicketAnswered;
use App\Notifications\TicketRaised;
use Illuminate\Support\Facades\Notification;

/*
| Who a ticket is addressed to, and who therefore hears about it.
|
| Three routes exist: a shopper to a store, a shopper to the marketplace, and a
| store to the marketplace. Getting the audience right at the moment it is
| written is the difference between an answer and a ticket forwarded twice.
*/

/** An order this customer placed with this store. */
function boughtFrom(Customer $customer, Vendor $vendor): Order
{
    $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => 'completed']);

    $order->items()->create([
        'vendor_id' => $vendor->id, 'name' => 'Kanchipuram silk saree',
        'unit_price' => 1000, 'quantity' => 1, 'total' => 1000,
    ]);

    return $order->load('items');
}

test('a shopper writes to a seller they have bought from', function () {
    Notification::fake();

    $customer = actingAsCustomer();
    $vendor = Vendor::factory()->create(['status' => 'approved', 'name' => 'Meera Textiles']);
    boughtFrom($customer, $vendor);

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Does this run small?',
        'message' => 'I usually take a medium.',
        'category' => 'product',
        'vendor_id' => $vendor->id,
    ])->assertCreated()
        ->assertJsonPath('data.audience', 'vendor')
        ->assertJsonPath('data.audience_label', 'The seller')
        ->assertJsonPath('data.seller.name', 'Meera Textiles');

    expect(Ticket::first()->vendor_id)->toBe($vendor->id);
});

test('a shopper cannot write to a seller they have never bought from', function () {
    actingAsCustomer();
    $stranger = Vendor::factory()->create(['status' => 'approved']);

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Hello', 'message' => 'Are you there?', 'category' => 'other',
        'vendor_id' => $stranger->id,
    ])->assertStatus(422)->assertJsonValidationErrors('vendor_id');

    // Nothing opens a channel to a stranger's inbox.
    expect(Ticket::count())->toBe(0);
});

test('naming an order routes it to that order’s seller by itself', function () {
    $customer = actingAsCustomer();
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    $order = boughtFrom($customer, $vendor);

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Not arrived', 'message' => 'It was due Tuesday.',
        'category' => 'delivery', 'order_number' => ltrim($order->number, '#'),
    ])->assertCreated()
        ->assertJsonPath('data.audience', 'vendor');

    expect(Ticket::first()->vendor_id)->toBe($vendor->id);
});

test('with no seller named it goes to the marketplace', function () {
    actingAsCustomer();

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Refund not received', 'message' => 'It has been a week.',
        'category' => 'payment',
    ])->assertCreated()
        ->assertJsonPath('data.audience', 'marketplace')
        ->assertJsonPath('data.audience_label', 'The marketplace');
});

test('a shopper’s question to a seller reaches that seller and nobody else', function () {
    Notification::fake();

    // Marketplace staff who would hear about a marketplace ticket.
    $staff = adminUser();

    $customer = actingAsCustomer();
    [$sellerUser, $vendor] = actingAsSeller();
    // Back to the shopper's token.
    test()->withHeader('Authorization', 'Bearer '.$customer->createToken('phone')->plainTextToken);
    app('auth')->forgetGuards();

    boughtFrom($customer, $vendor);

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Where is it?', 'message' => 'Due Tuesday.',
        'category' => 'delivery', 'vendor_id' => $vendor->id,
    ])->assertCreated();

    // Every shopper's question about a saree in front of staff who cannot
    // answer it is exactly what the audience column prevents.
    Notification::assertSentTo($sellerUser, TicketRaised::class);
    Notification::assertNotSentTo($staff, TicketRaised::class);
});

test('a seller writes to the marketplace, and the desk hears it', function () {
    Notification::fake();

    $staff = adminUser();
    [$sellerUser, $vendor] = actingAsSeller(['name' => 'Meera Textiles']);

    $this->postJson(route('api.seller.support.tickets.store'), [
        'subject' => 'August payout has not landed',
        'message' => 'The panel says paid but nothing has arrived.',
        'category' => 'payment',
    ])->assertCreated()
        ->assertJsonPath('data.direction', 'outgoing')
        ->assertJsonPath('data.status_label', 'With the marketplace');

    $ticket = Ticket::firstOrFail();

    expect($ticket->audience)->toBe('marketplace')
        ->and($ticket->opened_by)->toBe('vendor')
        ->and($ticket->customer_id)->toBeNull()
        ->and($ticket->vendor_id)->toBe($vendor->id)
        // A seller asking is not a seller answering: the clock has not
        // started, because nobody has replied yet.
        ->and($ticket->first_responded_at)->toBeNull()
        ->and($ticket->status)->toBe('open');

    Notification::assertSentTo($staff, TicketRaised::class);
});

test('a seller answers a shopper, and the shopper is told', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    [$sellerUser, $vendor] = actingAsSeller();

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id,
        'audience' => 'vendor',
        'vendor_id' => $vendor->id,
        'opened_by' => 'customer',
    ]);

    $this->postJson(route('api.seller.support.tickets.reply', $ticket->number), [
        'message' => 'Posted this morning, tracking to follow.',
        'resolve' => true,
    ])->assertOk();

    expect($ticket->fresh()->status)->toBe('resolved')
        ->and($ticket->fresh()->first_responded_at)->not->toBeNull();

    Notification::assertSentTo($customer, TicketAnswered::class);
});

test('a store sees only its own post', function () {
    [, $mine] = actingAsSeller();

    $theirs = Ticket::factory()->create([
        'audience' => 'vendor',
        'vendor_id' => Vendor::factory()->create()->id,
    ]);
    Ticket::factory()->create([
        'audience' => 'vendor', 'vendor_id' => $mine->id, 'subject' => 'Mine',
    ]);

    $this->getJson(route('api.seller.support.tickets.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Mine');

    $this->getJson(route('api.seller.support.tickets.show', $theirs->number))->assertNotFound();
});

test('the seller sees the shopper’s side, never the marketplace’s notes', function () {
    $staff = adminUser();
    $customer = Customer::factory()->create(['first_name' => 'Priya']);
    [, $vendor] = actingAsSeller();

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->id, 'audience' => 'vendor', 'vendor_id' => $vendor->id,
    ]);
    $ticket->addMessage('Where is my parcel?', from: $customer);
    $ticket->addMessage('Chasing this store about it.', staff: $staff, internal: true);

    $response = $this->getJson(route('api.seller.support.tickets.show', $ticket->number))->assertOk();

    expect($response->json('data.messages'))->toHaveCount(1)
        ->and($response->json('data.messages.0.author'))->toBe('Priya');
});

test('the admin queue is the marketplace’s own, with the stores’ visible on request', function () {
    actingAsAdmin();
    $vendor = Vendor::factory()->create();

    Ticket::factory()->create(['audience' => 'marketplace', 'subject' => 'For the desk']);
    Ticket::factory()->create([
        'audience' => 'vendor', 'vendor_id' => $vendor->id, 'subject' => 'For the seller',
    ]);

    $this->get(route('admin.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.subject', 'For the desk')
            // Oversight: how much the stores are carrying, without it being a
            // queue staff are meant to work.
            ->where('summary.with_sellers', 1));

    $this->get(route('admin.tickets.index', ['audience' => 'vendor']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.subject', 'For the seller'));
});
