<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 36px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11.5px; color: #1a1a1a; }
    .brand-name { font-size: 20px; font-weight: bold; color: {{ $company['brand_color'] }}; margin: 0; }
    .brand-tagline { font-size: 10px; color: #666; margin: 2px 0 0; }
    .company-details { font-size: 9.5px; color: #666; text-align: right; line-height: 1.5; }
    .header-table { width: 100%; margin-bottom: 16px; }
    .doc-title { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
    .doc-sub { font-size: 12.5px; margin: 0 0 4px; }
    .facts { width: 100%; border-collapse: collapse; margin: 10px 0 4px; }
    .facts td { padding: 4px 8px 4px 0; font-size: 10.5px; vertical-align: top; }
    .facts .k { color: #888; width: 90px; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.3px; padding-top: 6px; }
    .section-title { font-size: 10.5px; text-transform: uppercase; color: #888; margin: 18px 0 6px; letter-spacing: 0.4px;
        border-bottom: 1px solid #ddd; padding-bottom: 4px; }
    .prose { white-space: pre-wrap; line-height: 1.5; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #f4f4f4; text-align: left; padding: 6px 8px; font-size: 9px;
        text-transform: uppercase; color: #555; border-bottom: 1px solid #ddd; }
    table.data td { padding: 6px 8px; font-size: 10.5px; border-bottom: 1px solid #eee; vertical-align: top; }
    .muted { color: #999; font-size: 10px; }
    .sign { width: 100%; margin-top: 34px; }
    .sign td { width: 50%; padding-right: 30px; font-size: 10px; color: #555; }
    .line { border-top: 1px solid #999; margin-top: 30px; padding-top: 4px; }
    .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #eee; font-size: 9px; color: #999; text-align: center; }
</style>
</head>
<body>
@php
    $statusLabel = ['present' => 'Present', 'absent' => 'Absent', 'apologies' => 'Apologies', 'invited' => '—'];
    $itemLabel = ['done' => 'Done', 'in_progress' => 'In progress', 'open' => 'Open', 'overdue' => 'Overdue'];
@endphp

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

<div class="doc-title">MINUTES OF MEETING</div>
<div class="doc-sub">{{ $meeting->title }}</div>

<table class="facts">
    <tr><td class="k">Date</td><td>{{ $meeting->starts_at->format('l d F Y, H:i') }}@if ($meeting->ends_at) – {{ $meeting->ends_at->format('H:i') }}@endif</td></tr>
    @if ($meeting->location || $meeting->online_link)
    <tr><td class="k">Venue</td><td>{{ $meeting->location ?: 'Online' }}@if ($meeting->online_link) ({{ $meeting->online_link }})@endif</td></tr>
    @endif
    @if ($meeting->department)
    <tr><td class="k">Department</td><td>{{ $meeting->department->name }}</td></tr>
    @endif
    <tr><td class="k">Chair</td><td>{{ $meeting->chair?->name ?? '—' }}</td></tr>
    <tr><td class="k">Secretary</td><td>{{ $meeting->secretary?->name ?? '—' }}</td></tr>
</table>

<div class="section-title">Attendance</div>
@if ($meeting->attendees->isEmpty())
    <div class="muted">No attendees recorded.</div>
@else
<table class="data">
    <thead><tr><th>Name</th><th>Organisation</th><th style="width: 90px;">Attendance</th></tr></thead>
    <tbody>
    @foreach ($meeting->attendees as $a)
        <tr>
            <td>{{ $a->user?->name ?? $a->guest_name }}</td>
            <td>{{ $a->user_id ? $company['name'] : ($a->guest_organisation ?: 'Guest') }}</td>
            <td>{{ $statusLabel[$a->attendance] ?? $a->attendance }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

@if (filled($meeting->agenda))
<div class="section-title">Agenda</div>
<div class="prose">{{ $meeting->agenda }}</div>
@endif

<div class="section-title">Minutes</div>
<div class="prose">{{ filled($meeting->minutes) ? $meeting->minutes : '—' }}</div>

@if (filled($meeting->decisions))
<div class="section-title">Decisions</div>
<div class="prose">{{ $meeting->decisions }}</div>
@endif

<div class="section-title">Action items</div>
@if ($meeting->actionItems->isEmpty())
    <div class="muted">None.</div>
@else
<table class="data">
    <thead><tr><th>Action</th><th style="width: 120px;">Owner</th><th style="width: 75px;">Due</th><th style="width: 70px;">Status</th></tr></thead>
    <tbody>
    @foreach ($meeting->actionItems as $i)
        <tr>
            <td>{{ $i->description }}</td>
            <td>{{ $i->owner?->name }}</td>
            <td>{{ $i->due_date?->format('d M Y') ?? '—' }}</td>
            <td>{{ $itemLabel[$i->status] ?? $i->status }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

<table class="sign">
    <tr>
        <td><div class="line">Chair — {{ $meeting->chair?->name ?? '' }}</div></td>
        <td><div class="line">Secretary — {{ $meeting->secretary?->name ?? '' }}</div></td>
    </tr>
</table>

<div class="footer">
    @if ($meeting->circulated_at) Circulated {{ $meeting->circulated_at->format('d M Y') }} · @endif
    Printed {{ $generatedAt->format('d M Y, H:i') }}
</div>
</body>
</html>
