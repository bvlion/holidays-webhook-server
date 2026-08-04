<?php

namespace Tests\Feature\Web;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeGoogleUser(string $email, string $name = 'テストユーザー', string $token = 'google-token'): GoogleUser
    {
        $googleUser = new GoogleUser();
        $googleUser->email = $email;
        $googleUser->name = $name;
        $googleUser->token = $token;

        return $googleUser;
    }

    public function test_authredirectはGoogleの認証画面へリダイレクトする()
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('redirect')->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/auth/redirect');

        $response->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_未登録のGoogleアカウントは新しいグループと所有者ユーザーを作成する()
    {
        $googleUser = $this->fakeGoogleUser('new-owner@example.test');
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/login/callback');

        $response->assertStatus(200);
        $group = Group::where('email', 'new-owner@example.test')->first();
        $this->assertNotNull($group);
        $user = User::where('groups_id', $group->id)->where('owner_flag', true)->first();
        $this->assertNotNull($user);
        $response->assertJson([
            'api_token' => $user->api_token,
            'user_name' => $user->user_name,
            'owner_flag' => 1,
        ]);
    }

    public function test_既存グループに所有者ユーザーが無い場合は新しい所有者ユーザーを作成する()
    {
        $group = Group::factory()->create(['email' => 'existing-group@example.test']);
        $googleUser = $this->fakeGoogleUser('existing-group@example.test');
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/login/callback');

        $response->assertStatus(200);
        $this->assertSame(1, User::where('groups_id', $group->id)->where('owner_flag', true)->count());
    }

    public function test_既存の所有者ユーザーがいる場合は再利用され重複作成されない()
    {
        $group = Group::factory()->create(['email' => 'returning@example.test']);
        $existingOwner = User::factory()->create(['groups_id' => $group->id, 'owner_flag' => true]);
        $googleUser = $this->fakeGoogleUser('returning@example.test');
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/login/callback');

        $response->assertStatus(200);
        $this->assertSame(1, User::where('groups_id', $group->id)->where('owner_flag', true)->count());
        $response->assertJson(['api_token' => $existingOwner->api_token]);
    }
}
