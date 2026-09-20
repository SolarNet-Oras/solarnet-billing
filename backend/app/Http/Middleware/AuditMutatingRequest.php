<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class AuditMutatingRequest
{
    private const SENSITIVE = ['password', 'password_confirmation', 'token', 'secret', 'authorization', 'api_key', 'private_key', 'photo', 'image', 'signature'];

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $response = $next($request);

        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true) || ! $request->user()) {
            return $response;
        }

        try {
            if (! Schema::hasTable('activity_logs')) return $response;
            $user = $request->user();
            $route = $request->route();
            $parameters = $route?->parameters() ?? [];
            [$subjectType, $subjectId] = $this->subject($parameters);
            $uri = (string) ($route?->uri() ?? $request->path());

            ActivityLog::create([
                'actor_id' => $user->getKey(),
                'actor_name' => $user->name ?? $user->full_name ?? $user->email ?? 'Authenticated user',
                'actor_type' => class_basename($user),
                'category' => $this->category($uri),
                'action' => $this->action($request, $route?->getActionName()),
                'method' => strtoupper($request->method()),
                'path' => '/'.$request->path(),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'response_status' => $response->getStatusCode(),
                'changes' => $this->sanitize($request->except(['_token'])),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Activity audit write failed without interrupting the requested action.', ['error' => $exception->getMessage()]);
        }

        return $response;
    }

    private function category(string $uri): string
    {
        $segments = array_values(array_filter(explode('/', $uri)));
        $index = array_search('v1', $segments, true);
        return mb_substr((string) ($segments[$index === false ? 0 : $index + 1] ?? 'system'), 0, 80);
    }

    private function action(Request $request, ?string $controllerAction): string
    {
        if ($controllerAction && str_contains($controllerAction, '@')) return str_replace('@', ' / ', class_basename($controllerAction));
        return strtoupper($request->method()).' '.$request->path();
    }

    private function subject(array $parameters): array
    {
        foreach ($parameters as $key => $value) {
            if (is_object($value) && method_exists($value, 'getKey')) return [class_basename($value), (string) $value->getKey()];
            if (is_scalar($value) && preg_match('/(^id$|customer|invoice|payment|ticket|remittance|campaign|user|router)/i', (string) $key)) {
                return [(string) $key, mb_substr((string) $value, 0, 100)];
            }
        }
        return [null, null];
    }

    private function sanitize(mixed $value, string $key = ''): mixed
    {
        if ($this->sensitive($key)) return '[REDACTED]';
        if (is_array($value)) {
            $safe = [];
            foreach (array_slice($value, 0, 50, true) as $childKey => $childValue) $safe[$childKey] = $this->sanitize($childValue, (string) $childKey);
            return $safe;
        }
        if (is_object($value)) return '[OBJECT]';
        if (is_string($value)) return mb_substr($value, 0, 1000);
        return $value;
    }

    private function sensitive(string $key): bool
    {
        $key = strtolower($key);
        return collect(self::SENSITIVE)->contains(fn (string $word) => str_contains($key, $word));
    }
}
