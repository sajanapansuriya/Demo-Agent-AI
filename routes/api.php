<?php

use App\Http\Controllers\AgentTeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('agent-team')->group(function (): void {
    Route::post('/run', [AgentTeamController::class, 'run']);
    Route::post('/agent', [AgentTeamController::class, 'runAgent']);
});
