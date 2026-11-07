<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use App\Support\AccountSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class UserBackendTest extends TestCase
{
    private UserService $users;
    private User $admin;
    private bool $configured = false;

    /** Use an isolated SQLite database; never refresh the owner's application DB. */
    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) $this->markTestSkipped('pdo_sqlite is required.');
        config([
            'database.connections.user_backend_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'database.default' => 'user_backend_test',
            'session.driver' => 'array',
            'cache.default' => 'array',
            'cache.limiter' => 'array',
            'hashing.bcrypt.rounds' => 4,
        ]);
        $this->configured = true;
        DB::purge('user_backend_test');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email')->unique();
            $table->string('password'); $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('email_verified_at')->nullable(); $table->rememberToken(); $table->timestamps();
        });
        Schema::create('clients', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id')->unique();
        });
        $this->admin = $this->account('Admin', User::ROLE_ADMIN);
        $this->users = app(UserService::class);
    }

    protected function tearDown(): void
    {
        if ($this->configured) DB::disconnect('user_backend_test');
        parent::tearDown();
    }

    public function test_creation_hashes_temporary_password_and_exposes_no_secrets(): void
    {
        $input = $this->input(['email' => ' Courier@Example.test ', 'name' => ' Courier ']);
        $user = $this->users->store($input, $this->admin);
        $this->assertSame('courier@example.test', $user->email);
        $this->assertSame('Courier', $user->name);
        $this->assertSame(User::ROLE_DELIVERY, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($input['password'], $user->password));
        $row = $this->users->row($user, $this->admin);
        $this->assertSame('Delivery Personnel', $row['role']);
        $this->assertSame('USR-002', $row['id']);
        foreach (['password', 'remember_token', 'password_hash'] as $key) $this->assertArrayNotHasKey($key, $row);
        $this->assertStringNotContainsString($input['password'], json_encode($row));
        $this->assertSame($row['version'], $this->users->row($user->fresh(), $this->admin)['version']);
    }

    public function test_global_case_insensitive_email_uniqueness_includes_client_accounts(): void
    {
        $client = $this->account('Client', User::ROLE_CLIENT);
        DB::table('clients')->insert(['user_id' => $client->id]);
        $this->rejected(fn () => $this->users->store($this->input(['email' => strtoupper($client->email)]), $this->admin), 'email');
        $this->assertSame(2, User::count());
        $this->rejected(fn () => $this->users->store($this->input(['email' => strtoupper($this->admin->email)]), $this->admin), 'email');
    }

    public function test_profile_update_preserves_password_and_clears_verification_on_email_change(): void
    {
        $user = $this->account('Staff', User::ROLE_SUPPLIER);
        $user->email_verified_at = now(); $user->save();
        $hash = $user->password;
        $signature = AccountSession::signature($user);
        $updated = $this->users->update($user, $this->edit($user, [
            'name' => 'Updated Staff', 'email' => 'changed@example.test', 'role' => User::ROLE_DELIVERY,
        ]), $this->admin);
        $this->assertSame($hash, $updated->password);
        $this->assertFalse($updated->must_change_password);
        $this->assertSame('Updated Staff', $updated->name);
        $this->assertNull($updated->email_verified_at);
        $this->assertNotSame($signature, AccountSession::signature($updated));
    }

    public function test_stale_profile_and_reset_requests_cannot_overwrite_newer_changes(): void
    {
        $user = $this->account('Staff', User::ROLE_SUPPLIER);
        $old = $this->edit($user);
        $this->users->update($user, [...$old, 'name' => 'New Name'], $this->admin);
        $this->rejected(fn () => $this->users->update($user, [...$old, 'name' => 'Stale Name'], $this->admin), 'version');
        $this->rejected(fn () => $this->users->resetPassword($user, [
            'version' => $old['version'], 'password' => 'New-temporary-password!',
        ], $this->admin), 'version');
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_status_retries_are_idempotent_and_revoke_old_sessions(): void
    {
        $user = $this->account('Staff', User::ROLE_SUPPLIER);
        $signature = AccountSession::signature($user);
        $input = ['status' => 'Inactive', 'version' => $this->version($user)];
        $first = $this->users->status($user, $input, $this->admin);
        $token = $first->remember_token;
        $second = $this->users->status($user, $input, $this->admin);
        $this->assertFalse($second->is_active);
        $this->assertSame($token, $second->remember_token);
        $this->assertNotSame($signature, AccountSession::signature($second));
        $this->actingAs($second)->withSession([AccountSession::KEY => $signature])
            ->getJson('/supplier/flowers/data')->assertForbidden();
    }

    public function test_password_reset_requires_change_revokes_sessions_and_does_not_log_credentials(): void
    {
        Log::spy();
        $user = $this->account('SecondAdmin', User::ROLE_ADMIN);
        $oldSignature = AccountSession::signature($user);
        $password = 'Brand-new-temporary-password!';
        $updated = $this->users->resetPassword($user, [
            'password' => $password, 'version' => $this->version($user),
        ], $this->admin);
        $this->assertTrue(Hash::check($password, $updated->password));
        $this->assertTrue($updated->must_change_password);
        $this->assertNotSame($oldSignature, AccountSession::signature($updated));
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) =>
            $message === 'Account temporary_password_reset'
            && !str_contains(json_encode($context), $password)
            && !str_contains(json_encode($context), $updated->password)
        )->once();
        $this->actingAs($updated)->withSession([AccountSession::KEY => $oldSignature])
            ->getJson('/supplier/users/data')->assertUnauthorized();
    }

    public function test_self_access_and_last_active_admin_are_protected(): void
    {
        $this->rejected(fn () => $this->users->update($this->admin,
            $this->edit($this->admin, ['role' => User::ROLE_DELIVERY]), $this->admin), 'role');
        $this->rejected(fn () => $this->users->status($this->admin, [
            'status' => 'Inactive', 'version' => $this->version($this->admin),
        ], $this->admin), 'role');
        $this->rejected(fn () => $this->users->resetPassword($this->admin, [
            'password' => 'New-temporary-password!', 'version' => $this->version($this->admin),
        ], $this->admin), 'password');
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame(User::ROLE_ADMIN, $this->admin->fresh()->role);
    }

    public function test_admin_can_demote_another_admin_when_one_active_admin_remains(): void
    {
        $other = $this->account('OtherAdmin', User::ROLE_ADMIN);
        $updated = $this->users->update($other, $this->edit($other, ['role' => User::ROLE_SUPPLIER]), $this->admin);
        $this->assertSame(User::ROLE_SUPPLIER, $updated->role);
        $this->assertSame(1, User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count());
    }

    public function test_client_accounts_are_hidden_and_cannot_be_promoted_through_user_management(): void
    {
        $client = $this->account('Client', User::ROLE_CLIENT);
        DB::table('clients')->insert(['user_id' => $client->id]);
        $this->assertCount(1, $this->users->data($this->admin)['data']);
        try {
            $this->users->update($client, [
                'name' => 'Client', 'email' => $client->email, 'role' => User::ROLE_ADMIN,
                'status' => 'Active', 'version' => str_repeat('a', 64),
            ], $this->admin);
            $this->fail('Client must remain inaccessible.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame(User::ROLE_CLIENT, $client->fresh()->role);
    }

    public function test_password_byte_limit_and_privilege_fields_are_enforced(): void
    {
        foreach ([str_repeat('x', 73), str_repeat('é', 37), 'Long-password-with-null'."\0"] as $password) {
            $this->rejected(fn () => $this->users->store($this->input(['password' => $password]), $this->admin), 'password');
        }
        foreach (['is_active' => false, 'must_change_password' => false, 'remember_token' => 'forged'] as $field => $value) {
            $this->rejected(fn () => $this->users->store($this->input([$field => $value]), $this->admin), $field);
        }
        $this->rejected(fn () => $this->users->store($this->input(['role' => 'client']), $this->admin), 'role');
        $this->assertSame(1, User::count());
    }

    public function test_http_routes_enforce_admin_access_and_return_public_dtos(): void
    {
        $staff = $this->account('Staff', User::ROLE_SUPPLIER);
        $this->actingAs($staff)->withSession([AccountSession::KEY => AccountSession::signature($staff)])
            ->postJson('/supplier/users', $this->input())->assertForbidden();
        $this->actingAs($this->admin)->withSession([AccountSession::KEY => AccountSession::signature($this->admin)])
            ->postJson('/supplier/users', $this->input())
            ->assertCreated()->assertJsonPath('data.roleValue', 'delivery')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    }

    public function test_delivery_login_forces_password_change_then_redirects_to_scoped_delivery_page(): void
    {
        $user = $this->users->store($this->input(), $this->admin);
        $this->post('/login', ['email' => $user->email, 'password' => 'Temporary-password!'])
            ->assertRedirect(route('password.change'));
        $this->put('/change-password', [
            'current_password' => 'Temporary-password!',
            'password' => 'Changed-personnel-password!', 'password_confirmation' => 'Changed-personnel-password!',
        ])->assertRedirect(route('delivery.deliveries'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->getJson('/supplier/users/data')->assertForbidden();
        $this->getJson('/supplier/deliveries/data')->assertForbidden();
    }

    private function account(string $name, string $role): User
    {
        return User::forceCreate([
            'name' => $name, 'email' => strtolower($name).'@example.test',
            'password' => 'Original-password!', 'role' => $role,
            'is_active' => true, 'must_change_password' => false,
        ]);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Courier', 'email' => 'courier@example.test',
            'role' => User::ROLE_DELIVERY, 'status' => 'Active', 'password' => 'Temporary-password!',
        ], $overrides);
    }

    private function version(User $user): string
    {
        return $this->users->row($user->fresh(), $this->admin)['version'];
    }

    private function edit(User $user, array $overrides = []): array
    {
        $user = $user->fresh();
        return array_replace([
            'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
            'status' => $user->is_active ? 'Active' : 'Inactive', 'version' => $this->version($user),
        ], $overrides);
    }

    private function rejected(callable $callback, ?string $field = null): void
    {
        try { $callback(); $this->fail('Expected account validation rejection.'); }
        catch (ValidationException $exception) {
            if ($field !== null) $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
