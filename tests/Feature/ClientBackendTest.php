<?php

namespace Tests\Feature;

use App\Http\Controllers\Supplier\ClientController;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Http\Requests\UpdateClientStatusRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClientBackendTest extends TestCase
{
    private ClientService $clients;
    private bool $testConnectionConfigured = false;

    /**
     * Build only a named SQLite memory database, never refresh the owner's application DB.
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Isolated client tests require pdo_sqlite.');
        }
        config([
            'database.connections.client_backend_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'database.default' => 'client_backend_test',
        ]);
        $this->testConnectionConfigured = true;
        DB::purge('client_backend_test');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        // Use the single canonical Client migration, regardless of its timestamp.
        $files = glob(database_path('migrations/*_create_clients_table.php')) ?: [];
        $this->assertCount(1, $files, 'Expected exactly one create_clients_table migration.');
        (require $files[0])->up();
        $this->assertTrue(Schema::hasTable('clients'), 'The selected migration must create the clients table.');
        // Client profiles now read persisted Order history; prepare its real schema here too.
        foreach (['create_flowers_table', 'create_order_tables'] as $migrationName) {
            $migrationFiles = glob(database_path('migrations/*_'.$migrationName.'.php')) ?: [];
            $this->assertCount(1, $migrationFiles, 'Expected one '.$migrationName.' migration.');
            (require $migrationFiles[0])->up();
        }
        $this->clients = app(ClientService::class);
    }

    /**
     * Disconnect only the ephemeral test connection.
     */
    protected function tearDown(): void
    {
        if ($this->testConnectionConfigured) {
            DB::disconnect('client_backend_test');
        }
        parent::tearDown();
    }

    /**
     * Account and profile creation use canonical contacts, hashed credentials, and a fixed role.
     */
    public function test_creation_sets_fixed_role_and_temporary_password_flags_atomically(): void
    {
        $client = $this->clients->store($this->input(['email' => '  CLIENT@Example.test  ']));
        $user = $client->user;
        $this->assertSame('CLI-001', $client->code);
        $this->assertSame('client@example.test', $user->email);
        $this->assertSame(User::ROLE_CLIENT, $user->role);
        $this->assertTrue((bool) $user->is_active);
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertNotSame('Temporary-test-password!', $user->password);
        $this->assertTrue(Hash::check('Temporary-test-password!', $user->password));
        $this->assertSame(1, User::count());
        $this->assertSame(1, Client::count());

        // A profile-write failure after the User insert must roll back the new account too.
        DB::statement("CREATE TRIGGER reject_test_client BEFORE INSERT ON clients BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
        try {
            $this->clients->store($this->input(['email' => 'another@example.test']));
            $this->fail('Expected the profile insert to fail.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame(1, User::count());
            $this->assertSame(1, Client::count());
        }
    }

    /**
     * Public JSON excludes credentials and derives actual empty Order totals.
     */
    public function test_public_responses_exclude_secrets_and_derive_order_totals(): void
    {
        $client = $this->clients->store($this->input());
        $controller = app(ClientController::class);
        $details = $controller->show($client, $this->clients)->getData(true);
        $this->assertTrue($details['order_history_available']);
        $this->assertSame([
            'total_orders' => 0, 'completed_orders' => 0,
            'ongoing_orders' => 0, 'completed_purchases_cents' => 0,
        ], $details['summary']);
        $this->assertSame([], $details['orders']);
        $this->assertSame(['dbId', 'id', 'name', 'email', 'phone', 'address', 'registered', 'status', 'version'], array_keys($details['data']));
        $json = json_encode($details, JSON_THROW_ON_ERROR);
        foreach (['password', 'remember_token', 'must_change_password', 'email_verified_at'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString('Temporary-test-password!', $json);
        $this->assertStringNotContainsString($client->user->password, $json);
    }

    /**
     * Email identity is unique across every account role, regardless of stored letter case.
     */
    public function test_case_insensitive_email_collision_with_admin_is_rejected(): void
    {
        User::forceCreate([
            'name' => 'Operational Admin', 'email' => 'ADMIN@EXAMPLE.TEST',
            'password' => 'Admin-test-password!', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'must_change_password' => false,
        ]);
        $this->rejected(fn () => $this->clients->store($this->input(['email' => 'admin@example.test'])), 'email');
        $this->assertSame(1, User::count());
        $this->assertSame(0, Client::count());
    }

    /**
     * Profile changes preserve credentials, role, code, and both record identities.
     */
    public function test_updates_preserve_password_role_and_identity(): void
    {
        $client = $this->clients->store($this->input());
        $client->user->email_verified_at = now();
        $client->user->save();
        $hash = $client->user->password;
        $row = $this->clients->row($client);
        $updated = $this->clients->update($client, $this->edit($client, [
            'name' => 'Updated Client', 'email' => 'updated@example.test', 'phone' => '09999999999',
        ]));
        $this->assertSame($hash, $updated->user->password);
        $this->assertNull($updated->user->email_verified_at);
        $this->assertSame(User::ROLE_CLIENT, $updated->user->role);
        $this->assertTrue((bool) $updated->user->must_change_password);
        $this->assertSame($client->id, $updated->id);
        $this->assertSame($client->user_id, $updated->user_id);
        $this->assertSame($client->code, $updated->code);
        $this->assertNotSame($row['version'], $this->clients->row($updated)['version']);
        $this->assertSame('Updated Client', $updated->user->name);
    }

    /**
     * A stale form cannot overwrite a newer contact edit or status change.
     */
    public function test_stale_versions_protect_profile_and_status_changes(): void
    {
        $client = $this->clients->store($this->input());
        $stale = $this->edit($client);
        $this->clients->update($client, $this->edit($client, ['phone' => '09999999999']));
        $this->rejected(fn () => $this->clients->update($client, $stale), 'version');
        $this->rejected(fn () => $this->clients->updateStatus($client, [
            'status' => 'Inactive', 'version' => $stale['version'],
        ]), 'version');
        $this->assertTrue((bool) $client->user()->first()->is_active);
        $this->assertSame('09999999999', $client->fresh()->phone);
    }

    /**
     * Retrying the explicit achieved state is harmless, even with its original version token.
     */
    public function test_desired_status_retries_are_idempotent(): void
    {
        $client = $this->clients->store($this->input());
        $input = ['status' => 'Inactive', 'version' => $this->clients->row($client)['version']];
        $first = $this->clients->updateStatus($client, $input);
        $retry = $this->clients->updateStatus($client, $input);
        $this->assertSame($this->clients->row($first), $this->clients->row($retry));
        $this->assertFalse((bool) $retry->user->is_active);
        $this->assertSame(1, User::count());
        $this->assertSame(1, Client::count());
    }

    /**
     * Form requests reject forged account privileges, oversized passwords, and unauthorized roles.
     */
    public function test_request_boundaries_protect_privileges_and_bcrypt_byte_limit(): void
    {
        $client = $this->clients->store($this->input());
        $update = new UpdateClientRequest;
        $update->setRouteResolver(fn () => new class($client) {
            public function __construct(private Client $client) {}
            public function parameter(string $name): Client { return $this->client; }
        });
        $rules = $update->rules();
        $input = $this->edit($client);
        $this->assertTrue(Validator::make($input, $rules)->passes());
        foreach (['role' => 'admin', 'is_active' => true, 'must_change_password' => true,
            'user_id' => 99, 'code' => 'FORGED', 'password' => 'Forged-test-password!'] as $field => $value) {
            $this->assertTrue(Validator::make([...$input, $field => $value], $rules)->fails(), $field);
        }
        $store = new StoreClientRequest;
        $longUnicode = str_repeat('é', 37); // 37 characters, but 74 UTF-8 bytes.
        $this->assertTrue(Validator::make($this->input([
            'email' => 'unicode@example.test', 'password' => $longUnicode,
        ]), $store->rules())->fails());
        foreach ([User::ROLE_ADMIN => true, User::ROLE_SUPPLIER => true, User::ROLE_CLIENT => false, 'delivery' => false] as $role => $allowed) {
            $user = new User;
            $user->role = $role; $user->is_active = true; $user->must_change_password = false;
            $store->setUserResolver(fn () => $user);
            $this->assertSame($allowed, $store->authorize());
        }
        $user->role = User::ROLE_ADMIN; $user->is_active = false;
        $this->assertFalse($store->authorize());
        $user->is_active = true; $user->must_change_password = true;
        $this->assertFalse($store->authorize());
    }

    /**
     * A profile repointed to an operational account is never editable or publicly serialized.
     */
    public function test_operational_account_profiles_are_inaccessible(): void
    {
        $client = $this->clients->store($this->input());
        $input = $this->edit($client);
        $user = $client->user;
        $user->role = User::ROLE_ADMIN;
        $user->save();
        foreach ([
            fn () => $this->clients->row($client->fresh()->load('user')),
            fn () => $this->clients->update($client, $input),
            fn () => $this->clients->updateStatus($client, ['status' => 'Inactive', 'version' => $input['version']]),
        ] as $action) {
            try {
                $action();
                $this->fail('Operational accounts must be inaccessible through client endpoints.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
        $this->assertSame('Test Client', $user->fresh()->name);
        $list = app(ClientController::class)->data($this->clients)->getData(true);
        $this->assertSame([], $list['data']);
    }

    /**
     * Provide valid new-client fields without relying on seeders or production accounts.
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Test Client', 'email' => 'client@example.test', 'phone' => '09123456789',
            'address' => 'Test address', 'status' => 'Active', 'password' => 'Temporary-test-password!',
        ], $overrides);
    }

    /**
     * Build an edit payload from the current explicit public representation.
     */
    private function edit(Client $client, array $overrides = []): array
    {
        $row = $this->clients->row($client);

        return array_replace(array_intersect_key($row, array_flip(['name', 'email', 'phone', 'address', 'status', 'version'])), $overrides);
    }

    /**
     * Assert a domain failure while permitting subsequent rollback assertions.
     */
    private function rejected(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected client validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
