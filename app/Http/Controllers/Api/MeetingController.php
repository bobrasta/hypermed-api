<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingAttachment;
use App\Models\MeetingAttendee;
use App\Models\Task;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use App\Services\NotificationTemplateService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Meetings register — see the 2026_10_13_000003 migration. meetings.manage
// (the Administrator, and the Director/CTO through admin tier) runs every
// meeting; everyone else sees the meetings they attend, and their minutes
// once circulated.
class MeetingController extends Controller
{
    use FiltersByPeriod;

    private function canManage(Request $request): bool
    {
        return app(EffectivePermissionResolver::class)->can($request->user(), 'meetings.manage');
    }

    private function abortUnlessManager(Request $request): void
    {
        abort_unless($this->canManage($request), 403, 'Only the Administrator, Director or CTO can change meeting records.');
    }

    private function abortUnlessVisible(Request $request, Meeting $meeting): void
    {
        abort_unless($this->canManage($request) || $meeting->isAttendee($request->user()), 403, 'You were not at this meeting.');
    }

    public function index(Request $request)
    {
        $canManage = $this->canManage($request);
        $query = Meeting::query()
            ->visibleTo($request->user(), $canManage)
            ->with(['chair:id,name', 'department:id,name'])
            ->withCount(['attendees', 'actionItems']);

        $this->applyPeriod($query, $request, 'starts_at');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }
        if ($request->filled('q')) {
            $q = '%' . $request->string('q') . '%';
            $query->where(fn ($w) => $w->where('title', 'ilike', $q)
                ->orWhere('agenda', 'ilike', $q)
                ->orWhere('minutes', 'ilike', $q)
                ->orWhere('decisions', 'ilike', $q));
        }

        $meetings = $query->orderByDesc('starts_at')->limit($this->perPage($request, 500))->get();

