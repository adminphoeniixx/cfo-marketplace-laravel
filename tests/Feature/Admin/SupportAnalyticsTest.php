<?php

use App\Models\Ticket;
use App\Models\Vendor;
use App\Notifications\TicketRaised;

/*
| Support on the analytics screen, and the kind tabs in the notification
| centre — two things the server already knew and nothing showed.
*/

test('analytics reports how the desk did, and the median rather than the mean', function () {
    actingAsAdmin();

    // Two answered quickly, one answered a fortnight late. A mean would say
    // "about four days"; nobody's Tuesday looked like that.
    foreach ([1, 2] as $hours) {
        Ticket::factory()->create([
            'created_at' => now()->subDays(2),
            'first_responded_at' => now()->subDays(2)->addHours($hours),
            'status' => 'resolved',
        ]);
    }
    Ticket::factory()->create([
        'created_at' => now()->subDays(2),
        'first_responded_at' => now()->subDays(2)->addHours(300),
        'status' => 'resolved',
    ]);

    // Opened, never answered: it has no first response to measure, and
    // counting it as zero would flatter the number exactly when it should not.
    Ticket::factory()->create(['created_at' => now()->subDay(), 'status' => 'open']);

    $this->get(route('admin.analytics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('support.opened', 4)
            ->where('support.resolved', 3)
            ->where('support.open_now', 1)
            ->where('support.unanswered_now', 1)
            // Two hours. The mean of 1, 2 and 300 is a hundred and one.
            ->where('support.first_response_hours', 2));
});

test('support is the marketplace’s desk, so it is hidden when filtered to one store', function () {
    actingAsAdmin();
    $vendor = Vendor::factory()->create(['status' => 'approved']);
    Ticket::factory()->create();

    $this->get(route('admin.analytics.index', ['vendor' => $vendor->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('support', null));
});

test('the notification centre offers the kinds this person actually has', function () {
    $admin = actingAsAdmin();

    // A ticket notification is one somebody has been sent; a payout one is not.
    $admin->notify(new TicketRaised(Ticket::factory()->create()));

    $this->get(route('admin.notifications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kinds', fn ($kinds) => collect($kinds)->pluck('value')->contains('ticket-raised')
                && collect($kinds)->pluck('value')->doesntContain('payout-recorded')));
});
