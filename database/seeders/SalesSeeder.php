<?php

namespace Database\Seeders;

use App\Models\Cancellation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Vendor;
use App\Models\VendorPayout;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SalesSeeder extends Seeder
{
    /**
     * @var array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    protected array $people = [
        ['Aarav', 'Sharma', 'Mumbai', 'Maharashtra'],
        ['Diya', 'Patel', 'Ahmedabad', 'Gujarat'],
        ['Vihaan', 'Reddy', 'Hyderabad', 'Telangana'],
        ['Ananya', 'Iyer', 'Chennai', 'Tamil Nadu'],
        ['Arjun', 'Singh', 'Lucknow', 'Uttar Pradesh'],
        ['Ishita', 'Banerjee', 'Kolkata', 'West Bengal'],
        ['Kabir', 'Khan', 'Bhopal', 'Madhya Pradesh'],
        ['Meera', 'Nair', 'Kochi', 'Kerala'],
        ['Rohan', 'Gupta', 'New Delhi', 'Delhi'],
        ['Saanvi', 'Joshi', 'Pune', 'Maharashtra'],
        ['Aditya', 'Verma', 'Jaipur', 'Rajasthan'],
        ['Nisha', 'Rao', 'Bengaluru', 'Karnataka'],
        ['Yash', 'Mehta', 'Surat', 'Gujarat'],
        ['Tanvi', 'Kulkarni', 'Nagpur', 'Maharashtra'],
        ['Devansh', 'Chauhan', 'Kanpur', 'Uttar Pradesh'],
        ['Riya', 'Dutta', 'Guwahati', 'Assam'],
        ['Aryan', 'Bhatia', 'Chandigarh', 'Punjab'],
        ['Kavya', 'Menon', 'Thiruvananthapuram', 'Kerala'],
        ['Neel', 'Kapoor', 'Gurugram', 'Haryana'],
        ['Pooja', 'Agarwal', 'Indore', 'Madhya Pradesh'],
        ['Sameer', 'Hussain', 'Srinagar', 'Jammu & Kashmir'],
        ['Aditi', 'Ghosh', 'Bhubaneswar', 'Odisha'],
        ['Manav', 'Pillai', 'Coimbatore', 'Tamil Nadu'],
        ['Shreya', 'Saxena', 'Varanasi', 'Uttar Pradesh'],
        ['Kunal', 'Trivedi', 'Vadodara', 'Gujarat'],
        ['Ira', 'Bose', 'Siliguri', 'West Bengal'],
        ['Rehan', 'Ali', 'Aurangabad', 'Maharashtra'],
        ['Trisha', 'Sen', 'Ranchi', 'Jharkhand'],
        ['Om', 'Deshpande', 'Nashik', 'Maharashtra'],
        ['Naina', 'Chopra', 'Amritsar', 'Punjab'],
        ['Veer', 'Rathore', 'Udaipur', 'Rajasthan'],
        ['Simran', 'Kaur', 'Ludhiana', 'Punjab'],
        ['Dev', 'Malhotra', 'Faridabad', 'Haryana'],
        ['Anika', 'Prasad', 'Patna', 'Bihar'],
        ['Rudra', 'Naik', 'Panaji', 'Goa'],
    ];

    /** @var list<string> */
    protected array $paymentMethods = ['UPI', 'Credit Card', 'Debit Card', 'Net Banking', 'Cash on Delivery', 'Wallet'];

    /** @var list<string> */
    protected array $carriers = ['Delhivery', 'Blue Dart', 'Ekart', 'DTDC', 'Shadowfax'];

    public function run(): void
    {
        $customers = $this->seedCustomers();
        $products = Product::with('variants')->where('status', 'active')->get();

        if ($products->isEmpty()) {
            return;
        }

        $orders = collect();

        foreach (range(1, 160) as $i) {
            $orders->push($this->makeOrder($customers->random(), $products));
        }

        $this->seedCancellations($orders);
        $this->seedRefunds($orders);
        $this->seedPayouts();
        $this->refreshCustomerTotals();
    }

    /**
     * @return Collection<int, Customer>
     */
    protected function seedCustomers(): Collection
    {
        return collect($this->people)->map(function (array $person, int $index) {
            [$first, $last, $city, $state] = $person;

            $customer = Customer::create([
                'first_name' => $first,
                'last_name' => $last,
                'email' => Str::lower($first.'.'.$last.($index + 1)).'@example.com',
                'phone' => '+91 '.random_int(70000, 99999).random_int(10000, 99999),
                'avatar_path' => 'https://i.pravatar.cc/150?u='.Str::slug($first.$last),
                'date_of_birth' => now()->subYears(random_int(19, 55))->subDays(random_int(0, 364))->toDateString(),
                'gender' => collect(['male', 'female', 'other', null])->random(),
                'status' => random_int(1, 100) <= 6 ? 'blocked' : 'active',
                'accepts_marketing' => random_int(1, 100) <= 62,
                'email_verified' => random_int(1, 100) <= 85,
                'notes' => random_int(1, 100) <= 20 ? 'Prefers delivery after 6pm. Called support once about a delayed order.' : null,
                'tags' => random_int(1, 100) <= 30 ? collect(['vip', 'repeat', 'wholesale', 'newsletter'])->shuffle()->take(random_int(1, 2))->values()->all() : null,
                'created_at' => now()->subDays(random_int(10, 700)),
            ]);

            $customer->addresses()->create([
                'label' => 'Home',
                'first_name' => $first,
                'last_name' => $last,
                'address_line1' => random_int(1, 400).', '.collect(['Green Park', 'MG Road', 'Rose Villa', 'Sunrise Apartments', 'Lake View'])->random(),
                'address_line2' => collect(['Near City Mall', 'Opp. Bus Depot', null])->random(),
                'city' => $city,
                'state' => $state,
                'postcode' => (string) random_int(110001, 799999),
                'country' => 'IN',
                'phone' => $customer->phone,
                'is_default_billing' => true,
                'is_default_shipping' => true,
            ]);

            if (random_int(1, 100) <= 35) {
                $customer->addresses()->create([
                    'label' => 'Office',
                    'first_name' => $first,
                    'last_name' => $last,
                    'company' => collect(['Infosys', 'TCS', 'Zoho', 'Freshworks', 'Zerodha'])->random(),
                    'address_line1' => 'Tower '.collect(['A', 'B', 'C'])->random().', Tech Park',
                    'city' => $city,
                    'state' => $state,
                    'postcode' => (string) random_int(110001, 799999),
                    'country' => 'IN',
                    'phone' => $customer->phone,
                ]);
            }

            return $customer;
        });
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     */
    protected function makeOrder(Customer $customer, EloquentCollection $products): Order
    {
        $placedAt = Carbon::now()->subDays(random_int(0, 120))->setTime(random_int(8, 22), random_int(0, 59));
        $address = $customer->addresses->first();

        $addressPayload = [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'address_line1' => $address?->address_line1,
            'address_line2' => $address?->address_line2,
            'city' => $address?->city,
            'state' => $address?->state,
            'postcode' => $address?->postcode,
            'country' => 'IN',
            'phone' => $customer->phone,
        ];

        $order = Order::create([
            'number' => Order::nextNumber(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => 'pending',
            'payment_status' => 'pending',
            'currency' => 'INR',
            'payment_method' => collect($this->paymentMethods)->random(),
            'transaction_id' => 'TXN'.strtoupper(Str::random(12)),
            'shipping_method' => collect(['Standard delivery', 'Express delivery'])->random(),
            'billing_address' => $addressPayload,
            'shipping_address' => $addressPayload,
            'customer_note' => random_int(1, 100) <= 15 ? 'Please call before delivery.' : null,
            'placed_at' => $placedAt,
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);

        $subtotal = 0;
        $taxTotal = 0;
        $commissionTotal = 0;

        foreach ($products->random(random_int(1, 4)) as $product) {
            $variant = $product->variants->isNotEmpty() ? $product->variants->random() : null;
            $quantity = random_int(1, 3);
            $unitPrice = (float) ($variant->price ?? $product->price);
            $lineTotal = round($unitPrice * $quantity, 2);
            $taxRate = $unitPrice < 1000 ? 5 : 18;
            $taxAmount = round($lineTotal * $taxRate / 100, 2);

            $commissionRate = $product->vendor_id
                ? (float) Vendor::find((int) $product->vendor_id)?->commission_rate
                : 0;
            $commissionAmount = round($lineTotal * $commissionRate / 100, 2);

            $order->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'vendor_id' => $product->vendor_id,
                'name' => $product->name,
                'sku' => $variant->sku ?? $product->sku,
                'image_path' => $product->images->first()?->path,
                'options' => $variant ? ['Variant' => $variant->name] : null,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $lineTotal,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commissionAmount,
                'vendor_earning' => round($lineTotal - $commissionAmount, 2),
                'created_at' => $placedAt,
                'updated_at' => $placedAt,
            ]);

            $subtotal += $lineTotal;
            $taxTotal += $taxAmount;
            $commissionTotal += $commissionAmount;
        }

        $shipping = $subtotal >= 999 ? 0 : 59;
        $discount = random_int(1, 100) <= 25 ? round($subtotal * (random_int(5, 15) / 100), 2) : 0;
        $grandTotal = round($subtotal + $taxTotal + $shipping - $discount, 2);

        // Give the order a realistic lifecycle based on how old it is.
        $ageInDays = (int) $placedAt->diffInDays(now());
        [$status, $paymentStatus, $fulfilment] = $this->lifecycle($ageInDays);

        $order->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'tax_total' => $taxTotal,
            'shipping_total' => $shipping,
            'grand_total' => $grandTotal,
            'commission_total' => $commissionTotal,
            'coupon_code' => $discount > 0 ? collect(['WELCOME10', 'FEST15', 'SAVE5'])->random() : null,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfilment,
            'carrier' => $fulfilment !== 'unfulfilled' ? collect($this->carriers)->random() : null,
            'tracking_number' => $fulfilment !== 'unfulfilled' ? strtoupper(Str::random(3)).random_int(100000000, 999999999) : null,
            'paid_at' => $paymentStatus === 'paid' ? $placedAt->copy()->addMinutes(random_int(1, 60)) : null,
            'shipped_at' => in_array($status, ['shipped', 'completed'], true) ? $placedAt->copy()->addDays(random_int(1, 3)) : null,
            'delivered_at' => $status === 'completed' ? $placedAt->copy()->addDays(random_int(3, 8)) : null,
            'cancelled_at' => $status === 'cancelled' ? $placedAt->copy()->addDays(random_int(0, 2)) : null,
        ]);

        if ($fulfilment === 'fulfilled') {
            $order->items()->update(['fulfillment_status' => 'fulfilled']);
            $order->items->each(fn ($item) => $item->update(['quantity_fulfilled' => $item->quantity]));
        }

        $order->events()->create([
            'type' => 'status',
            'title' => 'Order placed',
            'body' => 'Order received from the storefront.',
            'created_at' => $placedAt,
            'updated_at' => $placedAt,
        ]);

        if ($paymentStatus === 'paid') {
            $order->events()->create([
                'type' => 'payment',
                'title' => 'Payment captured',
                'body' => $order->payment_method.' · '.$order->transaction_id,
                'created_at' => $placedAt->copy()->addMinutes(2),
                'updated_at' => $placedAt->copy()->addMinutes(2),
            ]);
        }

        if ($order->shipped_at) {
            $order->events()->create([
                'type' => 'fulfillment',
                'title' => 'Shipment dispatched',
                'body' => $order->carrier.' · '.$order->tracking_number,
                'created_at' => $order->shipped_at,
                'updated_at' => $order->shipped_at,
            ]);
        }

        return $order->fresh('items');
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    protected function lifecycle(int $ageInDays): array
    {
        $roll = random_int(1, 100);

        if ($ageInDays <= 1) {
            return match (true) {
                $roll <= 55 => ['pending', 'paid', 'unfulfilled'],
                $roll <= 80 => ['processing', 'paid', 'unfulfilled'],
                $roll <= 92 => ['pending', 'pending', 'unfulfilled'],
                default => ['cancelled', 'failed', 'unfulfilled'],
            };
        }

        if ($ageInDays <= 5) {
            return match (true) {
                $roll <= 40 => ['processing', 'paid', 'partially_fulfilled'],
                $roll <= 75 => ['shipped', 'paid', 'fulfilled'],
                $roll <= 88 => ['on_hold', 'paid', 'unfulfilled'],
                default => ['cancelled', 'refunded', 'unfulfilled'],
            };
        }

        return match (true) {
            $roll <= 74 => ['completed', 'paid', 'fulfilled'],
            $roll <= 84 => ['shipped', 'paid', 'fulfilled'],
            $roll <= 92 => ['cancelled', 'refunded', 'unfulfilled'],
            default => ['refunded', 'refunded', 'fulfilled'],
        };
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    protected function seedCancellations(Collection $orders): void
    {
        $candidates = $orders->filter(fn (Order $order) => in_array($order->status, ['pending', 'processing', 'on_hold', 'cancelled'], true))
            ->shuffle()
            ->take(22);

        foreach ($candidates as $order) {
            $items = $order->items;

            if ($items->isEmpty()) {
                continue;
            }

            $status = collect(['pending', 'pending', 'approved', 'rejected'])->random();
            $selected = $items->random(min($items->count(), random_int(1, 2)));
            $total = 0;

            $cancellation = Cancellation::create([
                'number' => Cancellation::nextNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'scope' => $selected->count() === $items->count() ? 'full' : 'partial',
                'reason' => collect(array_keys(Cancellation::REASONS))->random(),
                'note' => collect([
                    'Customer called and asked to cancel before dispatch.',
                    'Ordered the wrong size, wants to reorder.',
                    'Found the same item cheaper elsewhere.',
                    null,
                ])->random(),
                'status' => $status,
                'restock' => true,
                'refund_requested' => random_int(1, 100) <= 60,
                'requested_by' => collect(['customer', 'customer', 'admin'])->random(),
                'review_note' => $status === 'rejected' ? 'Item already shipped — cancellation not possible at this stage.' : null,
                'reviewed_at' => $status === 'pending' ? null : $order->placed_at?->copy()->addDays(1),
                'created_at' => $order->placed_at?->copy()->addHours(random_int(2, 40)),
            ]);

            foreach ($selected as $item) {
                $quantity = random_int(1, $item->quantity);
                $amount = round(((float) $item->total / max($item->quantity, 1)) * $quantity, 2);
                $total += $amount;

                $cancellation->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);

                if ($status === 'approved') {
                    $item->increment('quantity_cancelled', $quantity);
                }
            }

            $cancellation->update(['total_amount' => $total]);
        }
    }

    /**
     * @param  Collection<int, Order>  $orders
     */
    protected function seedRefunds(Collection $orders): void
    {
        $candidates = $orders->filter(fn (Order $order) => in_array($order->payment_status, ['paid', 'refunded'], true))
            ->shuffle()
            ->take(26);

        foreach ($candidates as $order) {
            $items = $order->items;

            if ($items->isEmpty()) {
                continue;
            }

            $status = collect(['pending', 'pending', 'approved', 'processed', 'processed', 'rejected'])->random();
            $selected = $items->random(min($items->count(), random_int(1, 2)));
            $itemsAmount = 0;
            $taxAmount = 0;

            $refund = Refund::create([
                'number' => Refund::nextNumber(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'type' => 'partial',
                'reason' => collect(array_keys(Refund::REASONS))->random(),
                'method' => collect(array_keys(Refund::METHODS))->random(),
                'note' => collect([
                    'Product arrived with a scratch on the side panel.',
                    'Customer shared photos, damage confirmed.',
                    'Wrong colour shipped from the warehouse.',
                    null,
                ])->random(),
                'status' => $status,
                'restock' => random_int(1, 100) <= 70,
                'shipping_amount' => random_int(1, 100) <= 30 ? 59 : 0,
                'review_note' => match ($status) {
                    'rejected' => 'Outside the 7-day return window.',
                    'approved', 'processed' => 'Verified with photos from the customer.',
                    default => null,
                },
                'reviewed_at' => $status === 'pending' ? null : $order->placed_at?->copy()->addDays(random_int(2, 6)),
                'processed_at' => $status === 'processed' ? $order->placed_at?->copy()->addDays(random_int(3, 9)) : null,
                'transaction_reference' => $status === 'processed' ? 'RFND'.strtoupper(Str::random(10)) : null,
                'created_at' => $order->placed_at?->copy()->addDays(random_int(1, 5)),
            ]);

            foreach ($selected as $item) {
                $quantity = random_int(1, max(1, (int) $item->quantity - (int) $item->quantity_cancelled));
                $amount = round(((float) $item->total / max($item->quantity, 1)) * $quantity, 2);
                $itemsAmount += $amount;
                $taxAmount += round(((float) $item->tax_amount / max($item->quantity, 1)) * $quantity, 2);

                $refund->items()->create([
                    'order_item_id' => $item->id,
                    'quantity' => $quantity,
                    'amount' => $amount,
                ]);

                if ($status === 'processed') {
                    $item->increment('quantity_refunded', $quantity);
                }
            }

            $total = round($itemsAmount + (float) $refund->shipping_amount, 2);

            $refund->update([
                'items_amount' => $itemsAmount,
                'tax_amount' => $taxAmount,
                'total_amount' => $total,
                'type' => $total >= (float) $order->grand_total ? 'full' : 'partial',
            ]);

            if ($status === 'processed') {
                $refundedTotal = round((float) $order->refunded_total + $total, 2);

                $order->update([
                    'refunded_total' => $refundedTotal,
                    'payment_status' => $refundedTotal >= (float) $order->grand_total - 0.01 ? 'refunded' : 'partially_refunded',
                ]);
            }
        }
    }

    protected function seedPayouts(): void
    {
        foreach (Vendor::approved()->get() as $vendor) {
            foreach (range(0, random_int(1, 3)) as $offset) {
                $end = now()->subMonths($offset)->endOfMonth();
                $start = $end->copy()->startOfMonth();

                $items = $vendor->orderItems()
                    ->whereHas('order', fn ($query) => $query
                        ->whereBetween('placed_at', [$start, $end])
                        ->whereNot('status', 'cancelled'))
                    ->get();

                if ($items->isEmpty()) {
                    continue;
                }

                $gross = round((float) $items->sum('total'), 2);
                $commission = round((float) $items->sum('commission_amount'), 2);
                $status = $offset === 0 ? 'pending' : ($offset === 1 ? collect(['processing', 'paid'])->random() : 'paid');

                VendorPayout::create([
                    'number' => VendorPayout::nextNumber(),
                    'vendor_id' => $vendor->id,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'gross_sales' => $gross,
                    'commission_amount' => $commission,
                    'net_amount' => round($gross - $commission, 2),
                    'orders_count' => $items->unique('order_id')->count(),
                    'status' => $status,
                    'method' => $vendor->payout_method,
                    'transaction_reference' => $status === 'paid' ? 'NEFT'.strtoupper(Str::random(10)) : null,
                    'paid_at' => $status === 'paid' ? $end->copy()->addDays(3) : null,
                    'created_at' => $end->copy()->addDay(),
                ]);
            }
        }
    }

    protected function refreshCustomerTotals(): void
    {
        Customer::query()->each(function (Customer $customer) {
            $orders = $customer->orders()->whereNot('status', 'cancelled')->get();

            $customer->update([
                'orders_count' => $orders->count(),
                'total_spent' => round((float) $orders->sum('grand_total') - (float) $orders->sum('refunded_total'), 2),
                'last_order_at' => $orders->max('placed_at'),
            ]);
        });
    }
}
