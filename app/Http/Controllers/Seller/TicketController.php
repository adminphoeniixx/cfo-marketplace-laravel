<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Api\Seller\ScopesToStore;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\TicketAnswered;
use App\Notifications\TicketRaised;
use App\Notifications\TicketReplied;
use App\Services\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The seller panel's half of the ticket desk.
 *
 * The web twin of the token API: same two piles — shoppers writing to this
 * store, and this store writing to the marketplace — same scoping, and the
 * same rule that a seller never sees marketplace staff talking among
 * themselves.
 */
class TicketController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in(['incoming', 'outgoing'])],
            'status' => ['nullable', Rule::in(Ticket::STATUSES)],
        ]);

        $storeId = $this->storeId($request);
        $direction = $filters['direction'] ?? 'incoming';

        $tickets = Ticket::query()
            ->where('vendor_id', $storeId)
            ->where('audience', $direction === 'incoming' ? 'vendor' : 'marketplace')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with(['customer:id,first_name,last_name', 'order:id,number'])
            // Oldest first among the unfinished: whoever has waited longest is
            // the next to hear back.
            ->orderByRaw("case when status in ('open','pending') then 0 else 1 end")
            ->orderBy('last_reply_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('seller/tickets/Index', [
            'tickets' => $tickets->through(fn (Ticket $ticket) => $this->row($ticket)),
            'filters' => [...$filters, 'direction' => $direction],
            'categories' => Ticket::CATEGORIES,
            'statuses' => Ticket::STATUSES,
            'summary' => [
                // What this store owes an answer on.
                'incoming_open' => Ticket::forVendor($storeId)->where('status', 'open')->count(),
                // What it is waiting on the marketplace for.
                'outgoing_open' => Ticket::where('vendor_id', $storeId)
                    ->where('audience', 'marketplace')->unfinished()->count(),
            ],
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        abort_unless($ticket->vendor_id === $this->storeId($request), 404);

        $ticket->load([
            'customer:id,first_name,last_name',
            'order:id,number,status,grand_total,placed_at',
            'messages.customer:id,first_name',
            'messages.user:id,name,role,vendor_id',
        ]);

        return Inertia::render('seller/tickets/Show', [
            'ticket' => [
                ...$this->row($ticket),
                'order' => $ticket->order ? [
                    'id' => $ticket->order->id,
                    'number' => $ticket->order->number,
                    'status' => $ticket->order->status,
                    'grand_total' => (float) $ticket->order->grand_total,
                ] : null,
                'messages' => $ticket->messages
                    // A seller reads what the shopper reads.
                    ->where('is_internal', false)
                    ->map(fn (TicketMessage $message) => [
                        'id' => $message->id,
                        'body' => $message->body,
                        'author' => $message->authorName(),
                        'author_type' => $message->authorType(),
                        'created_at' => $message->created_at?->toIso8601String(),
                    ])->values(),
            ],
        ]);
    }

    /**
     * Write to the marketplace. Never to a shopper — a store answers those,
     * it does not open them.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_keys(Ticket::CATEGORIES))],
        ]);

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'audience' => 'marketplace',
            'vendor_id' => $this->storeId($request),
            'opened_by' => 'vendor',
            'opened_by_user_id' => $request->user()->id,
            'subject' => $data['subject'],
            'category' => $data['category'],
            'status' => 'open',
            'priority' => 'normal',
            'last_reply_at' => now(),
        ]);

        $ticket->addMessage($data['message'], staff: $request->user());

        Notifier::send(new TicketRaised($ticket->load(['vendor:id,name'])), $request->user());

        return redirect()
            ->route('seller.tickets.show', $ticket->id)
            ->with('success', "Sent to the marketplace as {$ticket->number}.");
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($ticket->vendor_id === $this->storeId($request), 404);
        abort_if($ticket->status === 'closed', 422, 'This ticket is closed.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'resolve' => ['boolean'],
        ]);

        $ticket->addMessage($data['body'], staff: $request->user());

        if ($ticket->audience === 'vendor') {
            if ($data['resolve'] ?? false) {
                $ticket->forceFill(['status' => 'resolved'])->save();
            }

            $ticket->customer?->notify(new TicketAnswered($ticket->fresh(), $data['body']));

            return back()->with('success', 'Reply sent to the shopper.');
        }

        Notifier::send(new TicketReplied($ticket->fresh()->load(['vendor:id,name'])), $request->user());

        return back()->with('success', 'Sent to the marketplace.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Ticket $ticket): array
    {
        $incoming = $ticket->audience === 'vendor';

        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'category_label' => Ticket::CATEGORIES[$ticket->category] ?? $ticket->category,
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'from' => $incoming ? $ticket->customerName('A shopper') : 'Your store',
            'order_number' => $ticket->relationLoaded('order') ? $ticket->order?->number : null,
            'can_reply' => $ticket->status !== 'closed',
            'waiting_hours' => $ticket->last_reply_at
                ? round(now()->diffInMinutes($ticket->last_reply_at, absolute: true) / 60, 1)
                : null,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
        ];
    }
}
