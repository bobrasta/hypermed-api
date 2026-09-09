<?php

namespace Database\Seeders;

use App\Models\FlowDepartment;
use Illuminate\Database\Seeder;

// Seeds the 8 department flow diagrams from the original flowchart TD.mmd
// (bienhypermed repo root) for the shareable research/sampling editor.
// firstOrCreate on key so re-running never duplicates or wipes edits real
// staff have since made — it only fills in a department that has no row yet.
class FlowDepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $path = __DIR__.'/data/flow_departments.json';
        $departments = json_decode(file_get_contents($path), true);

        foreach ($departments as $key => $d) {
            FlowDepartment::firstOrCreate(
                ['key' => $key],
                ['title' => $d['title'], 'nodes' => $d['nodes'], 'edges' => $d['edges']]
            );
        }
    }
}
