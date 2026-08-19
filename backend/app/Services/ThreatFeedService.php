<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterThreatObservation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ThreatFeedService
{
    public const FEED_NAME = 'Feodo Tracker';

    public function __construct(private readonly MikrotikService $mikrotikService)
    {
    }

    /**
     * Scan active RouterOS connections against the configured external feed.
     * This method only reads RouterOS state and writes SolarNet audit records.
     * It never adds a firewall address-list or filter rule.
     */
    public function scanRouter(Router $router): array
    {
        try {
            $indicators = $this->indicators();
            $result = $this->mikrotikService->threatFeedConnections($router, $indicators);
            if (!$result['success']) return $result;

            $observations = [];
            foreach ($result['matches'] as $match) {
                $observation = RouterThreatObservation::query()->firstOrNew([
                    'router_id' => $router->id,
                    'feed_name' => self::FEED_NAME,
                    'remote_ip' => $match['remote_ip'],
                ]);

                if (!$observation->exists) {
                    $observation->status = 'pending';
                    $observation->first_observed_at = now();
                } elseif ($observation->status === 'blocked' && $observation->block_expires_at?->isPast()) {
                    // The RouterOS list entry was intentionally temporary.
                    // If the same indicator is seen again after expiry, make
                    // a fresh, auditable operator review mandatory.
                    $observation->status = 'pending';
                    $observation->reviewed_by = null;
                    $observation->reviewed_at = null;
                    $observation->review_note = null;
                    $observation->blocked_at = null;
                    $observation->block_expires_at = null;
                }

                $observation->connection_directions = $match['directions'];
                $observation->last_observed_at = now();
                $observation->save();
                $observations[] = $observation;
            }

            $limitedMessage = ($result['scan_limited'] ?? false)
                ? sprintf(' The safe %d-connection scan limit was reached; run the scan again later for a newer sample.', (int) ($result['connection_limit'] ?? 0))
                : '';

            return [
                'success' => true,
                'message' => sprintf('Read-only scan completed. %d active connection(s) matched %s.', count($observations), self::FEED_NAME) . $limitedMessage,
                'data' => [
                    'feed_name' => self::FEED_NAME,
                    'indicators_loaded' => count($indicators),
                    'connections_checked' => $result['connections_checked'],
                    'scan_limited' => (bool) ($result['scan_limited'] ?? false),
                    'connection_limit' => $result['connection_limit'] ?? null,
                    'matches' => $observations,
                    'scanned_at' => now()->toIso8601String(),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Threat-feed scan failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Threat-feed scan failed: ' . $e->getMessage()];
        }
    }

    /**
     * Make the feed parser independently testable. Only valid IPv4 indicators
     * are accepted; comments, blank lines and malformed data are ignored.
     *
     * @return array<string, true>
     */
    public function parseIndicators(string $body): array
    {
        $indicators = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $candidate = trim(preg_replace('/\s*(?:#|;).*$/', '', $line) ?? '');
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $indicators[$candidate] = true;
            }
        }

        return $indicators;
    }

    /** @return array<string, true> */
    private function indicators(): array
    {
        $cacheKey = 'threat-monitor:feodo-indicators';
        $seconds = max(60, (int) config('threat-monitor.cache_seconds', 900));

        return Cache::remember($cacheKey, now()->addSeconds($seconds), function (): array {
            $response = Http::accept('text/plain')
                ->timeout(10)
                ->retry(1, 250)
                ->get((string) config('threat-monitor.feodo_url'));

            if (!$response->successful()) {
                throw new RuntimeException('The threat feed returned HTTP ' . $response->status() . '.');
            }

            $indicators = $this->parseIndicators($response->body());
            if ($indicators === []) {
                throw new RuntimeException('The threat feed returned no usable IPv4 indicators.');
            }

            return $indicators;
        });
    }
}
