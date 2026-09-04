<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = [];

    public static function read(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    /**
     * The same read, but answered once per request.
     *
     * For the places that ask on every row of a list — an order resource
     * wanting the support number is twenty orders asking three times each
     * otherwise. Memoised per request rather than per process, so a setting
     * saved in the panel is live on the very next request even under Octane.
     */
    public static function cached(string $key, mixed $default = null): mixed
    {
        $all = once(fn () => static::pluck('value', 'key'));

        return $all[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
    }
}
