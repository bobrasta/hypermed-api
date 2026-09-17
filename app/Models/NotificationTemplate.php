<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $fillable = ['template_key', 'notification_type', 'title_template', 'body_template', 'description'];
}
