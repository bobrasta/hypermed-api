<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Messaging\SettingAuditPolicy;
use App\Models\Setting;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    // Settings that decide which money needs the Director's sign-off — the
    // CTO's admin-tier must not be a way around final authorisation.
    private const MONEY_ROUTING_KEYS = ['expense_director_threshold'];

    // Whether anyone may read other people's chats: the Director's own seat
    // only, never a delegate or the CTO's admin-tier.
    private const PRIVACY_KEYS = [SettingAuditPolicy::KEY];

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

        abort_if(in_array($key, self::PRIVACY_KEYS, true) && ! $request->user()->isDirector(), 403,
            'Only the Director can switch chat review on or off.');

        $data = $request->validate(['value' => ['required', 'string']]);
        if (in_array($key, self::PRIVACY_KEYS, true)) {
            $request->validate(['value' => ['in:0,1']]);
        }

        Setting::set($key, $data['value'], $request->user()->id);

        if ($key === SettingAuditPolicy::KEY) {
            activity()->causedBy($request->user())->withProperties(['setting' => $key, 'value' => $data['value']])
                ->log($data['value'] === '1' ? 'Switched chat review ON' : 'Switched chat review OFF');
        }

        return response()->json(['data' => ['key' => $key, 'value' => $data['value']]]);
    }
}
