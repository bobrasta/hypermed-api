<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    // Roles that can manage all tasks
    private static array $managerRoles = ['admin', 'manager'];

    // ── List ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = Task::with(['assignee', 'creator'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'assigned' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END ASC")
            ->orderBy('due_date', 'asc')
            ->orderBy('created_at', 'desc');

        if (in_array($user->role, self::$managerRoles)) {
            // Managers can filter by any staff member
            if ($request->filled('assigned_to')) {
                $query->where('assigned_to', $request->assigned_to);
            }
            if ($request->filled('category')) {
                $query->where('category', $request->category);
            }
            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
        } else {
            // Staff only see their own tasks
            $query->where('assigned_to', $user->id);
        }

        return response()->json([
            'data' => $query->get()->map(fn ($t) => $this->format($t)),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        if (! in_array(Auth::user()->role, self::$managerRoles)) {
            return response()->json(['message' => 'Not authorised.'], 403);
        }

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category'    => ['required', 'in:field,sales,finance,office,cs,general'],
            'task_type'   => ['required', 'string', 'max:100'],
            'priority'    => ['required', 'in:critical,high,medium,low'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'due_date'    => ['required', 'date', 'after:now'],
        ]);

        $data['created_by'] = Auth::id();
        $data['status']     = 'assigned';

        $task = Task::create($data);
        $task->load(['assignee', 'creator']);

        return response()->json(['data' => $this->format($task)], 201);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, Task $task): JsonResponse
    {
        $user       = Auth::user();
        $isManager  = in_array($user->role, self::$managerRoles);
        $isAssignee = $task->assigned_to === $user->id;

        if (! $isManager && ! $isAssignee) {
            return response()->json(['message' => 'Not authorised.'], 403);
        }

        // Assignees can only progress status — not change metadata
        if ($isAssignee && ! $isManager) {
            $data = $request->validate([
                'status' => ['required', 'in:in_progress,completed'],
            ]);
        } else {
            $data = $request->validate([
                'title'       => ['sometimes', 'string', 'max:255'],
                'description' => ['sometimes', 'nullable', 'string'],
                'category'    => ['sometimes', 'in:field,sales,finance,office,cs,general'],
                'task_type'   => ['sometimes', 'string', 'max:100'],
                'priority'    => ['sometimes', 'in:critical,high,medium,low'],
                'status'      => ['sometimes', 'in:assigned,in_progress,completed,overdue'],
                'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
                'due_date'    => ['sometimes', 'date'],
            ]);
        }

        $justCompleted = false;

        if (isset($data['status'])) {
            if ($data['status'] === 'in_progress' && ! $task->started_at) {
                $data['started_at'] = now();
            }
            if ($data['status'] === 'completed' && ! $task->completed_at) {
                $data['completed_at'] = now();
                $justCompleted        = true;
            }
        }

        $task->update($data);
        $task->load(['assignee', 'creator']);

        if ($justCompleted) {
            $this->notifyManagers($task);
        }

        return response()->json(['data' => $this->format($task)]);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(Task $task): JsonResponse
    {
        if (! in_array(Auth::user()->role, self::$managerRoles)) {
            return response()->json(['message' => 'Not authorised.'], 403);
        }

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function notifyManagers(Task $task): void
    {
        $assigneeName = $task->assignee?->name ?? 'Staff';

        User::whereIn('role', self::$managerRoles)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'task',
                'title'       => 'Task Completed',
                'body'        => "{$assigneeName} completed: {$task->title}",
                'entity_type' => 'task',
                'entity_id'   => $task->id,
                'is_read'     => false,
            ]));
    }

    private function format(Task $t): array
    {
        $assignee = $t->assignee;
        $creator  = $t->creator;

        return [
            'id'                => $t->id,
            'title'             => $t->title,
            'description'       => $t->description,
            'category'          => $t->category,
            'task_type'         => $t->task_type,
            'priority'          => $t->priority,
            'status'            => $t->effective_status,
            'assigned_to'       => $t->assigned_to,
            'assignee_name'     => $assignee?->name,
            'assignee_initials' => $assignee?->avatar_initials ?? $this->initials($assignee?->name),
            'assignee_role'     => $assignee?->role,
            'created_by'        => $t->created_by,
            'creator_name'      => $creator?->name,
            'due_date'          => $t->due_date?->toDateString(),
            'started_at'        => $t->started_at?->toIso8601String(),
            'completed_at'      => $t->completed_at?->toIso8601String(),
            'created_at'        => $t->created_at->toIso8601String(),
        ];
    }

    private function initials(?string $name): ?string
    {
        if (! $name) return null;

        return collect(explode(' ', trim($name)))
            ->map(fn ($p) => strtoupper($p[0] ?? ''))
            ->implode('');
    }
}
