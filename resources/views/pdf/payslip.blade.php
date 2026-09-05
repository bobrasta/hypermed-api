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
    .employee-table { width: 100%; margin: 16px 0; border-collapse: collapse; }
    .employee-table td { padding: 4px 0; font-size: 10.5px; vertical-align: top; }
    .employee-table .lbl { color: #888; width: 130px; }
    .section-title { font-size: 10.5px; text-transform: uppercase; color: #888; margin: 20px 0 6px; letter-spacing: 0.4px;
        border-bottom: 1px solid #ddd; padding-bottom: 4px; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #f4f4f4; text-align: left; padding: 6px 8px; font-size: 9px;
        text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
    table.data td { padding: 6px 8px; font-size: 10.5px; border-bottom: 1px solid #eee; }
    table.data .num { text-align: right; }
    .two-col { width: 100%; }
    .two-col > td { width: 50%; vertical-align: top; padding-right: 14px; }
    .net-pay-table { width: 100%; margin-top: 16px; border-collapse: collapse; }
    .net-pay-table td { padding: 10px 12px; }
    .net-pay-table .lbl { font-size: 11px; text-transform: uppercase; color: #fff; opacity: 0.85; }
    .net-pay-table .val { font-size: 18px; font-weight: bold; color: #fff; text-align: right; }
    .net-pay-box { background: {{ $company['brand_color'] }}; }
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

<div class="doc-title">PAYSLIP</div>
<div class="doc-number">{{ $periodLabel }}</div>

<table class="employee-table">
    <tr>
        <td class="lbl">Employee</td><td><strong>{{ $user->name }}</strong></td>
        <td class="lbl">NSSF No.</td><td>{{ $user->nssf_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Position</td><td>{{ $user->position?->title ?? '—' }}</td>
        <td class="lbl">TIN No.</td><td>{{ $user->tin_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Pay Period</td><td>{{ $periodLabel }}</td>
        <td class="lbl">Paid On</td><td>{{ $paidAt ?? '—' }}</td>
    </tr>
</table>

<table class="two-col">
    <tr>
        <td>
            <div class="section-title">Earnings</div>
            <table class="data">
                <tbody>
                    <tr><td>Base Salary</td><td class="num">{{ number_format($item->base_salary) }}</td></tr>
                    <tr><td>Allowances</td><td class="num">{{ number_format($item->allowances_total) }}</td></tr>
                    <tr><td>Overtime</td><td class="num">{{ number_format($item->overtime_amount) }}</td></tr>
                    <tr><td><strong>Gross Pay</strong></td><td class="num"><strong>{{ number_format($item->gross_pay) }}</strong></td></tr>
                </tbody>
            </table>
        </td>
        <td>
            <div class="section-title">Deductions</div>
            <table class="data">
                <tbody>
                    <tr><td>PAYE</td><td class="num">{{ number_format($item->paye_amount) }}</td></tr>
                    <tr><td>NSSF</td><td class="num">{{ number_format($item->nssf_amount) }}</td></tr>
                    <tr><td>HESLB</td><td class="num">{{ number_format($item->heslb_amount) }}</td></tr>
                    <tr><td>Other Deductions</td><td class="num">{{ number_format($item->other_deductions) }}</td></tr>
                    <tr><td><strong>Total Deductions</strong></td><td class="num"><strong>{{ number_format($item->gross_pay - $item->net_pay) }}</strong></td></tr>
                </tbody>
            </table>
        </td>
    </tr>
</table>

@if(! is_null($item->nssf_employer_amount))
<div class="section-title">Employer Contribution</div>
<table class="data">
    <tbody>
        <tr><td>NSSF (Employer Match)</td><td class="num">{{ number_format($item->nssf_employer_amount) }}</td></tr>
    </tbody>
</table>
@endif

<table class="net-pay-table">
    <tr class="net-pay-box">
        <td class="lbl">Net Pay</td>
        <td class="val">TSh {{ number_format($item->net_pay) }}</td>
    </tr>
</table>

@if($item->notes)
<div class="section-title">Notes</div>
<p class="muted">{{ $item->notes }}</p>
@endif

<div class="footer">
    {{ $company['name'] }} · This is a system-generated payslip, no signature required.
</div>

</body>
</html>
