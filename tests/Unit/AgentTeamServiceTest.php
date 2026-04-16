<?php

namespace Tests\Unit;

use App\Exceptions\GeminiApiException;
use App\Services\AgentTeamService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentTeamServiceTest extends TestCase
{
    public function test_run_agent_processes_team_leader_tool_calls_until_completion(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 3);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_task_plan',
                                    'arguments' => json_encode([
                                        'feature_name' => 'Authentication',
                                        'objective' => 'Plan the authentication delivery.',
                                        'deliverables' => ['Auth endpoints', 'Tests'],
                                        'implementation_steps' => ['Create routes', 'Implement auth service'],
                                        'acceptance_criteria' => ['Users can register', 'Users can log in'],
                                        'test_strategy' => ['Feature tests', 'Rate-limit tests'],
                                        'risks' => ['Token expiry handling'],
                                        'priority' => 'high',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Team leader delivery plan is ready.',
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('team_leader', 'Plan an authentication API.');

        $this->assertSame('Team leader delivery plan is ready.', $output);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && ($payload['tools'][0]['function']['name'] ?? null) === 'create_task_plan';
        });
        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'] ?? [];

            foreach ($messages as $message) {
                if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'call_1') {
                    return true;
                }
            }

            return false;
        });
    }

    public function test_team_lead_alias_runs_team_leader_agent(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 1);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Alias resolved correctly.',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('team_lead', 'Plan the authentication feature.');

        $this->assertSame('Alias resolved correctly.', $output);
        Http::assertSentCount(1);
    }

    public function test_backend_role_runs_backend_agent(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 1);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Backend implementation plan is ready.',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('backend', 'Design the API and persistence layer for invoices.');

        $this->assertSame('Backend implementation plan is ready.', $output);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'] ?? [];

            return str_contains((string) ($messages[0]['content'] ?? ''), 'backend implementation owner');
        });
    }

    public function test_github_pr_role_runs_github_pr_agent(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 1);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'GitHub PR workflow is ready.',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('github_pr', 'Prepare git push and PR steps for the completed authentication backend work.');

        $this->assertSame('GitHub PR workflow is ready.', $output);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'] ?? [];

            return str_contains((string) ($messages[0]['content'] ?? ''), 'Git and GitHub delivery owner');
        });
    }

    public function test_github_pr_role_can_read_github_configuration_tool(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 1);
        config()->set('services.gemini.retry_sleep_ms', 0);
        config()->set('services.github.token', 'github-token');
        config()->set('services.github.owner', 'acme');
        config()->set('services.github.repository', 'ai-agent');
        config()->set('services.github.base_branch', 'develop');
        config()->set('services.github.use_gh_cli', false);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_github_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_github_configuration',
                                    'arguments' => '{}',
                                ],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'GitHub configuration checked.',
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('github_pr', 'Prepare the PR workflow for the completed backend work.');

        $this->assertSame('GitHub configuration checked.', $output);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return ($request->data()['tools'][0]['function']['name'] ?? null) === 'get_github_configuration';
        });
        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'] ?? [];

            foreach ($messages as $message) {
                if (($message['role'] ?? null) === 'tool' && ($message['tool_call_id'] ?? null) === 'call_github_1') {
                    return str_contains((string) ($message['content'] ?? ''), 'Owner: acme')
                        && str_contains((string) ($message['content'] ?? ''), 'Repository: ai-agent')
                        && str_contains((string) ($message['content'] ?? ''), 'Status: Ready for GitHub automation');
                }
            }

            return false;
        });
    }

    public function test_run_agent_retries_on_transient_503_error(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 3);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'error' => [
                        'code' => 503,
                        'message' => 'High demand',
                    ],
                ], 503)
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Recovered after retry.',
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('team_leader', 'Create a plan for login and registration.');

        $this->assertSame('Recovered after retry.', $output);
        Http::assertSentCount(2);
    }

    public function test_run_agent_falls_back_to_flash_lite_after_repeated_503_errors(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 2);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'error' => [
                        'code' => 503,
                        'message' => 'High demand',
                    ],
                ], 503)
                ->push([
                    'error' => [
                        'code' => 503,
                        'message' => 'Still high demand',
                    ],
                ], 503)
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Fallback model succeeded.',
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        $output = app(AgentTeamService::class)->runAgent('team_leader', 'Plan the authentication feature.');

        $this->assertSame('Fallback model succeeded.', $output);
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request): bool {
            return ($request->data()['model'] ?? null) === 'gemini-2.5-flash-lite';
        });
    }

    public function test_run_agent_does_not_retry_or_fallback_after_429_error(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 3);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 429,
                    'message' => 'Quota exceeded',
                    'details' => [
                        [],
                        [],
                        ['retryDelay' => '15s'],
                    ],
                ],
            ], 429),
        ]);

        try {
            app(AgentTeamService::class)->runAgent('team_leader', 'Create a plan for login and registration.');
            $this->fail('Expected GeminiApiException was not thrown.');
        } catch (GeminiApiException $exception) {
            $this->assertSame(429, $exception->status());
            $this->assertSame(15, $exception->retryAfterSeconds());
            $this->assertStringContainsString('Quota exceeded', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            return ($request->data()['model'] ?? null) === 'gemini-2.5-flash';
        });
    }

    public function test_run_team_uses_team_leader_agent_and_returns_single_output(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.model', 'gemini-2.5-flash');
        config()->set('services.gemini.fallback_models', ['gemini-2.5-flash-lite']);
        config()->set('services.gemini.retries', 1);
        config()->set('services.gemini.retry_sleep_ms', 0);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Single agent plan output.',
                    ],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $output = app(AgentTeamService::class)->runTeam('Build a login system with JWT and refresh tokens.');

        $this->assertSame([
            'role' => 'team_leader',
            'output' => 'Single agent plan output.',
        ], $output);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $messages = $payload['messages'] ?? [];

            return ($payload['tools'][0]['function']['name'] ?? null) === 'create_task_plan'
                && str_contains((string) ($messages[1]['content'] ?? ''), 'Feature Request:');
        });
    }
}
