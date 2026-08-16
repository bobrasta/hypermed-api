<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::whereKey($key)->value('value') ?? $default;
    }

    public static function set(string $key, string $value, ?int $updatedBy = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $updatedBy]);
    }
}
