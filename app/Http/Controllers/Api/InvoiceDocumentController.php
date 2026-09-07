<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Contracts\View\View;

/**
 * An issued invoice, rendered.
 *
 * One route for both kinds and for both audiences, because a document reached
 * by signature has no audience to check against — the signature *is* the
 * authority, and it is only ever handed to the party whose invoice it is. The
 * controllers that mint those links are where ownership is decided.
 *
 * Carries no token, which is what lets the page be opened in a browser, handed
 * to a download manager or attached to an email without an app proxying bytes.
 */
class InvoiceDocumentController extends Controller
{
    public function __invoke(int $invoice): View
    {
        return view('invoices.document', [
            'invoice' => Invoice::findOrFail($invoice),
        ]);
    }
}
