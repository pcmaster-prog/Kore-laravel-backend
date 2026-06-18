<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::random(24)),
                    'role' => 'aspirante',
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ]);
            } else {
                $user->update([
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                ]);
            }

            $token = $user->createToken('portal_token')->plainTextToken;

            $frontendUrl = rtrim(env('FRONTEND_PORTAL_URL', 'https://vacantes.decorartereposteria.mx'), '/') . '/auth/google/callback';
            $redirectUrl = $frontendUrl . '?token=' . $token . '&user=' . urlencode(json_encode([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
            ]));
            return redirect()->away($redirectUrl);

        } catch (\Exception $e) {
            Log::error("Google Auth Error: " . $e->getMessage());
            $errorUrl = rtrim(env('FRONTEND_PORTAL_URL', 'https://vacantes.decorartereposteria.mx'), '/') . '/login?error=auth_failed';
            return redirect()->away($errorUrl);
        }
    }
}
