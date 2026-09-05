<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Proof that the scheduler is alive.
 *
 * Everything that happens on its own here — following parcels with the courier
 * — happens because a supercronic process inside the web container runs
 * `schedule:run` every minute. Whether it is actually doing so has until now
 * only been answerable by reading a Dockerfile and believing it, and that
 * belief has already been wrong once.
 *
 * This stamps the time on every tick. A dashboard, a person or a monitor can
 * then ask the one question that matters — when did the scheduler last run —
 * and get an answer rather than an inference.
 */
class RecordHeartbeat extends Command
{
    protected $signature = 'system:heartbeat';

    protected $description = 'Record that the scheduler ran, so somebody can check that it is';

    /** Where the timestamp lives, and what reads it. */
    public const KEY = 'scheduler_last_run_at';

    public function handle(): int
    {
        Setting::put(self::KEY, now()->toIso8601String(), 'system');

        return self::SUCCESS;
    }

    /**
     * How long ago the scheduler last ran, in seconds. Null where it never has.
     */
    public static function secondsSinceLastRun(): ?int
    {
        $at = Setting::read(self::KEY);

        return is_string($at) && $at !== ''
            // Cast, because `diffInSeconds` answers a float and a fraction of
            // a second is not a thing anybody wants to read on a dashboard.
            ? (int) now()->diffInSeconds(Carbon::parse($at), absolute: true)
            : null;
    }
}
