<?php

namespace App\Services\Couriers;

use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delhivery, spoken over HTTP.
 *
 * Three things happen here and nowhere else: a parcel is booked and a waybill
 * comes back, a waybill is asked where it has got to, and a booking is called
 * off. What that means for an order — the tracking number, the timeline, the
 * status — belongs to `BookShipment` and `SyncShipments`, so this class never
 * writes to the database.
 *
 * Constructed with credentials rather than reading them: they come from the
 * courier's own row in the panel, or from the environment where somebody set
 * them there first. `Couriers` decides which.
 *
 * Delhivery's own oddity, kept in one place: `create.json` wants a *form* body
 * whose `data` field is JSON, not a JSON body. Sending it as JSON returns a
 * cheerful 200 with `success: false` and no waybill, which is exactly the kind
 * of failure that gets deployed.
 */
class DelhiveryClient implements CourierClient
{
    /** What Delhivery calls the state a parcel is in, in this marketplace's words. */
    private const STATUS_MAP = [
        'Manifested' => 'booked',
        'Not Picked' => 'booked',
        'In Transit' => 'in_transit',
        'Pending' => 'in_transit',
        'Dispatched' => 'out_for_delivery',
        'Delivered' => 'delivered',
        'RTO' => 'returning',
        'DTO' => 'returning',
        'Lost' => 'lost',
        'Canceled' => 'cancelled',
        'Cancelled' => 'cancelled',
    ];

    /**
     * Their two-letter code for which *journey* a parcel is on, which the
     * status alone does not say.
     *
     * This is the whole reason returns were invisible: a parcel coming back to
     * the seller reports `Status: "In Transit"` and then `Status: "Delivered"`,
     * exactly like one going out. Only `StatusType` distinguishes them, and
     * "Delivered" under `RT` means delivered *back to the seller* — the one
     * status this marketplace must never show a shopper as good news.
     */
    private const RETURN_TYPE = 'RT';

    /**
     * @param  string  $token  Delhivery's API token.
     * @param  string  $pickupName  A warehouse registered in their panel — a
     *                              name they do not know refuses every booking.
     * @param  string|null  $sellerName  Printed on the label as the sender.
     */
    public function __construct(
        private readonly string $token,
        private readonly string $pickupName,
        private readonly ?string $sellerName = null,
        private readonly string $baseUrl = 'https://track.delhivery.com',
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
    ) {}

