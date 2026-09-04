{{--
    The invoice, as a page that prints.

    No PDF library in this project, and none needed: every platform the shopper
    app runs on can turn a print-ready page into a PDF, and an HTML invoice
    stays readable in a browser, an email client and a screen reader — which a
    generated PDF does not.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $order->number }}</title>
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
        .sheet { max-width: 760px; margin: 0 auto; background: #fff; padding: 34px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.09); }
        h1 { font-size: 19px; margin: 0 0 2px; letter-spacing: -.2px; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .07em; color: #6b6b70; margin: 26px 0 8px; }
        .muted { color: #6b6b70; }
        .head { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap; align-items: flex-start; }
        .head .right { text-align: right; }
        .pill { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #eaf7ee; color: #14663a; }
        .pill.due { background: #fdf2e3; color: #7a4a08; }
        .cols { display: flex; gap: 28px; flex-wrap: wrap; }
        .cols > div { flex: 1 1 220px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #ececee; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #6b6b70; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { border: 0; padding: 3px 6px; }
        tfoot tr.grand td { border-top: 1px solid #1a1a1a; font-weight: 700; padding-top: 8px; }
        .seller { font-weight: 700; margin-top: 18px; }
        footer { max-width: 760px; margin: 14px auto 0; font-size: 11px; }
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
            <h1>{{ $store['name'] }}</h1>
            @if ($store['address'])<div class="muted">{!! nl2br(e($store['address'])) !!}</div>@endif
            <div class="muted">
                {{ $store['email'] }}@if ($store['phone']) · {{ $store['phone'] }}@endif
            </div>
        </div>
        <div class="right">
            <h1>Invoice</h1>
            <div class="muted">{{ $order->number }}</div>
            <div class="muted">{{ optional($order->placed_at ?? $order->created_at)->format('j M Y') }}</div>
            <div style="margin-top:6px">
                <span class="pill {{ $order->payment_status === 'paid' ? '' : 'due' }}">
                    {{ $order->payment_status === 'paid' ? 'Paid' : 'Payment due' }}
                </span>
            </div>
        </div>
    </div>

    <h2>Billed to</h2>
    <div class="cols">
        <div>
            @php($address = $order->shipping_address ?? [])
            <div><strong>{{ trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')) ?: $order->customer?->name }}</strong></div>
            @foreach (['address_line1', 'address_line2'] as $line)
                @if (! empty($address[$line]))<div class="muted">{{ $address[$line] }}</div>@endif
            @endforeach
            <div class="muted">
                {{ collect([$address['city'] ?? null, $address['state'] ?? null, $address['postcode'] ?? null])->filter()->join(', ') }}
            </div>
            @if (! empty($address['phone']))<div class="muted">{{ $address['phone'] }}</div>@endif
        </div>
        <div>
            <div class="muted">Payment</div>
            <div>{{ $order->payment_method ?: 'Not recorded' }}</div>
            @if ($order->transaction_id)<div class="muted">{{ $order->transaction_id }}</div>@endif
            @if ($order->shipping_method)
                <div class="muted" style="margin-top:6px">Delivery</div>
                <div>{{ $order->shipping_method }}</div>
            @endif
        </div>
    </div>

    @foreach ($groups as $vendorId => $lines)
        {{-- One block per seller: a basket across two stores is two consignments. --}}
        <div class="seller">{{ $vendors[$vendorId]->name ?? 'Marketplace' }}</div>
        @if (! empty($vendors[$vendorId]?->gst_number))
            <div class="muted" style="font-size:11.5px">GSTIN {{ $vendors[$vendorId]->gst_number }}</div>
        @endif
        <table>
            <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Unit</th>
                <th class="num">Tax</th>
                <th class="num">Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        {{ $line->name }}
                        @if ($line->sku)<div class="muted" style="font-size:11.5px">SKU {{ $line->sku }}</div>@endif
                        @foreach ((array) $line->options as $key => $value)
                            <div class="muted" style="font-size:11.5px">{{ $key }}: {{ $value }}</div>
                        @endforeach
                    </td>
                    <td class="num">{{ (int) $line->quantity }}</td>
                    <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->tax_amount, 2) }}</td>
                    <td class="num">{{ number_format((float) $line->total, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach

    <table style="margin-top:18px">
        <tfoot>
        <tr><td>Items</td><td class="num">₹{{ number_format((float) $order->subtotal, 2) }}</td></tr>
        @if ((float) $order->discount_total > 0)
            <tr>
                <td>Discount @if ($order->coupon_code)({{ $order->coupon_code }})@endif</td>
                <td class="num">−₹{{ number_format((float) $order->discount_total, 2) }}</td>
            </tr>
        @endif
        <tr><td>Delivery</td><td class="num">{{ (float) $order->shipping_total > 0 ? '₹'.number_format((float) $order->shipping_total, 2) : 'FREE' }}</td></tr>
        <tr><td>Tax</td><td class="num">₹{{ number_format((float) $order->tax_total, 2) }}</td></tr>
        <tr class="grand"><td>{{ $order->payment_status === 'paid' ? 'Paid' : 'Payable' }}</td><td class="num">₹{{ number_format((float) $order->grand_total, 2) }}</td></tr>
        @if ((float) $order->refunded_total > 0)
            <tr><td class="muted">Refunded</td><td class="num muted">₹{{ number_format((float) $order->refunded_total, 2) }}</td></tr>
        @endif
        </tfoot>
    </table>
</div>
<footer class="muted">
    Sold by the sellers named above through {{ $store['name'] }}. Questions about a line go to that seller;
    anything else to {{ $store['email'] }}.
</footer>
</body>
</html>
