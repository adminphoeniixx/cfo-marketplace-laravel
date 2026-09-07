<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Invoicing\IssueCommissionInvoice;
use App\Actions\Invoicing\IssueTaxInvoice;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\VendorPayout;
use Illuminate\Contracts\View\View;

/**
 * Every invoice the marketplace can see, which is all of them.
 *
 * Support answers for both sides of a marketplace, so it has to be able to
 * open the seller's document as well as its own — a shopper asking "where is
 * my bill" is a question about a seller's invoice, not the marketplace's.
 */
class InvoiceController extends Controller
{
    public function order(Order $order, int $vendor): View
    {
        $order->loadMissing('items');
        $invoice = app(IssueTaxInvoice::class)->handle($order, $vendor);

        abort_if($invoice === null, 404);

        return view('invoices.document', ['invoice' => $invoice]);
    }

    public function commission(VendorPayout $payout): View
    {
        $invoice = app(IssueCommissionInvoice::class)->handle($payout);

        abort_if($invoice === null, 404);

        return view('invoices.document', ['invoice' => $invoice]);
    }
}
