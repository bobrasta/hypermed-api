<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Setting::pluck('value', 'key')]);
    }

    public function update(Request $request, string $key)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can change approval settings.');

        $data = $request->validate(['value' => ['required', 'string']]);

        Setting::set($key, $data['value'], $request->user()->id);

        return response()->json(['data' => ['key' => $key, 'value' => $data['value']]]);
    }
}
