<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Carbon|null $date_of_birth
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_order_at
 *
 * A shopper.
 *
 * Staff have always been able to create these from the panel; since the
 * shopper app they can also sign in as one. That is why this extends
 * Authenticatable — the token on a customer request belongs to *this* model,
 * never to a `User`, and every customer endpoint reads its subject from the
 * token rather than from the payload.
 */
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'total_spent' => 'decimal:2',
            'tags' => 'array',
            'accepts_marketing' => 'boolean',
            'push_enabled' => 'boolean',
            'notify_order_updates' => 'boolean',
            'sms_order_updates' => 'boolean',
            'notify_deals' => 'boolean',
            'email_verified' => 'boolean',
            'date_of_birth' => 'date',
            'last_order_at' => 'datetime',
            'phone_verified_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * Ways this shopper has saved to pay — not the marketplace's own list of
     * what it accepts, which is `PaymentMethod`.
     *
     * @return HasMany<CustomerPaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(CustomerPaymentMethod::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * @return HasMany<ProductReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * @return HasMany<Cancellation, $this>
     */
    public function cancellations(): HasMany
    {
        return $this->hasMany(Cancellation::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * Recalculate the cached order totals shown on customer lists and profiles.
     */
    public function refreshOrderStats(): void
    {
        $orders = $this->orders()->whereNot('status', 'cancelled')->get();

        $this->update([
            'orders_count' => $orders->count(),
            'total_spent' => round((float) $orders->sum('grand_total') - (float) $orders->sum('refunded_total'), 2),
            'last_order_at' => $orders->max('placed_at'),
        ]);
    }
}
