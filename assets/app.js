// ─── State ───────────────────────────────────────────────────
const state = {
    sessionEs: null, // EventSource for the session viewer
};

// ─── Router ──────────────────────────────────────────────────
const routes = [
    { pattern: /^\/$/, view: renderDashboard },
    { pattern: /^\/usage$/, view: renderUsageView },
    { pattern: /^\/sessions$/, view: () => navigate('/', true) },
    { pattern: /^\/sessions\/([A-Za-z0-9_-]+)$/, view: renderSessionView },
];

function navigate(path, push = true) {
    if (push) history.pushState(null, '', path);
    onViewLeave();

    const app = document.getElementById('app');
    app.innerHTML = '';

    // Strip query string for route matching but keep it accessible.
    const [pathname] = path.split('?');

    for (const route of routes) {
        const m = pathname.match(route.pattern);
        if (m) {
            route.view(...m.slice(1));
            return;
        }
    }

    app.innerHTML = `
        <div class="max-w-md mx-auto mt-10 bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm">
            ${emptyState(illoSatellite(), 'signal lost', 'no route matches ' + esc(pathname))}
            <div class="pb-10 -mt-8 text-center">
                <a href="/" data-route class="text-xs font-mono text-emerald-600 dark:text-emerald-500 hover:underline">↩ return to base</a>
            </div>
        </div>`;
}

// Intercept link clicks.
document.addEventListener('click', e => {
    const a = e.target.closest('a[data-route]');
    if (!a) return;
    e.preventDefault();
    const href = a.getAttribute('href');
    if (href !== location.pathname + location.search) navigate(href);
});

window.addEventListener('popstate', () => navigate(location.pathname + location.search, false));

// ─── Lifecycle ───────────────────────────────────────────────
function onViewLeave() {
    if (state.sessionEs) {
        state.sessionEs.close();
        state.sessionEs = null;
    }
}

// ─── Shared Utilities ────────────────────────────────────────
function esc(str) {
    const el = document.createElement('span');
    el.textContent = str;
    return el.innerHTML;
}

