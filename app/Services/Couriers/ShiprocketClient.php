<?php

namespace App\Services\Couriers;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shiprocket, which is an aggregator rather than a courier.
 *
 * That is the reason to have it: one set of credentials reaches Delhivery,
 * Blue Dart, Ekart, DTDC and the rest, and Shiprocket picks between them per
 * parcel. Adding it is not a sixth integration — it is most of the remaining
 * ones at once.
 *
 * Two things make it awkward, and both are handled here rather than by the
 * caller. Its token is an email-and-password login that lasts ten days, so it
 * is cached and re-fetched rather than configured; and booking is **two**
 * calls — an order is created, then a waybill is assigned to the shipment it
 * produced — so `createShipment()` does both and hands back the AWB, which is
 * the only thing a parcel is tracked by.
 */
class ShiprocketClient implements CourierClient
{
    private const BASE = 'https://apiv2.shiprocket.in/v1/external';

    /** Their word for where a parcel is, in this marketplace's. */
    private const STATUS_MAP = [
        'NEW' => 'booked',
        'AWB ASSIGNED' => 'booked',
        'PICKUP SCHEDULED' => 'booked',
        'PICKED UP' => 'in_transit',
        'IN TRANSIT' => 'in_transit',
        'OUT FOR DELIVERY' => 'out_for_delivery',
        'DELIVERED' => 'delivered',
        'RTO INITIATED' => 'returning',
        'RTO DELIVERED' => 'returning',
        'CANCELED' => 'cancelled',
        'CANCELLED' => 'cancelled',
        'LOST' => 'lost',
    ];

