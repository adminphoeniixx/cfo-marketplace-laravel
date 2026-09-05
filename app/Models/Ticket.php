<?php

namespace App\Models;

use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Vendor|null $vendor
 * @property User|null $opener
 *
 * The relations are genuinely nullable — a ticket need not be about an order
 * and need not be assigned — which the generic relation types do not say.
 * @property Customer|null $customer
 * @property Order|null $order
 * @property User|null $assignee
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $first_responded_at
 * @property Carbon|null $closed_at
 *
 * One conversation between a shopper and the support desk.
 *
 * `status` is about who the ticket is waiting on, not how anybody feels about
 * it: `open` means support owes a reply, `pending` means the shopper does,
 * `resolved` means support believes it is done and `closed` means it is. That
 * distinction is the whole value of the column — a queue sorted by "who is
 * blocked" is a queue somebody can work.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public const STATUSES = ['open', 'pending', 'resolved', 'closed'];

    /**
     * Who has to answer.
     *
     * A shopper asking where their parcel is wants the *seller*; a shopper
     * asking why a refund has not landed wants the marketplace. Routing that
     * at the moment it is written is the difference between an answer and a
     * ticket forwarded twice.
     */
    public const AUDIENCES = ['vendor', 'marketplace'];

    /** Which side started it. */
    public const OPENED_BY = ['customer', 'vendor', 'staff'];

    /**
     * What the shopper is writing in about, in their own words.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'order' => 'A problem with an order',
        'delivery' => 'Delivery or tracking',
        'return' => 'Return or refund',
        'payment' => 'Payment or refund not received',
        'product' => 'A question about a product',
        'account' => 'My account',
        'other' => 'Something else',
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('id') ?? 0;

        return 'TKT-'.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The store involved: the one being written to where `audience` is
     * `vendor`, and the author's own where a seller wrote to the marketplace.
     *
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The shopper's name, or a stand-in.
     *
     * `customer_id` is required, so there is always a row — but customers are
     * soft-deleted, and a closed account makes the relation resolve to null
     * while the ticket, and the conversation on it, stay perfectly readable.
     * Read through the trash, because who wrote in is a fact that does not
     * stop being true when they leave.
     */
    public function customerName(string $fallback = 'A shopper', string $column = 'first_name'): string
    {
        // A seller writing to the marketplace has no shopper in the
        // conversation at all, so there is nothing to look up.
        if ($this->customer_id === null) {
            return $fallback;
        }

        $name = Customer::withTrashed()->whereKey($this->customer_id)->value($column);

        return is_string($name) && $name !== '' ? $name : $fallback;
    }

    /**
     * Who opened it, however they signed in.
     */
    public function openerName(): string
    {
        if ($this->opened_by === 'vendor') {
            return (string) (Vendor::whereKey($this->vendor_id)->value('name') ?: 'A seller');
        }

        return $this->customerName();
    }

    /**
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    /**
     * Everything still on somebody's desk.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUnfinished(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'pending']);
    }

    /**
     * Addressed to the marketplace — from a shopper or from one of its sellers.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForMarketplace(Builder $query): Builder
    {
        return $query->where('audience', 'marketplace');
    }

    /**
     * Addressed to one store, which is the only pile that store may read.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('audience', 'vendor')->where('vendor_id', $vendorId);
    }

    /** Raised by a store, and waiting on the marketplace. */
    public function isFromVendor(): bool
    {
        return $this->opened_by === 'vendor';
    }

    /** Who is expected to answer, in words. */
    public function audienceLabel(): string
    {
        return $this->audience === 'vendor' ? 'The seller' : 'The marketplace';
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['resolved', 'closed'], true);
    }

    /**
     * How long the shopper waited for a human, in hours. Null while nobody has
     * answered — which is not zero, and must not be shown as it.
     */
    public function hoursToFirstReply(): ?float
    {
        return $this->first_responded_at && $this->created_at
            ? round($this->created_at->diffInMinutes($this->first_responded_at, absolute: true) / 60, 1)
            : null;
    }

    /**
     * Record a message and move the ticket to whoever it now waits on.
     *
     * Kept here rather than in two controllers, because the bookkeeping is the
     * easy thing to get subtly different: a staff reply must stamp the first
     * response only once, and a shopper writing back must reopen a ticket
     * somebody had already called resolved.
     */
    public function addMessage(string $body, ?Customer $from = null, ?User $staff = null, bool $internal = false): TicketMessage
    {
        // "Answering" means the side the ticket is addressed to. A seller
        // replying to a shopper is answering; a seller writing to the
        // marketplace is asking, even though both are `User` logins.
        $isAnswer = $staff !== null && ! ($this->isFromVendor() && $staff->isVendor());

        $message = $this->messages()->create([
            'customer_id' => $from?->id,
            'user_id' => $staff?->id,
            'body' => $body,
            'is_internal' => $internal,
        ]);

        // An internal note is staff talking among themselves. It moves
        // nothing: the shopper is still waiting exactly as long as they were.
        if ($internal) {
            return $message;
        }

        $this->forceFill([
            'last_reply_at' => now(),
            'status' => $isAnswer ? 'pending' : 'open',
            'first_responded_at' => $isAnswer
                ? ($this->first_responded_at ?? now())
                : $this->first_responded_at,
            // Whoever raised it writing back on something already answered has
            // not finished with it, whatever the other side decided.
            'closed_at' => $isAnswer ? $this->closed_at : null,
        ])->save();

        return $message;
    }
}
