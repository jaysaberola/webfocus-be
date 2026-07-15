<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;


class AccountController extends Controller
{
    //
    /**
     * 🔹 Get current logged-in user
     */
    public function me(Request $request)
    {
        return $request->user();
    }

    /**
     * 🔹 Update personal info (avatar, fname, lname)
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'fname'  => 'required|string|max:255',
            'lname'  => 'required|string|max:255',
            'mobile' => 'nullable|string|max:60',
            'birth_date' => 'nullable|date',
            'address_street' => 'nullable|string|max:255',
            'address_city' => 'nullable|string|max:120',
            'address_municipality' => 'nullable|string|max:120',
            'address_province' => 'nullable|string|max:120',
            'address_zip' => 'nullable|string|max:30',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg|max:1024',
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {

            // 🔥 Delete old avatar if exists
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            // 🔹 Store new avatar
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = $path;
        }

        $user->fname = $request->fname;
        $user->lname = $request->lname;
        $user->mobile = $request->mobile;
        $user->birth_date = $request->birth_date;
        $user->address_street = $request->address_street;
        $user->address_city = $request->address_city;
        $user->address_municipality = $request->address_municipality;
        $user->address_province = $request->address_province;
        $user->address_zip = $request->address_zip;
        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * 🔹 Change email
     */
    public function updateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email,' . $request->user()->id,
        ]);

        $user = $request->user();
        $user->email = $request->email;
        $user->save();

        return response()->json([
            'message' => 'Email updated successfully',
            'user' => $user,
        ]);
    }

    /**
     * 🔹 Change password
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }
}