function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function formatTokens(n) {
    if (n >= 1000000000) return (n / 1000000000).toFixed(1) + 'B';
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

function timeAgo(ts) {
    const diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function clockTime(ts) {
    return new Date(ts * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function dayLabel(ts) {
    const d = new Date(ts * 1000);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    if (d.toDateString() === today.toDateString()) return 'Today';
    if (d.toDateString() === yesterday.toDateString()) return 'Yesterday';
    const opts = { weekday: 'short', month: 'short', day: 'numeric' };
    if (d.getFullYear() !== today.getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString([], opts);
}

function localDayKey(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function dayKeyLabel(key) {
    const [y, m, dd] = key.split('-').map(Number);
    const d = new Date(y, m - 1, dd);
    const opts = { weekday: 'short', month: 'short', day: 'numeric' };
    if (y !== new Date().getFullYear()) opts.year = 'numeric';
    return d.toLocaleDateString([], opts);
}

function usageSummary(usage) {
    if (!usage) return '';
    return formatTokens(usage.input) + ' in / ' + formatTokens(usage.output) + ' out';
}

// Display name for a project path. Generic leaf dirs (public/, src/, ...)
// are ambiguous on their own, so include the parent: "anchor.localhost/public".
const GENERIC_DIRS = new Set(['public', 'src', 'app', 'www', 'html', 'dist', 'build', 'site']);
function projectLabel(path) {
    const parts = String(path || '').split('/').filter(Boolean);
    if (!parts.length) return path || '';
    const last = parts[parts.length - 1];
    if (parts.length > 1 && GENERIC_DIRS.has(last.toLowerCase())) {
        return parts[parts.length - 2] + '/' + last;
    }
    return last;
}

// Home-relative form of a path for secondary display lines.
function shortPath(path) {
    return String(path || '').replace(/^\/Users\/[^/]+\//, '~/');
}

// ─── Sources ─────────────────────────────────────────────────
const SOURCE_COLORS = {
	amp:         'bg-lime-500/10 text-lime-700 dark:text-lime-400 border-lime-500/20',
    t3code:      'bg-purple-500/10 text-purple-500 border-purple-500/20',
    opencode:    'bg-blue-500/10 text-blue-500 border-blue-500/20',
    claude:      'bg-emerald-500/10 text-emerald-500 border-emerald-500/20',
    commandcode: 'bg-cyan-500/10 text-cyan-500 border-cyan-500/20',
    antigravity: 'bg-orange-500/10 text-orange-500 border-orange-500/20',
    kimi:        'bg-pink-500/10 text-pink-500 border-pink-500/20',
    grok:        'bg-amber-500/10 text-amber-500 border-amber-500/20',
};

const SOURCE_DOTS = {
	amp:         'bg-lime-500',
    t3code:      'bg-purple-500',
    opencode:    'bg-blue-500',
    claude:      'bg-emerald-500',
    commandcode: 'bg-cyan-500',
    antigravity: 'bg-orange-500',
    kimi:        'bg-pink-500',
    grok:        'bg-amber-500',
};

function sourceBadge(source, label) {
    if (!source) return '';
    const colors = SOURCE_COLORS[source] || SOURCE_COLORS.claude;
    return `<span class="inline-block shrink-0 text-[11px] font-mono font-medium px-1.5 py-0.5 rounded border ${colors}" title="${esc(label || source)}">${esc(source)}</span>`;
}

/** Predicted expiry chip for providers with a known day TTL (e.g. Claude). */
function retentionBadge(s, showBadges) {
    if (!showBadges || s.days_left === undefined || s.days_left === null) return '';
    const left = Number(s.days_left);
    const risk = s.retention_risk || 'ok';
    // Only surface sessions inside the warning window (or worse) on the row.
    if (risk === 'ok') return '';
    let label;
    if (left < 0) label = 'expired';
    else if (left === 0) label = 'expires today';
    else if (left === 1) label = 'expires tomorrow';
    else label = `expires ${left}d`;
    const cls = risk === 'expired' || risk === 'critical'
        ? 'ret-badge ret-badge-crit'
        : 'ret-badge ret-badge-warn';
    const title = s.expires_at
        ? `Predicted expiry ${new Date(s.expires_at * 1000).toLocaleDateString()} (last activity + retention)`
        : 'Predicted expiry from last activity + provider retention';
    return `<span class="${cls}" title="${esc(title)}">${esc(label)}</span>`;
}

function formatRetentionDays(days) {
    if (days === null || days === undefined) return '-';
    if (days >= 3650) return Math.round(days / 365) + 'y';
    if (days >= 365) return (days / 365).toFixed(days % 365 === 0 ? 0 : 1) + 'y';
    return days + 'd';
}

// CLI resume recipes per source. Sources absent here can't be resumed.
const RESUME_BINS = {
	amp:         { bin: 'amp',    flag: '',                               resume: 'threads continue' },
    claude:      { bin: 'claude', flag: '--dangerously-skip-permissions', resume: '--resume' },
    commandcode: { bin: 'cmd',    flag: '--yolo',                         resume: '--resume' },
    antigravity: { bin: 'agy',    flag: '--dangerously-skip-permissions', resume: '--conversation' },
    grok:        { bin: 'grok',   flag: '',                               resume: '--resume' },
};

function resumeCommand(source, project, sessionId) {
    const cfg = RESUME_BINS[source];
    if (!cfg) return null;
    const shellQuote = value => `'${String(value).replace(/'/g, `'"'"'`)}'`;
    const cd = project && project !== '-' ? `cd ${shellQuote(project)} && ` : '';
    return cd + `${cfg.bin} ${cfg.flag ? cfg.flag + ' ' : ''}${cfg.resume} ${shellQuote(sessionId)}`;
}

const ICON_COPY = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>';
const ICON_CHECK = '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';

function copyWithFlash(btn, text, iconSel) {
    navigator.clipboard.writeText(text).then(() => {
        const target = iconSel ? btn.querySelector(iconSel) : btn;
        const orig = target.innerHTML;
        target.innerHTML = ICON_CHECK;
        setTimeout(() => { target.innerHTML = orig; }, 1500);
    });
}

// ─── Illustrations ───────────────────────────────────────────
// Radar scope - sweep + blips (blip delays synced to the 5s sweep).
function illoRadar(cls = 'w-24 h-24') {
    return `<svg class="${cls} mx-auto text-zinc-300 dark:text-cc-line3" viewBox="0 0 96 96" fill="none" aria-hidden="true">
        <circle cx="48" cy="48" r="44" stroke="currentColor" stroke-width="1.5" stroke-dasharray="3 4"/>
        <circle cx="48" cy="48" r="32" stroke="currentColor" stroke-width="1"/>
        <circle cx="48" cy="48" r="19" stroke="currentColor" stroke-width="1" opacity="0.7"/>
        <line x1="48" y1="10" x2="48" y2="86" stroke="currentColor" stroke-width="1" opacity="0.4"/>
        <line x1="10" y1="48" x2="86" y2="48" stroke="currentColor" stroke-width="1" opacity="0.4"/>
        <g class="radar-sweep">
            <path d="M48 48 L48 8 A40 40 0 0 1 73.7 17.4 Z" fill="#10b981" opacity="0.12"/>
            <line x1="48" y1="48" x2="48" y2="8" stroke="#10b981" stroke-width="1.5" opacity="0.7"/>
        </g>
        <circle class="radar-blip" style="animation-delay:.55s" cx="63" cy="30" r="2.5" fill="#10b981"/>
        <circle class="radar-blip" style="animation-delay:3.3s" cx="30" cy="60" r="2" fill="#10b981"/>
        <circle class="radar-blip" style="animation-delay:2.1s" cx="58" cy="66" r="1.8" fill="#10b981"/>
        <circle cx="48" cy="48" r="2" fill="currentColor"/>
    </svg>`;
}

// Drifting satellite with a dropped signal - for 404 / not-found.
function illoSatellite(cls = 'w-28 h-28') {
    return `<svg class="${cls} mx-auto text-zinc-300 dark:text-cc-line3" viewBox="0 0 96 96" fill="none" aria-hidden="true">
        <circle cx="14" cy="20" r="1" fill="currentColor" opacity="0.6"/>
        <circle cx="82" cy="14" r="1.2" fill="currentColor" opacity="0.5"/>
        <circle cx="88" cy="58" r="1" fill="currentColor" opacity="0.6"/>
        <circle cx="10" cy="70" r="1.2" fill="currentColor" opacity="0.4"/>
        <circle cx="70" cy="88" r="1" fill="currentColor" opacity="0.5"/>
        <g transform="rotate(-18 48 54)">
            <rect x="40" y="48" width="16" height="12" rx="2" stroke="currentColor" stroke-width="1.5"/>
            <line x1="40" y1="52" x2="56" y2="52" stroke="currentColor" stroke-width="1" opacity="0.5"/>
            <line x1="32" y1="54" x2="40" y2="54" stroke="currentColor" stroke-width="1.5"/>
            <line x1="56" y1="54" x2="64" y2="54" stroke="currentColor" stroke-width="1.5"/>
            <rect x="18" y="49" width="14" height="10" rx="1" stroke="currentColor" stroke-width="1.5"/>
            <line x1="23" y1="49" x2="23" y2="59" stroke="currentColor" stroke-width="1" opacity="0.5"/>
            <line x1="27" y1="49" x2="27" y2="59" stroke="currentColor" stroke-width="1" opacity="0.5"/>
            <rect x="64" y="49" width="14" height="10" rx="1" stroke="currentColor" stroke-width="1.5"/>
            <line x1="69" y1="49" x2="69" y2="59" stroke="currentColor" stroke-width="1" opacity="0.5"/>
            <line x1="73" y1="49" x2="73" y2="59" stroke="currentColor" stroke-width="1" opacity="0.5"/>
            <line x1="48" y1="48" x2="48" y2="42" stroke="currentColor" stroke-width="1.5"/>
            <path d="M43 42 A5 5 0 0 1 53 42" stroke="currentColor" stroke-width="1.5"/>
        </g>
        <path d="M52 32 A14 14 0 0 1 62 28" stroke="#10b981" stroke-width="1.5" stroke-dasharray="2 3" opacity="0.6"/>
        <path d="M54 24 A22 22 0 0 1 70 18" stroke="#10b981" stroke-width="1.5" stroke-dasharray="2 3" opacity="0.35"/>
        <path d="M74 8 l6 6 M80 8 l-6 6" stroke="#f43f5e" stroke-width="1.5" opacity="0.7"/>
    </svg>`;
}

function emptyState(illo, title, sub) {
    return `<div class="py-14 px-6 text-center">
        ${illo}
        <div class="mt-5 text-xs font-mono font-semibold uppercase tracking-[0.2em] text-zinc-400 dark:text-cc-dim">${title}</div>
        ${sub ? `<div class="mt-1.5 text-xs font-mono text-zinc-400/80 dark:text-cc-dim">${sub}</div>` : ''}
    </div>`;
}

// ─── Index Status (nav) ──────────────────────────────────────
async function loadIndexStatus() {
    try {
        const res = await fetch('/api/sessions/search/status');
        const d = await res.json();
        const el = document.getElementById('index-status');
        if (el && d.indexed) {
            el.textContent = d.indexed + ' indexed · ' + formatBytes(d.db_size_bytes || 0);
        }
    } catch (err) {}
}

// ─── View: Dashboard (session index) ─────────────────────────
const PAGE_SIZE = 150;

function renderDashboard() {
    const app = document.getElementById('app');
    app.innerHTML = `
        <div class="space-y-4">
            <!-- Search -->
            <div class="relative">
                <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="session-search" autocomplete="off" spellcheck="false"
                    placeholder="Search sessions - Enter searches full conversation content"
                    class="w-full h-11 pl-10 pr-28 rounded-xl border border-zinc-200 dark:border-cc-line bg-white dark:bg-cc-card text-sm shadow-sm placeholder:text-zinc-400 dark:placeholder:text-cc-dim text-zinc-900 dark:text-cc-bright">
                <button id="deep-search-btn" disabled
                    class="absolute right-2 top-1/2 -translate-y-1/2 px-2.5 py-1 text-xs font-medium rounded-lg bg-zinc-900 text-zinc-100 dark:bg-cc-ink dark:text-cc-bg disabled:opacity-25 disabled:cursor-not-allowed transition-opacity">
                    Deep search
                </button>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <div id="source-pills" class="flex flex-wrap items-center gap-1.5"></div>
                <div class="ml-auto flex items-center gap-2">
                    <div id="project-combo" class="relative">
                        <input type="text" id="project-filter-input" autocomplete="off" spellcheck="false" placeholder="All projects"
                            class="w-56 rounded-lg border border-zinc-200 dark:border-cc-line bg-white dark:bg-cc-card text-zinc-700 dark:text-cc-soft placeholder:text-zinc-400 dark:placeholder:text-cc-dim px-2.5 py-1.5 text-xs">
                        <button id="project-clear-btn" type="button" title="Clear project filter"
                            class="hidden absolute right-1.5 top-1/2 -translate-y-1/2 p-0.5 rounded text-zinc-400 dark:text-cc-dim hover:text-zinc-600 dark:hover:text-cc-mut">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                        <div id="project-combo-list" class="hidden absolute right-0 z-30 mt-1 w-96 max-h-80 overflow-y-auto rounded-lg border border-zinc-200 dark:border-cc-line3 bg-white dark:bg-cc-card shadow-xl"></div>
                    </div>
                    <button id="reindex-btn" title="Rebuild search index" class="p-1.5 rounded-lg border border-zinc-200 dark:border-cc-line bg-white dark:bg-cc-card text-zinc-400 hover:text-zinc-600 dark:hover:text-cc-soft transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </div>
            </div>

            <!-- Activity heatmap -->
            <div class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm px-4 pt-3 pb-2">
                <div id="heatmap-wrap" class="overflow-x-auto"></div>
                <div class="flex items-center justify-between gap-3 mt-1.5 px-0.5">
                    <span id="heatmap-total" class="text-[11px] font-mono text-zinc-400 dark:text-cc-dim"></span>
                    <span class="flex items-center gap-1.5 text-[11px] font-mono text-zinc-400 dark:text-cc-dim shrink-0">
                        Less
                        <svg width="73" height="11" aria-hidden="true">
                            <rect class="hm-l0" x="0"  width="11" height="11" rx="2"></rect>
                            <rect class="hm-l1" x="15" width="11" height="11" rx="2"></rect>
                            <rect class="hm-l2" x="31" width="11" height="11" rx="2"></rect>
                            <rect class="hm-l3" x="47" width="11" height="11" rx="2"></rect>
                            <rect class="hm-l4" x="62" width="11" height="11" rx="2"></rect>
                        </svg>
                        More
                    </span>
                </div>
            </div>

            <!-- Retention monitor -->
            <div id="retention-strip" class="hidden bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm px-4 py-3">
                <div class="flex items-start justify-between gap-3 mb-2">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-cc-dim">Retention</div>
                        <div id="retention-summary" class="text-[11px] font-mono text-zinc-500 dark:text-cc-mut mt-0.5"></div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" id="retention-expiring-btn" class="hidden text-[11px] font-mono px-2 py-1 rounded-lg border border-amber-500/30 text-amber-700 dark:text-amber-400 hover:bg-amber-500/10 transition-colors">Expiring soon</button>
                        <button type="button" id="retention-config-btn" class="text-[11px] font-mono px-2 py-1 rounded-lg border border-zinc-200 dark:border-cc-line text-zinc-500 dark:text-cc-mut hover:text-zinc-800 dark:hover:text-cc-soft transition-colors">Configure</button>
                    </div>
                </div>
                <div id="retention-rows" class="space-y-1.5"></div>
            </div>

            <!-- Retention prefs modal -->
            <div id="retention-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
                <div id="retention-modal-backdrop" class="absolute inset-0 bg-black/40 dark:bg-black/60"></div>
                <div class="relative w-full max-w-md rounded-xl border border-zinc-200 dark:border-cc-line3 bg-white dark:bg-cc-card shadow-2xl p-5">
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-cc-bright">Retention monitor</h2>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-cc-mut leading-relaxed">
                        Command Center predicts when agent cleanup will delete local transcripts.
                        Warning prefs stay in Command Center only - they do not change agent settings.
                    </p>
                    <label class="mt-4 block text-xs font-medium text-zinc-600 dark:text-cc-soft">
                        Warn when a session has this many days left
                        <input type="number" id="retention-warn-input" min="1" max="3650" class="mt-1.5 w-full rounded-lg border border-zinc-200 dark:border-cc-line bg-white dark:bg-cc-panel text-sm text-zinc-900 dark:text-cc-ink px-3 py-2 font-mono">
                    </label>
                    <label class="mt-3 flex items-center gap-2 text-xs text-zinc-600 dark:text-cc-soft cursor-pointer">
                        <input type="checkbox" id="retention-show-strip" class="rounded border-zinc-300 dark:border-cc-line">
                        Show retention strip on dashboard
                    </label>
                    <label class="mt-2 flex items-center gap-2 text-xs text-zinc-600 dark:text-cc-soft cursor-pointer">
                        <input type="checkbox" id="retention-show-badges" class="rounded border-zinc-300 dark:border-cc-line">
                        Show expiry badges on session rows
                    </label>
                    <div id="retention-modal-error" class="hidden mt-3 text-xs text-red-600 dark:text-red-400"></div>
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" id="retention-modal-cancel" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-zinc-200 dark:border-cc-line text-zinc-600 dark:text-cc-mut hover:bg-zinc-50 dark:hover:bg-cc-panel">Cancel</button>
                        <button type="button" id="retention-modal-save" class="px-3 py-1.5 text-xs font-medium rounded-lg bg-zinc-900 text-white dark:bg-cc-ink dark:text-cc-bg">Save</button>
                    </div>
                </div>
            </div>

            <!-- Sessions -->
            <div id="sessions-list" class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm overflow-hidden">
                ${emptyState(illoRadar('w-16 h-16'), 'scanning', 'loading session archives')}
            </div>
        </div>
    `;

    // ── Panel state ──
    let allSessions = [];
    let sources = [];          // [{id,label}]
    let deepResults = null;    // array | null
    let activeSource = '';
    let shown = PAGE_SIZE;
    let dailyStats = {};       // 'YYYY-MM-DD' -> {sessions, tokens_in, tokens_out, tokens_cache}
    let dayFilter = '';        // 'YYYY-MM-DD' - set by clicking a heatmap cell
    let allProjects = [];      // [{path, name, sessions, latest}]
    let activeProject = '';    // project path filter ('' = all)
    let sessionsLoaded = false; // first /api/sessions fetch has resolved
    let retentionReport = null; // GET /api/retention payload
    let expiringOnly = false;   // filter to at-risk sessions

    const searchInput = document.getElementById('session-search');
    const projectInput = document.getElementById('project-filter-input');

    // Restore state from URL query params.
    const urlParams = new URLSearchParams(location.search);
    const initialQuery = urlParams.get('q') || '';
    const initialDeep = urlParams.get('deep') === '1';
    const initialProject = urlParams.get('project') || '';
    activeSource = urlParams.get('source') || '';
    dayFilter = urlParams.get('day') || '';
    expiringOnly = urlParams.get('expiring') === '1';

    function updateURL() {
        const params = new URLSearchParams();
        const q = searchInput.value.trim();
        if (q) params.set('q', q);
        if (deepResults) params.set('deep', '1');
        if (activeProject) params.set('project', activeProject);
        if (activeSource) params.set('source', activeSource);
        if (dayFilter) params.set('day', dayFilter);
        if (expiringOnly) params.set('expiring', '1');
        const qs = params.toString();
        history.replaceState(null, '', '/' + (qs ? '?' + qs : ''));
    }

    // ── Data loading ──
    async function loadSources() {
        try {
            sources = await (await fetch('/api/sessions/sources')).json();
        } catch (err) { sources = []; }
    }

    async function loadRetention() {
        try {
            retentionReport = await (await fetch('/api/retention')).json();
        } catch (err) {
            retentionReport = null;
        }
        renderRetentionStrip();
        renderList(); // badges depend on prefs
    }

    function renderRetentionStrip() {
        const strip = document.getElementById('retention-strip');
        const rowsEl = document.getElementById('retention-rows');
        const summaryEl = document.getElementById('retention-summary');
        const expBtn = document.getElementById('retention-expiring-btn');
        if (!strip || !rowsEl || !retentionReport) return;

        const prefs = retentionReport.prefs || {};
        if (prefs.show_strip === false) {
            strip.classList.add('hidden');
            return;
        }
        strip.classList.remove('hidden');

        const warn = prefs.warning_days ?? 7;
        const providers = retentionReport.providers || [];
        const withSessions = providers.filter(p => (p.stats && p.stats.total > 0) || p.kind === 'days');
        const atRisk = retentionReport.at_risk || 0;

        if (summaryEl) {
            summaryEl.textContent = atRisk
                ? `${atRisk} session${atRisk === 1 ? '' : 's'} within ${warn}d warning window`
                : `No sessions inside ${warn}d warning window`;
        }
        if (expBtn) {
            if (atRisk > 0) {
                expBtn.classList.remove('hidden');
                expBtn.textContent = expiringOnly ? 'Show all' : `Expiring soon (${atRisk})`;
                expBtn.classList.toggle('ret-expiring-active', expiringOnly);
            } else {
                expBtn.classList.add('hidden');
            }
        }

        // Prefer providers with day TTL first, then the rest that have sessions.
        const ordered = [...withSessions].sort((a, b) => {
            const aDays = a.kind === 'days' ? 0 : 1;
            const bDays = b.kind === 'days' ? 0 : 1;
            if (aDays !== bDays) return aDays - bDays;
            return (b.stats?.total || 0) - (a.stats?.total || 0);
        });

        rowsEl.innerHTML = ordered.map(p => {
            const total = p.stats?.total || 0;
            let policyLabel;
            let riskCls = 'ret-pol-ok';
            if (p.kind === 'days') {
                const srcNote = p.source === 'default' ? 'default' : 'configured';
                policyLabel = `${formatRetentionDays(p.days)} (${srcNote})`;
                if ((p.stats?.critical || 0) > 0 || (p.stats?.expired || 0) > 0) riskCls = 'ret-pol-crit';
                else if ((p.stats?.expiring || 0) > 0) riskCls = 'ret-pol-warn';
            } else if (p.kind === 'none') {
                policyLabel = 'no auto-delete';
            } else {
                policyLabel = 'unknown';
            }

            let tail = `${total} session${total === 1 ? '' : 's'}`;
            if (p.kind === 'days' && (p.stats?.expiring || 0) > 0) {
                tail += ` · ${p.stats.expiring} at risk`;
            } else if (p.kind === 'days' && p.stats?.soonest !== null && p.stats?.soonest !== undefined) {
                const s = p.stats.soonest;
                tail += s < 0 ? ' · oldest past cutoff' : ` · soonest ${s}d`;
            }

            const note = p.note ? ` title="${esc(p.note)}"` : '';
            return `<div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] font-mono"${note}>
                <span class="inline-flex items-center gap-1.5 min-w-[7.5rem]">
                    <span class="w-1.5 h-1.5 rounded-full ${SOURCE_DOTS[p.id] || 'bg-zinc-400'}"></span>
                    <span class="text-zinc-700 dark:text-cc-soft">${esc(p.label || p.id)}</span>
                </span>
                <span class="${riskCls}">${esc(policyLabel)}</span>
                <span class="text-zinc-400 dark:text-cc-dim">${esc(tail)}</span>
            </div>`;
        }).join('');
    }

    function openRetentionModal() {
        const modal = document.getElementById('retention-modal');
        if (!modal || !retentionReport) return;
        const prefs = retentionReport.prefs || {};
        document.getElementById('retention-warn-input').value = prefs.warning_days ?? 7;
        document.getElementById('retention-show-strip').checked = prefs.show_strip !== false;
        document.getElementById('retention-show-badges').checked = prefs.show_badges !== false;
        document.getElementById('retention-modal-error').classList.add('hidden');
        modal.classList.remove('hidden');
    }

    function closeRetentionModal() {
        const modal = document.getElementById('retention-modal');
        if (modal) modal.classList.add('hidden');
    }

    async function saveRetentionPrefs() {
        const errEl = document.getElementById('retention-modal-error');
        const days = parseInt(document.getElementById('retention-warn-input').value, 10);
        const body = {
            warning_days: Number.isFinite(days) ? days : 7,
            show_strip: document.getElementById('retention-show-strip').checked,
            show_badges: document.getElementById('retention-show-badges').checked,
        };
        try {
            const res = await fetch('/api/retention/preferences', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Save failed');
            closeRetentionModal();
            // Re-annotate via reload so days_left risk bands match new warning window.
            await loadSessions();
            await loadRetention();
        } catch (err) {
            if (errEl) {
                errEl.textContent = err.message || 'Save failed';
                errEl.classList.remove('hidden');
            }
        }
    }

    async function loadProjects() {
        try {
            allProjects = await (await fetch('/api/sessions/projects')).json();
        } catch (err) { allProjects = []; }
    }

    // ── Project combobox ──
    let comboHighlight = -1;
    let comboItems = [];   // paths currently listed ('' = All projects)

    function comboItemHtml(path, label, sub, count, idx) {
        const active = idx === comboHighlight;
        const base = active ? 'bg-zinc-100 dark:bg-cc-panel' : '';
        return `<div class="combo-item px-3 py-1.5 cursor-pointer hover:bg-zinc-100 dark:hover:bg-cc-panel ${base}" data-path="${esc(path)}" data-idx="${idx}">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-xs truncate text-zinc-800 dark:text-cc-ink">${esc(label)}</span>
                ${count !== null ? `<span class="text-[10px] font-mono shrink-0 text-zinc-400 dark:text-cc-dim">${count}</span>` : ''}
            </div>
            ${sub ? `<div class="text-[10px] font-mono truncate text-zinc-400 dark:text-cc-dim">${esc(sub)}</div>` : ''}
        </div>`;
    }

    function renderComboList(filter) {
        const listEl = document.getElementById('project-combo-list');
        if (!listEl) return;
        const q = (filter || '').trim().toLowerCase();
        const matches = allProjects.filter(p =>
            !q || projectLabel(p.path).toLowerCase().includes(q) || p.path.toLowerCase().includes(q)
        );

        comboItems = [''].concat(matches.slice(0, 150).map(p => p.path));
        let html = comboItemHtml('', 'All projects', '', null, 0);
        matches.slice(0, 150).forEach((p, i) => {
            html += comboItemHtml(p.path, projectLabel(p.path), shortPath(p.path), p.sessions, i + 1);
        });
        if (!matches.length && q) {
            html += '<div class="px-3 py-2 text-xs text-zinc-400 dark:text-cc-dim">no projects match</div>';
        }
        listEl.innerHTML = html;

        // mousedown beats the input blur that closes the list.
        listEl.querySelectorAll('.combo-item').forEach(item => {
            item.addEventListener('mousedown', e => {
                e.preventDefault();
                selectProject(item.dataset.path);
            });
        });
    }

    function openCombo(filter) {
        comboHighlight = -1;
        renderComboList(filter);
        document.getElementById('project-combo-list').classList.remove('hidden');
    }

    function closeCombo() {
        document.getElementById('project-combo-list')?.classList.add('hidden');
        comboHighlight = -1;
    }

    function syncProjectInput() {
        projectInput.value = activeProject ? projectLabel(activeProject) : '';
        projectInput.title = activeProject ? shortPath(activeProject) : '';
        document.getElementById('project-clear-btn')?.classList.toggle('hidden', !activeProject);
    }

    async function selectProject(path) {
        closeCombo();
        projectInput.blur();
        if (path === activeProject) { syncProjectInput(); return; }
        activeProject = path || '';
        syncProjectInput();
        shown = PAGE_SIZE;
        await loadSessions();
        renderHeatmap();   // counts update instantly; token sums follow
        loadDailyStats();
        renderPills();
        if (deepResults) {
            doDeepSearch();
        } else {
            updateURL();
            renderList();
        }
    }

    async function loadSessions() {
        const container = document.getElementById('sessions-list');
        try {
            const params = new URLSearchParams();
            if (activeProject) params.set('project', activeProject);
            const qs = params.toString();
            allSessions = await (await fetch('/api/sessions' + (qs ? '?' + qs : ''))).json();
        } catch (err) {
            if (container) container.innerHTML = emptyState(illoSatellite('w-20 h-20'), 'scan failed', 'could not load sessions');
            allSessions = [];
        }
        sessionsLoaded = true;
    }

    // ── Activity heatmap ──
    async function loadDailyStats() {
        try {
            const params = new URLSearchParams();
            if (activeProject) params.set('project', activeProject);
            if (activeSource) params.set('source', activeSource);
            const qs = params.toString();
            const rows = await (await fetch('/api/sessions/stats/daily' + (qs ? '?' + qs : ''))).json();
            dailyStats = {};
            rows.forEach(r => { dailyStats[r.day] = r; });
        } catch (err) {
            dailyStats = {};
        }
        renderHeatmap();
    }

    function renderHeatmap() {
        const wrap = document.getElementById('heatmap-wrap');
        if (!wrap) return;

        const CELL = 11, PITCH = 14, TOP = 16, LEFT = 28;
        const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const dayKey = localDayKey;

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        // Calendar-year view: Jan 1 through Dec 31, future days blacked out.
        const jan1 = new Date(today.getFullYear(), 0, 1);
        const yearEnd = new Date(today.getFullYear(), 11, 31);
        const start = new Date(jan1);
        start.setDate(start.getDate() - start.getDay()); // snap back to Sunday

        // Counts come from the live session list so they match the day headers
        // below exactly; dailyStats only contributes token sums (index-backed).
        const counts = {};
        let list = allSessions;
        if (activeSource) list = list.filter(s => s.source === activeSource);
        list.forEach(s => {
            if (!s.timestamp_s) return;
            const key = dayKey(new Date(s.timestamp_s * 1000));
            counts[key] = (counts[key] || 0) + 1;
        });

        // Level thresholds: quartiles of the non-zero day counts in range.
        const nonzero = [];
        for (let d = new Date(jan1); d <= today; d.setDate(d.getDate() + 1)) {
            const n = counts[dayKey(d)] || 0;
            if (n > 0) nonzero.push(n);
        }
        nonzero.sort((a, b) => a - b);
        const q = p => nonzero.length ? nonzero[Math.min(nonzero.length - 1, Math.floor(p * nonzero.length))] : 1;
        const t1 = q(0.25), t2 = q(0.5), t3 = q(0.75);
        const level = n => n === 0 ? 0 : n <= t1 ? 1 : n <= t2 ? 2 : n <= t3 ? 3 : 4;

        let rects = '';
        const monthLabels = [];
        let week = 0, lastMonth = -1, total = 0;

        // One pass over the full padded year: past days are live cells, and
        // everything else (before Jan 1, after today, trailing partial week)
        // renders as a blacked-out placeholder. Placeholders are not .hm-cell,
        // so the tooltip/click handlers skip them. The loop runs to the
        // Saturday on or after Dec 31 to keep the grid edge square.
        for (let d = new Date(start); d <= yearEnd || d.getDay() !== 0; d.setDate(d.getDate() + 1)) {
            const wd = d.getDay();
            if (wd === 0 && d > start) week++;

            if (d >= jan1 && d <= yearEnd && d.getMonth() !== lastMonth) {
                lastMonth = d.getMonth();
                monthLabels.push({ week, name: MONTHS[lastMonth] });
            }

            if (d >= jan1 && d <= today) {
                const key = dayKey(d);
                const s = dailyStats[key];
                const n = counts[key] || 0;
                total += n;

                rects += `<rect class="hm-cell hm-l${level(n)}${key === dayFilter ? ' hm-selected' : ''}" x="${LEFT + week * PITCH}" y="${TOP + wd * PITCH}"
                    width="${CELL}" height="${CELL}" rx="2" data-day="${key}" data-count="${n}"
                    data-tin="${s ? s.tokens_in : 0}" data-tout="${s ? s.tokens_out : 0}"
                    aria-label="${n} session${n !== 1 ? 's' : ''} on ${key}"></rect>`;
            } else {
                rects += `<rect class="hm-future" x="${LEFT + week * PITCH}" y="${TOP + wd * PITCH}"
                    width="${CELL}" height="${CELL}" rx="2" aria-hidden="true"></rect>`;
            }
        }

        // Drop the first month label when the next one crowds it out.
        if (monthLabels.length > 1 && monthLabels[1].week - monthLabels[0].week < 2) monthLabels.shift();

        let labels = monthLabels.map(m => `<text class="hm-label" x="${LEFT + m.week * PITCH}" y="9">${m.name}</text>`).join('');
        [['Mon', 1], ['Wed', 3], ['Fri', 5]].forEach(([name, row]) => {
            labels += `<text class="hm-label" x="0" y="${TOP + row * PITCH + 9}">${name}</text>`;
        });

        const width = LEFT + (week + 1) * PITCH;
        const height = TOP + 7 * PITCH;
        // viewBox + width:100% scales the grid up to fill the card on wide
        // screens; min-width keeps it scrollable (not shrunken) on small ones.
        wrap.innerHTML = `<svg viewBox="0 0 ${width} ${height}" style="width:100%;min-width:${width}px;height:auto;display:block" role="img" aria-label="Session activity for ${today.getFullYear()}">${labels}${rects}</svg>`;
        wrap.scrollLeft = wrap.scrollWidth;

        // No count until sessions have actually loaded - a "0 sessions" flash
        // on the skeleton render reads as broken.
        const totalEl = document.getElementById('heatmap-total');
        if (totalEl) totalEl.textContent = sessionsLoaded ? total.toLocaleString() + ' sessions in ' + today.getFullYear() : '';

        const svg = wrap.querySelector('svg');
        wireHeatmapTooltip(svg);

        // Click a day to filter the session list to it; click again to clear.
        svg.addEventListener('click', e => {
            const cell = e.target.closest('.hm-cell');
            if (!cell) return;
            dayFilter = dayFilter === cell.dataset.day ? '' : cell.dataset.day;
            deepResults = null;
            shown = PAGE_SIZE;
            updateDeepSearchBtn();
            updateURL();
            renderHeatmap();
            renderList();
        });
    }

    function wireHeatmapTooltip(svg) {
        if (!svg) return;

        function getTip() {
            let tip = document.getElementById('heatmap-tooltip');
            if (!tip) {
                tip = document.createElement('div');
                tip.id = 'heatmap-tooltip';
                tip.style.display = 'none';
                document.getElementById('app').appendChild(tip);
            }
            return tip;
        }

        svg.addEventListener('pointermove', e => {
            const cell = e.target.closest('.hm-cell');
            const tip = getTip();
            if (!cell) { tip.style.display = 'none'; return; }

            const n = parseInt(cell.dataset.count, 10);
            const tin = parseInt(cell.dataset.tin, 10);
            const tout = parseInt(cell.dataset.tout, 10);

            tip.textContent = '';
            const value = document.createElement('div');
            value.className = 'hm-tip-value';
            value.textContent = n === 0 ? 'No sessions' : n + ' session' + (n !== 1 ? 's' : '');
            tip.appendChild(value);

            const date = document.createElement('div');
            date.className = 'hm-tip-sub';
            date.textContent = dayKeyLabel(cell.dataset.day);
            tip.appendChild(date);

            if (tin + tout > 0) {
                const tok = document.createElement('div');
                tok.className = 'hm-tip-sub';
                tok.textContent = formatTokens(tin) + ' in · ' + formatTokens(tout) + ' out';
                tip.appendChild(tok);
            }

            tip.style.display = 'block';
            const tw = tip.offsetWidth, th = tip.offsetHeight;
            let x = e.clientX + 12, yPos = e.clientY - th - 10;
            if (x + tw > window.innerWidth - 8) x = e.clientX - tw - 12;
            if (yPos < 8) yPos = e.clientY + 14;
            tip.style.left = x + 'px';
            tip.style.top = yPos + 'px';
        });

        svg.addEventListener('pointerleave', () => {
            const tip = document.getElementById('heatmap-tooltip');
            if (tip) tip.style.display = 'none';
        });
    }

    // ── Source pills ──
    function renderPills() {
        const el = document.getElementById('source-pills');
        if (!el) return;
        const counts = {};
        allSessions.forEach(s => { counts[s.source] = (counts[s.source] || 0) + 1; });

        const pill = (id, label, count) => {
            const active = activeSource === id;
            const base = active
                ? 'border-zinc-900 dark:border-cc-ink bg-zinc-900 text-white dark:bg-cc-ink dark:text-cc-bg'
                : 'border-zinc-200 dark:border-cc-line bg-white dark:bg-cc-card text-zinc-600 dark:text-cc-mut hover:border-zinc-400 dark:hover:border-[#2a3830]';
            const dot = id ? `<span class="w-1.5 h-1.5 rounded-full ${SOURCE_DOTS[id] || 'bg-zinc-400'}"></span>` : '';
            return `<button data-source="${esc(id)}" class="source-pill inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-medium transition-colors ${base}">
                ${dot}<span>${esc(label)}</span><span class="font-mono text-[11px] ${active ? 'opacity-60' : 'text-zinc-400 dark:text-cc-dim'}">${count}</span>
            </button>`;
        };

        let html = pill('', 'All', allSessions.length);
        sources.forEach(s => { if (counts[s.id]) html += pill(s.id, s.label, counts[s.id]); });
        el.innerHTML = html;

        el.querySelectorAll('.source-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                activeSource = btn.dataset.source;
                shown = PAGE_SIZE;
                renderPills();
                renderHeatmap();   // counts update instantly; token sums follow
                loadDailyStats();
                if (deepResults) {
                    doDeepSearch(); // re-run with the new source scope
                } else {
                    updateURL();
                    renderList();
                }
            });
        });
    }

    // ── List rendering ──
    function warningDays() {
        return retentionReport?.prefs?.warning_days ?? 7;
    }

    function showRetentionBadges() {
        return retentionReport?.prefs?.show_badges !== false;
    }

    function visibleSessions() {
        let list = allSessions;
        if (activeSource) list = list.filter(s => s.source === activeSource);
        if (dayFilter) list = list.filter(s => s.timestamp_s && localDayKey(new Date(s.timestamp_s * 1000)) === dayFilter);
        if (expiringOnly) {
            const w = warningDays();
            list = list.filter(s => s.days_left !== undefined && s.days_left !== null && Number(s.days_left) <= w);
        }
        const q = searchInput.value.trim().toLowerCase();
        if (q) {
            list = list.filter(s =>
                (s.display || '').toLowerCase().includes(q) ||
                (s.projectName || '').toLowerCase().includes(q) ||
                (s.project || '').toLowerCase().includes(q)
            );
        }
        return list;
    }

    function copyBtnHtml(source, project, id) {
        if (!RESUME_BINS[source]) return '<span class="w-[26px] shrink-0"></span>';
        return `<button class="copy-resume-btn shrink-0 p-1 rounded text-zinc-300 dark:text-cc-dim opacity-0 group-hover:opacity-100 hover:!text-blue-500 transition-all"
            data-project="${esc(project || '')}" data-sid="${esc(id)}" data-source="${esc(source)}" title="Copy CLI resume command">${ICON_COPY}</button>`;
    }

    function rowHtml(s) {
        const title = s.display || s.id;
        const src = s.source || 'claude';
        return `
        <div class="session-row group flex items-center gap-3 px-4 py-2 cursor-pointer border-t border-zinc-100 dark:border-cc-line2 hover:bg-zinc-50 dark:hover:bg-cc-panel"
             data-session-id="${esc(s.id)}" data-source="${esc(src)}">
            ${sourceBadge(src, s.sourceLabel)}
            <span class="flex-1 min-w-0 truncate text-sm text-zinc-800 dark:text-cc-ink" title="${esc(title)}">${esc(title)}</span>
            ${retentionBadge(s, showRetentionBadges())}
            <span class="hidden md:block max-w-[240px] truncate text-xs font-mono text-zinc-400 dark:text-cc-dim" title="${esc(shortPath(s.project) || '')}">${esc(projectLabel(s.project) || s.projectName || '')}</span>
            <span class="hidden sm:block w-20 text-right text-xs font-mono text-zinc-400 dark:text-cc-dim shrink-0 whitespace-nowrap">${s.size ? formatBytes(s.size) : ''}</span>
            <span class="text-right text-xs font-mono text-zinc-400 dark:text-cc-dim shrink-0 whitespace-nowrap" style="width:4.5rem">${s.timestamp_s ? clockTime(s.timestamp_s) : ''}</span>
            ${copyBtnHtml(src, s.project, s.id)}
        </div>`;
    }

    function dayHeaderHtml(label, count) {
        return `<div class="flex items-baseline gap-2 px-4 pt-3 pb-1.5 first:pt-2.5 bg-white dark:bg-cc-card">
            <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-cc-dim">${esc(label)}</span>
            <span class="text-[11px] font-mono text-zinc-300 dark:text-cc-dim">${count}</span>
        </div>`;
    }

    function renderList() {
        const container = document.getElementById('sessions-list');
        if (!container) return;

        if (deepResults) { renderDeepResults(container); return; }

        const list = visibleSessions();

        let dayBanner = '';
        if (dayFilter) {
            dayBanner = `<div class="px-4 py-2 bg-emerald-500/10 text-xs font-mono text-emerald-600 dark:text-emerald-500 flex items-center justify-between">
                <span>${list.length} session${list.length !== 1 ? 's' : ''} on ${esc(dayKeyLabel(dayFilter))}</span>
                <button id="clear-day-filter" class="font-medium hover:underline">clear</button>
            </div>`;
        }
        if (expiringOnly) {
            dayBanner += `<div class="px-4 py-2 bg-amber-500/10 text-xs font-mono text-amber-700 dark:text-amber-400 flex items-center justify-between">
                <span>${list.length} session${list.length !== 1 ? 's' : ''} expiring within ${warningDays()}d</span>
                <button id="clear-expiring-filter" class="font-medium hover:underline">clear</button>
            </div>`;
        }

        if (list.length === 0) {
            container.innerHTML = dayBanner + emptyState(illoRadar(), 'no signals detected', 'no sessions match the current filters');
            wireClearDayFilter(container);
            return;
        }

        const slice = list.slice(0, shown);

        // Count sessions per day (over the full filtered list, not just the slice).
        const dayCounts = {};
        list.forEach(s => {
            const key = s.timestamp_s ? new Date(s.timestamp_s * 1000).toDateString() : 'undated';
            dayCounts[key] = (dayCounts[key] || 0) + 1;
        });

        let html = dayBanner;
        let lastDay = null;
        slice.forEach(s => {
            const key = s.timestamp_s ? new Date(s.timestamp_s * 1000).toDateString() : 'undated';
            if (key !== lastDay) {
                lastDay = key;
                html += dayHeaderHtml(s.timestamp_s ? dayLabel(s.timestamp_s) : 'Undated', dayCounts[key]);
            }
            html += rowHtml(s);
        });

        if (list.length > shown) {
            html += `<button id="show-more" class="w-full py-2.5 border-t border-zinc-100 dark:border-cc-line2 text-xs font-mono text-zinc-400 hover:text-zinc-600 dark:hover:text-cc-soft hover:bg-zinc-50 dark:hover:bg-cc-panel transition-colors">
                show more (${list.length - shown} remaining)
            </button>`;
        }

        container.innerHTML = html;
        wireRows(container);
        wireClearDayFilter(container);
        document.getElementById('show-more')?.addEventListener('click', () => {
            shown += PAGE_SIZE;
            renderList();
        });
    }

    function wireClearDayFilter(container) {
        container.querySelector('#clear-day-filter')?.addEventListener('click', () => {
            dayFilter = '';
            shown = PAGE_SIZE;
            updateURL();
            renderHeatmap();
            renderList();
        });
        container.querySelector('#clear-expiring-filter')?.addEventListener('click', () => {
            expiringOnly = false;
            shown = PAGE_SIZE;
            updateURL();
            renderRetentionStrip();
            renderList();
        });
    }

    function renderDeepResults(container) {
        if (deepResults.length === 0) {
            container.innerHTML = `
                <div class="px-4 py-2 bg-blue-500/10 text-xs font-mono text-blue-500 flex items-center justify-between">
                    <span>0 matches</span>
                    <button id="clear-deep-search" class="font-medium text-blue-400 hover:underline">clear</button>
                </div>
                ${emptyState(illoRadar(), 'no echoes on this frequency', 'nothing in conversation content matched - try different keywords')}`;
            wireClearDeep(container);
            return;
        }

        let html = `<div class="px-4 py-2 bg-blue-500/10 text-xs font-mono text-blue-500 flex items-center justify-between">
            <span>${deepResults.length} match${deepResults.length !== 1 ? 'es' : ''} in conversation content</span>
            <button id="clear-deep-search" class="font-medium text-blue-400 hover:underline">clear</button>
        </div>`;

        deepResults.forEach(s => {
            const src = s.source || 'claude';
            const typeLabel = s.matchType === 'user' ? 'you' : s.matchType === 'assistant' ? 'assistant' : s.matchType === 'summary' ? 'summary' : '';
            const typeBadge = typeLabel ? `<span class="inline-block text-[11px] font-mono font-medium px-1 py-0.5 rounded bg-zinc-100 dark:bg-cc-panel text-zinc-500 mr-1.5">${typeLabel}</span>` : '';
            html += `
            <div class="session-row group px-4 py-2.5 cursor-pointer border-t border-zinc-100 dark:border-cc-line2 hover:bg-zinc-50 dark:hover:bg-cc-panel"
                 data-session-id="${esc(s.id)}" data-source="${esc(src)}">
                <div class="flex items-center gap-2.5 min-w-0">
                    ${sourceBadge(src, s.sourceLabel)}
                    <span class="flex-1 min-w-0 truncate text-sm font-medium text-zinc-800 dark:text-cc-ink">${esc(s.display || s.id)}</span>
                    <span class="hidden sm:block max-w-[220px] truncate text-xs font-mono text-zinc-400 dark:text-cc-dim" title="${esc(shortPath(s.project) || '')}">${esc(projectLabel(s.project) || s.projectName || '')}</span>
                    <span class="text-xs font-mono text-zinc-400 dark:text-cc-dim whitespace-nowrap shrink-0">${s.timestamp_s ? timeAgo(s.timestamp_s) : ''}</span>
                    ${copyBtnHtml(src, s.project, s.id)}
                </div>
                <div class="mt-1 text-xs leading-relaxed text-zinc-500 dark:text-cc-mut line-clamp-2">${typeBadge}${s.snippet || ''}</div>
            </div>`;
        });

        container.innerHTML = html;
        wireClearDeep(container);
        wireRows(container);
    }

    function wireClearDeep(container) {
        container.querySelector('#clear-deep-search')?.addEventListener('click', () => {
            deepResults = null;
            searchInput.value = '';
            shown = PAGE_SIZE;
            updateDeepSearchBtn();
            updateURL();
            renderList();
        });
    }

    function wireRows(container) {
        container.querySelectorAll('.session-row').forEach(row => {
            row.addEventListener('click', e => {
                if (e.target.closest('.copy-resume-btn')) return;
                const src = row.dataset.source || '';
                navigate('/sessions/' + row.dataset.sessionId + (src ? '?source=' + encodeURIComponent(src) : ''));
            });
        });
        container.querySelectorAll('.copy-resume-btn').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                const cmd = resumeCommand(btn.dataset.source, btn.dataset.project, btn.dataset.sid);
                if (cmd) copyWithFlash(btn, cmd);
            });
        });
    }

    // ── Deep search ──
    function updateDeepSearchBtn() {
        const btn = document.getElementById('deep-search-btn');
        if (btn) btn.disabled = searchInput.value.trim().length < 3;
    }

    async function doDeepSearch() {
        const q = searchInput.value.trim();
        if (q.length < 3) return;

        const container = document.getElementById('sessions-list');
        const btn = document.getElementById('deep-search-btn');
        if (!container) return;

        container.innerHTML = emptyState(illoRadar('w-16 h-16'), 'sweeping', 'searching full conversation content…');
        if (btn) { btn.disabled = true; btn.textContent = '…'; }

        try {
            const params = new URLSearchParams();
            params.set('q', q);
            if (activeProject) params.set('project', activeProject);
            if (activeSource) params.set('source', activeSource);
            const res = await fetch('/api/sessions/search?' + params.toString());
            if (!res.ok) throw new Error('Search failed');
            deepResults = await res.json();
            updateURL();
            renderList();
        } catch (err) {
            container.innerHTML = emptyState(illoSatellite('w-20 h-20'), 'sweep failed', 'the deep search errored - try again');
        } finally {
            if (btn) { btn.textContent = 'Deep search'; }
            updateDeepSearchBtn();
        }
    }

    // ── Events ──
    projectInput.addEventListener('focus', () => {
        projectInput.select();
        openCombo('');
    });

    projectInput.addEventListener('input', () => {
        openCombo(projectInput.value);
    });

    projectInput.addEventListener('blur', () => {
        // Delay so a mousedown on a list item can fire first.
        setTimeout(() => { closeCombo(); syncProjectInput(); }, 120);
    });

    projectInput.addEventListener('keydown', e => {
        const listEl = document.getElementById('project-combo-list');
        const open = listEl && !listEl.classList.contains('hidden');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!open) { openCombo(projectInput.value); return; }
            const dir = e.key === 'ArrowDown' ? 1 : -1;
            comboHighlight = Math.max(0, Math.min(comboItems.length - 1, comboHighlight + dir));
            renderComboList(projectInput.value);
            listEl.querySelector(`[data-idx="${comboHighlight}"]`)?.scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (!open) return;
            // Highlighted item wins; otherwise the first real match.
            const pick = comboHighlight >= 0 ? comboItems[comboHighlight]
                : (comboItems.length > 1 ? comboItems[1] : '');
            selectProject(pick);
        } else if (e.key === 'Escape') {
            closeCombo();
            syncProjectInput();
            projectInput.blur();
        }
    });

    document.getElementById('project-clear-btn').addEventListener('click', () => selectProject(''));

    searchInput.addEventListener('input', () => {
        updateDeepSearchBtn();
        if (!deepResults) {
            shown = PAGE_SIZE;
            updateURL();
            renderList();
        }
    });

    searchInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && searchInput.value.trim().length >= 3) {
            doDeepSearch();
        }
        if (e.key === 'Escape') {
            searchInput.value = '';
            deepResults = null;
            shown = PAGE_SIZE;
            updateDeepSearchBtn();
            updateURL();
            renderList();
        }
    });

    document.getElementById('deep-search-btn').addEventListener('click', doDeepSearch);

    document.getElementById('reindex-btn').addEventListener('click', async () => {
        const btn = document.getElementById('reindex-btn');
        btn.disabled = true;
        btn.classList.add('animate-spin');
        try {
            const res = await fetch('/api/sessions/search/reindex', { method: 'POST' });
            const data = await res.json();
            btn.title = `Index rebuilt: ${data.indexed} sessions in ${(data.elapsed_ms / 1000).toFixed(1)}s`;
            loadIndexStatus();
        } catch (err) {
            btn.title = 'Rebuild failed';
        } finally {
            btn.disabled = false;
            btn.classList.remove('animate-spin');
        }
    });

    document.getElementById('retention-config-btn')?.addEventListener('click', openRetentionModal);
    document.getElementById('retention-modal-cancel')?.addEventListener('click', closeRetentionModal);
    document.getElementById('retention-modal-backdrop')?.addEventListener('click', closeRetentionModal);
    document.getElementById('retention-modal-save')?.addEventListener('click', saveRetentionPrefs);
    document.getElementById('retention-expiring-btn')?.addEventListener('click', () => {
        expiringOnly = !expiringOnly;
        shown = PAGE_SIZE;
        updateURL();
        renderRetentionStrip();
        renderList();
    });

    // ── Initial load ──
    if (initialQuery) searchInput.value = initialQuery;
    updateDeepSearchBtn();

    // Paint the year grid immediately as a skeleton (all-empty cells) so the
    // heatmap card never sits as a bare legend while sessions load.
    renderHeatmap();

    (async () => {
        await loadSources();
        await loadProjects();
        if (initialProject) {
            activeProject = initialProject;
            syncProjectInput();
        }
        await loadSessions();
        renderPills();
        renderList();
        loadDailyStats();
        loadRetention();
        searchInput.focus();
        if (initialQuery && initialDeep) doDeepSearch();
    })();
}

