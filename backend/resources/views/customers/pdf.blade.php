<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer register</title>
    <style>
        @page { margin: 14mm 9mm 13mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #172033; font-size: 7.6px; line-height: 1.3; }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 9px; margin-bottom: 10px; }
        .company { color: #1d4ed8; font-size: 19px; font-weight: bold; }
        .subtitle, .muted { color: #64748b; font-size: 7px; }
        .report-title { float: right; text-align: right; margin-top: -34px; }
        .report-title strong { display: block; font-size: 14px; color: #0f172a; }
        .report-title span { color: #64748b; font-size: 7px; }
        .summary { background: #f8fafc; border: 1px solid #dbe4f0; margin-bottom: 10px; padding: 6px 8px; color: #334155; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        thead { display: table-header-group; }
        th { background: #1d4ed8; color: white; font-size: 6.8px; font-weight: bold; padding: 6px 4px; text-align: left; text-transform: uppercase; }
        td { border-bottom: 1px solid #dbe4f0; padding: 5px 4px; vertical-align: top; overflow-wrap: anywhere; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .account, .mono { font-family: DejaVu Sans Mono, monospace; font-size: 7px; }
        .name { font-weight: bold; color: #0f172a; }
        .label { color: #64748b; font-size: 6.3px; font-weight: bold; text-transform: uppercase; }
        .line { margin-top: 2px; }
        .status { display: inline-block; border-radius: 8px; font-size: 6.3px; font-weight: bold; padding: 2px 4px; text-transform: uppercase; }
        .status-active { background: #dcfce7; color: #166534; }
        .status-suspended { background: #fef3c7; color: #92400e; }
        .status-expired { background: #fee2e2; color: #991b1b; }
        .footer { color: #64748b; font-size: 6.5px; margin-top: 10px; text-align: center; }
        .empty { border: 1px dashed #94a3b8; color: #475569; padding: 22px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $company['name'] }}</div>
        <div class="subtitle">{{ $company['tagline'] }}@if(!empty($company['address'])) &middot; {{ $company['address'] }}@endif</div>
        <div class="report-title">
            <strong>Complete Customer Register</strong>
            <span>Generated {{ $generatedAt->format('M d, Y h:i A') }} &middot; {{ $generatedBy }}</span>
        </div>
    </div>

    <div class="summary">
        <strong>{{ $customers->count() }}</strong> registered customer{{ $customers->count() === 1 ? '' : 's' }} included.
        @if($filters['status'] !== '') Status: <strong>{{ ucfirst($filters['status']) }}</strong>. @endif
        @if($filters['search'] !== '') Search: <strong>{{ $filters['search'] }}</strong>. @endif
        Blank values are shown as not recorded. Pending installation applications remain excluded until approved.
    </div>

    @if($customers->isEmpty())
        <div class="empty">No registered customers match the selected filters.</div>
    @else
        <table>
            <thead><tr>
                <th style="width:10%">Account / status</th>
                <th style="width:19%">Customer / contact</th>
                <th style="width:21%">Installation address</th>
                <th style="width:18%">Network identity</th>
                <th style="width:15%">Router / fiber</th>
                <th style="width:17%">Service / billing</th>
            </tr></thead>
            <tbody>
            @foreach($customers as $customer)
                <tr>
                    <td>
                        <div class="account">{{ $customer->account_number }}</div>
                        <div class="line"><span class="status status-{{ strtolower($customer->status) }}">{{ $customer->status }}</span></div>
                    </td>
                    <td>
                        <div class="name">{{ $customer->full_name }}</div>
                        <div class="line">{{ $customer->contact_number ?: 'Not recorded' }}</div>
                        <div class="muted">{{ $customer->email ?: 'No email recorded' }}</div>
                    </td>
                    <td>
                        <div>{{ $customer->address ?: 'Not recorded' }}</div>
                        @if(is_array($customer->gps_coordinates) && isset($customer->gps_coordinates['latitude'], $customer->gps_coordinates['longitude']))
                            <div class="muted mono">{{ $customer->gps_coordinates['latitude'] }}, {{ $customer->gps_coordinates['longitude'] }}</div>
                        @else
                            <div class="muted">No coordinates recorded</div>
                        @endif
                    </td>
                    <td>
                        <div><span class="label">MAC</span> <span class="mono">{{ $customer->mac_address ?: 'Not recorded' }}</span></div>
                        <div class="line"><span class="label">IP</span> <span class="mono">{{ $customer->ip_address ?: 'Not recorded' }}</span></div>
                        <div class="line"><span class="label">VLAN</span> {{ $customer->vlan ?: 'Not recorded' }}</div>
                        @if($customer->mac_binding_status)<div class="muted">Binding: {{ str_replace('_', ' ', $customer->mac_binding_status) }}</div>@endif
                    </td>
                    <td>
                        <div class="name">{{ $customer->router?->name ?: 'No router assigned' }}</div>
                        @if($customer->router)<div class="muted">{{ $customer->router->location ?: $customer->router->host }}</div>@endif
                        <div class="line"><span class="label">OLT port</span> {{ $customer->olt_port ?: 'Not recorded' }}</div>
                        <div class="muted"><span class="label">ONU</span> {{ $customer->onu_information ?: 'Not recorded' }}</div>
                    </td>
                    <td>
                        <div class="name">{{ $customer->servicePlan?->name ?: 'No plan assigned' }}</div>
                        @if($customer->servicePlan)<div class="muted">{{ $customer->servicePlan->download_speed }}/{{ $customer->servicePlan->upload_speed }} Mbps</div>@endif
                        <div class="line"><span class="label">Fee</span> &#8369;{{ number_format((float) $customer->monthly_fee, 2) }}</div>
                        <div><span class="label">Due</span> {{ $customer->billingCycleDay() ? 'Every ' . $customer->billingCycleDay() : 'Not recorded' }}</div>
                        <div><span class="label">Installed</span> {{ optional($customer->installation_date)->format('M d, Y') ?: 'Not recorded' }}</div>
                        <div class="muted"><span class="label">Technician</span> {{ $customer->technician?->name ?: 'Not assigned' }}</div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">SolarNet complete customer register &middot; Read-only export</div>
</body>
</html>
