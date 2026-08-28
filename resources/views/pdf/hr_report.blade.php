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
    .kpi-table { width: 100%; margin: 16px 0; border-collapse: collapse; }
    .kpi-table td { width: 16.6%; padding: 8px 6px; border: 1px solid #eee; vertical-align: top; }
    .kpi-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.3px; }
    .kpi-value { font-size: 15px; font-weight: bold; margin-top: 3px; color: {{ $company['brand_color'] }}; }
    .section-title { font-size: 10.5px; text-transform: uppercase; color: #888; margin: 20px 0 6px; letter-spacing: 0.4px;
        border-bottom: 1px solid #ddd; padding-bottom: 4px; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #f4f4f4; text-align: left; padding: 6px 8px; font-size: 9px;
        text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
    table.data td { padding: 6px 8px; font-size: 10.5px; border-bottom: 1px solid #eee; }
    table.data .num { text-align: right; }
    .two-col { width: 100%; }
    .two-col > td { width: 50%; vertical-align: top; padding-right: 14px; }
    .muted { color: #999; font-size: 10px; }
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

<div class="doc-title">HR REPORT</div>
<div class="doc-number">Generated {{ $generatedAt->format('d M Y, H:i') }} &nbsp;·&nbsp; {{ $year }}</div>

<table class="kpi-table">
    <tr>
        <td><div class="kpi-label">Headcount</div><div class="kpi-value">{{ $headcount }}</div></td>
        <td><div class="kpi-label">Turnover</div><div class="kpi-value">{{ $turnoverRate }}%</div></td>
        <td><div class="kpi-label">Active contracts</div><div class="kpi-value">{{ $activeContracts }}</div></td>
        <td><div class="kpi-label">Expiring (90d)</div><div class="kpi-value">{{ $expiringContracts->count() }}</div></td>
        <td><div class="kpi-label">Open cases</div><div class="kpi-value">{{ $openCases->count() }}</div></td>
        <td><div class="kpi-label">Payroll YTD</div><div class="kpi-value">{{ number_format($payrollNetYtd / 1000000, 1) }}M</div></td>
    </tr>
</table>

<table class="two-col">
    <tr>
        <td>
            <div class="section-title">Headcount by Department</div>
            <table class="data">
                <thead><tr><th>Department</th><th class="num">Staff</th></tr></thead>
                <tbody>
                @forelse($byDepartment as $dept => $n)
                    <tr><td>{{ $dept }}</td><td class="num">{{ $n }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">No staff on record.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>
        <td>
            <div class="section-title">Leave Used vs Allocated ({{ $year }})</div>
            <table class="data">
                <thead><tr><th>Type</th><th class="num">Used</th><th class="num">Allocated</th></tr></thead>
                <tbody>
                @forelse($leaveByType as $t)
                    <tr><td>{{ $t['label'] }}</td><td class="num">{{ $t['used'] }}</td><td class="num">{{ $t['allocated'] }}</td></tr>
                @empty
                    <tr><td colspan="3" class="muted">No leave types configured.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>
    </tr>
</table>

<table class="two-col">
    <tr>
        <td>
            <div class="section-title">Contract Mix</div>
            <table class="data">
                <thead><tr><th>Type</th><th class="num">Active</th></tr></thead>
                <tbody>
                @forelse($contractsByType as $type => $n)
                    <tr><td>{{ ucwords(str_replace('_', ' ', $type)) }}</td><td class="num">{{ $n }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">No active contracts.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>
        <td>
            <div class="section-title">Attendance — {{ $attendanceMonthLabel }}</div>
            <table class="data">
                <thead><tr><th>Status</th><th class="num">Days</th></tr></thead>
                <tbody>
                @forelse($attendanceByStatus as $status => $n)
                    <tr><td>{{ ucwords(str_replace('_', ' ', $status)) }}</td><td class="num">{{ $n }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">No attendance marked this month.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>
    </tr>
</table>

<div class="section-title">Contracts Expiring — Next 90 Days</div>
<table class="data">
    <thead><tr><th>Staff</th><th>Type</th><th>End Date</th><th class="num">Days Left</th></tr></thead>
    <tbody>
    @forelse($expiringContracts as $c)
        <tr>
            <td>{{ $c->user?->name ?? '—' }}</td>
            <td>{{ ucwords(str_replace('_', ' ', $c->contract_type)) }}</td>
            <td>{{ $c->end_date->toDateString() }}</td>
            <td class="num">{{ now()->diffInDays($c->end_date, false) }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">Nothing expiring within 90 days.</td></tr>
    @endforelse
    </tbody>
</table>

<table class="two-col">
    <tr>
        <td>
            <div class="section-title">Recruitment</div>
            <table class="data">
                <thead><tr><th>Vacancy</th><th class="num">Applicants</th></tr></thead>
                <tbody>
                @forelse($openVacancies as $v)
                    <tr><td>{{ $v->position?->title ?? 'Vacancy #' . $v->id }}</td><td class="num">{{ $v->applications_count }}</td></tr>
                @empty
                    <tr><td colspan="2" class="muted">No open vacancies.</td></tr>
                @endforelse
                </tbody>
            </table>
            <p class="muted" style="margin-top:6px">Talent pool: {{ $talentPoolCount }} · Hires by source: {{ $hiresBySource->map(fn($n,$s) => "$s ($n)")->implode(', ') ?: '—' }}</p>
        </td>
        <td>
            <div class="section-title">Discipline — Open Cases</div>
            <table class="data">
                <thead><tr><th>Staff</th><th>Stage</th><th>Incident</th></tr></thead>
                <tbody>
                @forelse($openCases as $c)
                    <tr><td>{{ $c->user?->name ?? '—' }}</td><td>{{ ucwords(str_replace('_', ' ', $c->stage)) }}</td><td>{{ $c->incident_date?->toDateString() }}</td></tr>
                @empty
                    <tr><td colspan="3" class="muted">No open cases — clean record.</td></tr>
                @endforelse
                </tbody>
            </table>
        </td>
    </tr>
</table>

<div class="footer">
    {{ $company['name'] }} · HR Report generated by Hypermed HR &nbsp;·&nbsp; Payroll runs YTD: {{ $payrollRunsYtd }}
</div>

</body>
</html>