// ─── View: Token Usage ───────────────────────────────────────
// Monthly token breakdown across providers. Fresh input (input + cache
// creation) and output share one chart; cache reads get their own - they
// run ~20x larger and would flatten everything else on a shared axis.
function renderUsageView() {
    const app = document.getElementById('app');
    app.innerHTML = `
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-sm font-mono font-bold tracking-[0.2em] text-zinc-900 dark:text-cc-bright mr-2">TOKEN USAGE</h1>
                <div id="usage-pills" class="flex flex-wrap items-center gap-1.5"></div>
            </div>
            <div id="usage-note" class="hidden text-[11px] font-mono text-zinc-400 dark:text-cc-dim"></div>
            <div id="usage-kpis" class="grid grid-cols-2 sm:grid-cols-4 gap-3"></div>
            <div class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm px-4 pt-3.5 pb-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-cc-dim">Tokens per month</span>
                    <span class="flex items-center gap-4 text-[11px] font-mono text-zinc-500 dark:text-cc-mut">
                        <span class="flex items-center gap-1.5"><svg width="10" height="10"><rect class="uz-in" width="10" height="10" rx="2"/></svg>fresh input</span>
                        <span class="flex items-center gap-1.5"><svg width="10" height="10"><rect class="uz-out" width="10" height="10" rx="2"/></svg>output</span>
                    </span>
                </div>
                <div id="usage-chart-main" class="overflow-x-auto mt-2"></div>
            </div>
            <div class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm px-4 pt-3.5 pb-2">
                <span class="text-xs font-semibold uppercase tracking-widest text-zinc-400 dark:text-cc-dim">Cache reads per month</span>
                <div id="usage-chart-cache" class="overflow-x-auto mt-2"></div>
            </div>
            <div id="usage-table" class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm overflow-hidden"></div>
        </div>
    `;

    let rows = [];        // raw per-(month,source) rows from the API
    let sources = [];     // [{id,label}]
    let activeSource = new URLSearchParams(location.search).get('source') || '';

    const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function monthLabel(key, withYear) {
        const [y, m] = key.split('-').map(Number);
        return MONTH_NAMES[m - 1] + (withYear ? ' ’' + String(y).slice(2) : '');
    }
    function monthLongLabel(key) {
        const [y, m] = key.split('-').map(Number);
        return new Date(y, m - 1, 1).toLocaleDateString([], { month: 'long', year: 'numeric' });
    }

    // Provider ids whose numbers are estimates (chars/4 heuristics, Grok's
    // context meter). Shown per-provider with a ~, excluded from All rollups
    // so measured totals stay real.
    function estimatedSet() {
        return new Set(sources.filter(s => s.usage === 'estimated').map(s => s.id));
    }
    function isEstimatedView() {
        return activeSource && estimatedSet().has(activeSource);
    }

    // Aggregate the filtered rows into ordered per-month totals.
    function monthTotals() {
        const est = estimatedSet();
        const byMonth = {};
        rows.forEach(r => {
            if (activeSource && r.source !== activeSource) return;
            if (!activeSource && est.has(r.source)) return;
            const t = byMonth[r.month] || (byMonth[r.month] = { sessions: 0, input: 0, output: 0, cache_read: 0, cache_creation: 0 });
            t.sessions += r.sessions;
            t.input += r.tokens_input;
            t.output += r.tokens_output;
            t.cache_read += r.tokens_cache_read;
            t.cache_creation += r.tokens_cache_creation;
        });
        return Object.keys(byMonth).sort().map(month => ({ month, ...byMonth[month], fresh: byMonth[month].input + byMonth[month].cache_creation }));
    }

    // Clean axis ticks: step is 1/2/5 x 10^k, ~4 gridlines.
    function niceTicks(max) {
        if (max <= 0) return { top: 1, ticks: [0, 1] };
        const rough = max / 4;
        const pow = Math.pow(10, Math.floor(Math.log10(rough)));
        const step = [1, 2, 5, 10].map(m => m * pow).find(s => s >= rough);
        const top = Math.ceil(max / step) * step;
        const ticks = [];
        for (let v = 0; v <= top; v += step) ticks.push(v);
        return { top, ticks };
    }

    // Column with a 4px rounded data-end and a square baseline.
    function barPath(x, y, w, h, baseY) {
        if (h <= 0) return '';
        const r = Math.min(4, w / 2, h);
        return `M${x},${baseY} L${x},${y + r} Q${x},${y} ${x + r},${y} L${x + w - r},${y} Q${x + w},${y} ${x + w},${y + r} L${x + w},${baseY} Z`;
    }

    // Shared column-chart renderer. series: [{key, cls, label}] pulls values
    // off each month row; one transparent hit band per month drives the tooltip.
    function drawChart(el, months, series, height, approx = false) {
        if (!el) return;
        if (!months.length || !months.some(m => series.some(s => m[s.key] > 0))) {
            el.innerHTML = `<div class="py-8 text-center text-xs font-mono text-zinc-400 dark:text-cc-dim">no token data indexed</div>`;
            return;
        }

        // Render at natural scale (no SVG stretching - a sparse chart scaled to
        // fill the card turns 9px labels into headlines). Bands widen to fill
        // the card when months are few, capped so a single month stays compact.
        const LEFT = 40, TOP = 6, BOTTOM = 18, RIGHT = 8;
        const avail = Math.max(320, (el.clientWidth || 900) - LEFT - RIGHT);
        const BAND = Math.max(40, Math.min(130, Math.floor(avail / months.length)));
        const GAP = 2, PAD = Math.max(6, Math.round(BAND * 0.18));
        const barW = Math.min(24, Math.floor((BAND - PAD * 2 - GAP * (series.length - 1)) / series.length));
        const groupW = barW * series.length + GAP * (series.length - 1);
        const plotH = height - TOP - BOTTOM;
        const baseY = TOP + plotH;
        const width = LEFT + months.length * BAND + RIGHT;

        const max = Math.max(...months.map(m => Math.max(...series.map(s => m[s.key]))));
        const { top, ticks } = niceTicks(max);
        const yOf = v => baseY - (v / top) * plotH;

        let svg = '';
        ticks.forEach(v => {
            if (v > 0) svg += `<line class="uz-grid" x1="${LEFT}" y1="${yOf(v)}" x2="${width - RIGHT}" y2="${yOf(v)}"/>`;
            svg += `<text class="uz-label" x="${LEFT - 6}" y="${yOf(v) + 3}" text-anchor="end">${formatTokens(v)}</text>`;
        });
        svg += `<line class="uz-axis" x1="${LEFT}" y1="${baseY}" x2="${width - RIGHT}" y2="${baseY}"/>`;

        let prevYear = '';
        months.forEach((m, i) => {
            const x0 = LEFT + i * BAND;
            const gx = x0 + (BAND - groupW) / 2;
            const year = m.month.split('-')[0];
            const withYear = year !== prevYear;
            prevYear = year;

            svg += `<g class="uz-group" data-idx="${i}">`;
            svg += `<rect class="uz-hit" x="${x0}" y="${TOP}" width="${BAND}" height="${plotH + BOTTOM}"/>`;
            series.forEach((s, si) => {
                svg += `<path class="uz-bar ${s.cls}" d="${barPath(gx + si * (barW + GAP), yOf(m[s.key]), barW, baseY - yOf(m[s.key]), baseY)}"/>`;
            });
            svg += `<text class="uz-label" x="${x0 + BAND / 2}" y="${baseY + 13}" text-anchor="middle">${monthLabel(m.month, withYear)}</text>`;
            svg += `</g>`;
        });

        el.innerHTML = `<svg viewBox="0 0 ${width} ${height}" style="width:${width}px;height:auto;display:block" role="img" aria-label="Monthly token usage">${svg}</svg>`;
        wireChartTooltip(el.querySelector('svg'), months, series, approx);
    }

    // Per-band hover tooltip: every series' value at that month, values lead.
    function wireChartTooltip(svg, months, series, approx = false) {
        function getTip() {
            let tip = document.getElementById('heatmap-tooltip');
            if (!tip) {
                tip = document.createElement('div');
                tip.id = 'heatmap-tooltip';
                tip.style.display = 'none';
                document.getElementById('app').appendChild(tip);
            }
            return tip;
        }

        svg.addEventListener('pointermove', e => {
            const group = e.target.closest('.uz-group');
            const tip = getTip();
            if (!group) { tip.style.display = 'none'; return; }
            const m = months[parseInt(group.dataset.idx, 10)];

            tip.textContent = '';
            const title = document.createElement('div');
            title.className = 'hm-tip-value';
            title.textContent = monthLongLabel(m.month);
            tip.appendChild(title);

            series.forEach(s => {
                const row = document.createElement('div');
                const key = document.createElement('span');
                key.className = 'uz-key uz-key-' + s.keyCls;
                const val = document.createElement('strong');
                val.textContent = (approx ? '~' : '') + formatTokens(m[s.key]);
                const lbl = document.createElement('span');
                lbl.className = 'hm-tip-sub';
                lbl.textContent = ' ' + s.label;
                row.appendChild(key);
                row.appendChild(val);
                row.appendChild(lbl);
                tip.appendChild(row);
            });

            tip.style.display = 'block';
            const tw = tip.offsetWidth, th = tip.offsetHeight;
            let x = e.clientX + 12, yPos = e.clientY - th - 10;
            if (x + tw > window.innerWidth - 8) x = e.clientX - tw - 12;
            if (yPos < 8) yPos = e.clientY + 14;
            tip.style.left = x + 'px';
            tip.style.top = yPos + 'px';
        });

        svg.addEventListener('pointerleave', () => {
            const tip = document.getElementById('heatmap-tooltip');
            if (tip) tip.style.display = 'none';
        });
    }

    function kpiTile(label, value, sub) {
        return `<div class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm px-4 py-3">
            <div class="text-[11px] font-mono text-zinc-400 dark:text-cc-dim">${esc(label)}</div>
            <div class="mt-0.5 text-xl font-semibold text-zinc-900 dark:text-cc-bright">${esc(value)}</div>
            ${sub ? `<div class="text-[11px] font-mono text-zinc-400 dark:text-cc-dim">${esc(sub)}</div>` : ''}
        </div>`;
    }

    function renderAll() {
        const months = monthTotals();
        const approx = isEstimatedView();
        const tilde = approx ? '~' : '';

        // Estimated-provider view gets a methodology note; the All view notes
        // which providers its totals leave out (only ones with data).
        const note = document.getElementById('usage-note');
        const est = estimatedSet();
        if (approx) {
            note.textContent = activeSource === 'grok'
                ? 'Estimated - context tokens come from Grok’s own counter; output is estimated from streamed text at ~4 chars per token.'
                : 'Estimated from raw transcripts at ~4 chars per token. System prompts and tool schemas are invisible, so real usage runs higher.';
            note.classList.remove('hidden');
        } else {
            const withData = sources.filter(s => est.has(s.id) && rows.some(r => r.source === s.id)).map(s => s.label);
            note.textContent = withData.length ? 'Measured providers only - estimated usage for ' + withData.join(', ') + ' is shown in their own views.' : '';
            note.classList.toggle('hidden', !withData.length);
        }

        const tot = months.reduce((a, m) => ({
            sessions: a.sessions + m.sessions, fresh: a.fresh + m.fresh,
            output: a.output + m.output, cache_read: a.cache_read + m.cache_read,
        }), { sessions: 0, fresh: 0, output: 0, cache_read: 0 });

        const first = months.length ? monthLabel(months[0].month, true) : '';
        const last = months.length ? monthLabel(months[months.length - 1].month, true) : '';
        const range = first === last ? first : first + ' - ' + last;
        document.getElementById('usage-kpis').innerHTML =
            kpiTile('Output tokens', tilde + formatTokens(tot.output), range) +
            kpiTile('Fresh input', tilde + formatTokens(tot.fresh), 'input + cache writes') +
            kpiTile('Cache reads', tilde + formatTokens(tot.cache_read), '') +
            kpiTile('Sessions', tot.sessions.toLocaleString(), '');

        drawChart(document.getElementById('usage-chart-main'), months, [
            { key: 'fresh', cls: 'uz-in', keyCls: 'in', label: 'fresh input' },
            { key: 'output', cls: 'uz-out', keyCls: 'out', label: 'output' },
        ], 190, approx);

        drawChart(document.getElementById('usage-chart-cache'), months, [
            { key: 'cache_read', cls: 'uz-cr', keyCls: 'cr', label: 'cache reads' },
        ], 120, approx);

        renderTable(months, approx);
        renderUsagePills();
    }

    function renderTable(months, approx = false) {
        const el = document.getElementById('usage-table');
        if (!el) return;
        if (!months.length) {
            el.innerHTML = emptyState(illoRadar(), 'no usage indexed', 'token data appears after sessions are indexed');
            return;
        }

        const num = n => n ? (approx ? '~' : '') + n.toLocaleString() : '<span class="text-zinc-300 dark:text-cc-line3">0</span>';
        const th = (label, right = true) => `<th class="px-4 py-2 text-[11px] font-mono font-medium uppercase tracking-wider text-zinc-400 dark:text-cc-dim ${right ? 'text-right' : 'text-left'}">${label}</th>`;

        let html = `<div class="overflow-x-auto"><table class="w-full text-xs font-mono" style="font-variant-numeric: tabular-nums">
            <thead><tr class="border-b border-zinc-100 dark:border-cc-line2">
                ${th('Month', false)}${th('Sessions')}${th('Input')}${th('Cache writes')}${th('Cache reads')}${th('Output')}
            </tr></thead><tbody>`;

        [...months].reverse().forEach(m => {
            html += `<tr class="border-t border-zinc-100 dark:border-cc-line2 hover:bg-zinc-50 dark:hover:bg-cc-panel">
                <td class="px-4 py-2 text-zinc-800 dark:text-cc-ink whitespace-nowrap">${esc(monthLongLabel(m.month))}</td>
                <td class="px-4 py-2 text-right text-zinc-500 dark:text-cc-mut">${m.sessions.toLocaleString()}</td>
                <td class="px-4 py-2 text-right text-zinc-500 dark:text-cc-mut">${num(m.input)}</td>
                <td class="px-4 py-2 text-right text-zinc-500 dark:text-cc-mut">${num(m.cache_creation)}</td>
                <td class="px-4 py-2 text-right text-zinc-500 dark:text-cc-mut">${num(m.cache_read)}</td>
                <td class="px-4 py-2 text-right text-zinc-800 dark:text-cc-ink">${num(m.output)}</td>
            </tr>`;
        });

        html += '</tbody></table></div>';
        el.innerHTML = html;
    }

    function renderUsagePills() {
        const el = document.getElementById('usage-pills');
        if (!el) return;

        // Only offer sources that actually carry token data.
        const withTokens = new Set();
        rows.forEach(r => {
            if (r.tokens_input + r.tokens_output + r.tokens_cache_read + r.tokens_cache_creation > 0) withTokens.add(r.source);
        });

        const pill = (id, label) => {
            const active = activeSource === id;
            const base = active
                ? 'border-zinc-900 dark:border-cc-ink bg-zinc-900 text-white dark:bg-cc-ink dark:text-cc-bg'
                : 'border-zinc-200 dark:border-cc-line bg-white dark:bg-cc-card text-zinc-600 dark:text-cc-mut hover:border-zinc-400 dark:hover:border-[#2a3830]';
            const dot = id ? `<span class="w-1.5 h-1.5 rounded-full ${SOURCE_DOTS[id] || 'bg-zinc-400'}"></span>` : '';
            return `<button data-source="${esc(id)}" class="usage-pill inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full border text-xs font-medium transition-colors ${base}">${dot}<span>${esc(label)}</span></button>`;
        };

        let html = pill('', 'All');
        sources.forEach(s => { if (withTokens.has(s.id)) html += pill(s.id, s.label); });
        el.innerHTML = html;

        el.querySelectorAll('.usage-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                activeSource = btn.dataset.source;
                history.replaceState(null, '', '/usage' + (activeSource ? '?source=' + encodeURIComponent(activeSource) : ''));
                renderAll();
            });
        });
    }

    (async () => {
        try {
            [rows, sources] = await Promise.all([
                fetch('/api/sessions/stats/monthly').then(r => r.json()),
                fetch('/api/sessions/sources').then(r => r.json()),
            ]);
        } catch (err) {
            rows = []; sources = [];
        }
        renderAll();
    })();
}

