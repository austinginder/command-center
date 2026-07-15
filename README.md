# Command Center

A local dashboard + CLI for browsing and searching your AI coding sessions - every conversation, across every tool, in one place.

If you bounce between AI coding CLIs, your conversation history ends up scattered across half a dozen dot-directories. Command Center indexes all of them into a single searchable timeline: skim what you worked on each day, deep-search full conversation content, replay any session, and copy a ready-to-run resume command.

**Your index stays on your machine.** Command Center reads local transcript files and builds its search index in a local SQLite database. For current Amp threads, it uses the installed `amp` CLI to list and download your own thread exports on demand. Command Center does not upload conversation data anywhere.

## Supported tools

| Tool | Reads from | Token usage |
|---|---|---|
| Amp | Local snapshots + `amp threads` | ✅ |
| Claude Code | `~/.claude` | ✅ |
| OpenCode | `$XDG_DATA_HOME/opencode` | ✅ |
| Kimi CLI | `~/.kimi` | ✅ |
| Command Code | `~/.commandcode` | estimated |
| T3 Code | `~/.t3` | estimated |
| Antigravity | `~/.gemini/antigravity-cli/brain` | - |
| Gemini CLI | `~/.gemini/tmp` | ✅ |
| Grok CLI | `~/.grok` | estimated |
| Codex | `~/.codex` (ChatGPT / VS Code Codex) | ✅ |

Tools you don't use are skipped automatically. Each location can be overridden with an env var (`AMP_HOME`, `CLAUDE_HOME`, `OPENCODE_HOME`, `KIMI_HOME`, `COMMANDCODE_HOME`, `T3CODE_HOME`, `ANTIGRAVITY_HOME`, `GEMINI_HOME`, `GROK_HOME`, `CODEX_HOME`).

## Features

- **Session index** - every session from every tool in one reverse-chronological list, grouped by day, filterable by tool and project. Nested subagents expand under their parent where the tool records them.
- **Deep search** - SQLite FTS5 full-text search across conversation content, with snippets and highlighting. The index updates incrementally; only changed sessions are re-read. Status shows listed / indexed / skipped / stale coverage.
- **Session viewer** - replay any conversation: user messages, assistant responses, collapsible tool-call groups, and turn summaries.
- **Resume commands** - one click copies the exact CLI command to resume a session in its original tool and project directory (OpenCode, Kimi, Claude, Grok, and others; T3 opens the desktop app).
- **Activity heatmap + usage** - GitHub-style sessions-per-day graph with token tooltips; monthly usage page for input / output / cache across providers.
- **Token tracking** - recorded usage where tools expose it; estimated usage for Command Code, T3 Code, and Grok.
- **Retention monitor** - optional per-provider TTL warnings when agent tools auto-delete old transcripts.
- **Model + live chips** - model labels on list rows when available; Grok shows a live badge for sessions still running.
- **`command-center` CLI** - search and inspect your history from the terminal, including `flow`: reconstruct how a project was built from its transcripts.

## Install

Command Center is a plain PHP app (PHP 8.1+ with the `sqlite3` extension - no database server, no build step). It needs a local web server that routes requests through `index.php`.

### Recommended: Cove

[Cove](https://cove.run) is a zero-config local dev environment for macOS (Caddy + FrankenPHP). With Cove installed:

```bash
cove add command-center --plain
cd "$(cove path command-center)"
rm index.php   # Cove's placeholder landing page
git clone https://github.com/austinginder/command-center.git .
```

Then open **https://command-center.localhost** - sessions appear immediately, and the search index builds on first deep search (or click the refresh button next to the project filter).

### CLI

Symlink the bundled script onto your `$PATH` (from the site directory):

```bash
ln -s "$(pwd)/command-center" ~/.local/bin/command-center
```

```bash
command-center "error handling"          # search everything
command-center "webhook" -p my-project   # scope to a project
command-center sessions                  # list sessions
command-center flow my-project           # how a project was built
command-center status                    # index health
```

Full CLI reference: [command-center-cli.md](command-center-cli.md).

### Updating

```bash
command-center version          # installed version
command-center update --check   # is a newer release available?
command-center update           # update in place
```

`update` compares the local `manifest.json` against the latest release manifest on GitHub. Git installs are updated with a fast-forward pull (it refuses if the working tree has local changes). Non-git installs download the release archive and copy it over the install, leaving `data/` untouched.

## API

Everything the UI does is available as JSON under `/api`:

```
GET  /api/sessions                     list sessions (?source=, ?project=)
GET  /api/sessions/projects            list projects
GET  /api/sessions/sources             available providers
GET  /api/sessions/search?q=           deep search (?source=, ?project=)
GET  /api/sessions/search/status       index health (listed / indexed / skipped / stale)
GET  /api/sessions/stats/daily         per-day counts + token totals
GET  /api/sessions/stats/monthly       per-month token totals by source
GET  /api/sessions/{id}                session meta (incl. nested subagents)
GET  /api/sessions/{id}/conversation   paginated events (default: latest 200; ?limit=&offset=)
POST /api/sessions/search/reindex      rebuild the index
POST /api/sessions/tokens/backfill     backfill token usage for indexed sessions
GET  /api/retention                    retention report (?prefer= preferences)
GET  /stream?session={id}              SSE replay of a session
```

## Privacy

Your conversation history is sensitive. Command Center is designed to be run **locally only**:

- All transcript reads and the FTS index live on your machine (`data/` - gitignored).
- No analytics, no phone-home, no external requests from the backend. (The UI shell loads Tailwind, marked.js, and fonts from CDNs. `command-center update` contacts GitHub, but only when you run it.)
- Don't host it on a public server unless you put authentication in front of it - the app itself has none.

## Adding a provider

Each tool is one self-contained class in `app/` implementing the provider contract documented in [`app/SessionRegistry.php`](app/SessionRegistry.php) - list sessions, extract searchable text, fingerprint for incremental indexing, stream a conversation, and (optionally) report token usage. Register it in `SessionRegistry::providers()` and `index.php`, and the UI, search, CLI, and heatmap pick it up automatically.

## License

[MIT](LICENSE)
