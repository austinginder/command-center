# Command Center CLI

A CLI for searching Claude Code conversation history. Powered by a SQLite FTS5 full-text index across all your sessions.

## Installation

Symlink the `command-center` script (in the repo root) somewhere on your `$PATH`:

```bash
ln -s "$(pwd)/command-center" ~/.local/bin/command-center
```

Then run from anywhere:

```
command-center "your query"
```

## Quick Start

```bash
# Search all conversations
command-center "error handling"

# Filter to a specific project
command-center "malware" -p captaincore-manager

# Get JSON for scripting
command-center "security-audit.html" --json

# Check index health
command-center status
```

## Commands

### search (default)

Search is the default - no subcommand needed.

```bash
# These are equivalent
command-center "deploy script"
command-center search "deploy script"

# No quotes needed for multi-word queries
command-center deploy script

# Filter by project (basename or full path)
command-center "migration" -p anchor-host
command-center "migration" --project ~/Cove/Sites/my-app.localhost

# Limit results
command-center "webhook" -n 5

# Skip index update for faster results
command-center "webhook" --no-update

# JSON output for piping
command-center "security-audit.html" --json | jq '.[].snippet'
command-center "backup" --json | jq 'length'
```

### reindex

Rebuild the full-text search index from scratch.

```bash
command-center reindex
# Indexed 718 sessions in 4523ms
# DB size: 28.3 MB
```

### status

Check index health.

```bash
command-center status
# Index status
#   Sessions indexed: 718
#   Database size:    28.3 MB
#   Last indexed:     10s ago
```

### sessions

List all sessions.

```bash
command-center sessions
command-center sessions -p captaincore-manager
command-center sessions --json
```

### projects

List all projects with session counts.

```bash
command-center projects
command-center projects --json
```

### open

Open a session in the Command Center web UI.

```bash
command-center open abc123-def4-5678-abcd-ef1234567890
```

### flow

Reconstruct **how a project was built** from its session transcripts. The code the
agent wrote is disposable; the sequence of human instructions that steered it is the
durable, learnable artifact. Reads local `.jsonl` files only - nothing is sent anywhere.

```bash
# Cliffnotes - arc, steering profile, course-corrections, model + token cost
command-center flow dismissed

# Forensic play-by-play (every user turn + a one-line agent trace per turn)
command-center flow dismissed --full

# Verbatim user turns only
command-center flow dismissed --forensic

# Deep-dive a single session (full or partial id)
command-center flow --session 45b81ec3

# Structured payload - the "browse the build" ingest format
command-center flow dismissed --json

# Editor brief - one bundle an agent uses to WRITE the recap
command-center flow dismissed --editor --images-dir /tmp/landmarks
```

**`--editor`** emits a single JSON brief with everything an agent needs to author a build
recap in one pass - it does NOT write prose itself. Contents: `identity` (suggested slug,
span, model), `manifest` (sessions/headings/landmarks/tokens…), `arc` (the wake), `spine`
(first heading per session), `tacks` (course-corrections), `genesis` (the first session's
interleaved flow, with **verbatim** headings to quote), and `landmarks` (the genesis
session's screenshots decoded to `--images-dir` with paired text, for the agent to caption).
This is the input to `/rutter-sync`.

The cliffnotes surface the highest-signal learning material:
- **The spine** - the first instruction of every session, which reads as the project's story.
- **Course-corrections** - where the human redirected the agent (the part worth studying).
- **Steering profile** - slash-command vs ad-hoc openers, screenshots handed over, links referenced, interrupts.
- **Cost** - model(s) used, output tokens generated, fresh vs cached input tokens.

The `--json` output is designed as an ingest format for a public "browse how it was built"
site (GitHub-for-AI-builds): project → span → totals → models → tokens → steering → spine →
corrections → per-session breakdown.

### version

Show the installed version (read from `manifest.json`).

```bash
command-center version
command-center -v
command-center version --json   # full manifest
```

### update

Check for and install the latest release. Compares the local `manifest.json` against
the latest on GitHub.

```bash
command-center update --check   # report only, install nothing
command-center update           # update in place
```

Git installs are updated with `git pull --ff-only` - if the working tree has local
changes, the update refuses and leaves everything alone. Non-git installs download
the release archive and copy it over the install. `data/` (your search index) is
never touched either way.

## Options

| Flag | Short | Description |
|------|-------|-------------|
| `--session <id>` | `-s` | Flow: deep-dive one session (full or partial id) |
| `--full` | | Flow: forensic play-by-play |
| `--forensic` | | Flow: verbatim user turns only |
| `--project` | `-p` | Filter by project name or path |
| `--limit` | `-n` | Max results (default: 20) |
| `--json` | `-j` | JSON output |
| `--no-color` | | Disable ANSI colors |
| `--no-update` | | Skip incremental index update |
| `--check` | | Update: check only, do not install |
| `--version` | `-v` | Show installed version |
| `--help` | `-h` | Show help |

## Exit Codes

| Code | Meaning |
|------|---------|
| 0 | Results found / command succeeded |
| 1 | No results found |
| 2 | Usage error |
| 3 | Runtime error |

## Workflow Examples

### Find a past conversation and resume it

```bash
# Search for the conversation
command-center "rate limiting" -n 5

# Get the session ID from JSON output
SESSION=$(command-center "rate limiting" --json | jq -r '.[0].id')

# Open it in the browser
command-center open $SESSION
```

### Check if a topic was discussed in a project

```bash
if command-center "database migration" -p my-project > /dev/null 2>&1; then
    echo "Found prior discussion"
else
    echo "No prior discussion found"
fi
```

### Export search results

```bash
# Save results to a file
command-center "malware cleanup" --json > malware-sessions.json

# Count results per project
command-center "security" --json -n 50 | jq 'group_by(.projectName) | map({project: .[0].projectName, count: length})'
```

### Pipe into other tools

```bash
# Search within search results
command-center "plugin update" --no-color | grep "captaincore"

# Get just session IDs
command-center "backup restore" --json | jq -r '.[].id'

# Find most recent match
command-center "deploy" --json | jq -r '.[0].display'
```
