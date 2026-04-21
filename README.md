# Team Leader Agent for Laravel

This project now supports Gemini-powered agent roles for planning and delivery handoff:

- `team_leader`
- `backend`
- `github_pr`

## Endpoints

- `POST /api/agent-team/run`
- `POST /api/agent-team/agent`
- `php artisan agent:run`

`/run` uses the team leader automatically.

`/agent` accepts:

```json
{
  "role": "team_leader",
  "message": "Plan a user authentication feature for Laravel."
}
```

```json
{
  "role": "backend",
  "message": "Design the Laravel API, database changes, and test plan for user authentication."
}
```

```json
{
  "role": "github_pr",
  "message": "Backend work is complete. Prepare the branch name, commit plan, push commands, PR title, and PR description."
}
```

```json
{
  "role": "github_pr",
  "message": "Backend work is complete. Create a feature branch if needed, commit the current changes, push them to origin, and open the GitHub pull request now."
}
```

Aliases:

- `team_lead` -> `team_leader`
- `github` -> `github_pr`
- `pr_creator` -> `github_pr`

## Configuration

Set these values in `.env`:

```env
GEMINI_API_KEY=your_google_gemini_api_key
GEMINI_MODEL=gemini-2.5-flash
GEMINI_TIMEOUT=120
GEMINI_MAX_TURNS=10
GEMINI_RETRIES=3
GEMINI_RETRY_SLEEP_MS=2000

GITHUB_TOKEN=your_github_personal_access_token
GITHUB_OWNER=your_github_username_or_org
GITHUB_REPOSITORY=your_repository_name
GITHUB_BASE_BRANCH=main
GITHUB_USE_GH_CLI=false
```

If you prefer GitHub CLI authentication instead of a token:

```env
GITHUB_USE_GH_CLI=true
```

## Usage

Open the dashboard in a browser after starting Laravel:

```bash
php artisan serve
```

Then visit:

```text
http://127.0.0.1:8000
```

The dashboard lets you:

- run `team_leader`
- run `backend`
- run `github_pr`
- run both roles side by side
- compare output in real time on one screen

For real pull request creation, the `github_pr` role now performs:

- git status inspection
- branch creation
- commit creation
- `git push -u origin <branch>`
- GitHub API pull request creation

Real PR creation requires:

- a valid git repository with an `origin` remote
- working local git push authentication
- `GITHUB_TOKEN`, `GITHUB_OWNER`, and `GITHUB_REPOSITORY` set in `.env`

Run the default feature prompt:

```bash
php artisan agent:run
```

Run a custom feature prompt:

```bash
php artisan agent:run --feature="Build a user authentication system with JWT and refresh tokens"
```

HTTP example:

```http
POST /api/agent-team/run
Content-Type: application/json

{
  "feature": "Build a user authentication system with JWT and refresh tokens"
}
```
