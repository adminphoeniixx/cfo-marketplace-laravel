<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| A tick, once a minute, so "is the scheduler running in production?" is a
| question with an answer. Everything else below only runs because this does.
*/
Schedule::command('system:heartbeat')->everyMinute()->withoutOverlapping();

/*
| Where every parcel has got to.
|
| Every fifteen minutes is often enough that a shopper opening the app sees
| something recent, and rare enough that a hundred parcels in flight is four
| hundred requests an hour rather than six thousand. It exits immediately when
| no courier is configured, so this costs nothing until one is.
*/
Schedule::command('shipments:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
