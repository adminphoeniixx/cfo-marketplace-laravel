<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketAnswered;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The support desk.
 *
 * The list is sorted by who has been waiting longest rather than by newest,
 * because a queue answered newest-first is a queue where somebody waits for
 * ever. Everything else here is bookkeeping the `Ticket` model owns — this
 * decides who may act, and says what happened.
 */
class TicketController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(Ticket::STATUSES)],
            'category' => ['nullable', Rule::in(array_keys(Ticket::CATEGORIES))],
            'assigned' => ['nullable', 'string', 'max:20'],
        ]);

        $tickets = Ticket::query()
            ->when($filters['search'] ?? null, fn ($query, string $term) => $query
                ->where(fn ($inner) => $inner
                    ->whereLike('number', "%{$term}%", caseSensitive: false)
                    ->orWhereLike('subject', "%{$term}%", caseSensitive: false)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn ($query, string $c) => $query->where('category', $c))
            ->when(($filters['assigned'] ?? null) === 'me',
                fn ($query) => $query->where('assigned_to', $request->user()->id))
            ->when(($filters['assigned'] ?? null) === 'nobody',
                fn ($query) => $query->whereNull('assigned_to'))
            ->with(['customer:id,first_name,last_name,email', 'assignee:id,name', 'order:id,number'])
            // Oldest reply first among the unfinished: the person who has been
            // waiting longest is the one to answer next.
            ->orderByRaw("case when status in ('open','pending') then 0 else 1 end")
            ->orderBy('last_reply_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/tickets/Index', [
            'tickets' => $tickets->through(fn (Ticket $ticket) => $this->row($ticket)),
            'filters' => $filters,
            'categories' => Ticket::CATEGORIES,
            'statuses' => Ticket::STATUSES,
            'summary' => [
                'open' => Ticket::where('status', 'open')->count(),
                'pending' => Ticket::where('status', 'pending')->count(),
                'unassigned' => Ticket::unfinished()->whereNull('assigned_to')->count(),
                // The one number a support lead actually manages to.
                'oldest_waiting_hours' => $this->oldestWaitingHours(),
            ],
        ]);
    }

    public function show(Ticket $ticket): Response
    {
        $ticket->load([
            'customer:id,first_name,last_name,email,phone,orders_count,total_spent',
            'assignee:id,name',
            'order:id,number,status,grand_total,placed_at',
            'messages.customer:id,first_name',
            'messages.user:id,name',
        ]);

        return Inertia::render('admin/tickets/Show', [
            'ticket' => [
                ...$this->row($ticket),
                'hours_to_first_reply' => $ticket->hoursToFirstReply(),
                'order' => $ticket->order ? [
                    'id' => $ticket->order->id,
                    'number' => $ticket->order->number,
                    'status' => $ticket->order->status,
                    'grand_total' => (float) $ticket->order->grand_total,
                    'placed_at' => $ticket->order->placed_at?->toIso8601String(),
                ] : null,
                'messages' => $ticket->messages->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'body' => $message->body,
                    // Staff see the real name; the shopper only ever sees
                    // "Support", which is what the customer API returns.
                    'author' => match (true) {
                        $message->user_id !== null => $message->user->name,
                        $message->customer_id !== null => $message->customer->first_name,
                        default => 'Shopper',
                    },
                    'author_type' => $message->authorType(),
                    'is_internal' => $message->is_internal,
                    'created_at' => $message->created_at?->toIso8601String(),
                ])->values(),
            ],
            'categories' => Ticket::CATEGORIES,
            'statuses' => Ticket::STATUSES,
            'priorities' => Ticket::PRIORITIES,
            // Only people who can actually open the ticket may be handed it.
            'assignees' => User::where('is_active', true)
                ->get(['id', 'name', 'role'])
                ->filter(fn (User $user) => Roles::allows($user, 'tickets') && ! $user->isVendor())
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name])
                ->values(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['boolean'],
            // Answering usually means it is done; saying so in the same action
            // is one less thing to forget.
            'resolve' => ['boolean'],
        ]);

        $internal = (bool) ($data['is_internal'] ?? false);

        $ticket->addMessage($data['body'], staff: $request->user(), internal: $internal);

        if (! $internal && ($data['resolve'] ?? false)) {
            $ticket->forceFill(['status' => 'resolved'])->save();
        }

        if (! $internal) {
            // Told to the shopper, in the app and by email — somebody who
            // wrote in on Monday is not holding the app open on Tuesday.
            $ticket->customer?->notify(new TicketAnswered($ticket->fresh(), $data['body']));
        }

        return back()->with('success', $internal ? 'Note added.' : 'Reply sent.');
    }

    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Ticket::STATUSES)],
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        if (($data['status'] ?? null) === 'closed') {
            $data['closed_at'] = now();
        }

        // Reopening has to clear the stamp, or a ticket closed in March still
        // reads as closed in March while somebody is actively working it.
        if (isset($data['status']) && ! in_array($data['status'], ['closed', 'resolved'], true)) {
            $data['closed_at'] = null;
        }

        $ticket->forceFill($data)->save();

        return back()->with('success', 'Ticket updated.');
    }

    /**
     * How long the shopper at the front of the queue has been waiting.
     */
    protected function oldestWaitingHours(): ?float
    {
        $oldest = Ticket::where('status', 'open')->orderBy('last_reply_at')->value('last_reply_at');

        return $oldest
            ? round(now()->diffInMinutes($oldest, absolute: true) / 60, 1)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'category' => $ticket->category,
            'category_label' => Ticket::CATEGORIES[$ticket->category] ?? $ticket->category,
            'customer' => $ticket->relationLoaded('customer') && $ticket->customer ? [
                'id' => $ticket->customer->id,
                'name' => $ticket->customer->name,
                'email' => $ticket->customer->email,
                'phone' => $ticket->customer->phone ?? null,
                'orders_count' => (int) ($ticket->customer->orders_count ?? 0),
                'total_spent' => (float) ($ticket->customer->total_spent ?? 0),
            ] : null,
            'order_number' => $ticket->relationLoaded('order') ? $ticket->order?->number : null,
            'assignee' => $ticket->relationLoaded('assignee') && $ticket->assignee
                ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name]
                : null,
            'assigned_to' => $ticket->assigned_to,
            // Hours since the last message, which is how long somebody has
            // been waiting on an open ticket.
            'waiting_hours' => $ticket->last_reply_at
                ? round(now()->diffInMinutes($ticket->last_reply_at, absolute: true) / 60, 1)
                : null,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
        ];
    }
}
