<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasUuids;

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
        return Cache::rememberForever('setting.' . $key, function () use ($key, $default) {
            $row = static::where('key', $key)->first();
            if (!$row) return $default;
            return $row->typedValue();
        });
    }

    public static function put(string $key, mixed $value, string $cast = 'string'): void
    {
        $stringValue = match ($cast) {
            'bool'  => $value ? '1' : '0',
            'json'  => json_encode($value),
            default => (string) $value,
        };
        static::updateOrCreate(['key' => $key], ['value' => $stringValue, 'cast' => $cast]);
        Cache::forget('setting.' . $key);
    }
}
