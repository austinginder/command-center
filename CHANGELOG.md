# Changelog

All notable changes to Command Center are documented here.

## [1.5.0] - 2026-07-14

### Added

- **Gemini CLI provider** - indexes `~/.gemini/tmp` chats with project path resolution, token usage where available, and retention TTL detection.
- **Session retention monitor** - optional per-provider TTL policies with at-risk / expired labels on the dashboard (collapsed by default). Auto-delete remains opt-in and off by default for new providers.
- **Nested subagents** - Grok, OpenCode, and Claude Code parents expand to show child agent sessions in the list and session viewer (Claude subagent counts load lazily for fast dashboard list times).
- **Estimated token usage** for Command Code, T3 Code, and Grok (char-based estimates; heatmap / usage views mark them as estimated).
- **Model chips** on session rows where cheap to read (Claude, OpenCode, Grok, Amp, T3 provider agent).
- **Grok live badge** - green live chip when `~/.grok/active_sessions.json` lists a session with a still-running PID.
- **Index coverage stats** - status and reindex report listed / indexed / skipped (no fingerprint) / stale counts in the UI and CLI.
- Resume copy recipes for OpenCode (`--session`) and Kimi (`--session`); T3 opens the desktop app with the thread title (no external deep-link yet).

### Improved

- Claude session titles: prefer first good history prompt and transcript summary over last slash-command / stub reply.
- Claude project session counts: unique `sessionId`s, not every `history.jsonl` line.
- Shared `Helpers::projectDisplayName()` so `…/public` workspaces show the site name across providers.
- Command Code path decode restores real filesystem casing after lowercased encode.
- Dashboard list performance: Claude subagent children load on expand instead of scanning every agent file on list.
- Grok: subagent filtering, signals-based usage, and recap FTS improvements.

### Fixed

- `/api/sessions/{id}` no longer shadows `/sessions/projects` and `/sessions/sources`.
- T3 Code resume no longer pretends `t3code://` deep-links into a thread (desktop content scheme + pairing gate).
- Usage charts render at natural scale; local SPA assets cache-bust so updates reach browsers.

## [1.4.0] - 2026-07-11

### Added

- **Usage page** (`/usage`, linked from the nav): monthly token breakdown across providers. KPI tiles for output, fresh input, cache reads, and sessions; a per-month column chart of fresh input vs output; a separate cache-reads chart (they run ~20x larger and would flatten everything else on a shared axis); and a full per-month table of input, cache writes, cache reads, and output. Filterable by provider.
- `GET /api/sessions/stats/monthly` - per-month token totals broken out by source.
- Token formatting gains a billions tier (`59.2B` instead of `59187.2M`).

### Fixed

- **Claude Code token totals now include subagent transcripts.** Usage logged by Agent tool and workflow subagents to `<session-id>/subagents/` files was invisible to the index - roughly 40% of output tokens on agent-heavy months. Usage now sums across the main transcript plus all nested subagent transcripts (deduped by message id), and the session fingerprint folds subagent files in so subagent-only activity triggers re-extraction on the next reindex.

## [1.3.0] - 2026-07-10

### Added

- **OpenCode SQLite backend.** OpenCode migrated its persistence from per-entity JSON files (`storage/`) to a SQLite database (`opencode.db`); sessions created after that migration were invisible to Command Center. The provider now reads the database when it exists (sessions, messages, parts, projects, token usage) and unions in any legacy file-tree sessions the migration missed. Installs that never migrated fall back to the file tree unchanged.

### Fixed

- OpenCode model detection: `providerID`/`modelID` live at the top level of message records, not under a `model` key, so every OpenCode session replay reported its model as "opencode". Real models (e.g. `kimi-for-coding k2p6`) now show for both database and legacy sessions.

## [1.2.0] - 2026-07-10

### Added

- **`command-center version`** (and `-v`/`--version`) - show the installed version, read from the new `manifest.json` at the repo root. `--json` prints the full manifest.
- **`command-center update`** - self-update to the latest release. Fetches the release manifest from GitHub and compares versions; git installs update via `git pull --ff-only` (refusing if the working tree has local changes), non-git installs download the release archive and copy it over the install. `data/` is never touched. `--check` reports whether an update is available without installing.
- `manifest.json` - release metadata (version, download URL, PHP requirement) committed with each release, following the update-check pattern used by Disembark.

## [1.1.0] - 2026-07-10

### Changed

- **Dark mode redesigned** to match the commandcenter.run landing page: deep green-black terminal palette, dot-grid backdrop, brighter heatmap ramp, and JetBrains Mono chrome. Light mode is unchanged.
- Body typeface switched from Inter to the system sans stack (SF Pro on macOS).

## [1.0.0] - 2026-07-10

Initial public release.

### Added

- **Unified session index** across seven AI coding tools: Claude Code, Command Code, T3 Code, OpenCode, Kimi CLI, Antigravity, and Grok CLI - one dashboard for every conversation on your machine.
- **Deep search** powered by a SQLite FTS5 full-text index over conversation content, with per-source and per-project filtering, snippets, and match highlighting.
- **Session viewer** that replays any conversation - user messages, assistant text, collapsible tool-call groups, and turn summaries - with one-click copy of the CLI resume command.
- **Activity heatmap** (GitHub contribution-graph style) showing sessions per day for the past year, with hover tooltips including per-day token usage. Click a day to filter the session list to it.
- **Token usage tracking** for providers that record it (Claude Code, OpenCode, Kimi), stored in the search index and backfillable for existing sessions via `POST /api/sessions/tokens/backfill`.
- **`command-center` CLI** - search, list sessions/projects, reindex, open a session in the browser, and `flow`: reconstruct how a project was built from its session transcripts (cliffnotes, forensic play-by-play, or structured JSON).
- JSON API: sessions, projects, sources, deep search, daily stats, conversation fetch, and an SSE stream endpoint.
- Dark mode, keyboard-friendly search, URL-persisted filters.
