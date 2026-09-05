<?php

namespace App\Models;

use Database\Factories\TicketMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Exactly one author is set, so each of these is null on half the rows —
 * something the generic relation types do not say.
 *
 * @property Customer|null $customer
 * @property User|null $user
 *
 * One line in a support conversation.
 *
 * Exactly one of `customer_id` and `user_id` is set, and which one is what
 * "who said this" means — there is no author column to disagree with them.
 */
class TicketMessage extends Model
{
    /** @use HasFactory<TicketMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the shopper is allowed to read: everything except staff talking
     * among themselves.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * Who wrote it, as the other side should see it.
     *
     * Never a staff member's own name: whoever writes in is dealing with a
     * store or with the marketplace, and a first name here is a detail nobody
     * needs and support cannot take back. A seller answering a shopper is
     * named, though — the shopper bought from them and knows who they are.
     */
    public function authorName(): string
    {
        if ($this->user_id !== null) {
            return $this->user->isVendor()
                ? (string) ($this->user->vendor?->name ?: 'The seller')
                : 'Support';
        }

        // Branching on the id rather than the relation: `nullOnDelete` means
        // a removed shopper leaves the column null, so the column is the
        // honest test of whether there is a name to read.
        return $this->customer_id !== null ? $this->customer->first_name : 'You';
    }

    public function authorType(): string
    {
        if ($this->user_id === null) {
            return 'customer';
        }

        return $this->user->isVendor() ? 'vendor' : 'support';
    }
}
