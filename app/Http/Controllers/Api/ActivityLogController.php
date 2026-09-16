<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

// Read-only view onto spatie/laravel-activitylog's activity_log table.
// Admin-tier only for now (company-wide change history is sensitive) — a
// narrower "history for this one record" view for other roles is a
// reasonable follow-up once there's a real request for it, not built
// speculatively here.
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to view the activity log.');

        $query = Activity::with('causer')->latest();

        if ($request->filled('subject_type')) {
            // Accept the short model name (e.g. "Expense") rather than
            // requiring the fully-qualified class string in the URL.
            $query->where('subject_type', 'App\\Models\\' . $request->input('subject_type'));
        }
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->input('causer_id'));
        }

        $activities = $query->paginate(50);

        $activities->getCollection()->transform(fn (Activity $a) => [
            'id'           => $a->id,
            'description'  => $a->description,
            'subject_type' => class_basename($a->subject_type),
            'subject_id'   => $a->subject_id,
            'causer_name'  => $a->causer?->name,
            'changes'      => $a->changes,
            'created_at'   => $a->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $activities]);
    }
}
