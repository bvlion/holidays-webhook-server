<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
    $user = $this->saveGroupsUser($google);
    return [
      'api_token' => $user->api_token,
      'user_name' => $user->user_name,
      'owner_flag' => $user->owner_flag
    ];
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
    $group = Group::updateOrCreate([
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
    return $user;
  }
}
