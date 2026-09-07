<?php

namespace App\Http\Controllers\Seller;

use App\Actions\Invoicing\IssueCommissionInvoice;
use App\Actions\Invoicing\IssueTaxInvoice;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\VendorPayout;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The same two documents as the seller API, printed rather than linked.
 *
 * The panel is a browser with a session, so there is nothing to sign: the
 * store is read off the signed-in user and a document belonging to another
 * store is a 404, exactly as it is everywhere else in this panel.
 */
class InvoiceController extends Controller
{
    public function order(Request $request, Order $order): View
    {
        $storeId = (int) $request->user()->vendor_id;

        abort_unless($order->items()->where('vendor_id', $storeId)->exists(), 404);

        $order->loadMissing('items');
        $invoice = app(IssueTaxInvoice::class)->handle($order, $storeId);

        abort_if($invoice === null, 404);

        return view('invoices.document', ['invoice' => $invoice]);
    }

    public function commission(Request $request, VendorPayout $payout): View
    {
        abort_unless($payout->vendor_id === (int) $request->user()->vendor_id, 404);

        $invoice = app(IssueCommissionInvoice::class)->handle($payout);

        abort_if($invoice === null, 404);

        return view('invoices.document', ['invoice' => $invoice]);
    }
}
