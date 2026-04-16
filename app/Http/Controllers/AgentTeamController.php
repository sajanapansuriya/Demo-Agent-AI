<?php

namespace App\Http\Controllers;

use App\Exceptions\GeminiApiException;
use App\Services\AgentTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AgentTeamController extends Controller
{
    public function __construct(protected AgentTeamService $agentTeam) {}

    public function dashboard(): View
    {
        return view('welcome');
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feature' => 'required|string|min:10|max:5000',
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->agentTeam->runTeam($validated['feature']),
            ]);
        } catch (GeminiApiException $e) {
            return response()->json(array_filter([
                'success' => false,
                'error' => $e->getMessage(),
                'retry_after_seconds' => $e->retryAfterSeconds(),
            ], static fn (mixed $value): bool => $value !== null), $e->status());
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function runAgent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|string|in:backend,github,github_pr,pr_creator,team_lead,team_leader',
            'message' => 'required|string|min:5|max:5000',
        ]);

        $normalizedRole = $this->agentTeam->normalizeRole($validated['role']);

        try {
            return response()->json([
                'success' => true,
                'role' => $normalizedRole,
                'output' => $this->agentTeam->runAgent($validated['role'], $validated['message']),
            ]);
        } catch (GeminiApiException $e) {
            return response()->json(array_filter([
                'success' => false,
                'error' => $e->getMessage(),
                'retry_after_seconds' => $e->retryAfterSeconds(),
            ], static fn (mixed $value): bool => $value !== null), $e->status());
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function ajaxRun(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => 'required|string|in:team_leader,backend,github_pr,both',
            'message' => 'required|string|min:5|max:5000',
        ]);

        try {
            if ($validated['mode'] === 'both') {
                return response()->json([
                    'success' => true,
                    'mode' => 'both',
                    'results' => [
                        'team_leader' => $this->agentTeam->runAgent('team_leader', $validated['message']),
                        'backend' => $this->agentTeam->runAgent('backend', $validated['message']),
                    ],
                ]);
            }

            $normalizedRole = $this->agentTeam->normalizeRole($validated['mode']);

            return response()->json([
                'success' => true,
                'mode' => $validated['mode'],
                'role' => $normalizedRole,
                'output' => $this->agentTeam->runAgent($validated['mode'], $validated['message']),
            ]);
        } catch (GeminiApiException $e) {
            return response()->json(array_filter([
                'success' => false,
                'error' => $e->getMessage(),
                'retry_after_seconds' => $e->retryAfterSeconds(),
            ], static fn (mixed $value): bool => $value !== null), $e->status());
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
