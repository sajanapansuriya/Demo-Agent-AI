<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'AI Agent Gemini') }}</title>
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=space-grotesk:400,500,700|instrument-sans:400,500,600" rel="stylesheet" />
        <link rel="stylesheet" href="{{ asset('css/agent-dashboard.css') }}">
    </head>
    <body class="agent-dashboard-page">
        <main class="agent-dashboard" data-agent-dashboard data-run-url="{{ route('agent-dashboard.run') }}">
            <section class="hero-panel">
                <div class="hero-copy">
                    <p class="eyebrow">Laravel Agent Control Room</p>
                    <h1>Run team leader and backend roles from one dashboard.</h1>
                    <p class="hero-text">
                        Write one feature request, send it to either role, or run both at the same time and compare the output live.
                    </p>
                </div>

                <div class="hero-metrics">
                    <div class="metric-card">
                        <span class="metric-label">Available Roles</span>
                        <strong>3</strong>
                        <span class="metric-note">`team_leader`, `backend`, `github_pr`</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Mode</span>
                        <strong>Realtime</strong>
                        <span class="metric-note">Browser to Laravel API</span>
                    </div>
                </div>
            </section>

            <section class="control-panel">
                <label class="input-label" for="agent-message">Feature Request</label>
                <textarea id="agent-message" class="prompt-input" data-agent-input rows="8" placeholder="Describe the feature, constraints, API expectations, validation rules, and rollout concerns.">Build an order management module with CRUD APIs, role-based authorization, audit logs, background notifications, and feature tests.</textarea>

                <div class="action-row">
                    <button type="button" class="action-button primary" data-run-role="team_leader">Run Team Leader</button>
                    <button type="button" class="action-button secondary" data-run-role="backend">Run Backend</button>
                    <button type="button" class="action-button secondary" data-run-role="github_pr">Run GitHub PR</button>
                    <button type="button" class="action-button ghost" data-run-both>Run Both</button>
                </div>

                <p class="status-banner" data-global-status>Ready.</p>
            </section>

            <section class="results-grid">
                <article class="agent-card" data-agent-card="team_leader">
                    <header class="agent-card-header">
                        <div>
                            <p class="agent-kicker">Strategy</p>
                            <h2>Team Leader</h2>
                        </div>
                        <span class="agent-badge">team_leader</span>
                    </header>
                    <p class="agent-description">Planning scope, delivery structure, risks, testing, and cross-team execution notes.</p>
                    <p class="agent-state" data-agent-status="team_leader">Idle</p>
                    <pre class="agent-output" data-agent-output="team_leader">No output yet.</pre>
                </article>

                <article class="agent-card backend-card" data-agent-card="backend">
                    <header class="agent-card-header">
                        <div>
                            <p class="agent-kicker">Implementation</p>
                            <h2>Backend</h2>
                        </div>
                        <span class="agent-badge">backend</span>
                    </header>
                    <p class="agent-description">Laravel APIs, migrations, services, policies, queues, validation, and backend tests.</p>
                    <p class="agent-state" data-agent-status="backend">Idle</p>
                    <pre class="agent-output" data-agent-output="backend">No output yet.</pre>
                </article>

                <article class="agent-card github-card" data-agent-card="github_pr">
                    <header class="agent-card-header">
                        <div>
                            <p class="agent-kicker">Release</p>
                            <h2>GitHub PR</h2>
                        </div>
                        <span class="agent-badge">github_pr</span>
                    </header>
                    <p class="agent-description">Branch naming, commit messages, push commands, PR title, PR body, and final pre-push checks.</p>
                    <p class="agent-state" data-agent-status="github_pr">Idle</p>
                    <pre class="agent-output" data-agent-output="github_pr">No output yet.</pre>
                </article>
            </section>
        </main>
        <script src="{{ asset('js/agent-dashboard.js') }}"></script>
    </body>
</html>
