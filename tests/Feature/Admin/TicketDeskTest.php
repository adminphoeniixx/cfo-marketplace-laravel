<?php

use App\Models\Customer;
use App\Models\Ticket;
use App\Notifications\TicketAnswered;
use App\Support\Roles;
use Illuminate\Support\Facades\Notification;

/*
| The support desk, from the staff side.
*/

beforeEach(function () {
    Notification::fake();
});

test('the queue answers the longest wait first', function () {
    actingAsAdmin();

    Ticket::factory()->create(['subject' => 'Waiting since Monday', 'last_reply_at' => now()->subDays(3)]);
    Ticket::factory()->create(['subject' => 'Just now', 'last_reply_at' => now()]);
    Ticket::factory()->create(['subject' => 'Done with', 'status' => 'closed', 'last_reply_at' => now()->subWeek()]);

    $this->get(route('admin.tickets.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/tickets/Index')
            ->has('tickets.data', 3)
            // A queue answered newest-first is a queue where somebody waits
            // for ever; a finished ticket is not in the queue at all.
            ->where('tickets.data.0.subject', 'Waiting since Monday')
            ->where('tickets.data.2.subject', 'Done with')
            ->where('summary.open', 2));
});

test('a reply reaches the shopper and stamps the first response', function () {
    $staff = actingAsAdmin();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id]);

    $this->post(route('admin.tickets.reply', $ticket->id), [
        'body' => 'We have chased the courier and it is out for delivery today.',
    ])->assertRedirect();

    $fresh = $ticket->fresh();

    expect($fresh->status)->toBe('pending')
        ->and($fresh->first_responded_at)->not->toBeNull()
        ->and($fresh->messages()->where('user_id', $staff->id)->exists())->toBeTrue();

    Notification::assertSentTo($customer, TicketAnswered::class);
});

test('an internal note tells nobody and moves nothing', function () {
    $staff = actingAsAdmin();
    $customer = Customer::factory()->create();
    $ticket = Ticket::factory()->create(['customer_id' => $customer->id, 'status' => 'open']);

    $this->post(route('admin.tickets.reply', $ticket->id), [
        'body' => 'Spoke to the seller; posting a replacement.',
        'is_internal' => true,
    ])->assertRedirect();

    $fresh = $ticket->fresh();

    // The shopper is still waiting exactly as long as they were.
    expect($fresh->status)->toBe('open')
        ->and($fresh->first_responded_at)->toBeNull();

    Notification::assertNothingSentTo($customer);
});

test('answering and resolving is one action', function () {
    actingAsAdmin();
    $ticket = Ticket::factory()->create();

    $this->post(route('admin.tickets.reply', $ticket->id), [
        'body' => 'Refunded in full.',
        'resolve' => true,
    ])->assertRedirect();

    expect($ticket->fresh()->status)->toBe('resolved');
});

test('reopening clears the closing stamp', function () {
    actingAsAdmin();
    $ticket = Ticket::factory()->create(['status' => 'closed', 'closed_at' => now()->subMonth()]);

    $this->patch(route('admin.tickets.update', $ticket->id), ['status' => 'open'])->assertRedirect();

    // Otherwise a ticket closed in March still reads as closed in March while
    // somebody is actively working it.
    expect($ticket->fresh()->closed_at)->toBeNull();
});

test('only somebody who holds the section can be handed a ticket', function () {
    actingAsAdmin();
    $ticket = Ticket::factory()->create();

    // A vendor login holds "orders" for their own store; that is not the
    // marketplace's support desk.
    [$seller] = actingAsSeller();
    actingAsAdmin();

    $this->get(route('admin.tickets.show', $ticket->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/tickets/Show')
            ->where('assignees', fn ($assignees) => collect($assignees)->pluck('id')->doesntContain($seller->id)));
});

test('a role without the support section cannot open the desk', function () {
    // Staff hold it by default, so the check needs a role that does not.
    actingAsAdmin(['role' => 'manager']);
    Roles::save('manager', ['orders']);

    $this->get(route('admin.tickets.index'))->assertForbidden();
});
