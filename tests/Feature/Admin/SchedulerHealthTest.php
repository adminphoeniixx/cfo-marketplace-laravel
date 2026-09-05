<?php

use App\Console\Commands\RecordHeartbeat;
use App\Models\Setting;

/*
| Whether anything runs on its own.
|
| Courier tracking — and everything else on the schedule — happens only because
| a process inside the web container calls `schedule:run` every minute. Reading
| the Dockerfile and believing it has already produced one wrong answer, so the
| marketplace now says.
*/

test('the heartbeat stamps the time', function () {
    $this->artisan('system:heartbeat')->assertSuccessful();

    expect(Setting::read(RecordHeartbeat::KEY))->not->toBeNull()
        ->and(RecordHeartbeat::secondsSinceLastRun())->toBeLessThan(5);
});

test('the settings screen says when the scheduler last ran', function () {
    actingAsAdmin();
    $this->artisan('system:heartbeat');

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scheduler.healthy', true)
            ->where('scheduler.seconds_ago', 0));
});

test('a scheduler that has stopped is called out, not glossed over', function () {
    actingAsAdmin();

    // A tick from an hour ago: the container is up, the scheduler is not.
    Setting::put(RecordHeartbeat::KEY, now()->subHour()->toIso8601String(), 'system');

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('scheduler.healthy', false));
});

test('a marketplace that has never ticked is not called healthy', function () {
    actingAsAdmin();

    $this->get(route('admin.settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('scheduler.healthy', false)
            ->where('scheduler.seconds_ago', null));
});