        return response()->json([
            'data' => $meetings->map(fn (Meeting $m) => $this->summary($m))->values(),
            'can_manage' => $canManage,
            // For the meeting form's department picker.
            'departments' => $canManage ? Department::orderBy('name')->get(['id', 'name']) : [],
            'categories' => Meeting::CATEGORIES,
        ]);
    }

    public function show(Request $request, Meeting $meeting)
    {
        $this->abortUnlessVisible($request, $meeting);

        return response()->json(['data' => $this->detail($meeting, $this->canManage($request))]);
    }

    public function store(Request $request)
    {
        $this->abortUnlessManager($request);
        $data = $this->validated($request, true);

        $meeting = DB::transaction(function () use ($request, $data) {
            $meeting = Meeting::create([
                ...collect($data)->except('attendees')->all(),
                'status'      => $data['status'] ?? 'scheduled',
                'recorded_by' => $data['recorded_by'] ?? $request->user()->id,
                'created_by'  => $request->user()->id,
            ]);
            $this->syncAttendees($meeting, $data['attendees'] ?? []);

            return $meeting;
        });

        $this->notifyInvited($meeting, $meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all(), $request->user());

        return response()->json(['data' => $this->detail($meeting, true)], 201);
    }

    public function update(Request $request, Meeting $meeting)
    {
        $this->abortUnlessManager($request);
        $data = $this->validated($request, false);

        $before = $meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all();
        DB::transaction(function () use ($meeting, $data) {
            $meeting->update(collect($data)->except('attendees')->all());
            if (array_key_exists('attendees', $data)) {
                $this->syncAttendees($meeting, $data['attendees'] ?? []);
            }
        });

        if ($meeting->status === 'scheduled') {
            $added = array_diff($meeting->attendees()->whereNotNull('user_id')->pluck('user_id')->all(), $before);
            $this->notifyInvited($meeting, $added, $request->user());
        }

        return response()->json(['data' => $this->detail($meeting->fresh(), true)]);
    }

    public function destroy(Request $request, Meeting $meeting)
    {
        $this->abortUnlessManager($request);

        DB::transaction(function () use ($meeting) {
            // Open tasks from this meeting go with it; finished ones stay as history.
            foreach ($meeting->actionItems()->with('task')->get() as $item) {
                if ($item->task && $item->task->status !== 'completed') {
                    $item->task->delete();
                }
            }
            foreach ($meeting->attachments as $a) {
                Storage::disk('public')->delete($this->attachmentPath($a));
            }
            $meeting->delete();
        });

        return response()->json(null, 204);
    }

    // Finalise and send the minutes to every staff attendee (in-app, and by
    // email where notification mail is on).
    public function circulate(Request $request, Meeting $meeting)
    {
        $this->abortUnlessManager($request);
        abort_if($meeting->status === 'cancelled', 422, 'A cancelled meeting has no minutes to circulate.');
        abort_if(blank($meeting->minutes) && blank($meeting->decisions), 422, 'Write the minutes before circulating them.');

        $meeting->update(['status' => 'circulated', 'circulated_at' => now()]);

        $ids = collect($meeting->attendees()->whereNotNull('user_id')->pluck('user_id'))
            ->push($meeting->chaired_by)->filter()->unique()
            ->reject(fn ($id) => $id === $request->user()->id);
        foreach ($ids as $id) {
            AppNotification::create([
                'user_id' => $id,
                ...app(NotificationTemplateService::class)->render('meeting.minutes_circulated', [
                    'title' => $meeting->title,
                    'date'  => $meeting->starts_at->format('d M Y'),
                ]),
                'entity_type' => 'meeting',
                'entity_id'   => $meeting->id,
                'is_read'     => false,
            ]);
        }

        return response()->json(['data' => $this->detail($meeting->fresh(), true)]);
    }

    public function pdf(Request $request, Meeting $meeting)
    {
        $this->abortUnlessVisible($request, $meeting);
        $canManage = $this->canManage($request);
        abort_unless($canManage || $meeting->status === 'circulated', 403, 'The minutes have not been circulated yet.');

        $meeting->load(['attendees.user:id,name', 'actionItems.owner:id,name', 'actionItems.task', 'chair:id,name', 'secretary:id,name', 'department:id,name']);

        return Pdf::loadView('pdf.meeting_minutes', [
            'meeting' => $meeting,
            'company' => config('company'),
            'generatedAt' => now(),
        ])->setPaper('a4')->download('Minutes - ' . Str::slug($meeting->title) . ' - ' . $meeting->starts_at->format('Y-m-d') . '.pdf');
    }

    // ── Action items (each is a task on the owner's board) ──────────────────

    public function storeActionItem(Request $request, Meeting $meeting)
    {
        $this->abortUnlessManager($request);
        $data = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'owner_id'    => ['required', 'exists:users,id'],
            'due_date'    => ['nullable', 'date'],
        ]);

        $item = DB::transaction(function () use ($request, $meeting, $data) {
            $task = Task::create([
                'title'       => Str::limit('Meeting action: ' . $data['description'], 250),
                'description' => $data['description'] . "\n\nFrom the meeting \"{$meeting->title}\" on " . $meeting->starts_at->format('d M Y') . '.',
                'category'    => 'general',
                'task_type'   => 'Meeting action',
                'priority'    => 'medium',
                'status'      => 'assigned',
                'assigned_to' => $data['owner_id'],
                'created_by'  => $request->user()->id,
                'due_date'    => isset($data['due_date']) ? \Carbon\Carbon::parse($data['due_date'])->endOfDay() : null,
            ]);

            return $meeting->actionItems()->create([...$data, 'task_id' => $task->id]);
        });

        // Opens the meeting, where the owner can also tick it done.
        AppNotification::create([
            'user_id' => $item->owner_id,
            ...app(NotificationTemplateService::class)->render('meeting.action_item', [
                'meeting'     => $meeting->title,
                'description' => $item->description,
                'due_suffix'  => $item->due_date ? ' — due ' . $item->due_date->format('d M Y') . '.' : '',
            ]),
            'entity_type' => 'meeting',
            'entity_id'   => $meeting->id,
            'is_read'     => false,
        ]);

        return response()->json(['data' => $this->actionItem($item->load(['owner:id,name', 'task']))], 201);
    }

    public function updateActionItem(Request $request, Meeting $meeting, MeetingActionItem $item)
    {
        abort_if($item->meeting_id !== $meeting->id, 404);
        // The owner may tick their own item done (or undo it); everything
        // else is the secretary's.
        $ownerTicking = $item->owner_id === $request->user()->id && array_keys($request->all()) === ['done'];
        abort_unless($ownerTicking || $this->canManage($request), 403, 'Only the Administrator, Director or CTO can change meeting records.');
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:2000'],
            'owner_id'    => ['sometimes', 'exists:users,id'],
            'due_date'    => ['sometimes', 'nullable', 'date'],
            'done'        => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($item, $data) {
            $item->update(collect($data)->except('done')->all());
            if ($task = $item->task) {
                $taskUpdate = [];
                if (isset($data['description'])) {
                    $taskUpdate['title'] = Str::limit('Meeting action: ' . $data['description'], 250);
                }
                if (isset($data['owner_id'])) {
                    $taskUpdate['assigned_to'] = $data['owner_id'];
                }
                if (array_key_exists('due_date', $data)) {
                    $taskUpdate['due_date'] = $data['due_date'] ? \Carbon\Carbon::parse($data['due_date'])->endOfDay() : null;
                }
                if (array_key_exists('done', $data)) {
                    $taskUpdate['status'] = $data['done'] ? 'completed' : 'assigned';
                    $taskUpdate['completed_at'] = $data['done'] ? now() : null;
                }
                $task->update($taskUpdate);
            }
        });

        return response()->json(['data' => $this->actionItem($item->fresh(['owner:id,name', 'task']))]);
    }

    public function destroyActionItem(Request $request, Meeting $meeting, MeetingActionItem $item)
    {
        $this->abortUnlessManager($request);
        abort_if($item->meeting_id !== $meeting->id, 404);
        DB::transaction(function () use ($item) {
            if ($item->task && $item->task->status !== 'completed') {
                $item->task->delete();
            }
            $item->delete();
        });

        return response()->json(null, 204);
    }

    // ── Attachments ──────────────────────────────────────────────────────────

    public function storeAttachment(Request $request, Meeting $meeting)
    {
        $this->abortUnlessManager($request);
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg', 'max:20480']]);

        $file = $request->file('file');
        $path = $file->store('meeting-attachments/' . $meeting->id, 'public');
        $a = $meeting->attachments()->create([
            'original_name' => $file->getClientOriginalName(),
            'stored_name'   => basename($path),
            'mime_type'     => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'uploaded_by'   => $request->user()->id,
        ]);

        return response()->json(['data' => $this->attachment($a)], 201);
    }

    public function destroyAttachment(Request $request, Meeting $meeting, MeetingAttachment $attachment)
    {
        $this->abortUnlessManager($request);
        abort_if($attachment->meeting_id !== $meeting->id, 404);
        Storage::disk('public')->delete($this->attachmentPath($attachment));
        $attachment->delete();

        return response()->json(null, 204);
    }

    // ── Report ───────────────────────────────────────────────────────────────

    public function report(Request $request)
    {
        $this->abortUnlessManager($request);

        $meetings = $this->applyPeriod(Meeting::query(), $request, 'starts_at')->get(['id', 'status', 'category', 'starts_at']);
        $ids = $meetings->pluck('id');
        $held = $meetings->whereIn('status', ['held', 'minutes_final', 'circulated']);

        $attendance = MeetingAttendee::whereIn('meeting_id', $held->pluck('id'))
            ->selectRaw('attendance, count(*) as n')->groupBy('attendance')->pluck('n', 'attendance');
        $present = (int) ($attendance['present'] ?? 0);
        $recorded = $present + (int) ($attendance['absent'] ?? 0) + (int) ($attendance['apologies'] ?? 0);

        $items = MeetingActionItem::whereIn('meeting_id', $ids)->with(['owner:id,name', 'task', 'meeting:id,title,starts_at'])->get();
        $byStatus = $items->groupBy(fn ($i) => $i->status)->map->count();

        return response()->json(['data' => [
            'meetings' => [
                'total'      => $meetings->count(),
                'held'       => $held->count(),
                'circulated' => $meetings->where('status', 'circulated')->count(),
                'scheduled'  => $meetings->where('status', 'scheduled')->count(),
                'cancelled'  => $meetings->where('status', 'cancelled')->count(),
                'awaiting_minutes' => $meetings->whereIn('status', ['held', 'minutes_final'])->count(),
            ],
            'by_category' => $meetings->groupBy('category')->map->count(),
            'by_month' => $meetings->groupBy(fn ($m) => $m->starts_at->format('Y-m'))->map->count()->sortKeys(),
            'attendance' => [
                'present'   => $present,
                'absent'    => (int) ($attendance['absent'] ?? 0),
                'apologies' => (int) ($attendance['apologies'] ?? 0),
                'not_recorded' => (int) ($attendance['invited'] ?? 0),
                'rate'      => $recorded > 0 ? round($present / $recorded * 100, 1) : null,
            ],
            'action_items' => [
                'total'       => $items->count(),
                'done'        => (int) ($byStatus['done'] ?? 0),
                'open'        => (int) ($byStatus['open'] ?? 0) + (int) ($byStatus['in_progress'] ?? 0),
                'overdue'     => (int) ($byStatus['overdue'] ?? 0),
            ],
            'by_owner' => $items->groupBy('owner_id')->map(fn ($g) => [
                'owner'   => $g->first()->owner?->name,
                'total'   => $g->count(),
                'done'    => $g->filter(fn ($i) => $i->status === 'done')->count(),
                'overdue' => $g->filter(fn ($i) => $i->status === 'overdue')->count(),
            ])->sortByDesc('total')->values(),
            'overdue' => $items->filter(fn ($i) => $i->status === 'overdue')->sortBy('due_date')->take(50)
                ->map(fn ($i) => [
                    ...$this->actionItem($i),
                    'meeting_id'    => $i->meeting_id,
                    'meeting_title' => $i->meeting?->title,
                ])->values(),
        ]]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $data = $request->validate([
            'title'        => [$req, 'string', 'max:255'],
            'category'     => ['sometimes', 'string', 'max:50'],
            'department_id'=> ['sometimes', 'nullable', 'exists:departments,id'],
            'starts_at'    => [$req, 'date'],
            'ends_at'      => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'location'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'online_link'  => ['sometimes', 'nullable', 'string', 'max:500'],
            'chaired_by'   => ['sometimes', 'nullable', 'exists:users,id'],
            'recorded_by'  => ['sometimes', 'nullable', 'exists:users,id'],
            'agenda'       => ['sometimes', 'nullable', 'string'],
            'minutes'      => ['sometimes', 'nullable', 'string'],
            'decisions'    => ['sometimes', 'nullable', 'string'],
            'status'       => ['sometimes', 'in:' . implode(',', Meeting::STATUSES)],
            'attendees'    => ['sometimes', 'array'],
            'attendees.*.user_id'            => ['nullable', 'exists:users,id'],
            'attendees.*.guest_name'         => ['nullable', 'required_without:attendees.*.user_id', 'string', 'max:255'],
            'attendees.*.guest_organisation' => ['nullable', 'string', 'max:255'],
            'attendees.*.guest_email'        => ['nullable', 'email', 'max:255'],
            'attendees.*.attendance'         => ['nullable', 'in:' . implode(',', MeetingAttendee::ATTENDANCE)],
        ]);
        abort_if(($data['status'] ?? null) === 'circulated', 422, 'Use "Circulate minutes" to send them to attendees.');

        return $data;
    }

    // Full replace — the form always sends the whole list.
    private function syncAttendees(Meeting $meeting, array $rows): void
    {
        $meeting->attendees()->delete();
        $seen = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            if ($userId !== null) {
                if (isset($seen[$userId])) {
                    continue;
                }
                $seen[$userId] = true;
            }
            $meeting->attendees()->create([
                'user_id'            => $userId,
                'guest_name'         => $userId ? null : ($row['guest_name'] ?? null),
                'guest_organisation' => $userId ? null : ($row['guest_organisation'] ?? null),
                'guest_email'        => $userId ? null : ($row['guest_email'] ?? null),
                'attendance'         => $row['attendance'] ?? 'invited',
            ]);
        }
    }

    private function notifyInvited(Meeting $meeting, array $userIds, User $actor): void
    {
        $where = $meeting->location ? " at {$meeting->location}" : ($meeting->online_link ? ' (online)' : '');
        foreach (array_unique($userIds) as $id) {
            if ($id === $actor->id) {
                continue;
            }
            AppNotification::create([
                'user_id' => $id,
                ...app(NotificationTemplateService::class)->render('meeting.scheduled', [
                    'organiser'    => $actor->name,
                    'title'        => $meeting->title,
                    'when'         => $meeting->starts_at->format('D d M Y, H:i'),
                    'where_suffix' => $where,
                ]),
                'entity_type' => 'meeting',
                'entity_id'   => $meeting->id,
                'is_read'     => false,
            ]);
        }
    }

    private function summary(Meeting $m): array
    {
        return [
            'id'          => $m->id,
            'title'       => $m->title,
            'category'    => $m->category,
            'status'      => $m->status,
            'starts_at'   => $m->starts_at?->toIso8601String(),
            'ends_at'     => $m->ends_at?->toIso8601String(),
            'location'    => $m->location,
            'online_link' => $m->online_link,
            'department'  => $m->department?->name,
            'chair'       => $m->chair?->name,
            'attendees_count'    => $m->attendees_count ?? $m->attendees()->count(),
            'action_items_count' => $m->action_items_count ?? $m->actionItems()->count(),
            'circulated_at' => $m->circulated_at?->toIso8601String(),
        ];
    }

    private function detail(Meeting $m, bool $canManage): array
    {
        $m->load([
            'attendees.user:id,name,email', 'actionItems.owner:id,name', 'actionItems.task',
            'attachments', 'chair:id,name', 'secretary:id,name', 'department:id,name', 'creator:id,name',
        ]);
        // Draft minutes are the secretary's work in progress.
        $minutesVisible = $canManage || $m->status === 'circulated';

        return [
            ...$this->summary($m),
            'department_id'  => $m->department_id,
            'chaired_by'     => $m->chaired_by,
            'recorded_by'    => $m->recorded_by,
            'secretary'      => $m->secretary?->name,
            'created_by'     => $m->creator?->name,
            'agenda'         => $m->agenda,
            'minutes'        => $minutesVisible ? $m->minutes : null,
            'decisions'      => $minutesVisible ? $m->decisions : null,
            'minutes_visible'=> $minutesVisible,
            'can_manage'     => $canManage,
            'attendees'      => $m->attendees->map(fn (MeetingAttendee $a) => [
                'id'                 => $a->id,
                'user_id'            => $a->user_id,
                'name'               => $a->user?->name ?? $a->guest_name,
                'guest_name'         => $a->guest_name,
                'guest_organisation' => $a->guest_organisation,
                'guest_email'        => $a->guest_email,
                'is_guest'           => $a->user_id === null,
                'attendance'         => $a->attendance,
            ])->values(),
            'action_items'   => $m->actionItems->map(fn ($i) => $this->actionItem($i))->values(),
            'attachments'    => $m->attachments->map(fn ($a) => $this->attachment($a))->values(),
        ];
    }

    private function actionItem(MeetingActionItem $i): array
    {
        return [
            'id'          => $i->id,
            'description' => $i->description,
            'owner_id'    => $i->owner_id,
            'owner'       => $i->owner?->name,
            'due_date'    => $i->due_date?->toDateString(),
            'task_id'     => $i->task_id,
            'status'      => $i->status,
            'completed_at'=> $i->task?->completed_at?->toIso8601String(),
        ];
    }

    private function attachmentPath(MeetingAttachment $a): string
    {
        return 'meeting-attachments/' . $a->meeting_id . '/' . $a->stored_name;
    }

    private function attachment(MeetingAttachment $a): array
    {
        return [
            'id'         => $a->id,
            'name'       => $a->original_name,
            'size'       => $a->size,
            'mime_type'  => $a->mime_type,
            'url'        => Storage::disk('public')->url($this->attachmentPath($a)),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
