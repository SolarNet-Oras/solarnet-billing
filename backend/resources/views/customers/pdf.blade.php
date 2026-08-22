<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer register</title>
    <style>
        @page { margin: 17mm 12mm 15mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #172033; font-size: 8.5px; line-height: 1.35; }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 12px; }
        .company { color: #1d4ed8; font-size: 20px; font-weight: bold; }
        .subtitle { color: #64748b; font-size: 9px; margin-top: 2px; }
        .report-title { float: right; text-align: right; margin-top: -35px; }
        .report-title strong { display: block; font-size: 15px; color: #0f172a; }
        .report-title span { color: #64748b; font-size: 8px; }
        .summary { background: #f8fafc; border: 1px solid #dbe4f0; border-radius: 4px; margin-bottom: 12px; padding: 7px 9px; color: #334155; }
        .summary strong { color: #0f172a; }
        table { border-collapse: collapse; width: 100%; }
        thead { display: table-header-group; }
        th { background: #1d4ed8; color: white; font-size: 7.4px; font-weight: bold; letter-spacing: .2px; padding: 7px 5px; text-align: left; text-transform: uppercase; }
        td { border-bottom: 1px solid #dbe4f0; padding: 6px 5px; vertical-align: top; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .account { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .name { font-weight: bold; color: #0f172a; }
        .muted { color: #64748b; font-size: 7.5px; }
        .amount { text-align: right; white-space: nowrap; }
        .status { display: inline-block; border-radius: 9px; font-size: 7px; font-weight: bold; padding: 2px 5px; text-transform: uppercase; }
        .status-active { background: #dcfce7; color: #166534; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .footer { color: #64748b; font-size: 7px; margin-top: 12px; text-align: center; }
        .empty { border: 1px dashed #94a3b8; color: #475569; padding: 22px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $company['name'] }}</div>
        <div class="subtitle">{{ $company['tagline'] }}@if(!empty($company['address'])) · {{ $company['address'] }}@endif</div>
        <div class="report-title">
            <strong>Customer Register</strong>
            <span>Generated {{ $generatedAt->format('M d, Y h:i A') }} · {{ $generatedBy }}</span>
        </div>
    </div>

    <div class="summary">
        <strong>{{ $customers->count() }}</strong> registered customer{{ $customers->count() === 1 ? '' : 's' }} included.
        @if($filters['status'] !== '') Status: <strong>{{ ucfirst($filters['status']) }}</strong>. @endif
        @if($filters['search'] !== '') Search: <strong>{{ $filters['search'] }}</strong>. @endif
        Pending installation applications are excluded and remain in Tickets until approved.
    </div>

    @if($customers->isEmpty())
        <div class="empty">No registered customers match the selected filters.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th style="width: 10%">Account</th>
                    <th style="width: 16%">Customer</th>
                    <th style="width: 12%">Contact</th>
                    <th style="width: 23%">Address</th>
                    <th style="width: 14%">Service plan</th>
                    <th style="width: 8%">Due day</th>
                    <th style="width: 8%">Status</th>
                    <th style="width: 9%; text-align:right">Monthly fee</th>
                </tr>
            </thead>
            <tbody>
                @foreach($customers as $customer)
                    <tr>
                        <td class="account">{{ $customer->account_number }}</td>
                        <td><div class="name">{{ $customer->full_name }}</div><div class="muted">Installed {{ optional($customer->installation_date)->format('M d, Y') ?: '—' }}</div></td>
                        <td>{{ $customer->contact_number ?: '—' }}</td>
                        <td>{{ $customer->address ?: '—' }}</td>
                        <td>
                            @if($customer->servicePlan)
                                <div class="name">{{ $customer->servicePlan->name }}</div>
                                <div class="muted">{{ $customer->servicePlan->download_speed }}/{{ $customer->servicePlan->upload_speed }} Mbps</div>
                            @else
                                <span class="muted">No plan assigned</span>
                            @endif
                        </td>
                        <td>{{ $customer->billingCycleDay() ? 'Every ' . $customer->billingCycleDay() : '—' }}</td>
                        <td><span class="status status-{{ strtolower($customer->status) }}">{{ $customer->status }}</span></td>
                        <td class="amount">₱{{ number_format((float) $customer->monthly_fee, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">SolarNet customer register · Read-only export</div>
</body>
</html>
