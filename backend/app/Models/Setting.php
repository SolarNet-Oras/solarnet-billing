<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Setting extends Model
{
    use BelongsToTenant, HasUuids;

    private static ?bool $tenantColumnAvailable = null;

    protected $fillable = ['key', 'value', 'cast', 'group', 'label', 'description', 'is_public'];

    protected $casts = ['is_public' => 'boolean'];

    /**
     * Typed getter — returns the value cast to its declared type.
     */
    public function typedValue(): mixed
    {
        return static::castValue($this->value, $this->cast);
    }

    public static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) return null;
        return match ($type) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json'  => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Cached `Setting::get('company.name', 'Solarnet Internet')`.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (! static::hasTenantColumn()) {
            return Cache::rememberForever('setting.'.$key, function () use ($key, $default) {
                $row = static::where('key', $key)->first();

                return $row ? $row->typedValue() : $default;
            });
        }

        $tenant = static::settingsTenant();
        $tenantId = $tenant?->getKey();

        $cacheTenant = $tenantId ?? 'legacy';

        return Cache::rememberForever("setting.{$cacheTenant}.{$key}", function () use ($key, $default, $tenant, $tenantId) {
            $row = static::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->first();

            if (! $row && $tenant?->is_primary) {
                $row = static::withoutGlobalScope('tenant')
                    ->whereNull('tenant_id')
                    ->where('key', $key)
                    ->first();
            }

            if (!$row) return $default;
            return $row->typedValue();
        });
    }

    public static function put(string $key, mixed $value, string $cast = 'string'): void
    {
        if (! static::hasTenantColumn()) {
            $stringValue = static::stringValue($value, $cast);
            static::updateOrCreate(['key' => $key], ['value' => $stringValue, 'cast' => $cast]);
            Cache::forget('setting.'.$key);

            return;
        }

        $tenant = static::settingsTenant();
        $tenantId = $tenant?->getKey();
        $stringValue = static::stringValue($value, $cast);
        $row = static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        if (! $row && $tenant?->is_primary) {
            $row = static::withoutGlobalScope('tenant')
                ->whereNull('tenant_id')
                ->where('key', $key)
                ->first();
        }

        if ($row) {
            $row->update(['value' => $stringValue, 'cast' => $cast]);
        } else {
            $row = new static;
            $row->forceFill([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $stringValue,
                'cast' => $cast,
            ]);
            $row->save();
        }
        Cache::forget('setting.'.($tenantId ?? 'legacy').'.'.$key);
    }

    private static function settingsTenant(): ?Tenant
    {
        return app(CurrentTenant::class)->get()
            ?? app(CurrentTenant::class)->resolveForWrite();
    }

    private static function stringValue(mixed $value, string $cast): string
    {
        return match ($cast) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    private static function hasTenantColumn(): bool
    {
        return self::$tenantColumnAvailable ??= Schema::hasColumn(
            (new static)->getTable(),
            'tenant_id'
        );
    }
}
