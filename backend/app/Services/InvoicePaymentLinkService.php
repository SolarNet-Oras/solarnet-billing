<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\CustomerPortalUrl;
use App\Support\CurrentTenant;

class InvoicePaymentLinkService
{
    public function url(Invoice $invoice): string
    {
        return CustomerPortalUrl::to('/pay/' . rawurlencode($this->token($invoice)));
    }

    public function token(Invoice $invoice): string
    {
        $payload = [
            'invoice_id' => $invoice->id,
            'tenant_id' => $invoice->getAttribute('tenant_id'),
            'customer_id' => $invoice->customer_id,
            'invoice_number' => $invoice->invoice_number,
            'expires_at' => now()->addDays(45)->timestamp,
        ];
        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encoded, (string) config('app.key'));

        return $encoded . '.' . $signature;
    }

    public function invoice(string $token): ?Invoice
    {
        [$encoded, $signature] = array_pad(explode('.', $token, 2), 2, null);
        if (!$encoded || !$signature || !hash_equals(hash_hmac('sha256', $encoded, (string) config('app.key')), $signature)) return null;
        $decoded = $this->base64UrlDecode($encoded);
        $payload = $decoded === null ? null : json_decode($decoded, true);
        if (!is_array($payload) || (int) ($payload['expires_at'] ?? 0) < now()->timestamp) return null;

        $tenant = filled($payload['tenant_id'] ?? null)
            ? Tenant::query()->whereKey($payload['tenant_id'])->first()
            : Tenant::query()->where('slug', config('saas.primary_tenant_slug'))->where('is_primary', true)->first();
        if ($tenant) app(CurrentTenant::class)->set($tenant);
        if (!$tenant && config('saas.enabled') && config('saas.enforcement_mode') === 'enforced') return null;

        return Invoice::with('customer:id,account_number,full_name,status')
            ->whereKey($payload['invoice_id'] ?? null)
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant->id))
            ->where('customer_id', $payload['customer_id'] ?? null)
            ->first();
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        return $decoded === false ? null : $decoded;
    }
}