// ─── View: Session Viewer ────────────────────────────────────
function renderSessionView(sessionId) {
    const app = document.getElementById('app');

    // Source from URL (falls back to server-side auto-detection if omitted).
    const qs = new URLSearchParams(location.search);
    const source = qs.get('source') || '';
    const sourceQs = source ? '&source=' + encodeURIComponent(source) : '';

    app.innerHTML = `
        <div class="max-w-3xl mx-auto space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-2.5 min-w-0">
                    <a href="#" class="session-back-btn mt-0.5 shrink-0 text-zinc-400 hover:text-zinc-600 dark:hover:text-cc-soft">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </a>
                    <div class="min-w-0">
                        <h1 id="session-title" class="text-sm font-semibold leading-snug text-zinc-900 dark:text-cc-bright break-words">${esc(sessionId.substring(0, 8))}…</h1>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span id="session-source-badge"></span>
                            <span id="session-meta" class="text-xs font-mono text-zinc-400 dark:text-cc-dim"></span>
                        </div>
                    </div>
                </div>
                <button id="session-copy-resume-btn" class="hidden shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border border-zinc-200 dark:border-cc-line3 bg-white dark:bg-cc-card text-xs font-medium text-zinc-600 dark:text-cc-soft hover:border-zinc-400 dark:hover:border-[#2a3830] transition-colors" title="Copy CLI resume command">
                    <span class="resume-icon">${ICON_COPY}</span> Copy resume
                </button>
            </div>
            <div id="session-log" class="bg-white dark:bg-cc-card rounded-xl border border-zinc-200 dark:border-cc-line shadow-sm px-4 sm:px-6 py-5 space-y-1 text-sm"></div>
        </div>
    `;

    // Back button: use history.back() to preserve search state.
    document.querySelector('.session-back-btn').addEventListener('click', e => {
        e.preventDefault();
        if (history.length > 1) {
            history.back();
        } else {
            navigate('/');
        }
    });

    // Fetch session metadata (scoped to source when we know it) for title/meta/resume.
    const metaUrl = '/api/sessions' + (source ? '?source=' + encodeURIComponent(source) : '');
    fetch(metaUrl)
        .then(r => r.json())
        .then(sessions => {
            const s = sessions.find(s => s.id === sessionId);
            if (!s) return;

            const title = document.getElementById('session-title');
            if (title && s.display) title.textContent = s.display;

            const badge = document.getElementById('session-source-badge');
            if (badge && s.source) badge.innerHTML = sourceBadge(s.source, s.sourceLabel);

            const meta = document.getElementById('session-meta');
            if (meta) {
                const parts = [];
                if (s.project || s.projectName) parts.push(projectLabel(s.project) || s.projectName);
                if (s.timestamp_s) parts.push(new Date(s.timestamp_s * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }));
                if (s.size) parts.push(formatBytes(s.size));
                meta.textContent = parts.join(' · ');
            }

            const cmd = resumeCommand(s.source, s.project, sessionId);
            const btn = document.getElementById('session-copy-resume-btn');
            if (cmd && btn) {
                btn.classList.remove('hidden');
                btn.addEventListener('click', () => copyWithFlash(btn, cmd, '.resume-icon'));
            }
        })
        .catch(() => {});

    // Stream the conversation (replays history, then closes).
    const log = document.getElementById('session-log');
    const es = new EventSource('/stream?session=' + encodeURIComponent(sessionId) + sourceQs);

    es.addEventListener('summary', e => {
        const d = JSON.parse(e.data);
        const div = document.createElement('div');
        div.className = 'text-zinc-500 italic text-xs py-1 mb-3 border-b border-zinc-100 dark:border-cc-line';
        div.innerHTML = '<strong>Summary:</strong> ' + esc(d.text);
        log.prepend(div);
    });

    es.addEventListener('user_message', e => appendUserMessage(log, JSON.parse(e.data).text));
    es.addEventListener('text', e => appendEntry(log, 'text', JSON.parse(e.data).text));
    es.addEventListener('tool_call', e => appendToolCall(log, JSON.parse(e.data)));
    es.addEventListener('tool_result', e => {
        const d = JSON.parse(e.data);
        if (d.preview) appendResult(log, d);
    });
    es.addEventListener('complete', e => appendComplete(log, JSON.parse(e.data)));
    es.addEventListener('done', () => {
        collapseToolGroup(log);
        es.close();
        state.sessionEs = null;
    });
    es.onerror = () => {};

    state.sessionEs = es;
}