    /**
     * Ask for a pincode nobody could mistake for a booking.
     *
     * Delhivery has no "who am I" endpoint, so serviceability stands in: it is
     * a GET, it changes nothing, and a bad token comes back 401.
     */
    public function ping(): bool
    {
        try {
            return $this->request()->get('/c/api/pin-codes/json/', ['filter_codes' => '110001'])->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Whether Delhivery delivers to a pincode, and on what terms.
     *
     * @return array{serviceable: bool, cod: bool, prepaid: bool, pickup: bool}|null
     *                                                                               Null where the question could not be asked.
     */
    public function serviceability(string $pincode): ?array
    {
        try {
            $response = $this->request()->get('/c/api/pin-codes/json/', ['filter_codes' => $pincode]);
        } catch (ConnectionException $e) {
            Log::warning('Delhivery unreachable for a serviceability check.', [
                'pincode' => $pincode, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $codes = (array) $response->json('delivery_codes', []);

        if ($codes === []) {
            return ['serviceable' => false, 'cod' => false, 'prepaid' => false, 'pickup' => false];
        }

        $postal = (array) ($codes[0]['postal_code'] ?? []);

        return [
            'serviceable' => true,
            // Delhivery answers "Y"/"N" here, not booleans.
            'cod' => ($postal['cod'] ?? 'N') === 'Y',
            'prepaid' => ($postal['pre_paid'] ?? 'N') === 'Y',
            'pickup' => ($postal['pickup'] ?? 'N') === 'Y',
        ];
    }

    /**
     * Book one order and hand back its waybill.
     *
     * @param  array<string, mixed>  $overrides  Weight, dimensions, seller name.
     *
     * @throws RuntimeException When Delhivery refuses or cannot be reached.
     */
    public function createShipment(Order $order, array $overrides = []): string
    {
        $address = (array) $order->shipping_address;
        $isCod = $order->isPayOnDelivery() && $order->payment_status !== 'paid';

        $shipment = [
            'name' => trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: 'Customer',
            'add' => trim(($address['address_line1'] ?? '').' '.($address['address_line2'] ?? '')),
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'country' => $address['country'] ?? 'India',
            'pin' => (string) ($address['postcode'] ?? ''),
            'phone' => (string) ($address['phone'] ?? $order->phone ?? ''),
            'order' => $order->number,
            // "COD" or "Prepaid", and the amount only means anything for the
            // first — sending a figure on a prepaid parcel is how a courier
            // ends up collecting money twice.
            'payment_mode' => $isCod ? 'COD' : 'Prepaid',
            'cod_amount' => $isCod ? (float) $order->grand_total : 0,
            'total_amount' => (float) $order->grand_total,
            'products_desc' => $order->items->pluck('name')->take(3)->implode(', ') ?: 'Merchandise',
            'quantity' => (string) $order->items->sum('quantity'),
            'weight' => (string) ($overrides['weight'] ?? 500),
            'shipment_width' => (string) ($overrides['width'] ?? 20),
            'shipment_height' => (string) ($overrides['height'] ?? 15),
            'seller_name' => $overrides['seller_name'] ?? $this->sellerName ?? config('app.name'),
            'shipping_mode' => 'Surface',
        ];

        $payload = [
            'shipments' => [$shipment],
            'pickup_location' => ['name' => $this->pickupName],
        ];

        try {
            // Form-encoded with a JSON `data` field: Delhivery's own shape, and
            // the reason this method exists rather than a one-line post.
            $response = $this->request()
                ->asForm()
                ->post('/api/cmu/create.json', [
                    'format' => 'json',
                    'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Delhivery could not be reached: '.$e->getMessage());
        }

        $packages = (array) $response->json('packages', []);
        $waybill = $packages[0]['waybill'] ?? null;

        if ($response->failed() || ! is_string($waybill) || $waybill === '') {
            // Delhivery answers 200 with `success: false` on a refusal, so the
            // status code alone is not the test.
            $why = $packages[0]['remarks'][0]
                ?? $response->json('rmk')
                ?? mb_substr($response->body(), 0, 200);

            Log::error('Delhivery refused a booking.', [
                'order' => $order->number,
                'status' => $response->status(),
                'why' => $why,
            ]);

            throw new RuntimeException(is_string($why) ? $why : 'Delhivery would not book this parcel.');
        }

        return $waybill;
    }

    /**
     * Where a parcel has got to.
     *
     * @return array{status: string, raw_status: string, location: string|null, updated_at: string|null, scans: list<array<string, mixed>>}|null
     *                                                                                                                                           Null where Delhivery could not be asked, which is not the same as
     *                                                                                                                                           "nothing has happened" and must not be treated as it.
     */
    public function track(string $waybill): ?array
    {
        try {
            $response = $this->request()->get('/api/v1/packages/json/', ['waybill' => $waybill]);
        } catch (ConnectionException $e) {
            Log::warning('Delhivery unreachable while tracking.', [
                'waybill' => $waybill, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $shipment = (array) ($response->json('ShipmentData.0.Shipment') ?? []);

        if ($shipment === []) {
            return null;
        }

        $status = (array) ($shipment['Status'] ?? []);
        $raw = (string) ($status['Status'] ?? '');
        $type = (string) ($status['StatusType'] ?? '');

        return [
            'status' => $this->normalise($raw, $type),
            // The journey is kept in the raw status where it applies, because
            // "Delivered" on a return and "Delivered" on a sale are the same
            // word for opposite outcomes and a timeline showing either one
            // should say which.
            'raw_status' => $type === self::RETURN_TYPE ? 'RTO '.$raw : $raw,
            'location' => is_string($status['StatusLocation'] ?? null) ? $status['StatusLocation'] : null,
            'updated_at' => is_string($status['StatusDateTime'] ?? null) ? $status['StatusDateTime'] : null,
            // `array_values` because Delhivery's scan list is keyed by
            // position on the way in but not guaranteed to be one on the way
            // out, and a list is what the caller iterates.
            'scans' => array_values(array_map(fn (array $scan) => [
                'status' => $scan['ScanDetail']['Scan'] ?? null,
                'instructions' => $scan['ScanDetail']['Instructions'] ?? null,
                'location' => $scan['ScanDetail']['ScannedLocation'] ?? null,
                'at' => $scan['ScanDetail']['ScanDateTime'] ?? null,
            ], (array) ($shipment['Scans'] ?? []))),
        ];
    }

    /**
     * Delhivery's word for where a parcel is, in this marketplace's — with the
     * journey taken into account, because it changes the answer.
     */
    private function normalise(string $raw, string $type): string
    {
        if ($type === self::RETURN_TYPE) {
            // Back with the seller, and finished. Everything else on a return
            // journey is still on its way there.
            return $raw === 'Delivered' ? 'returned' : 'returning';
        }

        return self::STATUS_MAP[$raw] ?? 'in_transit';
    }

    /**
     * Call a booking off. Only possible before the parcel is collected.
     */
    public function cancel(string $waybill): bool
    {
        try {
            $response = $this->request()->post('/api/p/edit', [
                'waybill' => $waybill,
                'cancellation' => 'true',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Delhivery unreachable while cancelling.', [
                'waybill' => $waybill, 'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $response->successful() && $response->json('status') !== false;
    }

    /**
     * The packing slip, as a URL somebody can open and print.
     *
     * Delhivery hosts the PDF and hands back a link to it, so the token is
     * spent here rather than handed to a browser.
     */
    public function label(string $waybill): ?string
    {
        try {
            $response = $this->request()->get('/api/p/packing_slip', ['wbns' => $waybill, 'pdf' => 'true']);
        } catch (ConnectionException $e) {
            Log::warning('Delhivery unreachable while fetching a label.', [
                'waybill' => $waybill, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        $url = $response->json('packages.0.pdf_download_link');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Ask for a van.
     *
     * Delhivery books a pickup against a *warehouse and a package count*, not
     * against waybills — so the list is counted rather than sent. Their window
     * is a time of day, and one that has already passed is refused, so an
     * afternoon slot is the default a morning run can still use.
     *
     * @param  list<string>  $waybills
     * @return array{scheduled: bool, reference: string|null, message: string|null}|null
     */
    public function schedulePickup(array $waybills, ?string $date = null): ?array
    {
        try {
            $response = $this->request()->post('/fm/request/new/', [
                'pickup_location' => $this->pickupName,
                'pickup_date' => $date ?? now()->format('Y-m-d'),
                'pickup_time' => '14:00:00',
                // At least one, or Delhivery reads it as a warehouse survey
                // rather than a collection.
                'expected_package_count' => max(count($waybills), 1),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Delhivery unreachable while scheduling a pickup.', ['error' => $e->getMessage()]);

            return null;
        }

        $reference = $response->json('pickup_id');

        if ($response->failed() || $reference === null) {
            return [
                'scheduled' => false,
                'reference' => null,
                // Their refusals are prose, and worth passing on verbatim:
                // "pickup already exists for this slot" is not an error to fix.
                'message' => (string) ($response->json('error')
                    ?? $response->json('message')
                    ?? mb_substr($response->body(), 0, 200)),
            ];
        }

        return [
            'scheduled' => true,
            'reference' => (string) $reference,
            'message' => null,
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                // Their own scheme: "Token <key>", not Bearer.
                'Authorization' => 'Token '.$this->token,
                'Accept' => 'application/json',
            ])
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }
}
