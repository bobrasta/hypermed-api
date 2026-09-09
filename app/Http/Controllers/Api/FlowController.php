<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowDepartment;
use App\Models\FlowSuggestion;
use Illuminate\Http\Request;

// Deliberately PUBLIC, unauthenticated (see routes/api.php) — this backs a
// research/sampling tool: a link handed to staff so they can correct the
// process-flow diagrams for their own department directly, gathering real
// input rather than a guess from the outside. No sensitive/financial/PII
// data lives here, so skipping auth is a deliberate scope call, not an
// oversight — every other endpoint in this app stays behind auth:sanctum.
class FlowController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => FlowDepartment::orderBy('title')->get(['key', 'title', 'updated_by', 'updated_role', 'updated_at']),
        ]);
    }

    public function show(string $key)
    {
        $department = FlowDepartment::where('key', strtoupper($key))->firstOrFail();

        return response()->json(['data' => $department]);
    }

    public function update(Request $request, string $key)
    {
        $data = $request->validate([
            'nodes' => ['present', 'array'],
            'edges' => ['present', 'array'],
            'submitted_by' => ['nullable', 'string', 'max:150'],
            'submitted_role' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $department = FlowDepartment::where('key', strtoupper($key))->firstOrFail();
        $department->update([
            'nodes' => $data['nodes'],
            'edges' => $data['edges'],
            'updated_by' => $data['submitted_by'] ?? null,
            'updated_role' => $data['submitted_role'] ?? null,
        ]);

        FlowSuggestion::create([
            'department_key' => $department->key,
            'submitted_by' => $data['submitted_by'] ?? null,
            'submitted_role' => $data['submitted_role'] ?? null,
            'note' => $data['note'] ?? null,
            'nodes_snapshot' => $data['nodes'],
            'edges_snapshot' => $data['edges'],
        ]);

        return response()->json(['data' => $department->fresh()]);
    }

    public function history(string $key)
    {
        $history = FlowSuggestion::where('department_key', strtoupper($key))
            ->orderByDesc('created_at')
            ->get(['id', 'submitted_by', 'submitted_role', 'note', 'created_at']);

        return response()->json(['data' => $history]);
    }
}
