<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1a1a1a; }
    .brand-name { font-size: 20px; font-weight: bold; color: {{ $company['brand_color'] }}; margin: 0; }
    .brand-tagline { font-size: 10px; color: #666; margin: 2px 0 0; }
    .company-details { font-size: 9.5px; color: #666; text-align: right; line-height: 1.5; }
    .header-table { width: 100%; margin-bottom: 18px; }
    .doc-title { font-size: 16px; font-weight: bold; margin: 0 0 4px; }
    .doc-number { font-size: 11px; color: #666; }
    .status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: bold;
        text-transform: uppercase; background: #eef; color: {{ $company['brand_color'] }}; }
    .meta-table { width: 100%; margin: 14px 0; }
    .meta-table td { vertical-align: top; padding-right: 20px; }
    .meta-label { font-size: 8.5px; color: #888; text-transform: uppercase; letter-spacing: 0.4px; }
    .meta-value { font-size: 11.5px; font-weight: bold; margin-top: 2px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
    table.items th { background: #f4f4f4; text-align: left; padding: 7px 8px; font-size: 9.5px;
        text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
    table.items td { padding: 7px 8px; font-size: 11px; border-bottom: 1px solid #eee; }
    table.items .num { text-align: right; }
    .totals { width: 260px; margin-left: auto; margin-top: 10px; }
    .totals td { padding: 4px 8px; font-size: 11px; }
    .totals .label { color: #666; }
    .totals .value { text-align: right; }
    .totals .grand td { border-top: 2px solid {{ $company['brand_color'] }}; padding-top: 8px;
        font-size: 14px; font-weight: bold; }
    .totals .grand .value { color: {{ $company['brand_color'] }}; }
    .section-title { font-size: 10px; text-transform: uppercase; color: #888; margin: 18px 0 4px; letter-spacing: 0.4px; }
    .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #eee; font-size: 9px; color: #999; text-align: center; }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width: 60%;">
            <p class="brand-name">{{ $company['name'] }}</p>
            <p class="brand-tagline">{{ $company['tagline'] }}</p>
        </td>
        <td class="company-details" style="width: 40%;">
            {{ $company['address'] }}<br>
            {{ $company['phone'] }}<br>
            {{ $company['email'] }}
        </td>
    </tr>
</table>

<div class="doc-title">QUOTATION</div>
<div class="doc-number">{{ $quotation->quotation_number }} &nbsp;·&nbsp;
    <span class="status-badge">{{ strtoupper($quotation->status) }}</span>
</div>

<table class="meta-table">
    <tr>
        <td>
            <div class="meta-label">Prepared For</div>
            <div class="meta-value">{{ $quotation->client_name }}</div>
            @if($quotation->client_contact)
                <div style="font-size: 10.5px; color: #666;">{{ $quotation->client_contact }}</div>
            @endif
            @if($quotation->client_email)
                <div style="font-size: 10.5px; color: #666;">{{ $quotation->client_email }}</div>
            @endif
        </td>
        <td>
            <div class="meta-label">Date Issued</div>
            <div class="meta-value">{{ $quotation->created_at->format('d M Y') }}</div>
        </td>
        <td>
            <div class="meta-label">Valid Until</div>
            <div class="meta-value">{{ $quotation->valid_until?->format('d M Y') ?? '—' }}</div>
        </td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width: 42%;">Description</th>
            <th class="num" style="width: 10%;">Qty</th>
            <th class="num" style="width: 16%;">Unit Price</th>
            <th class="num" style="width: 10%;">Disc.</th>
            <th class="num" style="width: 22%;">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($quotation->items as $item)
        <tr>
            <td>{{ $item->description }}</td>
            <td class="num">{{ $item->quantity }} {{ $item->unit_of_measure }}</td>
            <td class="num">{{ number_format($item->unit_price) }}</td>
            <td class="num">{{ $item->discount_percent > 0 ? number_format($item->discount_percent, 1) . '%' : '—' }}</td>
            <td class="num">{{ number_format($item->total_price) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td class="label">Subtotal</td><td class="value">{{ $currencyLabel }} {{ number_format($quotation->subtotal) }}</td></tr>
    @if($quotation->discount_amount > 0)
    <tr><td class="label">Discount</td><td class="value">-{{ $currencyLabel }} {{ number_format($quotation->discount_amount) }}</td></tr>
    @endif
    @if($quotation->tax_amount > 0)
    <tr><td class="label">Tax</td><td class="value">{{ $currencyLabel }} {{ number_format($quotation->tax_amount) }}</td></tr>
    @endif
    <tr class="grand"><td class="label">Total</td><td class="value">{{ $currencyLabel }} {{ number_format($quotation->total_amount) }}</td></tr>
</table>

@if($quotation->notes)
<div class="section-title">Notes</div>
<div style="font-size: 10.5px; color: #444;">{{ $quotation->notes }}</div>
@endif

@if($quotation->terms)
<div class="section-title">Terms &amp; Conditions</div>
<div style="font-size: 10.5px; color: #444;">{{ $quotation->terms }}</div>
@endif

<div class="footer">
    {{ $company['name'] }} &nbsp;·&nbsp; Generated {{ now()->format('d M Y H:i') }}
</div>

</body>
</html>
