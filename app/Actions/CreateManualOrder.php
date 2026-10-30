<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\LowStockReached;
use App\Notifications\OrderPlaced;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Raising an order by hand, for a single store.
 *
 * Three callers share this: the marketplace panel (an admin picking any
 * vendor), the seller panel, and the seller API. Keeping the maths in one
 * place is the point — commission, tax and stock all have to come out the same
 * whichever door the order came in through, or a seller's earnings depend on
 * which app the support agent happened to use.
 *
 * The store is always passed in, never read from the payload. Callers decide
 * whose order this is; this decides what it costs.
 */
class CreateManualOrder
{
    /**
     * Rules every caller shares. `vendor_id` is deliberately absent — a seller
     * client has no business sending one, and the admin panel adds its own.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::in(Order::STATUSES)],
            'payment_status' => ['required', Rule::in(Order::PAYMENT_STATUSES)],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'shipping_method' => ['nullable', 'string', 'max:60'],
            'shipping_total' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'discount_total' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'coupon_code' => ['nullable', 'string', 'max:60'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'admin_note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ];
    }

    /**
     * Active products a vendor can be billed for, with everything the order
     * form and the totals maths need.
     *
     * @return Collection<int, Product>
     */
    public static function sellableProducts(?int $vendorId): Collection
    {
        if (! $vendorId) {
            /** @var Collection<int, Product> */
            return Product::query()->whereRaw('1 = 0')->get();
        }

        return Product::query()
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('position')])
            ->with(['images' => fn ($q) => $q->orderBy('position')])
            ->with('taxClass.rates')
            ->where('vendor_id', $vendorId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * The tax percentage attached to a product's tax class. Falls back to zero
     * when the product has no class or the class has no active rate.
     */
    public static function taxRateFor(Product $product): float
    {
        $rate = $product->taxClass?->rates
            ->where('is_active', true)
            ->sortBy('priority')
            ->first();

        return (float) ($rate->rate ?? 0);
    }

    /**
     * What the store is paid for one unit, when the store has said: the
     * variant's own cost, else the product's. Null for a product from before
     * sellers named a cost, which `splitFor()` charges by commission instead.
     */
    public static function unitCostFor(Product $product, ?ProductVariant $variant): ?float
    {
        // `->` rather than `?->`: on the left of `??` PHP already reads the
        // property with isset semantics, so a null variant falls through.
        $cost = $variant->cost_price ?? $product->cost_price;

        return $cost === null ? null : (float) $cost;
    }

    /**
     * How one line's money divides between the marketplace and the store.
     *
     * Where the seller named a cost, the cost is what they are paid, whatever
     * the shopper paid: the marketplace sets the price, keeps the difference,
     * and so carries any coupon too. The difference is booked as commission,
     * which is what the commission invoice and every report already read.
     *
     * Without a cost, the store's commission rate comes off what was paid.
     *
     * @param  float  $paid  The line after its share of any discount, before tax.
     * @return array{commission_rate: float, commission_amount: float, vendor_earning: float}
     */
    public static function splitFor(float $paid, ?float $unitCost, int $quantity, float $commissionRate): array
    {
        if ($unitCost === null) {
            $commission = round($paid * $commissionRate / 100, 2);

            return [
                'commission_rate' => $commissionRate,
                'commission_amount' => $commission,
                'vendor_earning' => round($paid - $commission, 2),
            ];
        }

        $earning = round($unitCost * $quantity, 2);
        $margin = round($paid - $earning, 2);

        return [
            // The margin as a share of the sale, so a line reads the same way
            // under either model. Clamped to what the column holds: a line
            // given away for next to nothing has a meaningless percentage.
            'commission_rate' => $paid > 0 ? max(round($margin / $paid * 100, 2), -999999.0) : 0.0,
            'commission_amount' => $margin,
            'vendor_earning' => $earning,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  Validated against `rules()`.
     * @param  string  $source  Recorded on the order event, so the timeline
     *                          says which door the order came in through.
     *
     * @throws ValidationException
     */
    public function handle(array $data, Vendor $vendor, User $actor, string $source = 'admin'): Order
    {
        $lines = $this->resolveLines($data, $vendor);

        $order = DB::transaction(
            fn () => $this->persist($data, $lines, $vendor, $actor, $source)
        );

        $this->notify($order, $actor);

        return $order;
    }

    /**
     * Turn the payload's item rows into priced lines, refusing anything this
     * store cannot actually sell.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    protected function resolveLines(array $data, Vendor $vendor): array
    {
        $products = self::sellableProducts($vendor->id)->keyBy('id');
        $lines = [];

        foreach ($data['items'] as $index => $row) {
            $product = $products->get((int) $row['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => "That product is not sellable under {$vendor->name}.",
                ]);
            }

            $variant = isset($row['product_variant_id'])
                ? $product->variants->firstWhere('id', (int) $row['product_variant_id'])
                : null;

            if (isset($row['product_variant_id']) && ! $variant) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_variant_id" => 'That variant does not belong to the selected product.',
                ]);
            }

            $quantity = (int) $row['quantity'];

            if ($product->track_inventory && ! $product->allow_backorder) {
                $available = (int) ($variant->stock_quantity ?? $product->stock_quantity);

                if ($quantity > $available) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Only {$available} in stock for {$product->name}.",
                    ]);
                }
            }

            $lines[] = [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => isset($row['unit_price'])
                    ? (float) $row['unit_price']
                    : (float) ($variant->price ?? $product->price),
                'tax_rate' => self::taxRateFor($product),
            ];
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    protected function persist(array $data, array $lines, Vendor $vendor, User $actor, string $source): Order
    {
        $customer = isset($data['customer_id'])
            ? Customer::with('addresses')->find((int) $data['customer_id'])
            : null;

        $address = $customer?->addresses->first();

        $addressPayload = $address ? [
            'first_name' => $address->first_name,
            'last_name' => $address->last_name,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'country' => $address->country ?? 'IN',
            'phone' => $address->phone ?? $data['phone'] ?? null,
        ] : null;

        $order = Order::create([
            'number' => Order::nextNumber(),
            'customer_id' => $customer?->id,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? $customer?->phone,
            'status' => $data['status'],
            'payment_status' => $data['payment_status'],
            'currency' => 'INR',
            'payment_method' => $data['payment_method'] ?? null,
            'shipping_method' => $data['shipping_method'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
            'billing_address' => $addressPayload,
            'shipping_address' => $addressPayload,
            'customer_note' => $data['customer_note'] ?? null,
            'admin_note' => $data['admin_note'] ?? null,
            'placed_at' => now(),
            'paid_at' => $data['payment_status'] === 'paid' ? now() : null,
        ]);

        $subtotal = 0.0;
        $taxTotal = 0.0;
        $commissionTotal = 0.0;
        $commissionRate = (float) $vendor->commission_rate;

        foreach ($lines as $line) {
            /** @var Product $product */
            $product = $line['product'];
            $variant = $line['variant'];

            $lineTotal = round($line['unit_price'] * $line['quantity'], 2);
            $taxAmount = round($lineTotal * $line['tax_rate'] / 100, 2);
            $split = self::splitFor($lineTotal, self::unitCostFor($product, $variant), $line['quantity'], $commissionRate);

            $order->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'vendor_id' => $vendor->id,
                'name' => $product->name,
                'sku' => $variant->sku ?? $product->sku,
                'image_path' => $product->images->first()?->path,
                'options' => $variant?->name ? ['Variant' => $variant->name] : null,
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $taxAmount,
                'total' => $lineTotal,
                ...$split,
            ]);

            if ($product->track_inventory) {
                $variant
                    ? $variant->decrement('stock_quantity', $line['quantity'])
                    : $product->decrement('stock_quantity', $line['quantity']);
            }

            $subtotal += $lineTotal;
            $taxTotal += $taxAmount;
            $commissionTotal += $split['commission_amount'];
        }

        $shipping = round((float) ($data['shipping_total'] ?? 0), 2);
        $discount = round((float) ($data['discount_total'] ?? 0), 2);

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => $shipping,
            'discount_total' => $discount,
            'commission_total' => round($commissionTotal, 2),
            'grand_total' => round($subtotal + $taxTotal + $shipping - $discount, 2),
        ]);

        $order->recordEvent(
            'status',
            'Order created manually',
            "Raised by {$actor->name} on behalf of {$vendor->name}.",
            ['vendor_id' => $vendor->id, 'source' => $source],
        );

        $customer?->refreshOrderStats();

        return $order;
    }

    protected function notify(Order $order, User $actor): void
    {
        // One copy per store, because the body quotes money: a seller on a
        // shared basket must see their own share, not the buyer's total.
        $order->load('items', 'customer');

        Notifier::sendPerStore(
            (new OrderPlaced($order))->vendorIds(),
            fn (?int $storeId) => new OrderPlaced($order, $storeId),
            $actor,
        );

        // Selling the last few of something is worth hearing about, but only
        // once it actually crosses the line this order pushed it over.
        Product::whereIn('id', $order->items->pluck('product_id')->filter())
            ->where('track_inventory', true)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->get()
            ->each(fn (Product $product) => Notifier::send(new LowStockReached($product), $actor));
    }
}
