<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HrSetting;
use Illuminate\Http\Request;

class HrSettingController extends Controller
{
    public function index()
    {
        return response()->json(['data' => HrSetting::pluck('value', 'key')]);
    }

    // Bulk upsert — {"settings": {"default_probation_days": "90", ...}}
    public function update(Request $request)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage HR settings.');

        $data = $request->validate([
            'settings'   => ['required', 'array'],
            'settings.*' => ['nullable', 'string'],
        ]);

        foreach ($data['settings'] as $key => $value) {
            HrSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json(['data' => HrSetting::pluck('value', 'key')]);
    }
}
