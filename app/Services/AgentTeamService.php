<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AgentTeamService
{
    protected const CANONICAL_ROLE = 'team_leader';

    protected const BACKEND_ROLE = 'backend';

    protected const GITHUB_PR_ROLE = 'github_pr';

    protected const ROLE_ALIASES = [
        'backend' => self::BACKEND_ROLE,
        'github' => self::GITHUB_PR_ROLE,
        'github_pr' => self::GITHUB_PR_ROLE,
        'pr_creator' => self::GITHUB_PR_ROLE,
        'team_lead' => self::CANONICAL_ROLE,
        'team_leader' => self::CANONICAL_ROLE,
    ];

    protected string $apiKey;

    protected string $model;

    protected array $fallbackModels;

    protected int $maxTurns;

    protected int $timeout;

    protected int $retries;

    protected int $retrySleepMs;

    protected string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';

    protected array $agents;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $this->fallbackModels = array_values(array_filter(
            (array) config('services.gemini.fallback_models', ['gemini-2.5-flash-lite', 'gemini-2.0-flash-lite', 'gemini-2.0-flash']),
            static fn (mixed $model): bool => is_string($model) && $model !== ''
        ));
        $this->maxTurns = (int) config('services.gemini.max_turns', 10);
        $this->timeout = (int) config('services.gemini.timeout', 120);
        $this->retries = max((int) config('services.gemini.retries', 3), 1);
        $this->retrySleepMs = max((int) config('services.gemini.retry_sleep_ms', 2000), 0);
        $this->agents = $this->defineAgents();
    }

    public function runAgent(string $role, string $userMessage, ?int $maxTurns = null): string
    {
        $this->ensureConfigured();

        $role = $this->normalizeRole($role);
        $agent = $this->agents[$role] ?? throw new InvalidArgumentException("Unknown agent role [{$role}].");
        $messages = [
            ['role' => 'system', 'content' => $agent['system']],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $turnLimit = $maxTurns ?? $this->maxTurns;
        $lastTextOutput = '';

        $this->log(str_repeat('=', 60));
        $this->log($agent['name'].' is working...');
        $this->log(str_repeat('=', 60));

        for ($turn = 0; $turn < $turnLimit; $turn++) {
            $response = $this->callApi($messages, $this->formatTools($agent['tools']));
            $choice = data_get($response, 'choices.0');

            if (! is_array($choice)) {
                throw new RuntimeException('Gemini returned an invalid response payload.');
            }

            $message = $choice['message'] ?? [];

            if (! is_array($message)) {
                throw new RuntimeException('Gemini response is missing the message payload.');
            }

            $textOutput = $this->extractText($message['content'] ?? '');
            $toolCalls = $message['tool_calls'] ?? [];

            if ($textOutput !== '') {
                $lastTextOutput = $textOutput;
                $this->log(PHP_EOL.$agent['name'].':'.PHP_EOL.$textOutput);
            }

            if ($toolCalls === []) {
                return $lastTextOutput;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $message['content'] ?? null,
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $index => $toolCall) {
                $toolName = data_get($toolCall, 'function.name');
                $toolId = data_get($toolCall, 'id', 'tool_call_'.$turn.'_'.$index);
                $arguments = data_get($toolCall, 'function.arguments', '{}');
                $toolInput = json_decode($arguments, true);

                if (! is_string($toolName) || $toolName === '') {
                    throw new RuntimeException('Gemini returned a tool call without a valid function name.');
                }

                if (! is_array($toolInput)) {
                    throw new RuntimeException("Gemini returned invalid tool arguments for [{$toolName}].");
                }

                $this->log(PHP_EOL.'Tool called: '.$toolName);

                $result = $this->executeTool($toolName, $toolInput, $role);

                $this->log($result);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolId,
                    'content' => $result,
                ];
            }
        }

        $this->log(PHP_EOL.$agent['name']." reached max turns ({$turnLimit}). Returning the last text output.");

        return $lastTextOutput;
    }

    public function runTeam(string $featureRequest): array
    {
        $this->log(PHP_EOL.str_repeat('=', 60));
        $this->log('TEAM LEADER AGENT - STARTING');
        $this->log(str_repeat('=', 60));
        $this->log(PHP_EOL.'Feature Request:'.PHP_EOL.$featureRequest.PHP_EOL);

        $output = $this->runAgent(self::CANONICAL_ROLE, $this->buildFeaturePrompt($featureRequest));

        $this->log(PHP_EOL.str_repeat('=', 60));
        $this->log('TEAM LEADER AGENT - COMPLETE');
        $this->log(str_repeat('=', 60).PHP_EOL);

        return [
            'role' => self::CANONICAL_ROLE,
            'output' => $output,
        ];
    }

    public function normalizeRole(string $role): string
    {
        return self::ROLE_ALIASES[$role] ?? $role;
    }

    protected function buildFeaturePrompt(string $featureRequest): string
    {
        return <<<PROMPT
You are the only AI agent in this Laravel project.
Create a complete implementation plan for the feature below.

Your answer must be practical and production-focused.
Cover:
- Goal summary
- Required Laravel backend work
- Data model or migration changes
- API endpoints
- Validation and security concerns
- Frontend or UI notes if needed
- Testing checklist
- Risks and next steps

Feature Request:
{$featureRequest}
PROMPT;
    }

    protected function defineAgents(): array
    {
        return [
            self::CANONICAL_ROLE => [
                'name' => 'Team Leader',
                'system' => <<<'SYSTEM'
You are the single delivery owner for a Laravel application.
You think like a strong technical team leader who can analyze requirements, define implementation scope, and produce a clear execution plan.

Your responsibilities:
- Understand the feature request fully
- Break the work into concrete implementation steps
- Call out Laravel architecture, routes, controllers, services, models, migrations, and tests where relevant
- Identify validation, security, performance, and rollout risks
- Keep the response concise but complete enough for engineers to build from

When useful, call the create_task_plan tool first to structure your planning.
Your final answer should be organized with short titled sections and clear action items.
SYSTEM,
                'tools' => [
                    [
                        'name' => 'create_task_plan',
                        'description' => 'Create a structured implementation plan for the team leader.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'feature_name' => ['type' => 'string'],
                                'objective' => ['type' => 'string'],
                                'deliverables' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'implementation_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'test_strategy' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                            ],
                            'required' => ['feature_name', 'objective', 'implementation_steps', 'acceptance_criteria', 'priority'],
                        ],
                    ],
                ],
            ],
            self::BACKEND_ROLE => [
                'name' => 'Backend Engineer',
                'system' => <<<'SYSTEM'
You are the backend implementation owner for a Laravel application.
You focus on server-side delivery details and produce practical implementation guidance for APIs and backend architecture.

Your responsibilities:
- Design Laravel backend implementation steps for the requested feature
- Focus on routes, controllers, services, models, migrations, jobs, events, policies, and tests
- Identify validation, security, data consistency, and performance concerns
- Keep frontend notes minimal unless the backend contract depends on them
- Return concise but buildable guidance for engineers

When useful, call the create_task_plan tool first to structure your work.
Your final answer should be organized with short titled sections and clear backend action items.
SYSTEM,
                'tools' => [
                    [
                        'name' => 'create_task_plan',
                        'description' => 'Create a structured implementation plan for the backend engineer.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'feature_name' => ['type' => 'string'],
                                'objective' => ['type' => 'string'],
                                'deliverables' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'implementation_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'test_strategy' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                            ],
                            'required' => ['feature_name', 'objective', 'implementation_steps', 'acceptance_criteria', 'priority'],
                        ],
                    ],
                ],
            ],
            self::GITHUB_PR_ROLE => [
                'name' => 'GitHub PR Manager',
                'system' => <<<'SYSTEM'
You are the Git and GitHub delivery owner for a Laravel application.
You focus on the final engineering handoff after code is finished: branch hygiene, commit quality, push flow, and pull request creation.

Your responsibilities:
- Review the described code changes and turn them into a clean Git workflow
- Check the configured GitHub credentials and repository settings before recommending commands
- Recommend a branch name, commit plan, and PR title
- Draft a concise PR description with summary, testing, and rollout notes
- Call out anything that should be verified before pushing
- Keep the response practical, command-oriented, and ready for engineers to use

Call the get_github_configuration tool before drafting push or PR steps.
When useful, call the create_task_plan tool first to structure your work.
Your final answer should be organized with short titled sections and include example git and GitHub commands.
SYSTEM,
                'tools' => [
                    [
                        'name' => 'get_github_configuration',
                        'description' => 'Return the configured GitHub authentication and repository settings from Laravel environment configuration.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'create_task_plan',
                        'description' => 'Create a structured implementation plan for the GitHub PR manager.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'feature_name' => ['type' => 'string'],
                                'objective' => ['type' => 'string'],
                                'deliverables' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'implementation_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'acceptance_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'test_strategy' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                            ],
                            'required' => ['feature_name', 'objective', 'implementation_steps', 'acceptance_criteria', 'priority'],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function executeTool(string $toolName, array $toolInput, string $agentRole): string
    {
        return match ($toolName) {
            'create_task_plan' => $this->toolCreateTaskPlan($toolInput),
            'get_github_configuration' => $this->toolGetGithubConfiguration(),
            default => "Unknown tool [{$toolName}] called by {$agentRole}.",
        };
    }

    protected function toolCreateTaskPlan(array $input): string
    {
        $priority = strtoupper((string) ($input['priority'] ?? 'medium'));

        return implode(PHP_EOL, [
            'Implementation Plan: '.($input['feature_name'] ?? 'Untitled feature'),
            'Priority: '.$priority,
            '',
            'Objective:',
            (string) ($input['objective'] ?? 'No objective provided.'),
            '',
            'Deliverables:',
            $this->bulletList($input['deliverables'] ?? []),
            '',
            'Implementation Steps:',
            $this->bulletList($input['implementation_steps'] ?? []),
            '',
            'Acceptance Criteria:',
            $this->bulletList($input['acceptance_criteria'] ?? []),
            '',
            'Test Strategy:',
            $this->bulletList($input['test_strategy'] ?? []),
            '',
            'Risks:',
            $this->bulletList($input['risks'] ?? []),
        ]);
    }

    protected function toolGetGithubConfiguration(): string
    {
        $token = (string) config('services.github.token', '');
        $owner = (string) config('services.github.owner', '');
        $repository = (string) config('services.github.repository', '');
        $baseBranch = (string) config('services.github.base_branch', 'main');
        $useGhCli = (bool) config('services.github.use_gh_cli', false);

        $missing = [];

        if ($owner === '') {
            $missing[] = 'GITHUB_OWNER';
        }

        if ($repository === '') {
            $missing[] = 'GITHUB_REPOSITORY';
        }

        if (! $useGhCli && $token === '') {
            $missing[] = 'GITHUB_TOKEN';
        }

        return implode(PHP_EOL, [
            'GitHub Configuration:',
            'Authentication: '.($useGhCli ? 'GitHub CLI (gh auth login)' : ($token !== '' ? 'Personal access token configured' : 'Not configured')),
            'Owner: '.($owner !== '' ? $owner : 'Not configured'),
            'Repository: '.($repository !== '' ? $repository : 'Not configured'),
            'Base Branch: '.($baseBranch !== '' ? $baseBranch : 'main'),
            'Status: '.($missing === [] ? 'Ready for GitHub automation' : 'Missing configuration'),
            'Missing Keys: '.($missing === [] ? 'None' : implode(', ', $missing)),
        ]);
    }

    protected function callApi(array $messages, array $tools): array
    {
        $payload = [
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $models = array_values(array_unique(array_merge([$this->model], $this->fallbackModels)));
        $lastErrorMessage = 'Gemini API request failed after all retry attempts.';
        $lastStatus = 503;
        $lastRetryAfterSeconds = null;

        foreach ($models as $modelIndex => $model) {
            $sleepMs = $this->retrySleepMs;
            $hasFallbackModel = isset($models[$modelIndex + 1]);
            $modelPayload = [...$payload, 'model' => $model];

            for ($attempt = 1; $attempt <= $this->retries; $attempt++) {
                try {
                    $response = Http::withHeaders([
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Content-Type' => 'application/json',
                    ])->timeout($this->timeout)->post($this->apiUrl, $modelPayload);

                    if ($response->successful()) {
                        return $response->json();
                    }

                    $status = $response->status();

                    if ($attempt < $this->retries && $this->shouldRetryStatus($status)) {
                        Log::warning('Retrying Gemini API request after transient failure.', [
                            'attempt' => $attempt,
                            'status' => $status,
                            'model' => $model,
                        ]);

                        $this->pause($sleepMs);
                        $sleepMs *= 2;

                        continue;
                    }

                    if ($hasFallbackModel && $this->shouldRetryStatus($status)) {
                        $lastErrorMessage = "Gemini model [{$model}] failed with status {$status}.";
                        $lastStatus = $status;
                        $lastRetryAfterSeconds = $this->extractRetryAfterSeconds($response->json());

                        Log::warning('Switching to fallback Gemini model after transient failure.', [
                            'status' => $status,
                            'model' => $model,
                            'fallback_model' => $models[$modelIndex + 1],
                        ]);

                        continue 2;
                    }

                    $response->throw();
                } catch (ConnectionException|RequestException $e) {
                    $status = $e instanceof RequestException ? $e->response?->status() : null;

                    if ($attempt < $this->retries && ($status === null || $this->shouldRetryStatus($status))) {
                        Log::warning('Retrying Gemini API request after exception.', [
                            'attempt' => $attempt,
                            'status' => $status,
                            'model' => $model,
                            'message' => $e->getMessage(),
                        ]);

                        $this->pause($sleepMs);
                        $sleepMs *= 2;

                        continue;
                    }

                    if ($hasFallbackModel && ($status === null || $this->shouldRetryStatus($status))) {
                        $lastErrorMessage = "Gemini model [{$model}] failed after retries.";
                        $lastStatus = $status ?? 503;
                        $lastRetryAfterSeconds = $e instanceof RequestException
                            ? $this->extractRetryAfterSeconds($e->response?->json())
                            : null;

                        Log::warning('Switching to fallback Gemini model after exception.', [
                            'status' => $status,
                            'model' => $model,
                            'fallback_model' => $models[$modelIndex + 1],
                            'message' => $e->getMessage(),
                        ]);

                        continue 2;
                    }

                    Log::error('Gemini API request failed.', [
                        'status' => $status,
                        'model' => $model,
                        'body' => $e instanceof RequestException ? $e->response?->body() : null,
                    ]);

                    throw $this->buildApiException(
                        $status ?? 503,
                        $model,
                        $e instanceof RequestException ? $e->response?->json() : null,
                        $e->getMessage(),
                        $e
                    );
                } catch (Throwable $e) {
                    Log::error('Gemini API exception.', [
                        'message' => $e->getMessage(),
                        'model' => $model,
                    ]);

                    throw new RuntimeException('Gemini API exception: '.$e->getMessage(), previous: $e);
                }
            }
        }

        throw new GeminiApiException($lastErrorMessage, $lastStatus, $lastRetryAfterSeconds);
    }

    protected function shouldRetryStatus(?int $status): bool
    {
        return in_array($status, [500, 502, 503, 504], true);
    }

    protected function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    protected function buildApiException(
        int $status,
        string $model,
        ?array $responseJson,
        string $defaultMessage,
        ?Throwable $previous = null
    ): GeminiApiException {
        $apiMessage = data_get($responseJson, 'error.message');
        $retryAfterSeconds = $this->extractRetryAfterSeconds($responseJson);

        if ($status === 429) {
            $message = "Gemini quota or rate limit exceeded for model [{$model}]";

            if (is_string($apiMessage) && $apiMessage !== '') {
                $message .= ': '.$apiMessage;
            }

            return new GeminiApiException($message, 429, $retryAfterSeconds, $previous);
        }

        $message = is_string($apiMessage) && $apiMessage !== ''
            ? "Gemini API request failed for model [{$model}]: {$apiMessage}"
            : 'Gemini API request failed: '.$defaultMessage;

        return new GeminiApiException($message, $status, $retryAfterSeconds, $previous);
    }

    protected function extractRetryAfterSeconds(?array $responseJson): ?int
    {
        $retryDelay = data_get($responseJson, 'error.details.2.retryDelay');

        if (! is_string($retryDelay) || ! preg_match('/^(\d+)(?:\.\d+)?s$/', $retryDelay, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function formatTools(array $tools): array
    {
        return array_map(
                 fn (array $tool): array => [
                     'type' => 'function',
                     'function' => [
                         'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ],
            $tools
        );
    }

    protected function ensureConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Missing GEMINI_API_KEY configuration.');
        }
    }

    protected function extractText(string|array|null $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;

                continue;
            }

            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode(PHP_EOL, $parts));
    }

    protected function bulletList(array $items): string
    {
        if ($items === []) {
            return '- None specified';
        }

        return implode(PHP_EOL, array_map(
            static fn ($item): string => '- '.(string) $item,
            $items
        ));
    }

    protected function log(string $message): void
    {
        if (app()->runningInConsole()) {
            echo $message.PHP_EOL;

            return;
        }

        Log::info($message);
    }
}
