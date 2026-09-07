<?php

namespace App\Http\Controllers\Api\Seller;

use App\Actions\Invoicing\IssueCommissionInvoice;
use App\Actions\Invoicing\IssueTaxInvoice;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * A store's two invoices, from the two directions it is billed in.
 *
 * `order` is the one this store raised: their supply, their GSTIN, their
 * series, addressed to the shopper. `commission` is the one raised *against*
 * them: the marketplace's fee for the period, with GST on it, which is the
 * document their accountant needs to claim the input credit back.
 */
class InvoiceController extends Controller
{
    use ScopesToStore;

    /**
     * This store's tax invoice for an order.
     *
     * A shared basket holds another seller's lines too; this only ever answers
     * with our own, because it is our own supply.
     */
    public function order(Request $request, int $order): JsonResponse
    {
        $model = $this->storeOrders($request)->findOrFail($order);
        $model->loadMissing('items');

        $invoice = app(IssueTaxInvoice::class)->handle($model, $this->storeId($request));

        if ($invoice === null) {
            // Not a failure. An order still awaiting payment, or cancelled
            // before it was ever supplied, has no invoice to raise.
            return response()->json([
                'message' => 'No invoice has been raised for this order yet.',
            ], 409);
        }

        return response()->json(['data' => $this->payload($invoice)]);
    }

    /**
     * The marketplace's commission invoice for a payout.
     */
    public function commission(Request $request, int $payout): JsonResponse
    {
        $model = $this->storePayouts($request)->findOrFail($payout);

        $invoice = app(IssueCommissionInvoice::class)->handle($model);

        if ($invoice === null) {
            return response()->json([
                'message' => 'No commission invoice has been raised for this payout.',
            ], 409);
        }

        return response()->json(['data' => $this->payload($invoice)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice): array
    {
        $expires = now()->addDays(7);

        return [
            'number' => $invoice->number,
            'type' => $invoice->type,
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'taxable_value' => round((float) $invoice->subtotal - (float) $invoice->discount_total, 2),
            'tax_total' => (float) $invoice->tax_total,
            'total' => (float) $invoice->grand_total,
            'url' => URL::temporarySignedRoute(
                'api.invoices.document',
                $expires,
                ['invoice' => $invoice->id],
            ),
            'expires_at' => $expires->toIso8601String(),
            'content_type' => 'text/html',
        ];
    }
}
