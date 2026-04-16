<?php

namespace Tests\Feature;

use App\Services\AgentTeamService;
use Mockery\MockInterface;
use Tests\TestCase;

class RunAgentTeamCommandTest extends TestCase
{
    public function test_agent_run_command_is_registered(): void
    {
        $feature = 'Build a shopping cart with checkout, promo codes, and order history.';

        $this->mock(AgentTeamService::class, function (MockInterface $mock) use ($feature): void {
            $mock->shouldReceive('runTeam')->once()->with($feature)->andReturn([
                'role' => 'team_leader',
                'output' => 'Plan ready.',
            ]);
        });

        $this->artisan('agent:run', ['--feature' => $feature])->assertSuccessful();
    }
}
