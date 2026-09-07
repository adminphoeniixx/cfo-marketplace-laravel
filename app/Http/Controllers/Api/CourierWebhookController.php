<?php

namespace App\Http\Controllers\Api;

use App\Actions\Shipping\ApplyTracking;
use App\Http\Controllers\Controller;
use App\Models\DeliveryPartner;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The courier telling us, rather than us asking.
 *
 * The fifteen-minute sync is a good floor and a bad ceiling: a shopper who
 * watches a parcel out for delivery is refreshing a screen that is up to a
 * quarter of an hour behind, and a failed delivery attempt sits unseen for the
 * same. These close that to seconds.
 *
 * The sync stays. A webhook that is never sent — or that arrives while this
 * marketplace is deploying — leaves no trace, and polling is what notices.
 * Both routes end in `ApplyTracking`, which is written so that the same news
 * arriving twice writes nothing the second time.
 *
 * Neither courier signs its payloads, so the shared secret in the URL or the
 * header is the whole authority. That is thin, which is why nothing here
 * trusts the body's own account of *which* order it is: the waybill is looked
 * up against orders this marketplace booked itself, and a waybill it never
 * booked is answered politely and dropped.
 */
class CourierWebhookController extends Controller
{
    /**
     * Shiprocket, which authenticates with a token of your own choosing sent
     * as `x-api-key` — set on the same screen as the callback URL.
     */
    public function shiprocket(Request $request, ApplyTracking $apply): JsonResponse
    {
        if (! $this->authorised($request, (string) config('services.shiprocket.webhook_token'))) {
            return response()->json(['message' => 'Not for you.'], 401);
        }

        $waybill = (string) ($request->input('awb') ?? '');
        $order = $this->orderFor($waybill, 'shiprocket');

        if ($order === null) {
            // 200, not 404: a courier that gets an error retries for hours,
            // and there is nothing to retry — the parcel is not ours.
            return response()->json(['message' => 'No such parcel here.']);
        }

        $raw = mb_strtoupper((string) $request->input('current_status', ''));

        $apply->handle($order, [
            'status' => $this->normalise($raw),
            'raw_status' => $raw,
            'scans' => array_values(array_map(fn (array $scan) => [
                'status' => $scan['sr-status-label'] ?? $scan['status'] ?? null,
                'instructions' => $scan['activity'] ?? null,
                'location' => $scan['location'] ?? null,
                'at' => $scan['date'] ?? null,
            ], (array) $request->input('scans', []))),
        ]);

        return response()->json(['message' => 'Noted.']);
    }

    /**
     * Delhivery, which pushes one shipment per call and authenticates with
     * whatever you put in the callback URL — so the token is a query
     * parameter, and a header is accepted for a marketplace that set one.
     */
    public function delhivery(Request $request, ApplyTracking $apply): JsonResponse
    {
        if (! $this->authorised($request, (string) config('services.delhivery.webhook_token'))) {
            return response()->json(['message' => 'Not for you.'], 401);
        }

        $shipment = (array) $request->input('Shipment', []);
        $waybill = (string) ($shipment['AWB'] ?? $request->input('waybill', ''));
        $order = $this->orderFor($waybill, 'delhivery');

        if ($order === null) {
            return response()->json(['message' => 'No such parcel here.']);
        }

        $status = (array) ($shipment['Status'] ?? []);
        $raw = (string) ($status['Status'] ?? '');
        $isReturn = ($status['StatusType'] ?? '') === 'RT';

        $apply->handle($order, [
            'status' => $isReturn
                ? ($raw === 'Delivered' ? 'returned' : 'returning')
                : $this->normalise(mb_strtoupper($raw)),
            'raw_status' => $isReturn ? 'RTO '.$raw : $raw,
            // Delhivery pushes the *current* state rather than a scan list, so
            // the push itself is the scan.
            'scans' => [[
                'status' => $isReturn ? 'RTO '.$raw : $raw,
                'instructions' => $status['Instructions'] ?? null,
                'location' => $status['StatusLocation'] ?? null,
                'at' => $status['StatusDateTime'] ?? null,
            ]],
        ]);

        return response()->json(['message' => 'Noted.']);
    }

    /**
     * The order a waybill belongs to, if this marketplace booked it.
     *
     * Scoped to orders still worth updating, because a waybill is only unique
     * within a courier's own numbering and a cancelled order from last year
     * should not be reopened by a stranger's parcel.
     */
    protected function orderFor(string $waybill, string $driver): ?Order
    {
        if (trim($waybill) === '') {
            return null;
        }

        // The carriers this driver answers for, by the name orders record —
        // one marketplace's "Shiprocket" is another's "Express Delivery", and
        // the row is what knows which.
        $carriers = DeliveryPartner::query()
            ->where('driver', $driver)
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower($name))
            ->all();

        $order = $carriers === [] ? null : Order::query()
            ->where('tracking_number', $waybill)
            ->whereIn(DB::raw('lower(carrier)'), $carriers)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->latest('id')
            ->first();

        if ($order === null) {
            Log::info('A courier pushed an update for a parcel we do not have.', [
                'driver' => $driver, 'waybill' => $waybill,
            ]);
        }

        return $order;
    }

    /**
     * Both providers' words for a parcel's state, upper-cased, in ours.
     *
     * Kept here rather than reached for on the client because a webhook must
     * be answerable without credentials — the courier is telling us something,
     * and needing a working login to understand it would mean an outage on
     * their sign-in silently discarding news about real parcels.
     */
    protected function normalise(string $raw): string
    {
        return match ($raw) {
            'NEW', 'AWB ASSIGNED', 'PICKUP SCHEDULED', 'MANIFESTED', 'NOT PICKED' => 'booked',
            'PICKED UP', 'IN TRANSIT', 'PENDING' => 'in_transit',
            'OUT FOR DELIVERY', 'DISPATCHED' => 'out_for_delivery',
            'DELIVERED' => 'delivered',
            'UNDELIVERED', 'NDR' => 'undelivered',
            'RTO INITIATED', 'RTO ACKNOWLEDGED', 'RTO IN TRANSIT', 'RTO', 'DTO' => 'returning',
            'RTO DELIVERED' => 'returned',
            'CANCELED', 'CANCELLED' => 'cancelled',
            'LOST' => 'lost',
            default => 'in_transit',
        };
    }

    /**
     * Whether the caller knows the secret.
     *
     * An unset secret refuses everything. The alternative — an open endpoint
     * that writes to orders until somebody remembers to configure it — is the
     * kind of default that is discovered rather than noticed.
     */
    protected function authorised(Request $request, string $expected): bool
    {
        if (trim($expected) === '') {
            return false;
        }

        $given = (string) ($request->header('x-api-key')
            ?? $request->bearerToken()
            ?? $request->query('token', ''));

        return hash_equals($expected, $given);
    }
}
