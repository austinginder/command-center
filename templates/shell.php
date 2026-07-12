<?php
/**
 * SPA HTML shell - single entry point for all view routes.
 * Loads Tailwind CDN, marked.js, and app.js.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center</title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['-apple-system', '"SF Pro Text"', '"Segoe UI"', 'system-ui', 'sans-serif'],
                    mono: ['"JetBrains Mono"', '"SF Mono"', '"Fira Code"', '"Cascadia Code"', 'monospace'],
                },
                // Terminal palette shared with the commandcenter.run landing page.
                colors: {
                    cc: {
                        bg:     '#0a0e0c',
                        card:   '#0c110e',
                        panel:  '#0e1411',
                        line:   '#1f2a24',
                        line2:  '#1a231e',
                        line3:  '#24312a',
                        ink:    '#e8ece9',
                        bright: '#f2f6f3',
                        soft:   '#c9d2cc',
                        mut:    '#9aa59e',
                        dim:    '#5c665f',
                        green:  '#34d399',
                    },
                },
            }
        }
    }</script>
    <script>
    (function() {
        var t = localStorage.getItem('theme');
        if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    })();
    </script>
    <!-- Marked.js -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="min-h-screen flex flex-col bg-zinc-50 dark:bg-cc-bg text-zinc-900 dark:text-cc-ink font-sans antialiased">

<!-- Navigation -->
<nav class="sticky top-0 z-40 bg-zinc-50/90 dark:bg-cc-bg/90 backdrop-blur border-b border-zinc-200 dark:border-cc-line2">
    <div class="max-w-5xl w-full mx-auto px-4 sm:px-6 h-14 flex items-center justify-between">
        <a href="/" data-route class="flex items-center gap-2.5 text-zinc-900 dark:text-cc-bright">
            <svg class="w-7 h-7 shrink-0" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <circle cx="16" cy="16" r="14" stroke="currentColor" stroke-width="1.5"/>
                <circle cx="16" cy="16" r="9" stroke="currentColor" stroke-width="1" opacity="0.35"/>
                <circle cx="16" cy="16" r="4.5" stroke="currentColor" stroke-width="1" opacity="0.25"/>
                <path d="M16 1v3M16 28v3M1 16h3M28 16h3" stroke="currentColor" stroke-width="1" opacity="0.5"/>
                <g class="radar-sweep">
                    <path d="M16 16 L16 3 A13 13 0 0 1 24.36 6.04 Z" fill="#10b981" opacity="0.18"/>
                    <line x1="16" y1="16" x2="16" y2="3" stroke="#10b981" stroke-width="1.5"/>
                </g>
                <circle class="radar-blip" style="animation-delay:.62s" cx="21.5" cy="10.5" r="1.6" fill="#10b981"/>
                <circle cx="16" cy="16" r="1.5" fill="currentColor"/>
            </svg>
            <span class="flex flex-col justify-center gap-1 leading-none">
                <span class="font-mono text-xs font-bold tracking-[0.25em]">COMMAND CENTER</span>
                <span class="font-mono text-[10px] tracking-[0.3em] text-zinc-400 dark:text-cc-dim">SESSION INDEX</span>
            </span>
        </a>
        <div class="flex items-center gap-3">
            <a href="/usage" data-route id="nav-usage" class="text-xs font-mono text-zinc-400 dark:text-cc-dim hover:text-zinc-600 dark:hover:text-cc-mut transition-colors">USAGE</a>
            <span id="index-status" class="hidden sm:inline text-xs font-mono text-zinc-400 dark:text-cc-dim"></span>
            <button id="dark-toggle" type="button" class="text-zinc-400 dark:text-cc-dim hover:text-zinc-600 dark:hover:text-cc-mut p-1 rounded transition-colors" title="Toggle dark mode">
                <svg id="icon-moon" class="w-3.5 h-3.5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                <svg id="icon-sun" class="w-3.5 h-3.5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </button>
        </div>
    </div>
</nav>

<!-- Main Content -->
<main id="app" class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 py-6 pb-12"></main>

<script src="/assets/app.js"></script>
</body>
</html>
