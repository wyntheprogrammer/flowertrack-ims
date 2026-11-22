<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Flower;
use App\Models\User;
use App\Services\ClientService;
use App\Support\AccountSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientAuthenticationTest extends TestCase
{
    private const TEMPORARY_PASSWORD = 'Temporary-flower-password!';

    private const NEW_PASSWORD = 'Updated-flower-password!';

    private const GENERIC_ERROR = 'Unable to sign in with these credentials.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'client_auth_test',
            'database.connections.client_auth_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'session.driver' => 'array',
            'cache.default' => 'array',
            'hashing.bcrypt.rounds' => 4,
        ]);

        $this->artisan('migrate', ['--database' => 'client_auth_test'])->assertSuccessful();
        $this->withoutVite();
    }

    public function test_direct_sign_in_only_accepts_an_internal_destination_and_logout_ends_the_session(): void
    {
        $user = $this->clientAccount('Active', false);

        $this->get(route('client.profile'))->assertRedirect(route('client.login'));
        $this->get(route('client.login'))->assertOk()->assertSeeTextInOrder([
            'Customer sign-in', 'Email', 'Password', 'Sign in', 'Contact Gemma’s Flower Shop for account access.',
        ])->assertSee('shop-footer-featured', false);
        $this->post(route('client.login.store'), [
            'email' => strtoupper($user->email),
            'password' => self::TEMPORARY_PASSWORD,
            'continue' => 'https://example.test/elsewhere',
        ])->assertRedirect(route('client.home'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(AccountSession::signature($user->fresh()), session(AccountSession::KEY));
        $this->get(route('client.profile'))->assertOk()->assertSeeText($user->email)->assertSeeText('Sign out');
        $this->get(route('supplier.dashboard'))->assertForbidden();

        $this->post(route('logout'))->assertRedirect(route('client.home'))->assertSessionMissing(AccountSession::KEY);
        $this->assertGuest();
        $this->get(route('client.checkout'))->assertOk()->assertSeeTextInOrder([
            'Select items in your cart to review checkout', 'Sign in to continue',
        ]);

        $events = AuditEvent::where('module', 'Authentication')->whereIn('action', ['Login Accepted', 'Session Ended'])->get();
        $this->assertCount(2, $events);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $user->id, 'actor_role' => User::ROLE_CLIENT, 'action' => 'Login Accepted',
            'result' => 'Success', 'route_name' => 'client.login.store', 'description' => 'Customer storefront sign-in accepted.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $user->id, 'action' => 'Session Ended', 'result' => 'Success',
        ]);
    }

    public function test_checkout_sign_in_with_a_temporary_password_preserves_the_guest_cart_and_reprices_checkout(): void
    {
        $user = $this->clientAccount();
        $rose = $this->stockProduct('Red Rose', 'Stem', 5000);
        $bouquet = $this->stockProduct('Garden Bouquet', 'Bouquet', 80000);
        config(['orders.delivery_fee' => '15.50']);

        $this->post(route('client.cart.add', $rose), ['quantity' => 3])->assertRedirect(route('client.cart'));
        $this->post(route('client.cart.add', $bouquet), ['quantity' => 1])->assertRedirect(route('client.cart'));
        $this->postJson(route('client.cart.selection'), ['selected' => [$rose->id]])->assertNoContent();

        $checkout = route('client.checkout', ['method' => 'delivery']);
        $signIn = route('client.login', ['continue' => 'client.checkout.delivery']);
        $this->get($checkout)->assertOk()
            ->assertSeeTextInOrder(['Red Rose', 'Quantity 3', 'Delivery total', '₱165.50', 'Sign in to continue'])
            ->assertSee('href="'.e($signIn).'"', false)
            ->assertDontSeeText('Garden Bouquet');
        $this->get($signIn)->assertOk()->assertSee('name="continue" value="client.checkout.delivery"', false);

        $sessionId = session()->getId();
        $this->post(route('client.login.store'), [
            'email' => $user->email, 'password' => self::TEMPORARY_PASSWORD, 'continue' => 'client.checkout.delivery',
        ])->assertRedirect(route('password.change'))
            ->assertSessionHas('shop.cart', [$rose->id => 3, $bouquet->id => 1])
            ->assertSessionHas('shop.cart_selection', [$rose->id]);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertAuthenticatedAs($user);

        $this->get($checkout)->assertRedirect(route('password.change'));
        $this->get(route('client.profile'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))->assertOk()
            ->assertSeeText('Change your password')
            ->assertSeeText('Replace your temporary password to continue.')
            ->assertDontSeeText('Back to My Profile')
            ->assertSee('shop-footer-featured', false);

        $this->from(route('password.change'))->put(route('password.update'), [
            'current_password' => self::TEMPORARY_PASSWORD,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => 'Different-new-password!',
        ])->assertRedirect(route('password.change'))
            ->assertSessionHasErrors(['password_confirmation' => 'The password confirmation does not match the new password.'])
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionHas('shop.cart', [$rose->id => 3, $bouquet->id => 1])
            ->assertSessionHas('shop.cart_selection', [$rose->id]);
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame($user->password, $user->fresh()->password);
        $this->withCookie(session()->getName(), session()->getId())->get(route('password.change'))->assertOk()
            ->assertSee('aria-describedby="password_confirmation-error"', false)
            ->assertDontSee(self::NEW_PASSWORD)
            ->assertDontSee('Different-new-password!');

        DB::table('flower_prices')->where('flower_id', $rose->id)->update(['price_cents' => 6000]);

        $sessionId = session()->getId();
        $this->put(route('password.update'), [
            'current_password' => self::TEMPORARY_PASSWORD,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect($checkout)
            ->assertSessionHas('shop.cart', [$rose->id => 3, $bouquet->id => 1])
            ->assertSessionHas('shop.cart_selection', [$rose->id]);
        $this->assertNotSame($sessionId, session()->getId());
        $updated = $user->fresh();
        $this->assertFalse($updated->must_change_password);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $updated->password));
        $this->assertSame(AccountSession::signature($updated), session(AccountSession::KEY));

        $this->withCookie(session()->getName(), session()->getId())->get($checkout)->assertOk()
            ->assertSeeTextInOrder(['Red Rose', '₱60.00 per stem', 'Quantity 3', 'Item subtotal', '₱180.00', 'Delivery total', '₱195.50'])
            ->assertDontSeeText('Garden Bouquet')
            ->assertDontSeeText('Sign in to continue');
        $this->get(route('client.cart'))->assertOk()
            ->assertSeeText('Garden Bouquet')
            ->assertSeeText('Review checkout (1 item)')
            ->assertSessionHas('shop.cart_selection', [$rose->id]);
        $this->assertSame(1, AuditEvent::where('action', 'Login Accepted')->where('actor_id', $user->id)->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'Login Rejected', 'actor_id' => $user->id]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_payments', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseHas('inventory_batches', ['flower_id' => $rose->id, 'remaining_quantity' => 5]);
    }

    public function test_profile_displays_only_the_signed_in_customers_account_details(): void
    {
        $user = $this->clientAccount('Active', false);
        $other = $this->clientAccount('Active', false);
        $other->clientProfile()->update(['phone' => '09991234567', 'address' => 'Other address, Manila']);
        $this->post(route('client.login.store'), ['email' => $user->email, 'password' => self::TEMPORARY_PASSWORD])
            ->assertRedirect(route('client.home'));

        $response = $this->get(route('client.profile', ['user_id' => $other->id, 'client_id' => $other->clientProfile->id]));

        $response->assertOk()
            ->assertSeeTextInOrder(['Account details', 'Name', $user->name, 'Sign-in email', $user->email,
                'Phone number', '09171234567', 'Address on file', 'Sampaloc, Manila',
                'Contact the shop to correct your account details.', 'Contact the shop', 'Security', 'Change password'])
            ->assertSee('aria-current="page" >My Profile</a>', false)
            ->assertSee('href="'.config('services.facebook.shop_url').'"', false)
            ->assertSee('href="'.route('password.change').'"', false)
            ->assertDontSeeText($other->email)
            ->assertDontSeeText('09991234567')
            ->assertDontSeeText('Other address, Manila');
    }

    public function test_profile_handles_blank_contact_details(): void
    {
        $user = $this->clientAccount('Active', false);
        $user->clientProfile()->update(['phone' => '', 'address' => ' ']);
        $this->actingAs($user)->withSession([AccountSession::KEY => AccountSession::signature($user)]);

        $this->get(route('client.profile'))->assertOk()
            ->assertSeeTextInOrder(['Phone number', 'Not on file', 'Address on file', 'Not on file']);
    }

    public function test_profile_signs_out_an_inactive_customer_and_rejects_staff(): void
    {
        $inactive = $this->clientAccount('Inactive', false);
        $this->actingAs($inactive)->withSession([AccountSession::KEY => AccountSession::signature($inactive)]);
        $this->get(route('client.profile'))->assertRedirect(route('client.login'));
        $this->assertGuest();

        $staff = User::factory()->create(['role' => User::ROLE_SUPPLIER, 'is_active' => true, 'must_change_password' => false]);
        $this->actingAs($staff)->withSession([AccountSession::KEY => AccountSession::signature($staff)]);
        $this->get(route('client.profile'))->assertForbidden();
    }

    public function test_voluntary_password_change_rejects_wrong_credentials_and_accepts_new_credentials_at_next_sign_in(): void
    {
        $user = $this->clientAccount('Active', false);
        $this->get(route('password.change'))->assertRedirect(route('login'));
        $this->post(route('client.login.store'), ['email' => $user->email, 'password' => self::TEMPORARY_PASSWORD])
            ->assertRedirect(route('client.home'));
        $this->get(route('password.change'))->assertOk()
            ->assertSeeTextInOrder(['Current password', 'New password', 'Confirm new password', 'Save password', 'Back to My Profile'])
            ->assertDontSeeText('Replace your temporary password');

        $this->from(route('password.change'))->put(route('password.update'), [
            'current_password' => 'Incorrect-current-password!',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('password.change'))->assertSessionHasErrors('current_password')
            ->assertSessionMissing('_old_input.current_password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->assertSame($user->password, $user->fresh()->password);
        $this->withCookie(session()->getName(), session()->getId())->get(route('password.change'))->assertOk()
            ->assertSee('aria-describedby="current_password-error"', false)
            ->assertDontSee('Incorrect-current-password!')
            ->assertDontSee(self::NEW_PASSWORD);

        $sessionId = session()->getId();
        $this->put(route('password.update'), [
            'current_password' => self::TEMPORARY_PASSWORD,
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('client.profile'));
        $this->assertNotSame($sessionId, session()->getId());
        $this->withCookie(session()->getName(), session()->getId())->get(route('client.profile'))->assertOk()
            ->assertSeeText('Your password has been changed.');

        $this->post(route('logout'))->assertRedirect(route('client.home'));
        $this->postJson(route('client.login.store'), ['email' => $user->email, 'password' => self::TEMPORARY_PASSWORD])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
        $this->post(route('client.login.store'), ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect(route('client.home'));
        $this->assertAuthenticatedAs($user);
        $audit = AuditEvent::all()->toJson();
        $this->assertStringNotContainsString('Incorrect-current-password!', $audit);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, $audit);
        $this->assertStringNotContainsString(self::TEMPORARY_PASSWORD, $audit);
    }

    public function test_bad_credentials_inactive_accounts_missing_profiles_and_staff_roles_fail_without_starting_a_session(): void
    {
        $active = $this->clientAccount('Active', false);
        $inactive = $this->clientAccount('Inactive');
        $unlinked = User::factory()->create([
            'role' => User::ROLE_CLIENT, 'is_active' => true, 'password' => self::TEMPORARY_PASSWORD,
        ]);
        $staff = User::factory()->create([
            'role' => User::ROLE_SUPPLIER, 'is_active' => true, 'must_change_password' => false,
            'password' => self::TEMPORARY_PASSWORD,
        ]);

        foreach ([
            [$active, 'wrong-password-marker'],
            [$inactive, self::TEMPORARY_PASSWORD],
            [$unlinked, self::TEMPORARY_PASSWORD],
            [$staff, self::TEMPORARY_PASSWORD],
        ] as [$user, $password]) {
            $this->postJson(route('client.login.store'), ['email' => $user->email, 'password' => $password])
                ->assertUnprocessable()->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
            $this->assertGuest();
        }

        $this->assertSame(4, AuditEvent::where('route_name', 'client.login.store')->where('action', 'Login Failed')->count());
        $this->assertSame(4, AuditEvent::where('route_name', 'client.login.store')->count());
        $this->assertStringNotContainsString('wrong-password-marker', AuditEvent::all()->toJson());

        $this->postJson(route('login.store'), ['email' => $active->email, 'password' => self::TEMPORARY_PASSWORD])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $staff->email, 'password' => self::TEMPORARY_PASSWORD])
            ->assertRedirect(route('supplier.dashboard'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_customer_sign_in_uses_the_existing_attempt_limit(): void
    {
        $user = $this->clientAccount('Active', false);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('client.login.store'), ['email' => $user->email, 'password' => 'Wrong password'])
                ->assertUnprocessable()->assertJsonPath('errors.email.0', self::GENERIC_ERROR);
        }

        $response = $this->postJson(route('client.login.store'), ['email' => $user->email, 'password' => self::TEMPORARY_PASSWORD]);
        $response->assertUnprocessable();
        $this->assertStringContainsString('Too many login attempts.', $response->json('errors.email.0'));
        $this->assertGuest();
    }

    public function test_staff_can_find_customer_sign_in_and_switch_without_losing_the_checkout_cart(): void
    {
        $staff = User::factory()->create(['role' => User::ROLE_SUPPLIER, 'is_active' => true, 'must_change_password' => false]);
        $rose = $this->stockProduct('Red Rose', 'Stem', 5000);
        $cart = [$rose->id => 3];
        $selected = [$rose->id];

        $this->actingAs($staff)->withSession([
            AccountSession::KEY => AccountSession::signature($staff),
            'shop.cart' => $cart,
            'shop.cart_selection' => $selected,
        ]);
        $signIn = route('client.login', ['continue' => 'client.checkout']);
        $this->get(route('client.home'))->assertOk()
            ->assertSee('class="shop-sign-in-link" href="'.route('client.login').'"', false);
        $this->get(route('client.checkout'))->assertOk()
            ->assertSee('class="shop-sign-in-link" href="'.$signIn.'"', false)
            ->assertSeeText('Sign in to continue');
        $this->get($signIn)->assertOk()->assertSeeText('Sign out of staff account')->assertDontSee('name="password"', false);
        $this->post(route('client.login.switch'), ['continue' => 'client.checkout'])->assertRedirect($signIn)
            ->assertSessionMissing(AccountSession::KEY)
            ->assertSessionHas('shop.cart', $cart)
            ->assertSessionHas('shop.cart_selection', $selected);
        $this->assertGuest();
        $this->get($signIn)->assertOk()->assertSee('name="password"', false);
    }

    private function clientAccount(string $status = 'Active', bool $mustChangePassword = true): User
    {
        $identity = User::factory()->make();
        $client = app(ClientService::class)->store([
            'name' => $identity->name, 'email' => $identity->email, 'password' => self::TEMPORARY_PASSWORD,
            'phone' => '09171234567', 'address' => 'Sampaloc, Manila', 'status' => $status,
        ]);

        if (! $mustChangePassword) {
            $client->user->forceFill(['must_change_password' => false])->save();
        }

        return $client->user;
    }

    private function stockProduct(string $name, string $type, int $price): Flower
    {
        $flower = Flower::create([
            'code' => str_replace(' ', '-', $name), 'name' => $name, 'product_type' => $type,
            'category' => 'Roses', 'status' => 'Active', 'description' => $name,
        ]);
        DB::table('flower_prices')->insert(['flower_id' => $flower->id, 'price_cents' => $price]);
        DB::table('inventory_batches')->insert([
            'flower_id' => $flower->id, 'code' => 'BAT-'.$flower->id,
            'received_date' => '2026-11-01', 'harvest_date' => '2026-11-01', 'received_quantity' => 5,
            'receiving_unit' => $type, 'conversion_factor' => 1, 'initial_quantity' => 5, 'remaining_quantity' => 5,
        ]);

        return $flower;
    }
}