// ─── Conversation Rendering ──────────────────────────────────
const TOOL_STYLES = {
    shell:  { bg: 'bg-zinc-200 dark:bg-cc-line3', text: 'text-zinc-700 dark:text-cc-bright' },
    file:   { bg: 'bg-blue-500/15',   text: 'text-blue-500 dark:text-blue-400' },
    search: { bg: 'bg-cyan-500/15',   text: 'text-cyan-600 dark:text-cyan-400' },
    agent:  { bg: 'bg-violet-500/15', text: 'text-violet-500 dark:text-violet-400' },
    web:    { bg: 'bg-amber-500/15',  text: 'text-amber-600 dark:text-amber-400' },
    skill:  { bg: 'bg-pink-500/15',   text: 'text-pink-500 dark:text-pink-400' },
    misc:   { bg: 'bg-zinc-500/15',   text: 'text-zinc-500 dark:text-cc-mut' },
    other:  { bg: 'bg-zinc-500/15',   text: 'text-zinc-500 dark:text-cc-mut' },
};

function appendEntry(log, type, text) {
    const div = document.createElement('div');
    if (type === 'init') {
        div.className = 'text-zinc-500 dark:text-cc-dim text-xs py-0.5 font-mono';
        div.textContent = text;
    } else {
        // Collapse any open tool group before showing assistant text.
        collapseToolGroup(log);
        div.className = 'md-body border-l-2 border-emerald-500/40 pl-3.5 py-1 my-1.5 text-zinc-800 dark:text-cc-ink leading-relaxed';
        div.innerHTML = marked.parse(text, { breaks: true });
    }
    log.appendChild(div);
}

