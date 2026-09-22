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
    .info-table { width: 100%; margin: 16px 0; border: 1px solid #ddd; border-collapse: collapse; }
    .info-table td { padding: 6px 8px; font-size: 11px; border-bottom: 1px solid #eee; }
    .info-table td.label { width: 22%; font-weight: bold; }
    .section-title { font-size: 10.5px; text-transform: uppercase; color: #888; margin: 20px 0 6px; letter-spacing: 0.4px;
        border-bottom: 1px solid #ddd; padding-bottom: 4px; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #f4f4f4; text-align: left; padding: 6px 8px; font-size: 9px;
        text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
    table.data td { padding: 6px 8px; font-size: 10.5px; border-bottom: 1px solid #eee; }
    table.data .num { text-align: right; }
    table.data tfoot td { font-weight: bold; border-top: 1px solid #ccc; }
    .muted { color: #999; font-size: 10px; }
    .reason-box { background: #fafafa; border: 1px solid #eee; border-radius: 4px; padding: 8px 10px; margin-top: 6px; font-size: 10.5px; }
    .summary-table { width: 60%; margin-top: 10px; margin-left: auto; border-collapse: collapse; }
    .summary-table td { padding: 4px 8px; font-size: 11px; }
    .summary-table td.val { text-align: right; font-weight: bold; }
    .summary-table tr.grand td { border-top: 1px solid #999; font-size: 12.5px; }
    .payment-row { background: #f4f4f4; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; margin-top: 12px; font-size: 11px; }
    .payment-row b { margin-right: 18px; }
    .sig-table { width: 100%; margin-top: 20px; border-collapse: collapse; }
    .sig-table tr { page-break-inside: avoid; }
    .sig-table td { width: 50%; vertical-align: top; padding: 6px 10px 16px; font-size: 10.5px; }
    .sig-role { font-weight: bold; }
    .sig-line { color: #888; margin-top: 10px; }
    .sig-approved { color: #2a7a2a; margin-top: 10px; }
    .sig-date { color: #666; font-size: 9.5px; margin-top: 2px; }
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

<div class="doc-title">Work Plan — Travel Plan #{{ $plan->id }}</div>
<div class="doc-number">
    Generated {{ now()->format('d M Y, H:i') }}
    @if($plan->revisions->isNotEmpty())
        &nbsp;·&nbsp; <span class="badge badge-edited">Edited by CTO</span>
    @endif
    &nbsp;·&nbsp; <span class="badge">{{ ucwords(str_replace('_', ' ', $plan->status)) }}</span>
</div>

{{-- Section 15.4: header block in the template's own order --}}
<table class="info-table">
    <tr><td class="label">Description of the Trip</td><td>{{ $plan->purpose ?: '—' }}</td></tr>
    <tr><td class="label">Trip coverage date</td><td>{{ $plan->start_date?->format('d/m/Y') }} – {{ $plan->end_date?->format('d/m/Y') }}</td></tr>
    <tr><td class="label">Name</td><td>{{ $plan->staff_name_snapshot ?? $plan->user?->name ?? '—' }}</td></tr>
    <tr><td class="label">Designation</td><td>{{ $plan->staff_designation_snapshot ?? '—' }}</td></tr>
</table>

@if($plan->serviceTicket)
    <p class="muted">Linked ticket: {{ $plan->serviceTicket->ticket_number }}</p>
@endif

{{-- Section 15.4 step 2: same column order as the XLSX/template —
     No., Date, Region, District, Site name, Activity, Labor, Per diem,
     Transportation fare. --}}
<div class="section-title">Day-by-Day Itinerary</div>
<table class="data">
    <thead>
        <tr>
            <th>No.</th><th>Date</th><th>Region</th><th>District</th><th>Site name</th><th>Activity</th>
            <th class="num">Labor</th><th class="num">Per diem</th><th class="num">Transportation fare</th>
        </tr>
    </thead>
    <tbody>
    @forelse($plan->lines as $line)
        <tr>
            <td>{{ $line->seq_no }}</td>
            <td>{{ \Carbon\Carbon::parse($line->date)->format('l, F j, Y') }}</td>
            <td>{{ $line->region ?? '—' }}</td>
            <td>{{ $line->district ?? '—' }}</td>
            <td>{{ $line->site_name ?? '—' }}</td>
            <td>{{ $line->activity ?? '—' }}</td>
            <td class="num">{{ number_format($line->labor_cost ?? 0, 2) }}</td>
            <td class="num">{{ number_format($line->per_diem_cost ?? 0, 2) }}</td>
            <td class="num">{{ number_format($line->transport_fare ?? 0, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted">No day-by-day lines recorded — single-total legacy request.</td></tr>
    @endforelse
    </tbody>
    @if($plan->lines->isNotEmpty())
    <tfoot>
        <tr>
            <td colspan="6">Total</td>
            <td class="num">{{ number_format($summary['total_labor'], 2) }}</td>
            <td class="num">{{ number_format($summary['total_per_diem'], 2) }}</td>
            <td class="num">{{ number_format($summary['total_transport'], 2) }}</td>
        </tr>
    </tfoot>
    @endif
</table>

{{-- Section 15.4 step 3: totals + summary block, all computed server-side --}}
<table class="summary-table">
    <tr><td>Number of sites visited</td><td class="val">{{ $summary['sites_visited'] }}</td></tr>
    <tr><td>Total number of days spent</td><td class="val">{{ $summary['days_spent'] }}</td></tr>
    <tr><td>Average number of days / site</td><td class="val">{{ $summary['avg_days_per_site'] ?? '-' }}</td></tr>
    <tr><td>Average Cost Per site</td><td class="val">{{ $summary['avg_cost_per_site'] !== null ? number_format($summary['avg_cost_per_site'], 2) : '-' }}</td></tr>
    <tr class="grand"><td>Grand total</td><td class="val">{{ number_format($summary['grand_total'], 2) }}</td></tr>
</table>

{{-- Section 15.4 step 4: payment details, masked unless the viewer is
     permitted to see the full account number (15.7/15.8). --}}
@if($plan->payment_snapshot)
    @php
        $acct = $plan->payment_snapshot['account_number'] ?? '';
        if (! $canSeeFullPayment && strlen($acct) > 4) {
            $acct = str_repeat('*', strlen($acct) - 4) . substr($acct, -4);
        }
    @endphp
    <div class="payment-row">
        <b>{{ $plan->payment_snapshot['provider'] ?? '' }}</b>
        <span>{{ $acct }}</span>
        &nbsp;&nbsp;
        <span>ACCOUNT NAME: {{ $plan->payment_snapshot['account_name'] ?? '' }}</span>
    </div>
@endif

{{-- Section 15.4 step 5 / 15.6: signature block generated from the
     plan's actual approval stages, not hardcoded. --}}
<div class="section-title">Sign-off</div>
<table class="sig-table">
    <tr>
        <td>
            <div class="sig-role">{{ $signatureBlock[0]['role_label'] }}: {{ $signatureBlock[0]['person_name'] ?? '—' }}</div>
            <div class="sig-date">{{ $signatureBlock[0]['acted_at_display'] ?? '' }}</div>
        </td>
        <td></td>
    </tr>
    @foreach (array_slice($signatureBlock, 1) as $line)
    <tr>
        <td>
            <div class="sig-role">{{ $line['role_label'] }}: {{ $line['person_name'] ?? '—' }}</div>
            @if ($line['pending'])
                <div class="sig-line">Signature: …..............................</div>
            @else
                <div class="sig-approved">Approved electronically on {{ $line['acted_at_display'] }}</div>
            @endif
        </td>
        <td></td>
    </tr>
    @endforeach
</table>

@if(in_array($plan->status, ['rejected', 'cancelled'], true))
    <div class="section-title">{{ $plan->status === 'rejected' ? 'Rejection Reason' : 'Cancellation Reason' }}</div>
    <div class="reason-box">{{ $plan->rejection_reason ?? $plan->team_lead_rejection_reason ?? $plan->cancellation_reason ?? '—' }}</div>
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
