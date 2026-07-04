'use strict';

(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const schemaList = document.getElementById('schemaList');
    const schemaSearch = document.getElementById('schemaSearch');
    const databaseName = document.getElementById('databaseName');
    const connectionDot = document.getElementById('connectionDot');
    const tableCount = document.getElementById('tableCount');
    const columnCount = document.getElementById('columnCount');
    const rateBadge = document.getElementById('rateBadge');
    const conversation = document.getElementById('conversation');
    const form = document.getElementById('questionForm');
    const input = document.getElementById('questionInput');
    const submitButton = document.getElementById('submitButton');
    const submitLabel = document.getElementById('submitLabel');
    const sendIcon = document.getElementById('sendIcon');
    const loadingIcon = document.getElementById('loadingIcon');
    const errorArea = document.getElementById('errorArea');
    const characterCount = document.getElementById('characterCount');

    let isLoading = false;
    let rateLimited = false;

    const createElement = (tag, className = '', text = '') => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== '') element.textContent = text;
        return element;
    };

    const readJson = async (response) => {
        const text = await response.text();
        try {
            return text ? JSON.parse(text) : {};
        } catch {
            return {
                success: false,
                message: 'The server returned an unreadable response.'
            };
        }
    };

    const showError = (message) => {
        errorArea.textContent = message;
        errorArea.classList.remove('hidden');
    };

    const clearError = () => {
        errorArea.textContent = '';
        errorArea.classList.add('hidden');
    };

    const updateRateBadge = (rate) => {
        if (!rate) return;

        rateBadge.replaceChildren();
        const dot = createElement('span', 'inline-block h-2 w-2 rounded-full');
        const label = createElement('span', 'ml-1');

        if (rate.remaining <= 0 || rate.allowed === false) {
            dot.classList.add('bg-rose-400');
            label.textContent = 'Request limit reached';
            rateBadge.className = 'shrink-0 rounded-full border border-rose-400/20 bg-rose-400/10 px-3 py-2 text-xs text-rose-200';
            rateLimited = true;
        } else {
            dot.classList.add('bg-emerald-400');
            label.textContent = `${rate.remaining} request${rate.remaining === 1 ? '' : 's'} remaining`;
            rateBadge.className = 'shrink-0 rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-2 text-xs text-emerald-200';
            rateLimited = false;
        }

        rateBadge.append(dot, label);
        submitButton.disabled = isLoading || rateLimited;
    };

    const renderSchemaError = (message) => {
        schemaList.replaceChildren();
        const box = createElement('div', 'rounded-2xl border border-rose-400/20 bg-rose-400/10 p-4 text-sm leading-6 text-rose-200', message);
        schemaList.append(box);
        databaseName.textContent = 'Connection failed';
        tableCount.textContent = '0';
        columnCount.textContent = '0';
        connectionDot.className = 'h-3 w-3 shrink-0 rounded-full bg-rose-400 shadow-lg shadow-rose-400/30';
        connectionDot.title = 'Disconnected';
    };

    const renderSchema = (tables) => {
        schemaList.replaceChildren();

        if (!Array.isArray(tables) || tables.length === 0) {
            schemaList.append(createElement('div', 'rounded-2xl border border-amber-400/20 bg-amber-400/10 p-4 text-sm text-amber-200', 'No queryable tables were found.'));
            return;
        }

        tables.forEach((table, index) => {
            const details = createElement('details', 'schema-card group rounded-2xl border border-white/10 bg-white/[0.025] transition open:bg-white/[0.045]');
            details.open = index < 2;
            const searchable = [table.name, ...(table.columns ?? []).map((column) => column.name)].join(' ').toLowerCase();
            details.dataset.search = searchable;

            const summary = createElement('summary', 'flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden');
            const left = createElement('div', 'min-w-0');
            const titleRow = createElement('div', 'flex items-center gap-2');
            const tableIcon = createElement('span', 'grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-violet-400/10 text-xs font-bold text-violet-300', 'T');
            const tableTitle = createElement('span', 'truncate text-sm font-semibold text-slate-100', table.name ?? 'Unknown table');
            titleRow.append(tableIcon, tableTitle);
            const meta = createElement('p', 'ml-9 mt-0.5 text-[11px] text-slate-500', `${table.columns?.length ?? 0} columns`);
            left.append(titleRow, meta);

            const chevron = createElement('span', 'text-slate-500 transition group-open:rotate-180', '⌄');
            summary.append(left, chevron);
            details.append(summary);

            const columnsWrap = createElement('div', 'border-t border-white/10 px-3 py-2');
            (table.columns ?? []).forEach((column) => {
                const row = createElement('div', 'flex items-start justify-between gap-3 rounded-xl px-2 py-2 text-xs hover:bg-white/[0.035]');
                const columnInfo = createElement('div', 'min-w-0');
                const nameLine = createElement('div', 'flex min-w-0 items-center gap-1.5');
                const columnName = createElement('span', 'truncate font-mono text-slate-200', column.name ?? 'unknown');
                nameLine.append(columnName);

                if (column.key === 'PRI') {
                    nameLine.append(createElement('span', 'rounded bg-amber-400/10 px-1.5 py-0.5 text-[9px] font-semibold text-amber-300', 'PK'));
                } else if (column.key === 'UNI') {
                    nameLine.append(createElement('span', 'rounded bg-blue-400/10 px-1.5 py-0.5 text-[9px] font-semibold text-blue-300', 'UQ'));
                }

                columnInfo.append(nameLine);
                const dataType = createElement('span', 'shrink-0 text-right font-mono text-[10px] uppercase text-cyan-300/80', column.type ?? '');
                row.append(columnInfo, dataType);
                columnsWrap.append(row);
            });

            if (Array.isArray(table.foreign_keys) && table.foreign_keys.length > 0) {
                const relationTitle = createElement('p', 'mt-2 border-t border-white/10 px-2 pt-3 text-[10px] font-semibold uppercase tracking-wider text-slate-500', 'Relationships');
                columnsWrap.append(relationTitle);
                table.foreign_keys.forEach((foreignKey) => {
                    columnsWrap.append(createElement(
                        'p',
                        'px-2 py-1 font-mono text-[10px] text-slate-400',
                        `${foreignKey.column} → ${foreignKey.referenced_table}.${foreignKey.referenced_column}`
                    ));
                });
            }

            details.append(columnsWrap);
            schemaList.append(details);
        });
    };

    const fetchSchema = async () => {
        try {
            const response = await fetch('api/schema.php', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            const payload = await readJson(response);

            if (!response.ok || payload.success !== true) {
                throw new Error(payload.message || 'The schema could not be loaded.');
            }

            databaseName.textContent = payload.database;
            tableCount.textContent = String(payload.stats?.tables ?? 0);
            columnCount.textContent = String(payload.stats?.columns ?? 0);
            connectionDot.className = 'h-3 w-3 shrink-0 rounded-full bg-emerald-400 shadow-lg shadow-emerald-400/30';
            connectionDot.title = 'Connected';
            renderSchema(payload.tables);
            updateRateBadge(payload.rate_limit);
        } catch (error) {
            renderSchemaError(error instanceof Error ? error.message : 'The schema could not be loaded.');
            showError('The database connection is not ready. Review .env and database setup.');
        }
    };

    const appendUserMessage = (question) => {
        const wrapper = createElement('article', 'ml-auto max-w-3xl rounded-3xl rounded-tr-lg border border-violet-400/20 bg-violet-400/10 px-5 py-4');
        const label = createElement('p', 'mb-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-violet-300', 'You');
        const text = createElement('p', 'whitespace-pre-wrap text-sm leading-6 text-slate-100', question);
        wrapper.append(label, text);
        conversation.append(wrapper);
        return wrapper;
    };

    const appendAssistantLoading = () => {
        const wrapper = createElement('article', 'max-w-full rounded-3xl rounded-tl-lg border border-cyan-400/15 bg-cyan-400/[0.045] p-5');
        const header = createElement('div', 'flex items-center gap-3');
        const spinner = createElement('div', 'h-5 w-5 animate-spin rounded-full border-2 border-cyan-300/25 border-t-cyan-300');
        const textWrap = createElement('div');
        textWrap.append(
            createElement('p', 'text-sm font-semibold text-white', 'Generating and validating SQL'),
            createElement('p', 'mt-0.5 text-xs text-slate-400', 'Reading the schema, asking the model, then applying server-side safety checks…')
        );
        header.append(spinner, textWrap);
        wrapper.append(header);
        conversation.append(wrapper);
        return wrapper;
    };

    const appendAssistantError = (wrapper, message, reference = '') => {
        wrapper.replaceChildren();
        wrapper.className = 'max-w-3xl rounded-3xl rounded-tl-lg border border-rose-400/20 bg-rose-400/[0.07] p-5';
        wrapper.append(
            createElement('p', 'text-sm font-semibold text-rose-200', 'Unable to complete this request'),
            createElement('p', 'mt-2 text-sm leading-6 text-slate-300', message)
        );
        if (reference) {
            wrapper.append(createElement('p', 'mt-3 font-mono text-[10px] text-slate-500', `Reference: ${reference}`));
        }
    };

    const valueToText = (value) => {
        if (value === null) return 'NULL';
        if (typeof value === 'object') return JSON.stringify(value);
        return String(value);
    };

    const renderResult = (wrapper, payload) => {
        wrapper.replaceChildren();
        wrapper.className = 'max-w-full rounded-3xl rounded-tl-lg border border-cyan-400/15 bg-cyan-400/[0.045] p-4 sm:p-5';

        const top = createElement('div', 'flex flex-wrap items-center justify-between gap-3');
        const statusWrap = createElement('div', 'flex items-center gap-2');
        statusWrap.append(
            createElement('span', 'grid h-7 w-7 place-items-center rounded-full bg-emerald-400/10 text-sm text-emerald-300', '✓'),
            createElement('p', 'text-sm font-semibold text-white', 'Query completed safely')
        );
        const countLabel = createElement('span', 'rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-slate-400', `${payload.result?.row_count ?? 0} row${payload.result?.row_count === 1 ? '' : 's'}`);
        top.append(statusWrap, countLabel);
        wrapper.append(top);

        const sqlSection = createElement('section', 'mt-4 overflow-hidden rounded-2xl border border-white/10 bg-slate-950/75');
        const sqlHeader = createElement('div', 'flex items-center justify-between border-b border-white/10 px-4 py-2.5');
        sqlHeader.append(createElement('span', 'text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-300', 'Generated SQL'));
        const copyButton = createElement('button', 'rounded-lg border border-white/10 px-2.5 py-1 text-[11px] text-slate-400 transition hover:border-cyan-400/30 hover:text-cyan-200', 'Copy');
        copyButton.type = 'button';
        copyButton.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(payload.sql ?? '');
                copyButton.textContent = 'Copied';
                window.setTimeout(() => { copyButton.textContent = 'Copy'; }, 1200);
            } catch {
                copyButton.textContent = 'Copy failed';
            }
        });
        sqlHeader.append(copyButton);
        const code = createElement('code', 'block overflow-x-auto whitespace-pre p-4 font-mono text-xs leading-6 text-emerald-300 sm:text-sm', payload.sql ?? '');
        sqlSection.append(sqlHeader, code);
        wrapper.append(sqlSection);

        const resultSection = createElement('section', 'mt-4 overflow-hidden rounded-2xl border border-white/10 bg-slate-950/60');
        const resultHeader = createElement('div', 'flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-4 py-3');
        resultHeader.append(createElement('span', 'text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-300', 'Query result'));
        if (payload.result?.truncated) {
            resultHeader.append(createElement('span', 'text-[11px] text-amber-300', 'Additional rows were capped by the server'));
        }
        resultSection.append(resultHeader);

        const columns = Array.isArray(payload.result?.columns) ? payload.result.columns : [];
        const rows = Array.isArray(payload.result?.rows) ? payload.result.rows : [];

        if (rows.length === 0) {
            resultSection.append(createElement('div', 'p-8 text-center text-sm text-slate-500', 'The query ran successfully but returned no rows.'));
        } else {
            const scroll = createElement('div', 'max-h-[28rem] overflow-auto');
            const table = createElement('table', 'min-w-full border-separate border-spacing-0 text-left text-xs');
            const thead = createElement('thead', 'sticky top-0 z-10 bg-slate-900');
            const headerRow = createElement('tr');
            columns.forEach((column) => {
                headerRow.append(createElement('th', 'whitespace-nowrap border-b border-r border-white/10 px-4 py-3 font-semibold text-slate-300 last:border-r-0', column));
            });
            thead.append(headerRow);

            const tbody = createElement('tbody');
            rows.forEach((row) => {
                const tr = createElement('tr', 'odd:bg-white/[0.018] hover:bg-cyan-400/[0.035]');
                columns.forEach((column) => {
                    const value = row[column];
                    const cell = createElement('td', 'max-w-md border-b border-r border-white/[0.06] px-4 py-3 align-top text-slate-300 last:border-r-0');
                    const text = createElement('span', 'block whitespace-pre-wrap break-words');
                    text.textContent = valueToText(value);
                    if (value === null) text.classList.add('italic', 'text-slate-600');
                    cell.append(text);
                    tr.append(cell);
                });
                tbody.append(tr);
            });

            table.append(thead, tbody);
            scroll.append(table);
            resultSection.append(scroll);
        }

        wrapper.append(resultSection);
    };

    const setLoading = (loading) => {
        isLoading = loading;
        submitButton.disabled = loading || rateLimited;
        submitLabel.textContent = loading ? 'Processing…' : 'Generate SQL';
        sendIcon.classList.toggle('hidden', loading);
        loadingIcon.classList.toggle('hidden', !loading);
        input.disabled = loading;
    };

    const scrollConversationToBottom = () => {
        conversation.scrollTo({ top: conversation.scrollHeight, behavior: 'smooth' });
    };

    const submitQuestion = async () => {
        const question = input.value.trim();
        if (!question || isLoading || rateLimited) {
            if (!question) showError('Enter a question about the connected database.');
            return;
        }

        clearError();
        appendUserMessage(question);
        const assistantMessage = appendAssistantLoading();
        input.value = '';
        input.dispatchEvent(new Event('input'));
        setLoading(true);
        scrollConversationToBottom();

        try {
            const response = await fetch('api/query.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ question })
            });

            const payload = await readJson(response);
            if (payload.rate_limit) updateRateBadge(payload.rate_limit);

            if (!response.ok || payload.success !== true) {
                appendAssistantError(assistantMessage, payload.message || 'The request could not be completed.', payload.reference || '');
                showError(payload.message || 'The request could not be completed.');
                return;
            }

            renderResult(assistantMessage, payload);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'A network error occurred.';
            appendAssistantError(assistantMessage, message);
            showError(message);
        } finally {
            setLoading(false);
            scrollConversationToBottom();
            if (!rateLimited) input.focus();
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void submitQuestion();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            void submitQuestion();
        }
    });

    input.addEventListener('input', () => {
        characterCount.textContent = `${input.value.length} / ${input.maxLength}`;
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
    });

    schemaSearch.addEventListener('input', () => {
        const searchTerm = schemaSearch.value.trim().toLowerCase();
        document.querySelectorAll('.schema-card').forEach((card) => {
            const matches = !searchTerm || card.dataset.search?.includes(searchTerm);
            card.classList.toggle('hidden', !matches);
            if (matches && searchTerm) card.open = true;
        });
    });

    document.querySelectorAll('.examplePrompt').forEach((button) => {
        button.addEventListener('click', () => {
            input.value = button.textContent.trim();
            input.dispatchEvent(new Event('input'));
            input.focus();
        });
    });

    void fetchSchema();
})();
