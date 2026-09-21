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
    .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px;
        text-transform: uppercase; letter-spacing: 0.3px; background: #f4f4f4; color: #555; }
    .badge-edited { background: #fff4e0; color: #a56a00; }
    .kpi-table { width: 100%; margin: 16px 0; border-collapse: collapse; }
    .kpi-table td { width: 20%; padding: 8px 6px; border: 1px solid #eee; vertical-align: top; }
    .kpi-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.3px; }
    .kpi-value { font-size: 14px; font-weight: bold; margin-top: 3px; color: {{ $company['brand_color'] }}; }
    .section-title { font-size: 10.5px; text-transform: uppercase; color: #888; margin: 20px 0 6px; letter-spacing: 0.4px;
        border-bottom: 1px solid #ddd; padding-bottom: 4px; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #f4f4f4; text-align: left; padding: 6px 8px; font-size: 9px;
        text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
    table.data td { padding: 6px 8px; font-size: 10.5px; border-bottom: 1px solid #eee; }
    table.data .num { text-align: right; }
    .muted { color: #999; font-size: 10px; }
    .reason-box { background: #fafafa; border: 1px solid #eee; border-radius: 4px; padding: 8px 10px; margin-top: 6px; font-size: 10.5px; }
    .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #eee; font-size: 9px; color: #999; text-align: center; }
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

<div class="doc-title">TRAVEL PLAN / PER-DIEM REQUEST #{{ $plan->id }}</div>
<div class="doc-number">
    Generated {{ now()->format('d M Y, H:i') }}
    @if($plan->revisions->isNotEmpty())
        &nbsp;·&nbsp; <span class="badge badge-edited">Edited by CTO</span>
    @endif
    &nbsp;·&nbsp; <span class="badge">{{ ucwords(str_replace('_', ' ', $plan->status)) }}</span>
</div>

<table class="kpi-table">
    <tr>
        <td><div class="kpi-label">Staff</div><div class="kpi-value" style="font-size:12px">{{ $plan->user?->name ?? '—' }}</div></td>
        <td><div class="kpi-label">Destination</div><div class="kpi-value" style="font-size:12px">{{ $plan->destination ?? '—' }}</div></td>
        <td><div class="kpi-label">Dates</div><div class="kpi-value" style="font-size:11px">{{ $plan->start_date?->format('d M Y') }} – {{ $plan->end_date?->format('d M Y') }}</div></td>
        <td><div class="kpi-label">Days</div><div class="kpi-value">{{ $plan->days_count ?? $plan->lines->count() }}</div></td>
        <td><div class="kpi-label">Total Amount</div><div class="kpi-value">{{ number_format($plan->amount) }}</div></td>
    </tr>
</table>

@if($plan->purpose)
    <div class="section-title">Purpose</div>
    <p>{{ $plan->purpose }}</p>
@endif

@if($plan->serviceTicket)
    <p class="muted">Linked ticket: {{ $plan->serviceTicket->ticket_number }}</p>
@endif

<div class="section-title">Day-by-Day Itinerary</div>
<table class="data">
    <thead>
        <tr>
            <th>#</th><th>Date</th><th>Region</th><th>District</th><th>Site</th><th>Activity</th>
            <th class="num">Per Diem</th><th class="num">Transport</th><th class="num">Labor</th>
        </tr>
    </thead>
    <tbody>
    @forelse($plan->lines as $line)
        <tr>
            <td>{{ $line->seq_no }}</td>
            <td>{{ \Carbon\Carbon::parse($line->date)->format('d M Y') }}</td>
            <td>{{ $line->region ?? '—' }}</td>
            <td>{{ $line->district ?? '—' }}</td>
            <td>{{ $line->site_name ?? '—' }}</td>
            <td>{{ $line->activity ?? '—' }}</td>
            <td class="num">{{ number_format($line->per_diem_cost ?? 0) }}</td>
            <td class="num">{{ number_format($line->transport_fare ?? 0) }}</td>
            <td class="num">{{ number_format($line->labor_cost ?? 0) }}</td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted">No day-by-day lines recorded — single-total legacy request.</td></tr>
    @endforelse
    </tbody>
</table>

@if(in_array($plan->status, ['rejected', 'cancelled'], true))
    <div class="section-title">{{ $plan->status === 'rejected' ? 'Rejection Reason' : 'Cancellation Reason' }}</div>
    <div class="reason-box">{{ $plan->rejection_reason ?? $plan->cancellation_reason ?? '—' }}</div>
@endif

@if($plan->adjustments->isNotEmpty())
    <div class="section-title">Payment Adjustments</div>
    <table class="data">
        <thead><tr><th>Date</th><th>Reason</th><th>By</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach($plan->adjustments as $adj)
            <tr>
                <td>{{ $adj->created_at->format('d M Y') }}</td>
                <td>{{ $adj->reason }}</td>
                <td>{{ $adj->createdBy?->name ?? '—' }}</td>
                <td class="num">{{ $adj->amount >= 0 ? '+' : '' }}{{ number_format($adj->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if($plan->revisions->isNotEmpty())
    <div class="section-title">Revision History</div>
    <table class="data">
        <thead><tr><th>Date</th><th>By</th><th>Role</th><th>Status</th><th>Reason</th></tr></thead>
        <tbody>
        @foreach($plan->revisions as $rev)
            <tr>
                <td>{{ $rev->created_at->format('d M Y H:i') }}</td>
                <td>{{ $rev->editor?->name ?? '—' }}</td>
                <td>{{ ucwords($rev->editor_role) }}</td>
                <td>{{ ucwords(str_replace('_', ' ', $rev->status)) }}</td>
                <td>{{ $rev->reason }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="footer">
    {{ $company['name'] }} · Travel plan generated by Hypermed &nbsp;·&nbsp; Status as of {{ now()->format('d M Y, H:i') }}
</div>

</body>
</html>