function appendUserMessage(log, text) {
    // Collapse any open tool group before a user message.
    collapseToolGroup(log);

    // Detect command XML tags from skill invocations.
    const cmdMatch = text.match(/<command-name>\s*(\/\S+)\s*<\/command-name>\s*<command-args>([\s\S]*?)<\/command-args>/);
    if (cmdMatch) {
        const div = document.createElement('div');
        div.className = 'mt-4 mb-1 flex items-center gap-1.5';
        div.innerHTML = `
            <span class="inline-flex items-center shrink-0 px-1.5 py-0.5 rounded text-[11px] font-mono font-semibold uppercase tracking-wider bg-zinc-200 dark:bg-cc-line3 text-zinc-600 dark:text-cc-soft">you</span>
            <code class="text-xs font-mono bg-violet-500/10 text-violet-500 dark:text-violet-400 px-1.5 py-0.5 rounded">${esc(cmdMatch[1])} ${esc(cmdMatch[2].trim())}</code>
        `;
        log.appendChild(div);
        return;
    }

    const div = document.createElement('div');
    div.className = 'mt-4 mb-1.5 rounded-lg bg-zinc-50 dark:bg-cc-panel border border-zinc-200 dark:border-cc-line3 px-3.5 py-2.5';
    div.innerHTML = `
        <div class="flex items-center gap-1.5 mb-1"><span class="inline-flex items-center shrink-0 px-1.5 py-0.5 rounded text-[11px] font-mono font-semibold uppercase tracking-wider bg-zinc-200 dark:bg-cc-line3 text-zinc-600 dark:text-cc-soft">you</span></div>
        <div class="md-body text-zinc-800 dark:text-cc-ink text-sm leading-relaxed">${marked.parse(text, { breaks: true })}</div>
    `;
    log.appendChild(div);
}

