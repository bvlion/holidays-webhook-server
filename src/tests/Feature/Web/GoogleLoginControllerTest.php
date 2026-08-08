<?php

namespace Tests\Feature\Web;

use App\Http\Controllers\Web\GoogleLoginController;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GoogleLoginControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function fakeGoogleUser(string $email, string $name = 'テストユーザー', string $token = 'google-token'): GoogleUser
    {
        $googleUser = new GoogleUser;
        $googleUser->email = $email;
        $googleUser->name = $name;
        $googleUser->token = $token;

        return $googleUser;
    }

    public function test_authredirectは_googleの認証画面へリダイレクトする()
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('redirect')->once()
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/auth/redirect');

        $response->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_未登録の_googleアカウントは新しいグループと所有者ユーザーを作成する()
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

    public function test_callbackでgoogle認証が失敗すると502を返し機密値をログへ出さない()
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $context_json = json_encode($context);

                return $message === 'external_service.failure'
                    && $context['integration'] === 'google_auth'
                    && $context['operation'] === 'callback'
                    && $context['exception'] === InvalidStateException::class
                    && ! str_contains($context_json, 'leaked-authorization-code')
                    && ! str_contains($context_json, 'leaked-state');
            });
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andThrow(
            new InvalidStateException('Invalid state: code=leaked-authorization-code')
        );
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/login/callback?code=leaked-authorization-code&state=leaked-state');

        $response->assertStatus(502);
    }

    public function test_api_loginでgoogle認証が失敗すると502を返し機密値をログへ出さない()
    {
        // apiLogin()にはルートが割り当てられていない(既存の未使用コード)ため、
        // コントローラーを直接呼び出して検証する。
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $context_json = json_encode($context);

                return $message === 'external_service.failure'
                    && $context['integration'] === 'google_auth'
                    && $context['operation'] === 'api_login'
                    && ! str_contains($context_json, 'secret-google-token');
            });
        $provider = Mockery::mock();
        $provider->shouldReceive('userFromToken')->once()->with('secret-google-token')->andThrow(
            new InvalidStateException('invalid token: secret-google-token')
        );
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $request = Request::create('/', 'POST', ['google_token' => 'secret-google-token']);

        try {
            app(GoogleLoginController::class)->apiLogin($request);
            $this->fail('例外が発生することを期待しています');
        } catch (HttpException $e) {
            $this->assertSame(502, $e->getStatusCode());
        }
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
