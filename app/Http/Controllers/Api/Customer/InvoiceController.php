<?php

namespace App\Http\Controllers\Api\Customer;

use App\Actions\Invoicing\IssueTaxInvoice;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The invoice for an order.
 *
 * Two endpoints, because a download is not an API call. `link` is asked over
 * the token like everything else and hands back a signed, time-limited URL;
 * `show` is that URL, and carries no token at all — which is what lets it be
 * opened in a browser, handed to a download manager, or attached to an email
 * without the app having to proxy the bytes itself.
 *
 * The document is HTML rather than a PDF: this marketplace has no PDF library,
 * and a print-ready page renders to PDF in one keystroke on every platform the
 * shopper app runs on. Said plainly here so nobody plans around a promise the
 * response does not make.
 */
class InvoiceController extends Controller
{
    use ScopesToCustomer;

    /**
     * The tax invoices for this order — one per seller.
     *
     * The marketplace sells nothing: each seller supplies their own goods, so
     * each raises their own invoice on their own GSTIN. A basket across two
     * stores therefore answers with two documents, and an app must render a
     * list rather than assume a single one.
     *
     * The combined `invoice` beside this stays what it was: a summary of the
     * whole order, useful to a shopper reconciling one payment, and not a tax
     * document. This endpoint is the tax document.
     */
    public function taxInvoices(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);
        $model->loadMissing('items');

        $expires = now()->addDays(7);

        $invoices = app(IssueTaxInvoice::class)->forOrder($model)
            ->map(fn (Invoice $invoice) => [
                'number' => $invoice->number,
                'seller' => $invoice->snapshot['supplier']['name'] ?? null,
                'seller_gstin' => $invoice->snapshot['supplier']['gstin'] ?? null,
                'issued_at' => $invoice->issued_at->toIso8601String(),
                'total' => (float) $invoice->grand_total,
                'url' => URL::temporarySignedRoute(
                    'api.invoices.document',
                    $expires,
                    ['invoice' => $invoice->id],
                ),
                'content_type' => 'text/html',
            ])
            ->all();

        return response()->json([
            'data' => $invoices,
            'meta' => [
                'order_number' => $model->number,
                'expires_at' => $expires->toIso8601String(),
                // Empty is a real answer, not a failure: an order still
                // awaiting payment has not been supplied yet, so nobody has
                // raised an invoice for it.
                'count' => count($invoices),
            ],
        ]);
    }

    /**
     * A link to this order's combined summary, good for seven days.
     */
    public function link(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);
        $expires = now()->addDays(7);

        return response()->json([
            'data' => [
                'number' => $model->number,
                'url' => URL::temporarySignedRoute(
                    'api.customer.invoices.show',
                    $expires,
                    ['order' => $model->id],
                ),
                'expires_at' => $expires->toIso8601String(),
                'content_type' => 'text/html',
            ],
        ]);
    }

    /**
     * The invoice itself.
     *
     * Reached by signature rather than by token, so ownership cannot be
     * checked against a caller — the signature *is* the authority, and it is
     * only ever handed to the shopper whose order it is.
     */
    public function show(int $order): View
    {
        $model = Order::with(['items', 'customer'])->findOrFail($order);

        $vendors = Vendor::whereIn('id', $model->items->pluck('vendor_id')->filter()->unique())
            ->get(['id', 'name', 'city', 'state', 'gst_number', 'phone', 'store_email'])
            ->keyBy('id');

        return view('invoices.order', [
            'order' => $model,
            'vendors' => $vendors,
            // Lines grouped the way they were sold: one block per seller,
            // because a basket across two stores is two consignments and two
            // sets of tax registration numbers.
            'groups' => $model->items->groupBy('vendor_id'),
            'store' => [
                'name' => Setting::cached('store_name', config('app.name')),
                'email' => Setting::cached('store_email'),
                'phone' => Setting::cached('store_phone'),
                'address' => Setting::cached('address'),
            ],
        ]);
    }
}
