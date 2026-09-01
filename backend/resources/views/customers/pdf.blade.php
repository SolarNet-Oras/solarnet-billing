<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Customer contact and network register</title>
    <style>
        @page { margin: 15mm 11mm 13mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #172033; font-size: 8.5px; line-height: 1.35; }
        .header { border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 11px; }
        .company { color: #1d4ed8; font-size: 20px; font-weight: bold; }
        .subtitle { color: #64748b; font-size: 8px; }
        .report-title { float: right; text-align: right; margin-top: -35px; }
        .report-title strong { display: block; font-size: 14px; color: #0f172a; }
        .report-title span { color: #64748b; font-size: 7px; }
        .summary { background: #f8fafc; border: 1px solid #dbe4f0; margin-bottom: 11px; padding: 7px 9px; color: #334155; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        thead { display: table-header-group; }
        th { background: #1d4ed8; color: white; font-size: 7.2px; padding: 7px 5px; text-align: left; text-transform: uppercase; }
        td { border-bottom: 1px solid #dbe4f0; padding: 6px 5px; vertical-align: top; overflow-wrap: anywhere; }
        tbody tr:nth-child(even) { background: #f8fafc; }
        .name { font-weight: bold; color: #0f172a; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 8px; }
        .missing { color: #9a3412; font-style: italic; }
        .status { color: #64748b; font-size: 7px; text-transform: uppercase; }
        .footer { color: #64748b; font-size: 7px; margin-top: 11px; text-align: center; }
        .empty { border: 1px dashed #94a3b8; color: #475569; padding: 22px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $company['name'] }}</div>
        <div class="subtitle">{{ $company['tagline'] }}</div>
        <div class="report-title">
            <strong>Incomplete Customer Details</strong>
            <span>Generated {{ $generatedAt->format('M d, Y h:i A') }} &middot; {{ $generatedBy }}</span>
        </div>
    </div>

    <div class="summary">
        <strong>{{ $customers->count() }}</strong> registered customer{{ $customers->count() === 1 ? '' : 's' }} missing a phone number, email, MAC address, or IP address.
        @if($filters['status'] !== '') Status: <strong>{{ ucfirst($filters['status']) }}</strong>. @endif
        @if($filters['search'] !== '') Search: <strong>{{ $filters['search'] }}</strong>. @endif
        Customers with all four required details complete are excluded from this report.
    </div>

    @if($customers->isEmpty())
        <div class="empty">No registered customers match the selected filters.</div>
    @else
        <table>
            <thead><tr>
                <th style="width:12%">Account</th>
                <th style="width:23%">Customer name</th>
                <th style="width:15%">Phone number</th>
                <th style="width:22%">Gmail / email</th>
                <th style="width:16%">MAC address</th>
                <th style="width:12%">IP address</th>
            </tr></thead>
            <tbody>
            @php($missingValues = ['', 'n/a', 'na', 'none', 'not recorded', 'to be updated', '-'])
            @foreach($customers as $customer)
                @php
                    $phoneMissing = in_array(strtolower(trim((string) $customer->contact_number)), $missingValues, true);
                    $emailMissing = in_array(strtolower(trim((string) $customer->email)), $missingValues, true);
                    $macMissing = in_array(strtolower(trim((string) $customer->mac_address)), $missingValues, true);
                    $ipMissing = in_array(strtolower(trim((string) $customer->ip_address)), $missingValues, true);
                @endphp
                <tr>
                    <td><div class="mono">{{ $customer->account_number }}</div><div class="status">{{ $customer->status }}</div></td>
                    <td class="name">{{ $customer->full_name }}</td>
                    <td class="{{ $phoneMissing ? 'missing' : '' }}">{{ $phoneMissing ? 'Not recorded' : $customer->contact_number }}</td>
                    <td class="{{ $emailMissing ? 'missing' : '' }}">{{ $emailMissing ? 'Not recorded' : $customer->email }}</td>
                    <td class="mono {{ $macMissing ? 'missing' : '' }}">{{ $macMissing ? 'Not recorded' : $customer->mac_address }}</td>
                    <td class="mono {{ $ipMissing ? 'missing' : '' }}">{{ $ipMissing ? 'Not recorded' : $customer->ip_address }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">SolarNet incomplete customer details report &middot; Read-only export</div>
</body>
</html>
