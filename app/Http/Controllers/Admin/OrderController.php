<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DeliveryPartner;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Vendor;
use App\Notifications\LowStockReached;
use App\Notifications\OrderPlaced;
use App\Notifications\OrderStatusChanged;
use App\Services\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->with('customer:id,first_name,last_name,email')
            ->withCount('items')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(
                fn ($q) => $q->where('number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('tracking_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
            ))
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('payment_status')->toString(), fn ($query, $status) => $query->where('payment_status', $status))
            ->when($request->string('payment_method')->toString(), fn ($query, $method) => $query->where('payment_method', $method))
            ->when($request->string('fulfillment_status')->toString(), fn ($query, $status) => $query->where('fulfillment_status', $status))
            ->when($request->filled('vendor'), fn ($query) => $query->whereHas(
                'items', fn ($q) => $q->where('vendor_id', $request->integer('vendor'))
            ))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('placed_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('placed_at', '<=', $request->date('to')))
            ->latest('placed_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/orders/Index', [
            'orders' => $orders,
            'filters' => $request->only(['search', 'status', 'payment_status', 'payment_method', 'fulfillment_status', 'vendor', 'from', 'to']),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name']),
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'paymentMethods' => PaymentMethod::orderBy('position')->orderBy('name')->pluck('name'),
            'counts' => collect(Order::STATUSES)
                ->mapWithKeys(fn ($status) => [$status => Order::where('status', $status)->count()])
                ->put('all', Order::count()),
            'summary' => [
                'revenue' => (float) Order::whereNot('status', 'cancelled')->sum('grand_total'),
                'unfulfilled' => Order::where('fulfillment_status', 'unfulfilled')
                    ->whereNotIn('status', ['cancelled', 'refunded'])->count(),
                'unpaid' => Order::where('payment_status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Manual order entry. Admins pick any vendor; a vendor user is locked to
     * their own store. Products are loaded per vendor so the picker only ever
     * shows what that vendor actually sells.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();

        $vendors = Vendor::query()
            ->when($user->isVendor(), fn ($query) => $query->whereKey($user->vendor_id))
            ->orderBy('name')
            ->get(['id', 'name', 'commission_rate']);

        $vendorId = $user->isVendor()
            ? $user->vendor_id
            : ($request->filled('vendor') ? $request->integer('vendor') : $vendors->first()?->id);

        return Inertia::render('admin/orders/Create', [
            'vendors' => $vendors,
            'selectedVendor' => $vendorId,
            'lockedToVendor' => $user->isVendor(),
            'products' => fn () => $this->sellableProducts($vendorId)->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => (float) $product->price,
                'stock_quantity' => (int) $product->stock_quantity,
                'track_inventory' => (bool) $product->track_inventory,
                'allow_backorder' => (bool) $product->allow_backorder,
                'tax_rate' => $this->taxRateFor($product),
                'variants' => $product->variants->map(fn ($variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'price' => (float) $variant->price,
                    'stock_quantity' => (int) $variant->stock_quantity,
                ])->values()->all(),
            ])->values()->all(),
            'customers' => Customer::query()
                ->where('status', 'active')
                ->orderBy('first_name')
                ->limit(200)
                ->get(['id', 'first_name', 'last_name', 'email', 'phone']),
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'paymentMethods' => PaymentMethod::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
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
        ]);

        // A vendor user may only ever raise orders for their own store.
        $vendorId = $user->isVendor() ? (int) $user->vendor_id : (int) $data['vendor_id'];

        $vendor = Vendor::findOrFail($vendorId);
        $products = $this->sellableProducts($vendorId)->keyBy('id');

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
                'tax_rate' => $this->taxRateFor($product),
            ];
        }

        $order = DB::transaction(function () use ($data, $lines, $vendor, $user) {
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
                $commissionAmount = round($lineTotal * $commissionRate / 100, 2);

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
                    'commission_rate' => $commissionRate,
                    'commission_amount' => $commissionAmount,
                    'vendor_earning' => round($lineTotal - $commissionAmount, 2),
                ]);

                if ($product->track_inventory) {
                    $variant
                        ? $variant->decrement('stock_quantity', $line['quantity'])
                        : $product->decrement('stock_quantity', $line['quantity']);
                }

                $subtotal += $lineTotal;
                $taxTotal += $taxAmount;
                $commissionTotal += $commissionAmount;
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
                "Raised by {$user->name} on behalf of {$vendor->name}.",
                ['vendor_id' => $vendor->id, 'source' => 'admin'],
            );

            $customer?->refreshOrderStats();

            return $order;
        });

        $actor = $request->user();

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

        return to_route('admin.orders.show', $order)
            ->with('success', "Order {$order->number} created.");
    }

    public function show(Order $order): Response
    {
        $order->load([
            'customer.addresses',
            'items.product:id,name,slug',
            'items.vendor:id,name',
            'events.user:id,name',
            'cancellations.items',
            'refunds.items',
        ]);

        return Inertia::render('admin/orders/Show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'paymentStatuses' => Order::PAYMENT_STATUSES,
            'fulfillmentStatuses' => Order::FULFILLMENT_STATUSES,
            'paymentMethods' => PaymentMethod::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
            'deliveryPartners' => DeliveryPartner::active()
                ->orderBy('position')->orderBy('name')->pluck('name'),
            'trackingUrl' => DeliveryPartner::where('name', $order->carrier)->first()
                ?->trackingUrlFor($order->tracking_number),
            'vendorBreakdown' => $order->items
                ->groupBy(fn (OrderItem $item) => $item->vendor->name ?? 'Store')
                ->map(fn ($items) => [
                    'items' => $items->count(),
                    'total' => round((float) $items->sum('total'), 2),
                    'commission' => round((float) $items->sum('commission_amount'), 2),
                    'earning' => round((float) $items->sum('vendor_earning'), 2),
                ]),
        ]);
    }

    public function updateStatus(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $from = $order->status;

        $timestamps = match ($data['status']) {
            'shipped' => ['shipped_at' => now()],
            'completed' => ['delivered_at' => now()],
            'cancelled' => ['cancelled_at' => now()],
            default => [],
        };

        $order->update(['status' => $data['status'], ...$timestamps]);

        $order->recordEvent(
            'status',
            "Status changed from {$from} to {$data['status']}",
            $data['note'] ?? null,
            ['from' => $from, 'to' => $data['status']],
        );

        // A seller who has already packed an order needs to hear that it was
        // cancelled or held; the rest of the ladder is noise to them.
        if (OrderStatusChanged::worthTelling($from, $data['status'])) {
            Notifier::send(new OrderStatusChanged($order->fresh('items')), $request->user());
        }

        return back()->with('success', "Order marked as {$data['status']}.");
    }

    public function updatePayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(Order::PAYMENT_STATUSES)],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'transaction_id' => ['nullable', 'string', 'max:120'],
        ]);

        $order->update([
            ...$data,
            'paid_at' => $data['payment_status'] === 'paid' ? ($order->paid_at ?? now()) : $order->paid_at,
        ]);

        $order->recordEvent('payment', "Payment marked as {$data['payment_status']}");

        return back()->with('success', 'Payment status updated.');
    }

    public function fulfill(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:80'],
            'notify_customer' => ['boolean'],
        ]);

        DB::transaction(function () use ($order, $data) {
            foreach ($data['items'] as $row) {
                $item = $order->items()->findOrFail((int) $row['id']);
                $quantity = min($row['quantity'], $item->quantity - $item->quantity_cancelled);

                $item->update([
                    'quantity_fulfilled' => $quantity,
                    'fulfillment_status' => match (true) {
                        $quantity <= 0 => 'unfulfilled',
                        $quantity >= $item->quantity - $item->quantity_cancelled => 'fulfilled',
                        default => 'partially_fulfilled',
                    },
                ]);
            }

            $order->load('items');

            $expected = $order->items->sum(fn ($item) => $item->quantity - $item->quantity_cancelled);
            $fulfilled = $order->items->sum('quantity_fulfilled');

            $order->update([
                'fulfillment_status' => match (true) {
                    $fulfilled <= 0 => 'unfulfilled',
                    $fulfilled >= $expected => 'fulfilled',
                    default => 'partially_fulfilled',
                },
                'tracking_number' => $data['tracking_number'] ?? $order->tracking_number,
                'carrier' => $data['carrier'] ?? $order->carrier,
                'shipped_at' => $order->shipped_at ?? now(),
                'status' => $order->status === 'processing' && $fulfilled >= $expected ? 'shipped' : $order->status,
            ]);

            $order->recordEvent(
                'fulfillment',
                'Fulfillment updated',
                empty($data['tracking_number']) ? null : "Tracking: {$data['tracking_number']}",
                ['carrier' => $data['carrier'] ?? null],
            );
        });

        return back()->with('success', 'Fulfillment updated.');
    }

    public function addNote(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $order->recordEvent('note', 'Note added', $data['body']);

        return back()->with('success', 'Note added to the timeline.');
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
            'shipping_method' => ['nullable', 'string', 'max:120'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'carrier' => ['nullable', 'string', 'max:80'],
            'shipping_address' => ['nullable', 'array'],
            'billing_address' => ['nullable', 'array'],
        ]);

        $order->update($data);

        return back()->with('success', 'Order updated.');
    }

    /**
     * Active products a vendor can be billed for, with everything the order
     * form and the totals maths need.
     *
     * @return Collection<int, Product>
     */
    private function sellableProducts(?int $vendorId): Collection
    {
        if (! $vendorId) {
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
    private function taxRateFor(Product $product): float
    {
        $rate = $product->taxClass?->rates
            ->where('is_active', true)
            ->sortBy('priority')
            ->first();

        return (float) ($rate->rate ?? 0);
    }
}
