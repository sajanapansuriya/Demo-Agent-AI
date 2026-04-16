<?php

use App\Http\Controllers\AgentTeamController;
use Illuminate\Support\Facades\Route;

Route::get('/', [AgentTeamController::class, 'dashboard']);
Route::post('/agent-dashboard/run', [AgentTeamController::class, 'ajaxRun'])->name('agent-dashboard.run');
