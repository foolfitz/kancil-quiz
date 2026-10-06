<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * 隱私權政策與使用條款（docs/SPEC.md 第 11 節）：不需登入，營運者與聯絡方式來自設定。
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_read_the_privacy_policy_and_terms(): void
    {
        config(['kancil.operator' => 'Kancil Quiz 志工團隊', 'kancil.contact_email' => 'hello@example.org', 'kancil.upload_quota_mb' => 300]);

        $this->get('/privacy')->assertOk()
            ->assertSee('<title>隱私權政策 - '.config('app.name').'</title>', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('legal/Privacy')
                ->where('operator', 'Kancil Quiz 志工團隊')
                ->where('contactEmail', 'hello@example.org')
                ->where('retention.attempt_months', 12)
                ->where('retention.trashed_days', 30)
                ->where('retention.contribution_months', 13));

        $this->get('/terms')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('legal/Terms')
                ->where('uploadQuotaMb', 300));
    }
}
