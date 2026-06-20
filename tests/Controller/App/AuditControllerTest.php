<?php

namespace Tests\Controller\App;

use App\Enums\ActivityLog;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_audit_page(): void
    {
        $this->user->assignRole(Role::ADMIN);

        $response = $this->get('system/audit');

        $response->assertOk()->assertSee('System Events');
    }

    public function test_audit_page_with_entries(): void
    {
        $this->user->assignRole(Role::ADMIN);

        $this->post('settings/generate-cron-token');

        $response = $this->get('system/audit');
        $response->assertSee('System: Cron Token was re-generated');
    }

    public function test_audit_page_escapes_activity_causer_name(): void
    {
        $this->user->assignRole(Role::ADMIN);
        $payload = '<img src=x onerror=alert(1)>';
        $attacker = User::factory()->create(['name' => $payload]);
        Activity::create([
            'description' => ActivityLog::USER_API_TOKEN_GENERATED,
            'causer_type' => User::class,
            'causer_id' => $attacker->id,
        ]);

        $response = $this->get('system/audit');

        $response->assertOk();
        $response->assertDontSee($payload, false);
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }
}
