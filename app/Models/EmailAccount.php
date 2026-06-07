<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAccount extends Model
{
    protected $fillable = [
        'user_id', 'label',
        'imap_host', 'imap_port', 'imap_encryption',
        'smtp_host', 'smtp_port', 'smtp_encryption',
        'username', 'password', 'from_name', 'from_email',
        'is_default', 'is_active', 'last_synced_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_default'     => 'boolean',
        'is_active'      => 'boolean',
        'last_synced_at' => 'datetime',
        'imap_port'      => 'integer',
        'smtp_port'      => 'integer',
    ];

    // Encrypt password on set, decrypt on get
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = encrypt($value);
    }

    public function getPasswordAttribute(string $value): string
    {
        return decrypt($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function syncedEmails()
    {
        return $this->hasMany(SyncedEmail::class);
    }
}
