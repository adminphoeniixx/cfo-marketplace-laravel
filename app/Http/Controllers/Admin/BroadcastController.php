<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendDealBroadcast;
use App\Models\Customer;
use App\Models\PushBroadcast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writing to every shopper at once.
 *
 * The only screen in the panel that speaks to shoppers unprompted, and the
 * reason it is deliberately plain: no scheduling, no segments, no templates.
 * Somebody types a line, sees how many phones it is about to reach, and sends
 * it. Everything else here is a consequence of an order; this is not, so it
 * keeps a record of who sent what.
 */
class BroadcastController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/broadcasts/Index', [
            'broadcasts' => PushBroadcast::with('sender:id,name')
                ->latest('id')
                ->paginate(20)
                ->through(fn (PushBroadcast $b) => [
                    'id' => $b->id,
                    'heading' => $b->heading,
                    'message' => $b->message,
                    'link' => $b->link,
                    'recipients' => $b->recipients,
                    'sender' => $b->sender?->name,
                    'created_at' => $b->created_at?->toIso8601String(),
                ]),
            'reach' => $this->reach(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Both are what a phone actually draws on a lock screen, so both
            // are capped near what a phone will actually show.
            'heading' => ['required', 'string', 'max:60'],
            'message' => ['required', 'string', 'max:180'],
            // An in-app route, not a URL: handing the app an https link would
            // bounce the shopper out to a browser.
            'link' => ['nullable', 'string', 'max:180', 'regex:/^\//'],
        ]);

        $broadcast = PushBroadcast::create([
            ...$data,
            'user_id' => $request->user()->getKey(),
            'recipients' => $this->reach(),
        ]);

        SendDealBroadcast::dispatch($broadcast->id);

        return back()->with(
            'success',
            "Sending to {$broadcast->recipients} shopper".($broadcast->recipients === 1 ? '' : 's').'.',
        );
    }

    /**
     * How many shoppers this would reach right now.
     *
     * Deliberately counts the ones who asked for deals rather than the ones
     * with a device registered: somebody with push off still gets the row in
     * their feed, and telling the sender otherwise would understate it.
     */
    private function reach(): int
    {
        return Customer::query()
            ->where('status', 'active')
            ->where('notify_deals', true)
            ->count();
    }
}
