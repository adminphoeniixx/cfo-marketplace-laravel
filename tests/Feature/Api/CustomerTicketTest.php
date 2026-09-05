<?php

use App\Models\Order;
use App\Models\Ticket;
use App\Notifications\TicketRaised;
use App\Notifications\TicketReplied;
use Illuminate\Support\Facades\Notification;

/*
| "I have a problem", from the shopper's side.
|
| The rules worth holding: a ticket belongs to one shopper, an internal note
| never leaves the panel, and a shopper writing back reopens something support
| had already called resolved.
*/

beforeEach(function () {
    $this->customer = actingAsCustomer(['first_name' => 'Priya']);
    Notification::fake();
});

test('a shopper opens a ticket and the desk is told', function () {
    adminUser();

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'My parcel has not arrived',
        'message' => 'It was due on Tuesday and there is no update.',
        'category' => 'delivery',
    ])->assertCreated()
        ->assertJsonPath('data.subject', 'My parcel has not arrived')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.status_label', 'We are looking into it')
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.author_type', 'customer');

    expect(Ticket::first()->number)->toBe('TKT-00001');

    Notification::assertSentTimes(TicketRaised::class, 1);
});

test('a ticket can be about an order, but only one of yours', function () {
    $mine = Order::factory()->create(['customer_id' => $this->customer->id]);
    $theirs = Order::factory()->create();

    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Wrong size', 'message' => 'The saree is a size small.',
        'category' => 'order', 'order_number' => ltrim($mine->number, '#'),
    ])->assertCreated();

    expect(Ticket::first()->order_id)->toBe($mine->id);

    // Naming somebody else's order finds nothing rather than attaching to it.
    $this->postJson(route('api.customer.support.tickets.store'), [
        'subject' => 'Curious', 'message' => 'About this order.',
        'category' => 'order', 'order_number' => ltrim($theirs->number, '#'),
    ])->assertStatus(422)->assertJsonValidationErrors('order_number');
});

test('an internal note never reaches the shopper', function () {
    $staff = adminUser();
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);

    $ticket->addMessage('Thanks, looking now.', staff: $staff);
    $ticket->addMessage('Seller says it was never collected.', staff: $staff, internal: true);

    $response = $this->getJson(route('api.customer.support.tickets.show', $ticket->number))
        ->assertOk()
        ->assertJsonCount(1, 'data.messages')
        // Staff answer as the marketplace, not as a named person.
        ->assertJsonPath('data.messages.0.author', 'Support');

    expect(collect($response->json('data.messages'))->pluck('body')->implode(' '))
        ->not->toContain('never collected');
});

test('a reply reopens a ticket support had called resolved', function () {
    adminUser();
    $ticket = Ticket::factory()->resolved()->create(['customer_id' => $this->customer->id]);

    $this->postJson(route('api.customer.support.tickets.reply', $ticket->number), [
        'message' => 'It still has not arrived.',
    ])->assertOk()->assertJsonPath('data.status', 'open');

    // Whatever support decided, the shopper has not finished with it.
    expect($ticket->fresh()->status)->toBe('open');

    Notification::assertSentTimes(TicketReplied::class, 1);
});

test('a closed ticket is not a conversation any more', function () {
    $ticket = Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => 'closed',
    ]);

    $this->postJson(route('api.customer.support.tickets.reply', $ticket->number), [
        'message' => 'One more thing.',
    ])->assertStatus(422)->assertJsonValidationErrors('message');
});

test('the shopper can close it themselves', function () {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);

    $this->postJson(route('api.customer.support.tickets.close', $ticket->number))
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.can_reply', false);

    expect($ticket->fresh()->closed_at)->not->toBeNull();
});

test('another shopper\'s ticket does not exist as far as this one is concerned', function () {
    $theirs = Ticket::factory()->create();

    $this->getJson(route('api.customer.support.tickets.show', $theirs->number))->assertNotFound();
    $this->postJson(route('api.customer.support.tickets.reply', $theirs->number), [
        'message' => 'Hello?',
    ])->assertNotFound();
    $this->postJson(route('api.customer.support.tickets.close', $theirs->number))->assertNotFound();
});

test('the list is mine, newest reply first', function () {
    Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'subject' => 'Older', 'last_reply_at' => now()->subDay(),
    ]);
    Ticket::factory()->create([
        'customer_id' => $this->customer->id,
        'subject' => 'Newer', 'last_reply_at' => now(),
    ]);
    Ticket::factory()->create(['subject' => 'Somebody else']);

    $this->getJson(route('api.customer.support.tickets.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.subject', 'Newer');
});

test('a closed account does not erase who wrote in', function () {
    $ticket = Ticket::factory()->create(['customer_id' => $this->customer->id]);

    // The shopper deletes their account. The ticket, and the conversation on
    // it, stay perfectly readable — and so does the name on them.
    $this->customer->delete();

    expect($ticket->fresh()->customerName())->toBe('Priya');
});
