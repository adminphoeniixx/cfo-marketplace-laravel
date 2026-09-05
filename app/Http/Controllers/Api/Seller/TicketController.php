<?php

namespace App\Http\Controllers\Api\Seller;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\TicketAnswered;
use App\Notifications\TicketRaised;
use App\Notifications\TicketReplied;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A store's two piles of post.
 *
 * **Incoming** is shoppers writing to this store — where is my parcel, does
 * this run small — which the seller answers. **Outgoing** is this store
 * writing to the marketplace, which until now it had no way to do at all: a
 * payout that had not landed or a listing wrongly taken down meant finding
 * somebody's email address.
 *
 * One endpoint, one `direction` filter, because to a seller these are two
 * columns of the same screen.
 *
 * Everything is scoped to the token's own store. A shopper's name and message
 * are visible to the store they wrote to and to nobody else.
 */
class TicketController extends Controller
{
    use ScopesToStore;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['nullable', Rule::in(['incoming', 'outgoing'])],
            'filter' => ['nullable', Rule::in(['open', 'all'])],
        ]);

        $storeId = $this->storeId($request);

        $tickets = Ticket::query()
            ->where('vendor_id', $storeId)
            ->when(($data['direction'] ?? null) === 'incoming',
                fn ($query) => $query->where('audience', 'vendor'))
            ->when(($data['direction'] ?? null) === 'outgoing',
                fn ($query) => $query->where('audience', 'marketplace'))
            ->when(($data['filter'] ?? null) === 'open', fn ($query) => $query->unfinished())
            ->with(['customer:id,first_name,last_name', 'order:id,number'])
            ->withCount(['messages' => fn ($query) => $query->visibleToCustomer()])
            ->orderByDesc('last_reply_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return response()->json(
            $tickets->through(fn (Ticket $ticket) => $this->shape($ticket))->toArray()
        );
    }

    /**
     * Write to the marketplace.
     *
     * Always addressed to the marketplace: a seller has no business opening a
     * ticket against a shopper, and nothing here lets them.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(array_keys(Ticket::CATEGORIES))],
        ]);

        $ticket = Ticket::create([
            'number' => Ticket::nextNumber(),
            'customer_id' => null,
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

        return response()->json(['data' => $this->shape($ticket->fresh(), withMessages: true)], 201);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return response()->json([
            'data' => $this->shape($this->findOwned($request, $number), withMessages: true),
        ]);
    }

    public function reply(Request $request, string $number): JsonResponse
    {
        $ticket = $this->findOwned($request, $number);

        if ($ticket->status === 'closed') {
            throw ValidationException::withMessages(['message' => 'This ticket is closed.']);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            // Answering a shopper usually finishes it. Only on the incoming
            // pile: a seller does not get to decide the marketplace is done.
            'resolve' => ['boolean'],
        ]);

        $ticket->addMessage($data['message'], staff: $request->user());

        if ($ticket->audience === 'vendor') {
            if ($data['resolve'] ?? false) {
                $ticket->forceFill(['status' => 'resolved'])->save();
            }

            // The shopper hears from the store, in the app and by email.
            $ticket->customer?->notify(new TicketAnswered($ticket->fresh(), $data['message']));
        } else {
            // Writing back to the marketplace on something staff had called
            // resolved has just reopened it.
            Notifier::send(new TicketReplied($ticket->fresh()->load(['vendor:id,name'])), $request->user());
        }

        return response()->json(['data' => $this->shape($ticket->fresh(), withMessages: true)]);
    }

    protected function findOwned(Request $request, string $number): Ticket
    {
        return Ticket::query()
            ->where('vendor_id', $this->storeId($request))
            ->where('number', mb_strtoupper($number))
            ->with(['customer:id,first_name,last_name', 'order:id,number'])
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function shape(Ticket $ticket, bool $withMessages = false): array
    {
        $incoming = $ticket->audience === 'vendor';

        $shape = [
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'category_label' => Ticket::CATEGORIES[$ticket->category] ?? $ticket->category,
            'status' => $ticket->status,
            // Read from this store's side of the desk: "open" on an incoming
            // ticket means the seller owes an answer, and on an outgoing one
            // means they are still waiting for the marketplace.
            'status_label' => match (true) {
                $ticket->status === 'open' && $incoming => 'Waiting for your reply',
                $ticket->status === 'open' => 'With the marketplace',
                $ticket->status === 'pending' && $incoming => 'Waiting for the shopper',
                $ticket->status === 'pending' => 'Waiting for your reply',
                $ticket->status === 'resolved' => 'Resolved',
                default => 'Closed',
            },
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'from' => $incoming ? $ticket->customerName('A shopper') : 'Your store',
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
            // A seller reads what the shopper reads. Marketplace staff talking
            // among themselves is not a store's business either.
            ->visibleToCustomer()
            ->with(['customer:id,first_name', 'user:id,name,role,vendor_id'])
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
