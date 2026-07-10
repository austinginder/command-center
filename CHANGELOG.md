# Changelog

All notable changes to Command Center are documented here.

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
