<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SocialMediaAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WebsiteSettingController extends Controller
{
    private function setting()
    {
        return Setting::firstOrFail();
    }

    /* =========================
       GET SETTINGS
    ========================= */
    public function show()
    {
        $dataPrivacy = Page::where('slug', 'data-privacy')->firstOrFail();

        return response()->json([
            'setting' => $this->setting(),
            'data_privacy' => $dataPrivacy,
        ]);
    }

    /* =========================
       WEBSITE TAB
    ========================= */
    public function updateWebsite(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:150',
            'website_name' => 'required|string|max:150',
            'copyright' => 'required|string|max:150',
            'company_logo' => 'nullable|image',
            'website_favicon' => 'nullable|image',
            'nav_alignment' => 'nullable|in:left,center,right',
        ]);

        $setting = $this->setting();

        if ($request->hasFile('company_logo')) {
            if ($setting->company_logo) {
                Storage::disk('public')->delete($setting->company_logo);
            }
            $setting->company_logo = $request->file('company_logo')
                ->store('logos', 'public');
        }

        if ($request->hasFile('website_favicon')) {
            if ($setting->website_favicon) {
                Storage::disk('public')->delete($setting->website_favicon);
            }
            $setting->website_favicon = $request->file('website_favicon')
                ->store('icons', 'public');
        }

        $setting->update($request->only([
            'company_name',
            'website_name',
            'copyright',
            'google_analytics',
            'google_map',
            'google_recaptcha_sitekey',
            'nav_alignment',
        ]));

        return response()->json(['message' => 'Website settings updated']);
    }

    /* =========================
       CONTACT TAB
    ========================= */
    public function updateContact(Request $request)
    {
        $request->validate([
            'company_address' => 'required',
            'mobile_no' => 'required',
            'tel_no' => 'required',
            'email' => 'required|email',
        ]);

        $this->setting()->update($request->only([
            'company_address',
            'mobile_no',
            'fax_no',
            'tel_no',
            'email',
        ]));

        return response()->json(['message' => 'Contact settings updated']);
    }

    /* =========================
       DATA PRIVACY TAB
    ========================= */

    public function updatePrivacy(Request $request)
    {
        $request->validate([
            'data_privacy_title' => 'required|string',
            'data_privacy_popup_content' => 'required|string',
            'data_privacy_content' => 'required|string',
        ]);

        DB::transaction(function () use ($request) {

            $this->setting()->update($request->only([
                'data_privacy_title',
                'data_privacy_popup_content',
                'data_privacy_content',
            ]));

            $dataPrivacy = Page::where('slug', 'data-privacy')->firstOrFail();

            $dataPrivacy->update([
                "name" => $request->data_privacy_title,
                "contents" => $request->data_privacy_content,
            ]);
        });

        return response()->json([
            'message' => 'Data privacy updated successfully'
        ], 200);
    }

    /* =========================
       SOCIAL MEDIA
    ========================= */
    public function getSocials(Request $request)
    {
        return SocialMediaAccount::where('user_id', $request->user()->id)->get();
    }

    public function updateSocials(Request $request)
    {
        $request->validate([
            'socials' => 'array',
            'socials.*.name' => 'required',
            'socials.*.media_account' => 'required',
        ]);

        $userId = $request->user()->id;
        SocialMediaAccount::where('user_id', $userId)->delete();

        foreach ($request->socials as $social) {
            SocialMediaAccount::create([
                'name' => $social['name'],
                'media_account' => $social['media_account'],
                'user_id' => $userId,
            ]);
        }

        return response()->json([
            'message' => 'Social media accounts updated successfully',
        ]);
    }

}