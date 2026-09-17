<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\Request;

// Lets an admin edit notification wording without a deploy. template_key
// and notification_type are intentionally not editable here — they're the
// wiring that ties a template to its call site and the notification's
// stored type; changing them would silently break the lookup or whatever
// reads AppNotification.type elsewhere, for no real benefit (only the text
// is what an admin would ever want to change).
class NotificationTemplateController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to view notification templates.');

        return response()->json(['data' => NotificationTemplate::orderBy('template_key')->get()]);
    }

    public function update(Request $request, NotificationTemplate $notificationTemplate)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to manage notification templates.');

        $data = $request->validate([
            'title_template' => ['sometimes', 'string', 'max:255'],
            'body_template'  => ['sometimes', 'string', 'max:2000'],
        ]);

        $notificationTemplate->update($data);

        return response()->json(['data' => $notificationTemplate]);
    }
}
