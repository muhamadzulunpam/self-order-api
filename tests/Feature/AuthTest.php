<?php
namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'salah',
        ])->assertUnprocessable();
    }

    public function test_user_can_logout_and_token_is_deleted(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_role_middleware_blocks_other_roles(): void
    {
        Route::middleware(['auth:sanctum', 'role:admin'])
            ->get('/api/_admin-only', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Cashier]));
        $this->getJson('/api/_admin-only')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/api/_admin-only')->assertOk();
    }
}