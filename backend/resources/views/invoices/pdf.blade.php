<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.6;
            padding: 20px;
        }
        .header {
            margin-bottom: 30px;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 20px;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 5px;
        }
        .company-tagline {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 10px;
        }
        .company-info {
            font-size: 10px;
            color: #64748b;
        }
        .invoice-title {
            font-size: 24px;
            font-weight: bold;
            text-align: right;
            color: #1e293b;
            margin-bottom: 10px;
        }
        .invoice-meta {
            text-align: right;
            margin-bottom: 30px;
        }
        .invoice-meta div {
            margin-bottom: 5px;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
            margin-top: 20px;
            color: #1e293b;
        }
        .two-column {
            width: 100%;
            margin-bottom: 30px;
        }
        .two-column td {
            vertical-align: top;
            width: 50%;
        }
        .bill-to, .invoice-details {
            padding: 15px;
            background-color: #f8fafc;
            border-radius: 5px;
        }
        .bill-to {
            margin-right: 10px;
        }
        .invoice-details {
            margin-left: 10px;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            margin-bottom: 20px;
        }
        table.items th {
            background-color: #2563eb;
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-weight: bold;
        }
        table.items td {
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        table.items tr:last-child td {
            border-bottom: none;
        }
        table.items .text-right {
            text-align: right;
        }
        table.items .text-center {
            text-align: center;
        }
        .totals {
            width: 300px;
            float: right;
            margin-top: 20px;
        }
        .totals table {
            width: 100%;
        }
        .totals td {
            padding: 8px 0;
        }
        .totals .label {
            text-align: right;
            padding-right: 20px;
            color: #64748b;
        }
        .totals .amount {
            text-align: right;
            font-weight: bold;
        }
        .totals .total-row {
            border-top: 2px solid #2563eb;
            font-size: 16px;
            color: #2563eb;
        }
        .totals .total-row td {
            padding-top: 12px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .status-draft { background-color: #f1f5f9; color: #475569; }
        .status-sent { background-color: #dbeafe; color: #1e40af; }
        .status-partial { background-color: #fef3c7; color: #92400e; }
        .status-paid { background-color: #d1fae5; color: #065f46; }
        .status-overdue { background-color: #fee2e2; color: #991b1b; }
        .status-cancelled { background-color: #f1f5f9; color: #64748b; }
        .notes {
            margin-top: 40px;
            padding: 15px;
            background-color: #fffbeb;
            border-left: 4px solid #f59e0b;
        }
        .notes-title {
            font-weight: bold;
            margin-bottom: 5px;
            color: #92400e;
        }
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 10px;
            color: #94a3b8;
        }
        .payment-terms {
            margin-top: 30px;
            padding: 15px;
            background-color: #f1f5f9;
            font-size: 10px;
            color: #475569;
        }
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%">
            <tr>
                <td style="width: 60%;">
                    <div class="company-name">{{ $company['name'] }}</div>
                    <div class="company-tagline">{{ $company['tagline'] }}</div>
                    <div class="company-info">
                        @if($company['address']){{ $company['address'] }}<br>@endif
                        @if($company['phone'] || $company['email'])Phone: {{ $company['phone'] ?: '—' }} | Email: {{ $company['email'] ?: '—' }}<br>@endif
                        @if($company['website'] || $company['tax_id'])
                            @if($company['website'])
                                Website: {{ $company['website'] }}
                            @endif
                            @if($company['website'] && $company['tax_id'])
                                |
                            @endif
                            @if($company['tax_id'])
                                Tax ID: {{ $company['tax_id'] }}
                            @endif
                        @endif
                    </div>
                </td>
                <td style="width: 40%; text-align: right; vertical-align: top;">
                    <div class="invoice-title">INVOICE</div>
                    <div class="invoice-meta">
                        <div><strong>Invoice #:</strong> {{ $invoice->invoice_number }}</div>
                        <div><strong>Issue Date:</strong> {{ $invoice->issue_date->format('M d, Y') }}</div>
                        <div><strong>Due Date:</strong> {{ $invoice->due_date->format('M d, Y') }}</div>
                        <div>
                            <strong>Status:</strong>
                            <span class="status-badge status-{{ $invoice->status }}">
                                {{ strtoupper($invoice->status) }}
                            </span>
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="two-column">
        <tr>
            <td>
                <div class="bill-to">
                    <div class="section-title">Bill To</div>
                    <strong>{{ $invoice->customer->full_name }}</strong><br>
                    @if($invoice->customer->address)
                        {{ $invoice->customer->address }}<br>
                    @endif
                    Email: {{ $invoice->customer->email }}<br>
                    @if($invoice->customer->contact_number)
                        Phone: {{ $invoice->customer->contact_number }}<br>
                    @endif
                    Account: {{ $invoice->customer->account_number }}
                </div>
            </td>
            <td>
                <div class="invoice-details">
                    <div class="section-title">Invoice Details</div>
                    <strong>Billing Period:</strong><br>
                    {{ $invoice->billing_period_start->format('M d, Y') }} to {{ $invoice->billing_period_end->format('M d, Y') }}<br><br>
                    @if($invoice->customer->servicePlan)
                        <strong>Service Plan:</strong><br>
                        {{ $invoice->customer->servicePlan->name }}<br>
                        {{ $invoice->customer->servicePlan->download_speed }}Mbps / {{ $invoice->customer->servicePlan->upload_speed }}Mbps
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 50%;">Description</th>
                <th class="text-center" style="width: 15%;">Quantity</th>
                <th class="text-right" style="width: 17.5%;">Unit Price</th>
                <th class="text-right" style="width: 17.5%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="text-center">{{ $item->quantity }}</td>
                <td class="text-right">₱{{ number_format($item->unit_price, 2) }}</td>
                <td class="text-right">₱{{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="clearfix">
        <div class="totals">
            <table>
                <tr>
                    <td class="label">VATable Sale:</td>
                    <td class="amount">₱{{ number_format($invoice->subtotal, 2) }}</td>
                </tr>
                <tr>
                    <td class="label">VAT (8%):</td>
                    <td class="amount">₱{{ number_format($invoice->tax, 2) }}</td>
                </tr>
                @if($invoice->discount > 0)
                <tr>
                    <td class="label">Discount:</td>
                    <td class="amount">-₱{{ number_format($invoice->discount, 2) }}</td>
                </tr>
                @endif
                <tr class="total-row">
                    <td class="label">Total (VAT-inclusive):</td>
                    <td class="amount">₱{{ number_format($invoice->total, 2) }}</td>
                </tr>
                @if($invoice->paid_amount > 0)
                <tr>
                    <td class="label">Paid:</td>
                    <td class="amount">-₱{{ number_format($invoice->paid_amount, 2) }}</td>
                </tr>
                <tr class="total-row">
                    <td class="label">Balance Due:</td>
                    <td class="amount">₱{{ number_format($invoice->balance, 2) }}</td>
                </tr>
                @endif
            </table>
        </div>
    </div>

    @if($invoice->notes)
    <div class="notes">
        <div class="notes-title">Notes:</div>
        {{ $invoice->notes }}
    </div>
    @endif

    @if($invoice->payments->count() > 0)
    <div style="clear: both; margin-top: 40px;">
        <div class="section-title">Payment History</div>
        <table class="items">
            <thead>
                <tr>
                    <th>Payment Date</th>
                    <th>Payment #</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->payments as $payment)
                <tr>
                    <td>{{ $payment->payment_date->format('M d, Y') }}</td>
                    <td>{{ $payment->payment_number }}</td>
                    <td>{{ ucwords(str_replace('_', ' ', $payment->payment_method)) }}</td>
                    <td>{{ $payment->reference ?? $payment->transaction_id ?? '-' }}</td>
                    <td class="text-right">₱{{ number_format($payment->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="payment-terms">
        <strong>Payment Terms:</strong> Payment is due within {{ $invoice->issue_date->diffInDays($invoice->due_date) }} days from the invoice date.
        Please make payment via any of the following methods: Cash, Bank Transfer, Credit/Debit Card, or Mobile Money.
        Include your invoice number ({{ $invoice->invoice_number }}) as reference when making payment.
    </div>

    <div class="footer">
        Thank you for your business!<br>
        This is a computer-generated invoice. For any queries, please contact {{ $company['email'] }}
    </div>
</body>
</html>
