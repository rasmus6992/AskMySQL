<?php

declare(strict_types=1);

use App\Security\Csrf;

$bootstrap = require dirname(__DIR__) . '/bootstrap/app.php';
$config = $bootstrap['config'];

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; font-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

$appName = htmlspecialchars((string) $config['app']['name'], ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8');
$maxQuestionLength = (int) $config['security']['max_question_length'];
$maxRequests = (int) $config['security']['rate_limit_max'];
?>
<!doctype html>
<html lang="en" class="h-full bg-slate-950">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="description" content="Convert natural-language questions into safe MySQL SELECT queries.">
    <title><?= $appName ?> — Text to SQL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { scrollbar-width: thin; scrollbar-color: rgb(71 85 105) transparent; }
        *::-webkit-scrollbar { width: 8px; height: 8px; }
        *::-webkit-scrollbar-thumb { background: rgb(71 85 105); border-radius: 999px; }
        *::-webkit-scrollbar-track { background: transparent; }
        .glass { background: rgba(15, 23, 42, .76); backdrop-filter: blur(18px); }
        .grid-bg {
            background-image:
                linear-gradient(rgba(148,163,184,.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148,163,184,.035) 1px, transparent 1px);
            background-size: 32px 32px;
        }
    </style>
    <script src="assets/js/app.js" defer></script>
</head>
<body class="h-full overflow-x-hidden bg-slate-950 text-slate-100 antialiased">
<div class="pointer-events-none fixed inset-0 grid-bg"></div>
<div class="pointer-events-none fixed left-1/2 top-[-18rem] h-[38rem] w-[38rem] -translate-x-1/2 rounded-full bg-cyan-500/10 blur-3xl"></div>

<div class="relative min-h-full">
    <header class="border-b border-white/10 bg-slate-950/80 backdrop-blur-xl">
        <div class="mx-auto flex max-w-[1600px] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <div class="flex min-w-0 items-center gap-3">
                <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl border border-cyan-400/20 bg-cyan-400/10 shadow-lg shadow-cyan-950/40">
                    <svg class="h-6 w-6 text-cyan-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <ellipse cx="12" cy="5" rx="8" ry="3"></ellipse>
                        <path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"></path>
                        <path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"></path>
                    </svg>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="truncate text-base font-semibold tracking-tight text-white sm:text-lg"><?= $appName ?></h1>
                        <span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-300">Read only</span>
                    </div>
                    <p class="truncate text-xs text-slate-400">Natural language to validated MySQL SELECT</p>
                </div>
            </div>

            <div id="rateBadge" class="shrink-0 rounded-full border border-white/10 bg-white/5 px-3 py-2 text-xs text-slate-300" aria-live="polite">
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-slate-500"></span>
                <span class="ml-1">Checking usage…</span>
            </div>
        </div>
    </header>

    <main class="mx-auto grid max-w-[1600px] gap-5 px-4 py-5 sm:px-6 lg:grid-cols-[390px_minmax(0,1fr)] lg:px-8">
        <aside class="glass overflow-hidden rounded-3xl border border-white/10 shadow-2xl shadow-black/20 lg:sticky lg:top-5 lg:h-[calc(100vh-7.5rem)]">
            <div class="border-b border-white/10 p-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Connected schema</p>
                        <h2 id="databaseName" class="mt-1 truncate text-lg font-semibold text-white">Loading database…</h2>
                    </div>
                    <div id="connectionDot" class="h-3 w-3 shrink-0 animate-pulse rounded-full bg-amber-400 shadow-lg shadow-amber-400/30" title="Connecting"></div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-white/10 bg-white/[0.035] p-3">
                        <p class="text-[11px] uppercase tracking-wider text-slate-500">Tables</p>
                        <p id="tableCount" class="mt-1 text-xl font-semibold text-white">—</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/[0.035] p-3">
                        <p class="text-[11px] uppercase tracking-wider text-slate-500">Columns</p>
                        <p id="columnCount" class="mt-1 text-xl font-semibold text-white">—</p>
                    </div>
                </div>

                <label class="relative mt-4 block">
                    <span class="sr-only">Search schema</span>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                    </svg>
                    <input id="schemaSearch" type="search" placeholder="Search tables or columns"
                           class="w-full rounded-xl border border-white/10 bg-slate-950/60 py-2.5 pl-10 pr-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400/40 focus:ring-2 focus:ring-cyan-400/10">
                </label>
            </div>

            <div id="schemaList" class="max-h-[60vh] space-y-2 overflow-y-auto p-3 lg:max-h-[calc(100vh-23rem)]" aria-live="polite">
                <div class="space-y-3 p-2" aria-hidden="true">
                    <div class="h-20 animate-pulse rounded-2xl bg-white/5"></div>
                    <div class="h-20 animate-pulse rounded-2xl bg-white/5"></div>
                    <div class="h-20 animate-pulse rounded-2xl bg-white/5"></div>
                </div>
            </div>
        </aside>

        <section class="glass flex min-h-[75vh] min-w-0 flex-col overflow-hidden rounded-3xl border border-white/10 shadow-2xl shadow-black/20 lg:h-[calc(100vh-7.5rem)]">
            <div class="border-b border-white/10 px-5 py-4 sm:px-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Query workspace</p>
                        <h2 class="mt-1 text-lg font-semibold text-white">Ask your database a question</h2>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-slate-400">
                        <svg class="h-4 w-4 text-emerald-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"></path><path d="m9 12 2 2 4-4"></path>
                        </svg>
                        SQL safety validation enabled
                    </div>
                </div>
            </div>

            <div id="conversation" class="flex-1 space-y-5 overflow-y-auto p-5 sm:p-6" aria-live="polite">
                <article class="max-w-3xl rounded-3xl rounded-tl-lg border border-cyan-400/15 bg-cyan-400/[0.055] p-5">
                    <div class="flex gap-3">
                        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-cyan-400/10 text-cyan-300">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 3a6 6 0 0 0-6 6c0 2.2 1.2 4.1 3 5.2V18h6v-3.8A6 6 0 0 0 12 3Z"></path><path d="M9 21h6"></path><path d="M9 9h.01M15 9h.01"></path>
                            </svg>
                        </div>
                        <div>
                            <h3 class="font-semibold text-white">Ask in plain English</h3>
                            <p class="mt-1 text-sm leading-6 text-slate-300">I will generate one safe SELECT query using only the connected schema, validate it, execute it with a row cap, and show both the SQL and result.</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" class="examplePrompt rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 transition hover:border-cyan-400/30 hover:text-cyan-200">Show sales city-wise between 01-06-2026 and 05-06-2026</button>
                                <button type="button" class="examplePrompt rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 transition hover:border-cyan-400/30 hover:text-cyan-200">What are the top 5 cities by net sales?</button>
                                <button type="button" class="examplePrompt rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300 transition hover:border-cyan-400/30 hover:text-cyan-200">Show daily completed sales totals</button>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <div class="border-t border-white/10 bg-slate-950/45 p-4 sm:p-5">
                <div id="errorArea" class="mb-3 hidden rounded-xl border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-200" role="alert"></div>

                <form id="questionForm" class="rounded-2xl border border-white/10 bg-slate-950/75 p-2 shadow-xl shadow-black/20 transition focus-within:border-cyan-400/35 focus-within:ring-2 focus-within:ring-cyan-400/10">
                    <label for="questionInput" class="sr-only">Database question</label>
                    <textarea id="questionInput" name="question" rows="2" maxlength="<?= $maxQuestionLength ?>"
                              placeholder="Ask a question about the connected data…"
                              class="max-h-40 min-h-[3.5rem] w-full resize-none bg-transparent px-3 py-2 text-sm leading-6 text-white outline-none placeholder:text-slate-600 sm:text-base"></textarea>
                    <div class="flex items-center justify-between gap-3 px-2 pb-1">
                        <div class="flex items-center gap-2 text-xs text-slate-500">
                            <span id="characterCount">0 / <?= $maxQuestionLength ?></span>
                            <span class="hidden sm:inline">• Enter to submit, Shift+Enter for new line</span>
                        </div>
                        <button id="submitButton" type="submit"
                                class="inline-flex items-center gap-2 rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50">
                            <svg id="sendIcon" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="m22 2-7 20-4-9-9-4Z"></path><path d="M22 2 11 13"></path>
                            </svg>
                            <svg id="loadingIcon" class="hidden h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path>
                            </svg>
                            <span id="submitLabel">Generate SQL</span>
                        </button>
                    </div>
                </form>
                <p class="mt-2 text-center text-[11px] text-slate-600">Maximum <?= $maxRequests ?> requests per IP per configured window. Results are capped server-side.</p>
            </div>
        </section>
    </main>
</div>

<noscript>
    <div class="fixed inset-x-4 bottom-4 rounded-xl bg-rose-600 p-4 text-center text-white">JavaScript is required to use this application.</div>
</noscript>
</body>
</html>
