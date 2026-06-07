<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedEmailAttachment extends Model
{
    protected $fillable = ['synced_email_id', 'filename', 'mime_type', 'size', 'storage_path'];

    protected $casts = ['size' => 'integer'];

    public function email()
    {
        return $this->belongsTo(SyncedEmail::class, 'synced_email_id');
    }
}
