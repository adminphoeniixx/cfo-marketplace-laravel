{{--
    An issued invoice, printed from its own snapshot.

    Nothing here reads the order, the vendor or the settings table. Everything
    on the page was copied into `snapshot` the moment the number was claimed,
    because an invoice is a record of what was true when it was issued — a
    seller who moves premises next March must not rewrite last April's paper.

    HTML rather than PDF, for the same reason the order summary is: this
    project has no PDF library, and every platform the apps run on turns a
    print-ready page into a PDF in one keystroke.
--}}
@php
    $snapshot = $invoice->snapshot;
    $supplier = $snapshot['supplier'] ?? [];
    $recipient = $snapshot['recipient'] ?? [];
    $lines = $snapshot['lines'] ?? [];
    $isCommission = $invoice->type === \App\Models\Invoice::COMMISSION;
    $treatment = $snapshot['tax_treatment'] ?? 'unknown';
    $money = fn ($amount) => '₹'.number_format((float) $amount, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $isCommission ? 'Commission invoice' : 'Tax invoice' }} {{ $invoice->number }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 32px 20px 64px;
            background: #f6f6f7;
            color: #1a1a1a;
            font: 13px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .sheet { max-width: 780px; margin: 0 auto; background: #fff; padding: 34px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.09); }
        h1 { font-size: 19px; margin: 0 0 2px; letter-spacing: -.2px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: #6b6b70; margin: 24px 0 6px; }
        .muted { color: #6b6b70; }
        .head { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; align-items: flex-start; }
        .head .right { text-align: right; }
        .doctype { font-size: 11px; text-transform: uppercase; letter-spacing: .09em; font-weight: 700; color: #6b6b70; }
        .gstin { font-size: 11.5px; font-weight: 700; letter-spacing: .02em; }
        .cols { display: flex; gap: 28px; flex-wrap: wrap; margin-top: 4px; }
        .cols > div { flex: 1 1 240px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #ececee; vertical-align: top; }
        th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: #6b6b70; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { border: 0; padding: 3px 6px; }
        tfoot tr.grand td { border-top: 1px solid #1a1a1a; font-weight: 700; padding-top: 8px; }
        .totals { max-width: 320px; margin-left: auto; margin-top: 14px; }
        .note { margin-top: 22px; font-size: 11.5px; border-top: 1px solid #ececee; padding-top: 12px; }
        footer { max-width: 780px; margin: 14px auto 0; font-size: 11px; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; padding: 0; max-width: none; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="head">
        <div>
            <div class="doctype">{{ $isCommission ? 'Sold by' : 'Sold by' }}</div>
            <h1>{{ $supplier['name'] ?? '' }}</h1>
            @foreach ((array) ($supplier['address'] ?? []) as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
            <div class="muted">
                {{ collect([$supplier['city'] ?? null, $supplier['state'] ?? null, $supplier['postcode'] ?? null])->filter()->join(', ') }}
            </div>
            @if (! empty($supplier['gstin']))
                <div class="gstin" style="margin-top:5px">GSTIN {{ $supplier['gstin'] }}</div>
            @endif
            @if (! empty($supplier['phone']) || ! empty($supplier['email']))
                <div class="muted">{{ collect([$supplier['phone'] ?? null, $supplier['email'] ?? null])->filter()->join(' · ') }}</div>
            @endif
        </div>
        <div class="right">
            <div class="doctype">Tax invoice</div>
            <h1>{{ $invoice->number }}</h1>
            <div class="muted">{{ $invoice->issued_at->format('j M Y') }}</div>
            @if (! $isCommission && ! empty($snapshot['order']['number']))
                <div class="muted">Order {{ $snapshot['order']['number'] }}</div>
            @endif
            @if ($isCommission && ! empty($snapshot['payout']['number']))
                <div class="muted">Payout {{ $snapshot['payout']['number'] }}</div>
            @endif
        </div>
    </div>

    <div class="cols">
        <div>
            <h2>{{ $isCommission ? 'Billed to (seller)' : 'Billed to' }}</h2>
            <div><strong>{{ $recipient['name'] ?? '' }}</strong></div>
            @foreach ((array) ($recipient['address'] ?? []) as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
            <div class="muted">
                {{ collect([$recipient['city'] ?? null, $recipient['state'] ?? null, $recipient['postcode'] ?? null])->filter()->join(', ') }}
            </div>
            @if (! empty($recipient['gstin']))
                <div class="gstin" style="margin-top:5px">GSTIN {{ $recipient['gstin'] }}</div>
            @endif
            @if (! empty($recipient['phone']))<div class="muted">{{ $recipient['phone'] }}</div>@endif
        </div>
        <div>
            <h2>Supply</h2>
            <div class="muted">Place of supply</div>
            <div>{{ $snapshot['place_of_supply'] ?? 'Not recorded' }}</div>
            @if (! $isCommission && ! empty($snapshot['order']))
                <div class="muted" style="margin-top:6px">Payment</div>
                <div>{{ $snapshot['order']['payment_method'] ?: 'Not recorded' }}</div>
            @endif
            @if ($isCommission && ! empty($snapshot['payout']))
                <div class="muted" style="margin-top:6px">Period</div>
                <div>
                    {{ \Illuminate\Support\Carbon::parse($snapshot['payout']['period_start'])->format('j M Y') }}
                    –
                    {{ \Illuminate\Support\Carbon::parse($snapshot['payout']['period_end'])->format('j M Y') }}
                </div>
            @endif
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>{{ $isCommission ? 'Service' : 'Item' }}</th>
            @unless ($isCommission)<th class="num">Qty</th>@endunless
            <th class="num">Taxable</th>
            @if ($treatment === 'intra')
                <th class="num">CGST</th>
                <th class="num">SGST</th>
            @elseif ($treatment === 'inter')
                <th class="num">IGST</th>
            @else
                <th class="num">Tax</th>
            @endif
            <th class="num">Total</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>
                    {{ $line['name'] }}
                    @if (! empty($line['description']))
                        <div class="muted" style="font-size:11.5px">{{ $line['description'] }}</div>
                    @endif
                    @if (! empty($line['sku']))<div class="muted" style="font-size:11.5px">SKU {{ $line['sku'] }}</div>@endif
                    @foreach ((array) ($line['options'] ?? []) as $key => $value)
                        <div class="muted" style="font-size:11.5px">{{ $key }}: {{ $value }}</div>
                    @endforeach
                    @if (! empty($line['tax_rate']))
                        <div class="muted" style="font-size:11.5px">GST {{ rtrim(rtrim(number_format((float) $line['tax_rate'], 2), '0'), '.') }}%</div>
                    @endif
                </td>
                @unless ($isCommission)<td class="num">{{ (int) ($line['quantity'] ?? 1) }}</td>@endunless
                <td class="num">{{ number_format((float) $line['taxable_value'], 2) }}</td>
                @if ($treatment === 'intra')
                    <td class="num">{{ number_format((float) $line['cgst'], 2) }}</td>
                    <td class="num">{{ number_format((float) $line['sgst'], 2) }}</td>
                @elseif ($treatment === 'inter')
                    <td class="num">{{ number_format((float) $line['igst'], 2) }}</td>
                @else
                    <td class="num">{{ number_format((float) $line['tax_amount'], 2) }}</td>
                @endif
                <td class="num">{{ number_format((float) $line['total'], 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tfoot>
        <tr><td>Taxable value</td><td class="num">{{ $money((float) $invoice->subtotal - (float) $invoice->discount_total) }}</td></tr>
        @if ((float) $invoice->shipping_total > 0)
            <tr><td>Delivery</td><td class="num">{{ $money($invoice->shipping_total) }}</td></tr>
        @endif
        @if ($treatment === 'intra')
            <tr><td>CGST</td><td class="num">{{ $money($invoice->cgst_total) }}</td></tr>
            <tr><td>SGST</td><td class="num">{{ $money($invoice->sgst_total) }}</td></tr>
        @elseif ($treatment === 'inter')
            <tr><td>IGST</td><td class="num">{{ $money($invoice->igst_total) }}</td></tr>
        @else
            <tr><td>Tax</td><td class="num">{{ $money($invoice->tax_total) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $money($invoice->grand_total) }}</td></tr>
        </tfoot>
    </table>

    <div class="note muted">
        @if ($isCommission)
            Charged by {{ $supplier['name'] ?? 'the marketplace' }} for the use of the platform over the period
            named above. The fee is deducted from payout {{ $snapshot['payout']['number'] ?? '' }};
            this invoice is your record of the GST charged on it.
        @else
            Sold by {{ $supplier['name'] ?? 'the seller' }}, who is the supplier of these goods.
            Payment was collected on their behalf by {{ $snapshot['collected_by'] ?? 'the marketplace' }}.
            Questions about an item on this invoice go to the seller named above.
        @endif
        @if ($treatment === 'unknown')
            <div style="margin-top:6px">
                The place of supply is not recorded against this document, so tax is shown as a single
                figure rather than split into CGST and SGST or charged as IGST.
            </div>
        @endif
    </div>
</div>
<footer class="muted">
    {{ $invoice->number }} · issued {{ $invoice->issued_at->format('j M Y') }} · this is a computer-generated invoice.
</footer>
</body>
</html>