function getOrCreateToolGroup(log) {
    const last = log.lastElementChild;
    if (last && last.classList.contains('tool-group')) return last;
    const details = document.createElement('details');
    details.className = 'tool-group my-1.5 ml-1 border-l border-zinc-200 dark:border-cc-line pl-3';
    const summary = document.createElement('summary');
    summary.className = 'text-zinc-400 dark:text-cc-dim cursor-pointer hover:text-zinc-500 select-none text-xs font-mono';
    summary.innerHTML = '<span class="tool-group-label">working…</span>';
    details.appendChild(summary);
    log.appendChild(details);
    return details;
}

function collapseToolGroup(log) {
    const last = log.lastElementChild;
    if (!last || !last.classList.contains('tool-group')) return;
    const count = last.querySelectorAll('.tool-row').length;
    if (count === 0) { last.remove(); return; }
    const tools = [...last.querySelectorAll('.tool-row')].map(r => r.dataset.tool);
    const unique = [...new Set(tools)];
    const labelEl = last.querySelector('.tool-group-label');
    if (labelEl) labelEl.textContent = `${count} tool call${count > 1 ? 's' : ''} (${unique.join(', ')})`;
}

function appendToolCall(log, data) {
    const group = getOrCreateToolGroup(log);
    const row = document.createElement('div');
    row.className = 'tool-row flex items-start gap-2 py-0.5 text-xs font-mono';
    row.dataset.tool = data.tool;
    const style = TOOL_STYLES[data.category] || TOOL_STYLES.other;
    const badge = document.createElement('span');
    badge.className = `inline-flex items-center shrink-0 px-1.5 py-0.5 rounded text-xs font-mono font-medium ${style.bg} ${style.text}`;
    badge.textContent = data.tool;
    row.appendChild(badge);

    if (data.tool === 'TodoWrite' && data.todos) {
        const list = document.createElement('div');
        list.className = 'text-zinc-600 dark:text-cc-mut text-xs leading-relaxed';
        data.todos.forEach(t => {
            const item = document.createElement('div');
            item.className = 'flex items-center gap-1.5';
            const icon = t.status === 'completed' ? '✓' : t.status === 'in_progress' ? '▶' : '○';
            const iconColor = t.status === 'completed' ? 'text-emerald-500' : t.status === 'in_progress' ? 'text-blue-500' : 'text-zinc-400';
            item.innerHTML = `<span class="${iconColor}">${icon}</span> <span>${esc(t.text)}</span>`;
            list.appendChild(item);
        });
        row.appendChild(list);
    } else {
        const label = document.createElement('span');
        label.className = 'text-zinc-500 dark:text-cc-dim break-all';
        label.textContent = data.label || '';
        row.appendChild(label);
    }
    group.appendChild(row);
}

