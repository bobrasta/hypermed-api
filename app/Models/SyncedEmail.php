<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedEmail extends Model
{
    protected $fillable = [
        'email_account_id', 'uid', 'message_id', 'folder',
        'subject', 'from_email', 'from_name',
        'to_addresses', 'cc_addresses', 'bcc_addresses',
        'body_html', 'body_text', 'in_reply_to',
        'is_read', 'is_flagged', 'is_draft', 'has_attachments',
        'email_date',
    ];

    protected $casts = [
        'to_addresses'  => 'array',
        'cc_addresses'  => 'array',
        'bcc_addresses' => 'array',
        'is_read'       => 'boolean',
        'is_flagged'    => 'boolean',
        'is_draft'      => 'boolean',
        'has_attachments' => 'boolean',
        'email_date'    => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function attachments()
    {
        return $this->hasMany(SyncedEmailAttachment::class);
    }
}
