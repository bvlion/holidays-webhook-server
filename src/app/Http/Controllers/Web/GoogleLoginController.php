<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class GoogleLoginController extends Controller
{
    public function redirectGoogleAuth()
    {
        return Socialite::driver('google')->redirect();
    }

    public function authGoogleCallback()
    {
        try {
            $google = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            $this->logAuthFailure('callback', $e);

            throw new HttpException(502, 'Google認証に失敗しました');
        }

        $user = $this->saveGroupsUser($google);

        return [
            'api_token' => $user->api_token,
            'user_name' => $user->user_name,
            'owner_flag' => $user->owner_flag,
        ];
    }

    public function apiLogin(Request $request)
    {
        $googleToken = $request->google_token;

        try {
            $google = Socialite::driver('google')->userFromToken($googleToken);
        } catch (Throwable $e) {
            $this->logAuthFailure('api_login', $e);

            throw new HttpException(502, 'Google認証に失敗しました');
        }

        return $this->saveGroupsUser($google);
    }

    /**
     * access token・authorization code・client secret・callback query・
     * 例外メッセージ自体（機密値を含み得る）をログへ出さず、切り分けに
     * 必要な最小限の情報だけを記録する。
     */
    private function logAuthFailure(string $operation, Throwable $e): void
    {
        Log::warning('external_service.failure', [
            'integration' => 'google_auth',
            'operation' => $operation,
            'exception' => get_class($e),
        ]);
    }

    private function saveGroupsUser(GoogleUser $google)
    {
        // groups を更新
        $group = Group::updateOrCreate([
            'email' => $google->email,
        ], [
            'token' => $google->token,
        ]);

        // users 登録
        $user = User::where('groups_id', $group->id)->where('owner_flag', true)->first();
        if (! $user) {
            $user = User::create([
                'groups_id' => $group->id,
                'api_token' => Str::random(60),
                'user_name' => $google->name,
                'owner_flag' => true,
            ]);
        }

        return $user;
    }
}
