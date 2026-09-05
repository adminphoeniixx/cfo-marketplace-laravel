<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\TicketRaised;
use App\Notifications\TicketReplied;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "I have a problem", with somewhere for it to go.
 *
 * The help screen could offer answers, a phone number and a chat link, and
 * that was support: anything an FAQ did not cover became a call nobody wrote
 * down. A ticket is the written record, and the shopper can see it move.
 *
 * Internal notes never leave the panel — every read here filters them out, in
 * the query rather than in a resource, so there is no shape of this endpoint
 * that could return one by accident.
 */
class TicketController extends Controller
{
    use ScopesToCustomer;

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $tickets = Ticket::query()
            ->where('customer_id', $customer->id)
            ->when($request->string('filter')->toString() === 'open',
                fn ($query) => $query->unfinished())
            ->with(['order:id,number'])
            ->withCount(['messages' => fn ($query) => $query->visibleToCustomer()])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json(
            $tickets->through(fn (Ticket $ticket) => $this->shape($ticket))->toArray()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_keys(Ticket::CATEGORIES))],
            // By number, because that is what the shopper has in front of
            // them; scoped below, so naming somebody else's order finds
            // nothing rather than attaching to it.
            'order_number' => ['nullable', 'string', 'max:20'],
        ]);

        $order = null;

        if ($number = $data['order_number'] ?? null) {
            $order = $customer->orders()
                ->where('number', $this->normaliseNumber($number))
                ->first();

            if (! $order) {
                throw ValidationException::withMessages([
                    'order_number' => 'That order number is not one of yours.',
                ]);
            }
        }

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'customer_id' => $customer->id,
            'order_id' => $order?->id,
            'subject' => $data['subject'],
            'category' => $data['category'],
            'status' => 'open',
            'priority' => 'normal',
            'last_reply_at' => now(),
        ]);

        $ticket->addMessage($data['message'], from: $customer);

        Notifier::send(new TicketRaised($ticket->load(['customer', 'order:id,number'])));

        return response()->json(['data' => $this->shape($ticket->fresh(), withMessages: true)], 201);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return response()->json(['data' => $this->shape($this->findOwned($request, $number), withMessages: true)]);
    }

    public function reply(Request $request, string $number): JsonResponse
    {
        $customer = $this->customer($request);
        $ticket = $this->findOwned($request, $number);

        if ($ticket->status === 'closed') {
            throw ValidationException::withMessages([
                'message' => 'This ticket is closed. Open a new one and we will pick it up from there.',
            ]);
        }

        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);

        $ticket->addMessage($data['message'], from: $customer);

        // Worth telling staff about: a reply on something they had already
        // called resolved has just reopened it.
        Notifier::send(new TicketReplied($ticket->fresh()->load(['customer', 'order:id,number'])));

        return response()->json(['data' => $this->shape($ticket->fresh(), withMessages: true)]);
    }

    /**
     * The shopper says they are done, whatever support thinks.
     */
    public function close(Request $request, string $number): JsonResponse
    {
        $ticket = $this->findOwned($request, $number);

        $ticket->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

        return response()->json(['data' => $this->shape($ticket->fresh())]);
    }

    protected function findOwned(Request $request, string $number): Ticket
    {
        return Ticket::query()
            ->where('customer_id', $this->customer($request)->id)
            ->where('number', mb_strtoupper($number))
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function shape(Ticket $ticket, bool $withMessages = false): array
    {
        $shape = [
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'category_label' => Ticket::CATEGORIES[$ticket->category] ?? $ticket->category,
            'status' => $ticket->status,
            // What the shopper should read into it, which is not the same as
            // the word support files it under.
            'status_label' => match ($ticket->status) {
                'open' => 'We are looking into it',
                'pending' => 'Waiting for your reply',
                'resolved' => 'Resolved',
                default => 'Closed',
            },
            'order_number' => $ticket->relationLoaded('order') ? $ticket->order?->number : null,
            'messages_count' => (int) ($ticket->messages_count ?? 0),
            'can_reply' => $ticket->status !== 'closed',
            'created_at' => $ticket->created_at?->toIso8601String(),
            'last_reply_at' => $ticket->last_reply_at?->toIso8601String(),
        ];

        if (! $withMessages) {
            return $shape;
        }

        return [...$shape, 'messages' => $ticket->messages()
            ->visibleToCustomer()
            ->with('customer:id,first_name')
            ->get()
            ->map(fn (TicketMessage $message) => [
                'id' => $message->id,
                'body' => $message->body,
                'author' => $message->authorName(),
                'author_type' => $message->authorType(),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all()];
    }
}
