<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verifies the short-lived customer portal bearer token.
 * This is deliberately separate from the staff JWT guard: a customer token
 * can only resolve the customer whose id, email, and account are signed in it.
 */
class CustomerPortalTokenService
{
    public function authenticate(Request $request): ?Customer
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        try {
            $decoded = json_decode(base64_decode($token), true);
            if (!is_array($decoded) || !isset($decoded['payload'], $decoded['signature'])) return null;

            $payload = $decoded['payload'];
            $signature = (string) $decoded['signature'];
            $expected = hash_hmac('sha256', json_encode($payload), config('app.key'));
            if (!hash_equals($expected, $signature)) return null;
            if (isset($payload['expires_at']) && (int) $payload['expires_at'] < now()->timestamp) return null;

            $customer = Customer::find($payload['customer_id'] ?? null);
            if (!$customer) return null;
            if (!hash_equals((string) $customer->email, (string) ($payload['email'] ?? ''))) return null;
            if (!hash_equals((string) $customer->account_number, (string) ($payload['account_number'] ?? ''))) return null;

            return $customer;
        } catch (\Throwable $e) {
            Log::notice('Customer portal token verification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