    public function __construct(
        private readonly string $email,
        private readonly string $password,
        private readonly ?string $pickupLocation = null,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Signing in *is* the test: the token endpoint is the only one that
     * proves an email and password without touching a shipment.
     */
    public function ping(): bool
    {
        return $this->token(fresh: true) !== null;
    }

    public function serviceability(string $pincode): ?array
    {
        $request = $this->requestOrNull();

        if ($request === null) {
            return null;
        }

        try {
            $response = $request->get(self::BASE.'/courier/serviceability/', [
                // A pickup pincode is required, so the marketplace's own
                // stands in — this answers "can anybody deliver there", which
                // is the question being asked.
                'pickup_postcode' => $this->pickupPostcode(),
                'delivery_postcode' => $pincode,
                'cod' => 0,
                'weight' => 0.5,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Shiprocket unreachable for a serviceability check.', [
                'pincode' => $pincode, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $couriers = (array) $response->json('data.available_courier_companies', []);

        if ($couriers === []) {
            return ['serviceable' => false, 'cod' => false, 'prepaid' => false, 'pickup' => false];
        }

        return [
            'serviceable' => true,
            // Any courier on the list that will collect cash is enough.
            'cod' => collect($couriers)->contains(fn (array $c) => (int) ($c['cod'] ?? 0) === 1),
            'prepaid' => true,
            'pickup' => true,
        ];
    }

    public function createShipment(Order $order, array $overrides = []): string
    {
        $address = (array) $order->shipping_address;
        $isCod = $order->isPayOnDelivery() && $order->payment_status !== 'paid';

        $payload = [
            'order_id' => $order->number,
            'order_date' => ($order->placed_at ?? $order->created_at ?? now())->format('Y-m-d H:i'),
            'pickup_location' => $this->pickupLocation ?: 'Primary',
            'billing_customer_name' => $address['first_name'] ?? 'Customer',
            'billing_last_name' => $address['last_name'] ?? '',
            'billing_address' => trim(($address['address_line1'] ?? '').' '.($address['address_line2'] ?? '')),
            'billing_city' => $address['city'] ?? '',
            'billing_pincode' => (string) ($address['postcode'] ?? ''),
            'billing_state' => $address['state'] ?? '',
            'billing_country' => $address['country'] ?? 'India',
            'billing_email' => $order->email ?? '',
            'billing_phone' => (string) ($address['phone'] ?? $order->phone ?? ''),
            'shipping_is_billing' => true,
            'order_items' => $order->items->map(fn ($item) => [
                'name' => $item->name,
                'sku' => $item->sku ?: 'SKU-'.$item->id,
                'units' => (int) $item->quantity,
                'selling_price' => (float) $item->unit_price,
            ])->values()->all(),
            'payment_method' => $isCod ? 'COD' : 'Prepaid',
            'sub_total' => (float) $order->subtotal,
            // Shiprocket rejects a shipment with no dimensions at all, and its
            // own minimum is 0.5kg — these are the defaults a parcel gets
            // until somebody weighs it.
            'length' => (float) ($overrides['length'] ?? 20),
            'breadth' => (float) ($overrides['width'] ?? 15),
            'height' => (float) ($overrides['height'] ?? 10),
            'weight' => (float) ($overrides['weight'] ?? 0.5),
        ];

        try {
            $created = $this->request()->post(self::BASE.'/orders/create/adhoc', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Shiprocket could not be reached: '.$e->getMessage());
        }

        if ($created->failed()) {
            $why = $created->json('message') ?? mb_substr($created->body(), 0, 200);

            Log::error('Shiprocket refused an order.', [
                'order' => $order->number, 'status' => $created->status(), 'why' => $why,
            ]);

            throw new RuntimeException(is_string($why) ? $why : 'Shiprocket would not take this order.');
        }

        // Sometimes the AWB comes back with the order; usually it does not,
        // and the shipment has to be given one.
        $awb = $created->json('awb_code');

        if (is_string($awb) && $awb !== '') {
            return $awb;
        }

        $shipmentId = $created->json('shipment_id');

        if (! $shipmentId) {
            throw new RuntimeException('Shiprocket took the order but returned no shipment to track.');
        }

        return $this->assignWaybill((int) $shipmentId, $order->number);
    }

    public function track(string $waybill): ?array
    {
        $request = $this->requestOrNull();

        if ($request === null) {
            return null;
        }

        try {
            $response = $request->get(self::BASE.'/courier/track/awb/'.rawurlencode($waybill));
        } catch (ConnectionException $e) {
            Log::warning('Shiprocket unreachable while tracking.', [
                'waybill' => $waybill, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $data = (array) ($response->json('tracking_data') ?? []);
        $shipment = (array) ($data['shipment_track'][0] ?? []);

        if ($shipment === []) {
            return null;
        }

        $raw = mb_strtoupper((string) ($shipment['current_status'] ?? ''));

        return [
            'status' => self::STATUS_MAP[$raw] ?? 'in_transit',
            'raw_status' => $raw,
            'location' => is_string($shipment['destination'] ?? null) ? $shipment['destination'] : null,
            'updated_at' => is_string($shipment['updated_time_stamp'] ?? null) ? $shipment['updated_time_stamp'] : null,
            'scans' => array_values(array_map(fn (array $scan) => [
                'status' => $scan['sr-status-label'] ?? $scan['status'] ?? null,
                'instructions' => $scan['activity'] ?? null,
                'location' => $scan['location'] ?? null,
                'at' => $scan['date'] ?? null,
            ], (array) ($data['shipment_track_activities'] ?? []))),
        ];
    }

    /**
     * Give a shipment a waybill, which is what makes it trackable.
     */
    protected function assignWaybill(int $shipmentId, string $orderNumber): string
    {
        try {
            $response = $this->request()->post(self::BASE.'/courier/assign/awb', [
                'shipment_id' => $shipmentId,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Shiprocket could not be reached: '.$e->getMessage());
        }

        $awb = $response->json('response.data.awb_code');

        if ($response->failed() || ! is_string($awb) || $awb === '') {
            $why = $response->json('message') ?? 'no courier would take it';

            Log::error('Shiprocket would not assign a waybill.', [
                'order' => $orderNumber, 'shipment' => $shipmentId, 'why' => $why,
            ]);

            // The order exists on their side either way, which is worth saying
            // plainly — somebody will find it there.
            throw new RuntimeException(
                'Shiprocket took the order but assigned no waybill: '.(is_string($why) ? $why : 'unknown reason')
            );
        }

        return $awb;
    }

    /**
     * Their token is a login that lasts ten days, so it is cached rather than
     * configured. Cached under a hash of the email: two stores on one
     * marketplace must not share a session.
     */
    protected function token(bool $fresh = false): ?string
    {
        $key = 'shiprocket:token:'.hash('sha256', $this->email);

        if ($fresh) {
            Cache::forget($key);
        }

        $token = Cache::remember($key, now()->addDays(8), function (): ?string {
            try {
                $response = Http::asJson()
                    ->connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->post(self::BASE.'/auth/login', [
                        'email' => $this->email,
                        'password' => $this->password,
                    ]);
            } catch (ConnectionException $e) {
                Log::warning('Shiprocket unreachable while signing in.', ['error' => $e->getMessage()]);

                return null;
            }

            $token = $response->json('token');

            if ($response->failed() || ! is_string($token) || $token === '') {
                Log::error('Shiprocket refused the login.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            return $token;
        });

        // Never cache a failure: a provider having a bad morning would
        // otherwise lock the marketplace out of it for eight days.
        if ($token === null) {
            Cache::forget($key);
        }

        return $token;
    }

    protected function pickupPostcode(): string
    {
        return (string) (config('services.shiprocket.pickup_postcode') ?: '110001');
    }

    /**
     * The same request, but null instead of an exception where signing in
     * failed.
     *
     * Reading is allowed to answer "could not ask"; booking is not, because a
     * caller that quietly books nothing is worse than one that is told.
     */
    private function requestOrNull(): ?PendingRequest
    {
        try {
            return $this->request();
        } catch (RuntimeException) {
            return null;
        }
    }

    private function request(): PendingRequest
    {
        $token = $this->token();

        if ($token === null) {
            throw new RuntimeException('Shiprocket would not sign us in.');
        }

        return Http::asJson()
            ->withToken($token)
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }
}
