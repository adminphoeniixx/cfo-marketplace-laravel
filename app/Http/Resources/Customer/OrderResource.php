<?php

namespace App\Http\Resources\Customer;

use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * An order as the person who placed it sees it.
 *
 * The mirror image of the seller's resource: there, the money is one store's
 * share and the lines are one store's lines. Here it is the whole basket, and
 * commission — which is between the marketplace and the seller — never appears.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();
        $vendors = Vendor::whereIn('id', $items->pluck('vendor_id')->filter()->unique())
            ->get(['id', 'name', 'city', 'phone', 'store_email'])
            ->keyBy('id');

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'payment_method' => $this->payment_method,
            'payment_icon' => PaymentMethod::iconForName($this->payment_method),
            // The receipt number the payment card shows under the total. Null
            // on cash on delivery, and until a gateway actually captures.
            'transaction_id' => $this->transaction_id,
            // True while the app still has to open a gateway for this order.
            'payment_required' => $this->awaitsPayment(),
            'shipping_method' => $this->shipping_method,
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => DeliveryPartner::trackingUrlFrom($this->carrier, $this->tracking_number),
            'coupon_code' => $this->coupon_code,
            'customer_note' => $this->customer_note,
            'shipping_address' => $this->shipping_address,
            'totals' => [
                'subtotal' => (float) $this->subtotal,
                'discount_total' => (float) $this->discount_total,
                'tax_total' => (float) $this->tax_total,
                'shipping_total' => (float) $this->shipping_total,
                'grand_total' => (float) $this->grand_total,
                'refunded_total' => (float) $this->refunded_total,
            ],
            'items' => OrderItemResource::collection($items),
            // Two sellers means two parcels; the app says so on the order page.
            'sellers' => $items->pluck('vendor_id')->filter()->unique()->values()
                ->map(fn ($id) => [
                    'id' => (int) $id,
                    'name' => $vendors[$id]->name ?? 'Seller',
                    'city' => $vendors[$id]->city ?? null,
                ])->all(),
            // Display-ready, from the window frozen at checkout: "Arriving
            // 4–6 Sep", "Delivered 2 Sep", or null once it is called off.
            'eta' => $this->etaLabel(),
            'invoice_url' => $this->invoiceUrl(),
            'help' => $this->help($vendors, $items),
            'can_cancel' => $this->canBeCancelledByCustomer(),
            'can_return' => $this->canBeReturnedByCustomer(),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'timeline' => $this->when($this->relationLoaded('events'), fn () => $this->timeline()),
        ];
    }

    /**
     * A signed, time-limited link to this order's invoice.
     *
     * Signed rather than token-authenticated so it survives being handed to a
     * download manager or a PDF viewer, neither of which carries the app's
     * bearer token.
     */
    protected function invoiceUrl(): string
    {
        return URL::temporarySignedRoute(
            'api.customer.invoices.show',
            now()->addDays(7),
            ['order' => $this->id],
        );
    }

    /**
     * Who to contact about this order.
     *
     * A list of sellers, never one: a basket across two stores is normal here,
     * so "the seller" of an order does not exist. `seller_phone` is filled in
     * only where there is exactly one of them and the row is unambiguous —
     * otherwise the app has the list and asks.
     *
     * @param  Collection<int, Vendor>  $vendors
     * @param  Collection<int, OrderItem>  $items
     * @return array<string, mixed>
     */
    protected function help(Collection $vendors, Collection $items): array
    {
        $sellers = $items->pluck('vendor_id')->filter()->unique()->values()
            ->map(fn ($id) => [
                'id' => (int) $id,
                'name' => $vendors[$id]->name ?? 'Seller',
                'phone' => $vendors[$id]->phone ?? null,
                'email' => $vendors[$id]->store_email ?? null,
            ])
            ->all();

        return [
            'sellers' => $sellers,
            'seller_phone' => count($sellers) === 1 ? $sellers[0]['phone'] : null,
            'support_email' => Setting::cached('store_email') ?: null,
            'support_phone' => Setting::cached('store_phone') ?: null,
            // Whatever the marketplace actually answers on, set in the admin
            // panel. Null means the app should fall back to its own help page.
            'chat_url' => Setting::cached('support_chat_url') ?: null,
        ];
    }

    /**
     * The order's own history, in the shopper's words.
     *
     * Staff and seller notes are dropped: an event without a customer-facing
     * type is internal, and the person who bought the thing has no business
     * reading it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function timeline(): array
    {
        return $this->events
            ->whereIn('type', ['status', 'payment', 'shipment', 'cancellation', 'refund'])
            ->sortBy('created_at')
            ->values()
            ->map(fn ($event) => [
                'type' => $event->type,
                'title' => $event->title,
                'at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
