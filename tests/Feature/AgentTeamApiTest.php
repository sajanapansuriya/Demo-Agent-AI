<?php

namespace Tests\Feature;

use App\Exceptions\GeminiApiException;
use App\Services\AgentTeamService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Mockery\MockInterface;
use Tests\TestCase;

class AgentTeamApiTest extends TestCase
{
    public function test_run_endpoint_returns_team_leader_output(): void
    {
        $feature = 'Build a profile management page with avatar uploads and validation.';

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($feature): void {
            $mock->shouldReceive('runTeam')->once()->with($feature)->andReturn([
                'role' => 'team_leader',
                'output' => 'Implementation plan ready.',
            ]);
        });

        $this->postJson('/api/agent-team/run', [
            'feature' => $feature,
        ])->assertOk()->assertJson([
            'success' => true,
            'data' => [
                'role' => 'team_leader',
                'output' => 'Implementation plan ready.',
            ],
        ]);
    }

    public function test_run_endpoint_returns_provider_status_for_gemini_quota_errors(): void
    {
        $feature = 'Build a profile management page with avatar uploads and validation.';

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($feature): void {
            $mock->shouldReceive('runTeam')->once()->with($feature)->andThrow(
                new GeminiApiException('Gemini quota exceeded.', 429, 15)
            );
        });

        $this->postJson('/api/agent-team/run', [
            'feature' => $feature,
        ])->assertStatus(429)->assertJson([
            'success' => false,
            'error' => 'Gemini quota exceeded.',
            'retry_after_seconds' => 15,
        ]);
    }

    public function test_single_agent_endpoint_accepts_team_lead_alias(): void
    {
        $message = 'Create a delivery plan for user registration and login.';

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($message): void {
            $mock->shouldReceive('normalizeRole')->once()->with('team_lead')->andReturn('team_leader');
            $mock->shouldReceive('runAgent')->once()->with('team_lead', $message)->andReturn('Plan ready.');
        });

        $this->postJson('/api/agent-team/agent', [
            'role' => 'team_lead',
            'message' => $message,
        ])->assertOk()->assertJson([
            'success' => true,
            'role' => 'team_leader',
            'output' => 'Plan ready.',
        ]);
    }

    public function test_single_agent_endpoint_accepts_backend_role(): void
    {
        $message = 'Design the Laravel API and database changes for order management.';

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($message): void {
            $mock->shouldReceive('normalizeRole')->once()->with('backend')->andReturn('backend');
            $mock->shouldReceive('runAgent')->once()->with('backend', $message)->andReturn('Backend plan ready.');
        });

        $this->postJson('/api/agent-team/agent', [
            'role' => 'backend',
            'message' => $message,
        ])->assertOk()->assertJson([
            'success' => true,
            'role' => 'backend',
            'output' => 'Backend plan ready.',
        ]);
    }

    public function test_single_agent_endpoint_accepts_github_pr_role(): void
    {
        $message = 'Backend role is done. Prepare git push commands and a GitHub PR draft.';

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($message): void {
            $mock->shouldReceive('normalizeRole')->once()->with('github_pr')->andReturn('github_pr');
            $mock->shouldReceive('runAgent')->once()->with('github_pr', $message)->andReturn('PR workflow ready.');
        });

        $this->postJson('/api/agent-team/agent', [
            'role' => 'github_pr',
            'message' => $message,
        ])->assertOk()->assertJson([
            'success' => true,
            'role' => 'github_pr',
            'output' => 'PR workflow ready.',
        ]);
    }

    public function test_single_agent_endpoint_rejects_unknown_roles(): void
    {
        $this->postJson('/api/agent-team/agent', [
            'role' => 'frontend',
            'message' => 'Do frontend work.',
        ])->assertStatus(422);
    }

    public function test_dashboard_ajax_endpoint_runs_both_roles(): void
    {
        $message = 'Design an inventory module with secure CRUD endpoints.';

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($message): void {
            $mock->shouldReceive('runAgent')->once()->with('team_leader', $message)->andReturn('Leader response.');
            $mock->shouldReceive('runAgent')->once()->with('backend', $message)->andReturn('Backend response.');
        });

        $this->postJson('/agent-dashboard/run', [
            'mode' => 'both',
            'message' => $message,
        ])->assertOk()->assertJson([
            'success' => true,
            'mode' => 'both',
            'results' => [
                'team_leader' => 'Leader response.',
                'backend' => 'Backend response.',
            ],
        ]);
    }
}
