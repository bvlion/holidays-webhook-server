<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use App\Models\User;
use App\Models\Group;

class GoogleLoginController extends Controller
{
  public function redirectGoogleAuth()
  {
    return Socialite::driver('google')->redirect();
  }

  public function authGoogleCallback()
  {
    $google = Socialite::driver('google')->stateless()->user();
    return $this->saveGroupsUser($google);
  }

  public function apiLogin(Request $request)
  {
    $googleToken = $request->google_token;
    $google = Socialite::driver('google')->userFromToken($googleToken);
    return $this->saveGroupsUser($google);
  }

  private function saveGroupsUser(GoogleUser $google)
  {
    // Groups を更新
    $group = Group::firstOrCreate([
      'email' => $google->email
    ], [
      'token' => $google->token
    ]);

    // token
    $token = Str::random(60);

    // ユーザー更新
    $user = User::where('groups_id', $group->id)->where('owner_flag', true)->first();
    if ($user) {
      $user->api_token = $token;
      $user->save();
    } else {
      $user = User::create([
        'groups_id' => $group->id,
        'api_token' => $token,
        'user_name' => $google->name,
        'owner_flag' => true,
      ]);
    }
    return ['user' => $user];
  }
}
