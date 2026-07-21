<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class PublicFreshchatController extends Controller
{
    public function show()
    {
        $token = trim((string) env('FRESHCHAT_TOKEN', ''));
        $host = rtrim((string) env('FRESHCHAT_HOST', 'https://wchat.freshchat.com'), '/');
        $direction = (string) env('FRESHCHAT_DIRECTION', 'ltr');
        $fwcScriptSrc = trim((string) env('FWC_SCRIPT_SRC', '//fw-cdn.com/11419951/4091723.js'));

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token !== '' ? $token : null,
                'host' => $host,
                'direction' => in_array($direction, ['ltr', 'rtl'], true) ? $direction : 'ltr',
                'fwc_script_src' => $fwcScriptSrc !== '' ? $fwcScriptSrc : null,
                'css_class' => (string) env('FRESHCHAT_CSS_CLASS', 'custom_fc_frame'),
                'css_right' => (string) env('FRESHCHAT_CSS_RIGHT', '20px'),
                'css_bottom' => (string) env('FRESHCHAT_CSS_BOTTOM', '100px'),
            ],
        ]);
    }
}
