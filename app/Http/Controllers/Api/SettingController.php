<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    // Settings that decide which money needs the Director's sign-off — the
    // CTO's admin-tier must not be a way around final authorisation.
    private const MONEY_ROUTING_KEYS = ['expense_director_threshold'];

    public function index()
    {
        return response()->json(['data' => Setting::pluck('value', 'key')]);
    }

    public function update(Request $request, string $key)
    {
        // The org chart drawing is the Administrator's to keep up to date too.
        $orgChartEditor = $key === 'org_chart_json'
            && app(EffectivePermissionResolver::class)->can($request->user(), 'org_chart.manage');
        abort_if(! $request->user()->hasDirectorAuthority() && ! $orgChartEditor, 403, 'Only the Director can change approval settings.');
        abort_if(in_array($key, self::MONEY_ROUTING_KEYS, true) && ! $request->user()->hasFinalMoneyAuthority(), 403,
            'Only the Director can change who has to approve money.');

        $data = $request->validate(['value' => ['required', 'string']]);

        Setting::set($key, $data['value'], $request->user()->id);

        return response()->json(['data' => ['key' => $key, 'value' => $data['value']]]);
    }
}