function appendResult(log, data) {
    const text = (data.preview || '').trim();
    if (!text || text.length < 3) return;
    // Append into the current tool group if one exists.
    const group = log.lastElementChild?.classList.contains('tool-group') ? log.lastElementChild : log;
    const details = document.createElement('details');
    details.className = 'ml-6 py-0.5';
    const summary = document.createElement('summary');
    summary.className = 'text-zinc-400 dark:text-cc-dim cursor-pointer hover:text-zinc-500 select-none text-xs font-mono';
    summary.textContent = 'output (' + formatBytes(data.length || text.length) + ')';
    const content = document.createElement('div');
    content.className = 'md-body mt-1 text-xs text-zinc-500 dark:text-cc-mut max-h-48 overflow-y-auto bg-zinc-50 dark:bg-cc-bg rounded p-2 border border-zinc-100 dark:border-cc-line';
    content.innerHTML = marked.parse(text, { breaks: true });
    details.appendChild(summary);
    details.appendChild(content);
    group.appendChild(details);
}

function appendComplete(log, data) {
    collapseToolGroup(log);
    const div = document.createElement('div');
    div.className = 'flex items-center gap-2 py-2 mt-2 mb-1 border-t border-zinc-100 dark:border-cc-line';
    const icon = document.createElement('span');
    icon.className = 'inline-flex items-center justify-center w-4 h-4 rounded-full bg-emerald-500/15 text-emerald-500 text-xs shrink-0';
    icon.textContent = '✓';
    const msg = document.createElement('span');
    msg.className = 'text-emerald-600 dark:text-emerald-500 font-mono text-xs';
    const tokens = data.usage ? usageSummary(data.usage) : '';
    const turns = data.turns ? data.turns + ' turns' : '';
    const meta = [tokens, turns].filter(Boolean).join(' / ');
    msg.textContent = 'Turn complete' + (meta ? ' - ' + meta : '');
    div.appendChild(icon);
    div.appendChild(msg);
    log.appendChild(div);
}

// ─── Init ────────────────────────────────────────────────────
// Dark mode toggle.
document.getElementById('dark-toggle').addEventListener('click', () => {
    const html = document.documentElement;
    html.classList.toggle('dark');
    localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
    updateDarkIcons();
});

function updateDarkIcons() {
    const isDark = document.documentElement.classList.contains('dark');
    document.getElementById('icon-moon').classList.toggle('hidden', isDark);
    document.getElementById('icon-sun').classList.toggle('hidden', !isDark);
}
updateDarkIcons();

loadIndexStatus();

// Initial route.
navigate(location.pathname + location.search, false);
