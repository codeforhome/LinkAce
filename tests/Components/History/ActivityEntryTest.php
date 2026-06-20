<?php

namespace Tests\Components\History;

use App\Enums\ActivityLog;
use App\Models\User;
use App\View\Components\History\ActivityEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_causer_name_is_escaped(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $user = User::factory()->create(['name' => $payload]);
        $activity = Activity::create([
            'description' => ActivityLog::USER_API_TOKEN_GENERATED,
            'causer_type' => User::class,
            'causer_id' => $user->id,
        ]);

        $output = (new ActivityEntry($activity))->render();

        $this->assertStringNotContainsString($payload, $output);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $output);
    }
}
