<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for the access-control, payment and input-validation fixes.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insert([
            'id' => 'tenant_001', 'name' => 'Tenant', 'subdomain' => 'tenant-001', 'plan_tier' => 'basic',
            'status' => 'active', 'farmer_count' => 0, 'mrr' => 999, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([
            ['usr_adm_1', 'admin', 'admin@example.com'],
            ['usr_far_1', 'farmer', 'farmer1@example.com'],
            ['usr_far_2', 'farmer', 'farmer2@example.com'],
            ['usr_vnd_1', 'vendor', 'vendor1@example.com'],
            ['usr_vnd_2', 'vendor', 'vendor2@example.com'],
            ['usr_cmp_1', 'company', 'company1@example.com'],
        ] as [$id, $role, $email]) {
            DB::table('users')->insert([
                'public_id' => $id, 'tenant_id' => 'tenant_001', 'role' => $role, 'name' => ucfirst($role).' '.$id,
                'email' => $email, 'phone' => '01700000000', 'password' => Hash::make('correct-password'),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (['usr_far_1' => 'AGS-1', 'usr_far_2' => 'AGS-2'] as $id => $fid) {
            DB::table('farmers')->insert([
                'id' => $id, 'user_id' => $id, 'tenant_id' => 'tenant_001', 'fid' => $fid, 'nid_hash' => 'secret_nid_'.$id,
                'name_en' => $id, 'district' => 'Dhaka', 'phone' => '01700000000', 'bkash_account' => '01799999999',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (['usr_vnd_1' => 'VND-1', 'usr_vnd_2' => 'VND-2'] as $id => $code) {
            DB::table('vendors')->insert([
                'id' => $id, 'user_id' => $id, 'tenant_id' => 'tenant_001', 'vendor_id' => $code,
                'company_name' => $id, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('companies')->insert([
            'id' => 'usr_cmp_1', 'user_id' => 'usr_cmp_1', 'tenant_id' => 'tenant_001', 'company_id' => 'CMP-1',
            'company_name' => 'Buyer Ltd', 'registration_no' => 'REG-1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            'id' => 'prod_1', 'tenant_id' => 'tenant_001', 'vendor_id' => 'usr_vnd_1', 'name_en' => 'Urea', 'category' => 'Fertilizer',
            'price' => 100, 'unit' => 'kg', 'stock_qty' => 10, 'estimated_delivery_days' => 2, 'rating' => 4,
            'review_count' => 3, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createLockedDeal(string $gateway = 'stripe'): string
    {
        DB::table('crop_listings')->insert([
            'id' => 'CRP-1', 'tenant_id' => 'tenant_001', 'farmer_id' => 'usr_far_1', 'crop_type' => 'Potato',
            'quantity_kg' => 100, 'asking_price' => 30, 'status' => 'matched', 'photos' => '[]', 'district' => 'Dhaka',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('crop_deals')->insert([
            'id' => 'deal_1', 'listing_id' => 'CRP-1', 'company_id' => 'usr_cmp_1', 'farmer_id' => 'usr_far_1',
            'agreed_price' => 30, 'quantity_kg' => 100, 'commission_pct' => 3, 'commission_amt' => 90,
            'status' => 'locked', 'payment_gateway' => $gateway, 'payment_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'deal_1';
    }

    // ---------------------------------------------------------------- authentication

    public function test_protected_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/admin/audit-logs')->assertStatus(401);
        $this->deleteJson('/api/v1/users/usr_adm_1')->assertStatus(401);
        $this->getJson('/api/v1/users')->assertStatus(401);
        $this->assertDatabaseHas('users', ['public_id' => 'usr_adm_1']);
    }

    public function test_login_issues_a_working_token_and_logout_revokes_it(): void
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'farmer1@example.com', 'password' => 'correct-password'])
            ->assertOk()
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.userId', 'usr_far_1');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_blocked_farmer_cannot_log_in_or_use_existing_token(): void
    {
        $this->actingAsApiUser('usr_far_1');
        DB::table('farmers')->where('id', 'usr_far_1')->update(['blocked' => true]);

        $this->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->postJson('/api/v1/auth/login', ['email' => 'farmer1@example.com', 'password' => 'correct-password'])->assertStatus(403);
    }

    // ---------------------------------------------------------------- authorization

    public function test_non_admin_cannot_use_admin_endpoints(): void
    {
        $this->actingAsApiUser('usr_far_1');

        $this->getJson('/api/v1/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/stats')->assertStatus(403);
        $this->patchJson('/api/v1/admin/orders/ORD-1/settlement', ['action' => 'release'])->assertStatus(403);
    }

    public function test_user_cannot_delete_or_edit_another_account(): void
    {
        $this->actingAsApiUser('usr_far_1');

        $this->deleteJson('/api/v1/users/usr_far_2')->assertStatus(403);
        $this->patchJson('/api/v1/users/usr_adm_1', ['email' => 'attacker@example.com'])->assertStatus(403);
        $this->getJson('/api/v1/notifications/usr_far_2')->assertStatus(403);
        $this->getJson('/api/v1/farmers/usr_far_2/cart')->assertStatus(403);

        $this->assertDatabaseHas('users', ['public_id' => 'usr_far_2']);
        $this->assertDatabaseHas('users', ['public_id' => 'usr_adm_1', 'email' => 'admin@example.com']);
    }

    public function test_email_change_requires_current_password(): void
    {
        $this->actingAsApiUser('usr_far_1');

        $this->patchJson('/api/v1/users/usr_far_1', ['email' => 'new@example.com'])->assertStatus(403);
        $this->patchJson('/api/v1/users/usr_far_1', ['email' => 'farmer2@example.com', 'currentPassword' => 'correct-password'])->assertStatus(422);
        $this->patchJson('/api/v1/users/usr_far_1', ['email' => 'new@example.com', 'currentPassword' => 'correct-password'])->assertOk();

        $this->assertDatabaseHas('users', ['public_id' => 'usr_far_1', 'email' => 'new@example.com']);
    }

    public function test_partial_profile_update_keeps_other_fields(): void
    {
        $this->actingAsApiUser('usr_far_1')->patchJson('/api/v1/users/usr_far_1', ['name' => 'Renamed'])->assertOk();

        $this->assertDatabaseHas('farmers', ['id' => 'usr_far_1', 'name_en' => 'Renamed', 'bkash_account' => '01799999999']);
    }

    public function test_farmer_list_hides_sensitive_fields_from_non_admins(): void
    {
        $vendorView = $this->actingAsApiUser('usr_vnd_1')->getJson('/api/v1/farmers')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('nidHash', $vendorView);
        $this->assertArrayNotHasKey('bkashAccount', $vendorView);

        $adminView = $this->actingAsApiUser('usr_adm_1')->getJson('/api/v1/farmers')->assertOk()->json('data.0');
        $this->assertArrayHasKey('nidHash', $adminView);
    }

    // ---------------------------------------------------------------- registration and OTP

    public function test_admin_role_cannot_be_self_registered(): void
    {
        $this->postJson('/api/v1/auth/register-role', [
            'role' => 'admin', 'name' => 'Evil', 'email' => 'evil@example.com', 'phone' => '1', 'password' => 'password1234',
        ])->assertOk()->assertJsonPath('data.success', false);

        $this->postJson('/api/v1/auth/send-otp', ['email' => 'evil@example.com', 'role' => 'admin', 'purpose' => 'registration'])
            ->assertJsonPath('data.success', false);

        $this->assertDatabaseMissing('users', ['email' => 'evil@example.com']);
    }

    public function test_send_otp_does_not_reveal_unknown_accounts(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/send-otp', ['email' => 'nobody@example.com', 'purpose' => 'login'])
            ->assertOk()
            ->assertJsonPath('data.success', true);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'farmer1@example.com', 'password' => 'wrong'])->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'farmer1@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_otp_requires_its_token_and_dies_after_repeated_wrong_codes(): void
    {
        // Isolate the per-OTP attempt counter from the per-minute route throttle.
        $this->withoutMiddleware(ThrottleRequests::class);

        DB::table('auth_otps')->insert([
            'id' => 'otp_1', 'user_id' => 'usr_far_1', 'email' => 'farmer1@example.com', 'role' => 'farmer', 'purpose' => 'login',
            'token' => 'otp-token-1', 'otp_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Email alone is no longer enough.
        $this->postJson('/api/v1/auth/verify-otp', ['email' => 'farmer1@example.com', 'otp' => '123456'])
            ->assertJsonPath('data.success', false);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verify-otp', ['email' => 'farmer1@example.com', 'otp' => '000000', 'otpToken' => 'otp-token-1']);
        }

        // Even the right code fails once the attempt budget is spent.
        $this->postJson('/api/v1/auth/verify-otp', ['email' => 'farmer1@example.com', 'otp' => '123456', 'otpToken' => 'otp-token-1'])
            ->assertJsonPath('data.success', false);
    }

    public function test_self_registered_officer_is_inactive_until_approved(): void
    {
        DB::table('auth_otps')->insert([
            'id' => 'otp_reg', 'email' => 'officer@example.com', 'role' => 'officer', 'purpose' => 'registration',
            'token' => 'reg-token', 'otp_hash' => Hash::make('111111'), 'expires_at' => now()->addMinutes(10),
            'consumed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/register-role', [
            'role' => 'officer', 'name' => 'New Officer', 'email' => 'officer@example.com', 'phone' => '017',
            'password' => 'officer-pass-123', 'registrationOtpToken' => 'reg-token',
        ])->assertOk()->assertJsonPath('data.success', true);

        $this->postJson('/api/v1/auth/login', ['email' => 'officer@example.com', 'password' => 'officer-pass-123'])->assertStatus(403);
    }

    // ---------------------------------------------------------------- marketplace

    public function test_vendor_cannot_mass_assign_or_edit_other_vendors_products(): void
    {
        $this->actingAsApiUser('usr_vnd_1')
            ->patchJson('/api/v1/products/prod_1', ['price' => 120, 'vendorId' => 'usr_vnd_2', 'rating' => 5, 'id' => 'hijacked'])
            ->assertOk();
        $this->assertDatabaseHas('products', ['id' => 'prod_1', 'vendor_id' => 'usr_vnd_1', 'rating' => 4, 'price' => 120]);

        $this->actingAsApiUser('usr_vnd_2')->patchJson('/api/v1/products/prod_1', ['price' => 1])->assertStatus(403);
        $this->actingAsApiUser('usr_vnd_2')->deleteJson('/api/v1/products/prod_1')->assertStatus(403);
    }

    public function test_order_validates_quantity_and_stock_and_never_starts_paid(): void
    {
        $this->actingAsApiUser('usr_far_1');

        $this->postJson('/api/v1/orders', ['items' => [['productId' => 'prod_1', 'quantity' => -5]]])->assertStatus(422);
        $this->postJson('/api/v1/orders', ['items' => [['productId' => 'prod_1', 'quantity' => 11]]])
            ->assertJsonPath('data.success', false);

        $orderId = $this->postJson('/api/v1/orders', ['items' => [['productId' => 'prod_1', 'quantity' => 4]], 'paymentGateway' => 'bkash'])
            ->assertJsonPath('data.success', true)
            ->json('data.orderId');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => 'pending', 'total_amount' => 400]);
        $this->assertDatabaseHas('products', ['id' => 'prod_1', 'stock_qty' => 6]);
    }

    public function test_order_status_follows_role_transitions(): void
    {
        $orderId = $this->actingAsApiUser('usr_far_1')
            ->postJson('/api/v1/orders', ['items' => [['productId' => 'prod_1', 'quantity' => 1]]])
            ->json('data.orderId');

        // The farmer cannot confirm on the vendor's behalf, and another vendor cannot touch it.
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])->assertStatus(422);
        $this->actingAsApiUser('usr_vnd_2')->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])->assertStatus(403);
        $this->actingAsApiUser('usr_vnd_1')->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'confirmed'])->assertOk();
    }

    public function test_tenant_update_ignores_non_editable_columns(): void
    {
        $this->actingAsApiUser('usr_adm_1')
            ->patchJson('/api/v1/admin/tenants/tenant_001', ['name' => 'Renamed', 'mrr' => 1, 'farmerCount' => 999])
            ->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => 'tenant_001', 'name' => 'Renamed', 'mrr' => 999, 'farmer_count' => 0]);
    }

    // ---------------------------------------------------------------- payments

    public function test_stripe_success_without_a_verified_session_does_not_mark_paid(): void
    {
        $this->createLockedDeal();

        $this->get('/api/v1/payments/stripe/success?deal_id=deal_1')->assertOk();
        $this->get('/api/v1/payments/stripe/success?deal_id=deal_1&session_id=cs_forged')->assertOk();

        $this->assertDatabaseHas('crop_deals', ['id' => 'deal_1', 'payment_status' => 'pending']);
    }

    public function test_mock_payment_works_only_when_simulator_is_enabled(): void
    {
        $this->createLockedDeal();
        DB::table('crop_deals')->where('id', 'deal_1')->update(['payment_ref' => 'mock_abc']);

        config(['agrosync.payments_mock_enabled' => false]);
        $this->get('/api/v1/payments/stripe/success?deal_id=deal_1&session_id=mock_abc');
        $this->assertDatabaseHas('crop_deals', ['id' => 'deal_1', 'payment_status' => 'pending']);

        config(['agrosync.payments_mock_enabled' => true]);
        $this->get('/api/v1/payments/stripe/success?deal_id=deal_1&session_id=mock_abc');
        $this->assertDatabaseHas('crop_deals', ['id' => 'deal_1', 'payment_status' => 'paid']);
    }

    public function test_sslcommerz_success_requires_the_stored_transaction(): void
    {
        $this->createLockedDeal('bkash');
        config(['agrosync.payments_mock_enabled' => true]);

        $this->post('/api/v1/payments/sslcommerz/success', ['value_a' => 'deal_1', 'status' => 'VALID'])->assertOk();
        $this->post('/api/v1/payments/sslcommerz/success', ['value_a' => 'deal_1', 'tran_id' => 'DEAL_FORGED', 'status' => 'VALID'])->assertOk();

        $this->assertDatabaseHas('crop_deals', ['id' => 'deal_1', 'payment_status' => 'pending']);
    }

    public function test_client_cannot_set_deal_payment_status(): void
    {
        $this->createLockedDeal();

        $this->actingAsApiUser('usr_cmp_1')
            ->patchJson('/api/v1/crop-deals/deal_1/status', ['status' => 'order_placed', 'paymentStatus' => 'paid', 'actorRole' => 'farmer'])
            ->assertStatus(422);

        $this->assertDatabaseHas('crop_deals', ['id' => 'deal_1', 'status' => 'locked', 'payment_status' => 'pending']);
    }

    public function test_payment_popup_escapes_request_values_inside_script(): void
    {
        $html = $this->get('/api/v1/payments/stripe/fail?deal_id='.urlencode('</script><script>alert(1)</script>'))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }
}
