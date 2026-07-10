# Command Center

A local dashboard + CLI for browsing and searching your AI coding sessions — every conversation, across every tool, in one place.

If you bounce between AI coding CLIs, your conversation history ends up scattered across half a dozen dot-directories. Command Center indexes all of them into a single searchable timeline: skim what you worked on each day, deep-search full conversation content, replay any session, and copy a ready-to-run resume command.

**Everything stays on your machine.** Command Center only reads local transcript files and builds its search index in a local SQLite database. Nothing is uploaded anywhere.

## Supported tools

| Tool | Reads from | Token usage |
|---|---|---|
| Claude Code | `~/.claude` | ✅ |
| OpenCode | `$XDG_DATA_HOME/opencode` | ✅ |
| Kimi CLI | `~/.kimi` | ✅ |
| Command Code | `~/.commandcode` | — |
| T3 Code | `~/.t3` | — |
| Antigravity | `~/.gemini/antigravity-cli/brain` | — |
| Grok CLI | `~/.grok` | — |

Tools you don't use are skipped automatically. Each location can be overridden with an env var (`CLAUDE_HOME`, `OPENCODE_HOME`, `KIMI_HOME`, `COMMANDCODE_HOME`, `T3CODE_HOME`, `ANTIGRAVITY_HOME`, `GROK_HOME`).

## Features

- **Session index** — every session from every tool in one reverse-chronological list, grouped by day, filterable by tool and project.
- **Deep search** — SQLite FTS5 full-text search across conversation content, with snippets and highlighting. The index updates incrementally; only changed sessions are re-read.
- **Session viewer** — replay any conversation: user messages, assistant responses, collapsible tool-call groups, and turn summaries.
- **Resume commands** — one click copies the exact CLI command to resume a session in its original tool and project directory.
- **Activity heatmap** — a GitHub-style contribution graph of sessions per day, with per-day token usage on hover.
- **Token tracking** — input/output/cache token totals per session, for tools that record usage.
- **`command-center` CLI** — search and inspect your history from the terminal, including `flow`: reconstruct how a project was built from its transcripts.

## Install

Command Center is a plain PHP app (PHP 8.1+ with the `sqlite3` extension — no database server, no build step). It needs a local web server that routes requests through `index.php`.

### Recommended: Cove

[Cove](https://cove.run) is a zero-config local dev environment for macOS (Caddy + FrankenPHP). With Cove installed:

```bash
cove add command-center --plain
site="$(cove path command-center)"
rm -rf "$site"
git clone https://github.com/austinginder/command-center.git "$site"
```

Then open **https://command-center.localhost** — sessions appear immediately, and the search index builds on first deep search (or click the refresh button next to the project filter).

### CLI

Symlink the bundled script onto your `$PATH`:

```bash
ln -s "$(cove path command-center)/command-center" ~/.local/bin/command-center
```

```bash
command-center "error handling"          # search everything
command-center "webhook" -p my-project   # scope to a project
command-center sessions                  # list sessions
command-center flow my-project           # how a project was built
command-center status                    # index health
```

Full CLI reference: [command-center-cli.md](command-center-cli.md).

## API

Everything the UI does is available as JSON under `/api`:

```
GET  /api/sessions                     list sessions (?source=, ?project=)
GET  /api/sessions/projects            list projects
GET  /api/sessions/sources             available providers
GET  /api/sessions/search?q=           deep search (?source=, ?project=)
GET  /api/sessions/stats/daily         per-day counts + token totals
GET  /api/sessions/{id}/conversation   full parsed conversation
POST /api/sessions/search/reindex      rebuild the index
POST /api/sessions/tokens/backfill     backfill token usage for indexed sessions
GET  /stream?session={id}              SSE replay of a session
```

## Privacy

Your conversation history is sensitive. Command Center is designed to be run **locally only**:

- All transcript reads and the FTS index live on your machine (`data/` — gitignored).
- No analytics, no phone-home, no external requests from the backend. (The UI shell loads Tailwind, marked.js, and fonts from CDNs.)
- Don't host it on a public server unless you put authentication in front of it — the app itself has none.

## Adding a provider

Each tool is one self-contained class in `app/` implementing the provider contract documented in [`app/SessionRegistry.php`](app/SessionRegistry.php) — list sessions, extract searchable text, fingerprint for incremental indexing, stream a conversation, and (optionally) report token usage. Register it in `SessionRegistry::providers()` and `index.php`, and the UI, search, CLI, and heatmap pick it up automatically.

## License

[MIT](LICENSE)
