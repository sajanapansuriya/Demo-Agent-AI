<?php

namespace App\Services;

use App\Exceptions\GeminiApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
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
- Check the configured GitHub credentials and repository settings before acting
- Inspect the current git branch and worktree before creating a pull request
- Create a branch if needed, commit changes when the user clearly asks to proceed, push the branch, and then create the pull request
- Draft a concise PR title and PR body when the user does not provide them
- Report the exact PR URL on success, or the exact blocker on failure
- Keep the response practical and execution-focused

Always call get_github_configuration and get_git_status first.
If the current branch matches the base branch, create a feature branch before pushing.
If there are uncommitted changes and the user wants the PR created now, commit them with a concrete commit message before pushing.
After the branch is pushed, call create_github_pull_request.
Your final answer should summarize what happened and include the PR URL when available.
SYSTEM,
                'tools' => [
                    [
                        'name' => 'get_github_configuration',
                        'description' => 'Return the configured GitHub authentication and repository settings from Laravel environment configuration.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => new stdClass(),
                        ],
                    ],
                    [
                        'name' => 'get_git_status',
                        'description' => 'Return the current git branch, origin remote, and worktree status for the repository.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => new stdClass(),
                        ],
                    ],
                    [
                        'name' => 'create_git_branch',
                        'description' => 'Create and switch to a new git branch for the upcoming pull request.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'branch_name' => ['type' => 'string'],
                                'from_ref' => ['type' => 'string'],
                            ],
                            'required' => ['branch_name'],
                        ],
                    ],
                    [
                        'name' => 'commit_git_changes',
                        'description' => 'Stage all tracked and untracked changes, then create a git commit.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'commit_message' => ['type' => 'string'],
                            ],
                            'required' => ['commit_message'],
                        ],
                    ],
                    [
                        'name' => 'push_git_branch',
                        'description' => 'Push the current or specified branch to the configured remote.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'branch_name' => ['type' => 'string'],
                                'remote' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'create_github_pull_request',
                        'description' => 'Create a real GitHub pull request for a pushed branch using the GitHub API.',
                        'input_schema' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'body' => ['type' => 'string'],
                                'head_branch' => ['type' => 'string'],
                                'base_branch' => ['type' => 'string'],
                                'draft' => ['type' => 'boolean'],
                            ],
                            'required' => ['title', 'body'],
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
            'get_git_status' => $this->toolGetGitStatus(),
            'create_git_branch' => $this->toolCreateGitBranch($toolInput),
            'commit_git_changes' => $this->toolCommitGitChanges($toolInput),
            'push_git_branch' => $this->toolPushGitBranch($toolInput),
            'create_github_pull_request' => $this->toolCreateGithubPullRequest($toolInput),
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
        ['token' => $token, 'owner' => $owner, 'repository' => $repository, 'base_branch' => $baseBranch, 'use_gh_cli' => $useGhCli] = $this->githubConfig();

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

    protected function toolGetGitStatus(): string
    {
        $branch = $this->currentGitBranch();
        $baseBranch = (string) config('services.github.base_branch', 'main');
        $origin = $this->runProcess(['git', 'remote', 'get-url', 'origin'], allowFailure: true);
        $status = $this->runProcess(['git', 'status', '--short', '--branch']);

        return implode(PHP_EOL, [
            'Git Status:',
            'Current Branch: '.$branch,
            'Base Branch: '.$baseBranch,
            'Origin: '.($origin !== '' ? $origin : 'Not configured'),
            'Has Uncommitted Changes: '.($this->repositoryHasChanges() ? 'Yes' : 'No'),
            'Status Output:',
            $status !== '' ? $status : 'Working tree clean.',
        ]);
    }

    protected function toolCreateGitBranch(array $input): string
    {
        $branchName = trim((string) ($input['branch_name'] ?? ''));
        $fromRef = trim((string) ($input['from_ref'] ?? ''));

        if ($branchName === '') {
            throw new RuntimeException('create_git_branch requires a non-empty branch_name.');
        }

        if ($this->currentGitBranch() === $branchName) {
            return implode(PHP_EOL, [
                'Git Branch:',
                'Status: Already on requested branch.',
                'Branch: '.$branchName,
            ]);
        }

        $command = ['git', 'checkout', '-b', $branchName];

        if ($fromRef !== '') {
            $command[] = $fromRef;
        }

        $output = $this->runProcess($command);

        return implode(PHP_EOL, [
            'Git Branch:',
            'Status: Branch created.',
            'Branch: '.$this->currentGitBranch(),
            'Output: '.$output,
        ]);
    }

    protected function toolCommitGitChanges(array $input): string
    {
        $commitMessage = trim((string) ($input['commit_message'] ?? ''));

        if ($commitMessage === '') {
            throw new RuntimeException('commit_git_changes requires a non-empty commit_message.');
        }

        $this->runProcess(['git', 'add', '-A']);

        $stagedFiles = $this->runProcess(['git', 'diff', '--cached', '--name-only'], allowFailure: true);

        if ($stagedFiles === '') {
            return implode(PHP_EOL, [
                'Git Commit:',
                'Status: No changes to commit.',
                'Commit Message: '.$commitMessage,
            ]);
        }

        $output = $this->runProcess(['git', 'commit', '-m', $commitMessage]);

        return implode(PHP_EOL, [
            'Git Commit:',
            'Status: Commit created.',
            'Commit Message: '.$commitMessage,
            'Files:',
            $this->indentLines($stagedFiles),
            'Output: '.$output,
        ]);
    }

    protected function toolPushGitBranch(array $input): string
    {
        $branchName = trim((string) ($input['branch_name'] ?? ''));
        $remote = trim((string) ($input['remote'] ?? 'origin'));
        $branchName = $branchName !== '' ? $branchName : $this->currentGitBranch();

        if ($branchName === '') {
            throw new RuntimeException('Unable to determine which branch to push.');
        }

        $output = $this->runProcess(['git', 'push', '-u', $remote, $branchName]);

        return implode(PHP_EOL, [
            'Git Push:',
            'Status: Branch pushed.',
            'Remote: '.$remote,
            'Branch: '.$branchName,
            'Output: '.$output,
        ]);
    }

    protected function toolCreateGithubPullRequest(array $input): string
    {
        ['token' => $token, 'owner' => $owner, 'repository' => $repository, 'base_branch' => $configuredBaseBranch] = $this->githubConfig();

        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $headBranch = trim((string) ($input['head_branch'] ?? ''));
        $baseBranch = trim((string) ($input['base_branch'] ?? ''));
        $draft = (bool) ($input['draft'] ?? false);

        if ($title === '' || $body === '') {
            throw new RuntimeException('create_github_pull_request requires non-empty title and body.');
        }

        if ($token === '') {
            throw new RuntimeException('Missing GITHUB_TOKEN configuration. API pull request creation cannot continue.');
        }

        if ($owner === '' || $repository === '') {
            throw new RuntimeException('Missing GITHUB_OWNER or GITHUB_REPOSITORY configuration.');
        }

        $headBranch = $headBranch !== '' ? $headBranch : $this->currentGitBranch();
        $baseBranch = $baseBranch !== '' ? $baseBranch : $configuredBaseBranch;

        if ($headBranch === '' || $baseBranch === '') {
            throw new RuntimeException('Unable to determine head or base branch for the pull request.');
        }

        if ($headBranch === $baseBranch) {
            throw new RuntimeException('Head branch matches base branch. Create and push a feature branch before opening a pull request.');
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->post("https://api.github.com/repos/{$owner}/{$repository}/pulls", [
                'title' => $title,
                'body' => $body,
                'head' => $headBranch,
                'base' => $baseBranch,
                'draft' => $draft,
            ]);

        if (! $response->successful()) {
            $message = data_get($response->json(), 'message')
                ?? $response->body()
                ?? 'Unknown GitHub API error.';

            throw new RuntimeException('GitHub pull request creation failed: '.$message);
        }

        return implode(PHP_EOL, [
            'GitHub Pull Request:',
            'Status: Created.',
            'Title: '.$title,
            'Head: '.$headBranch,
            'Base: '.$baseBranch,
            'URL: '.(string) data_get($response->json(), 'html_url', ''),
            'Number: '.(string) data_get($response->json(), 'number', ''),
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

    protected function githubConfig(): array
    {
        return [
            'token' => (string) config('services.github.token', ''),
            'owner' => (string) config('services.github.owner', ''),
            'repository' => (string) config('services.github.repository', ''),
            'base_branch' => (string) config('services.github.base_branch', 'main'),
            'use_gh_cli' => (bool) config('services.github.use_gh_cli', false),
        ];
    }

    protected function currentGitBranch(): string
    {
        return $this->runProcess(['git', 'branch', '--show-current']);
    }

    protected function repositoryHasChanges(): bool
    {
        return $this->runProcess(['git', 'status', '--porcelain'], allowFailure: true) !== '';
    }

    protected function runProcess(array $command, bool $allowFailure = false): string
    {
        $result = Process::path(base_path())
            ->timeout(120)
            ->run($command);

        $output = trim($result->output() !== '' ? $result->output() : $result->errorOutput());

        if (! $allowFailure && $result->failed()) {
            throw new RuntimeException(sprintf(
                'Command failed [%s]: %s',
                implode(' ', $command),
                $output !== '' ? $output : 'Unknown process error.'
            ));
        }

        return $output;
    }

    protected function indentLines(string $value): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $line): string => '  '.$line,
            preg_split('/\r\n|\r|\n/', trim($value)) ?: []
        ));
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
