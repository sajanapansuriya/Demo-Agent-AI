<?php

namespace App\Console\Commands;

use App\Exceptions\GeminiApiException;
use App\Services\AgentTeamService;
use Illuminate\Console\Command;

class RunAgentTeam extends Command
{
    protected $signature = 'agent:run {--feature= : Custom feature request string}';

    protected $description = 'Run the team leader agent';

    public function __construct(protected AgentTeamService $agentTeam)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $feature = $this->option('feature') ?: $this->defaultFeature();

        $this->newLine();
        $this->info('Feature Request:');
        $this->line($feature);
        $this->newLine();

        try {
            $results = $this->agentTeam->runTeam($feature);
        } catch (GeminiApiException $e) {
            $this->error($e->getMessage());

            if ($e->retryAfterSeconds() !== null) {
                $this->line('Retry after approximately '.$e->retryAfterSeconds().' seconds.');
            }

            return self::FAILURE;
        }

        $this->info('Agent complete. Summary:');
        $this->line('  Role:   '.$results['role']);
        $this->line('  Output: '.strlen($results['output']).' chars');

        return self::SUCCESS;
    }

    protected function defaultFeature(): string
    {
        return <<<'FEATURE'
User Authentication System:
Build a complete user authentication feature including:
- User registration with email and password
- Login with JWT token issuance
- Password reset via email link
- Protected route middleware
- Remember me with refresh tokens
- Rate limiting on auth endpoints to prevent brute force
FEATURE;
    }
}
