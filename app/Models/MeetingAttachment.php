<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingAttachment extends Model
{
    protected $fillable = ['meeting_id', 'original_name', 'stored_name', 'mime_type', 'size', 'uploaded_by'];
}
