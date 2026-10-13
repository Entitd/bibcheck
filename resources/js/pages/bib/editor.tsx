import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    DatabaseZap,
    Save,
    TriangleAlert,
    Upload,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ExternalSourceResult from '@/components/external-source-result';
import AppLayout from '@/layouts/app-layout';
import { analyzeBibtex } from '@/lib/bibtex-validator';
import type {
    BibtexAnalysis,
    BibtexAnalysisError,
    BibtexEntry,
    BibtexTypeTemplate,
} from '@/lib/bibtex-validator';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Редактор BibLaTeX',
        href: '/',
    },
];

type PageProps = {
    flash?: {
        success?: string;
        error?: string;
    };
};

type ExternalRun = {
    id: string;
    status: string;
    finished_entries?: number;
    total_entries?: number;
    analysis?: BibtexAnalysis;
};

async function externalRequest(
    url: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<ExternalRun> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const payload = await response.json();
    if (!response.ok)
        throw new Error(
            Object.values(payload.errors ?? {})
                .flat()
                .join(' ') ||
                payload.message ||
                'Не удалось выполнить проверку.',
        );
    return payload.data;
}

type BibEditorProps = {
    analysis?: BibtexAnalysis | null;
    externalCheckAvailable?: boolean;
    entries?: Record<string, BibtexEntry>;
    metrics?: Record<string, number | string>;
    errors?: BibtexAnalysisError[];
    bibtexTypes?: BibtexTypeTemplate[];
    checkHistory?: {
        id: number;
        filename: string;
        created_at: string;
    } | null;
};

function escapeHtml(text: string) {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function toErrorsMap(errors: BibtexAnalysisError[]) {
    return errors.reduce<Record<number, BibtexAnalysisError[]>>(
        (accumulator, error) => {
            if (!accumulator[error.line]) {
                accumulator[error.line] = [];
            }
            accumulator[error.line].push(error);
            return accumulator;
        },
        {},
    );
}

function isBlockingError(error: BibtexAnalysisError) {
    return error.severity === 'error' || error.severity === 'syntax';
}

function isPositiveVerdict(verdict?: string) {
    if (!verdict) {
        return false;
    }

    const normalized = verdict.toLowerCase();

    return (
        normalized.includes('соответствует') && !normalized.startsWith('не ')
    );
}

function getTypeTrigger(value: string, caretPosition: number) {
    const beforeCaret = value.slice(0, caretPosition);
    const match = beforeCaret.match(/(^|\s)(@[a-z_]*)$/i);

    if (!match) {
        return null;
    }

    const token = match[2];
    const query = token.slice(1).toLowerCase();

    return {
        query,
        start: beforeCaret.length - token.length,
        end: beforeCaret.length,
    };
}

function buildBibtexSnippet(type: BibtexTypeTemplate) {
    const key = `${type.name}_key`;
    const fields = type.fields.map((field) => `  ${field} = {}`).join(',\n');
    const snippet = fields.length
        ? `@${type.name}{${key},\n${fields}\n}`
        : `@${type.name}{${key}\n}`;

    return {
        snippet,
        keyStart: type.name.length + 2,
        keyEnd: type.name.length + 2 + key.length,
    };
}

export default function BibEditorPage({
    analysis,
    bibtexTypes = [],
    checkHistory,
    externalCheckAvailable = true,
}: BibEditorProps) {
    const page = usePage<PageProps>();
    const flash = page.props.flash;
    const [liveAnalysis, setLiveAnalysis] = useState<BibtexAnalysis>(
        () => analysis ?? analyzeBibtex('', bibtexTypes),
    );
    const [liveErrors, setLiveErrors] = useState<BibtexAnalysisError[]>(
        () => liveAnalysis.errors,
    );
    const [externalProcessing, setExternalProcessing] = useState(false);
    const [externalRun, setExternalRun] = useState<ExternalRun | null>(
        analysis?.external_run ?? null,
    );
    const [externalError, setExternalError] = useState('');
    const [snapshotChanged, setSnapshotChanged] = useState(false);
    const uploadForm = useForm({
        bib_file: null as File | null,
    });
    const updateForm = useForm({
        bib_content: analysis?.raw_content ?? '',
        original_filename: analysis?.original_filename ?? 'created_file.bib',
    });
    const contentRef = useRef(updateForm.data.bib_content);
    contentRef.current = updateForm.data.bib_content;
    const runActive =
        externalRun?.status === 'pending' || externalRun?.status === 'running';

    useEffect(() => {
        setExternalRun(analysis?.external_run ?? null);
    }, [analysis]);

    useEffect(() => {
        if (externalRun?.analysis)
            setSnapshotChanged(
                externalRun.analysis.raw_content !==
                    updateForm.data.bib_content,
            );
    }, [externalRun?.analysis, updateForm.data.bib_content]);

    useEffect(() => {
        if (!externalRun?.id || !runActive) return;
        const controller = new AbortController();
        let busy = false;
        const poll = async () => {
            if (busy) return;
            busy = true;
            try {
                const run = await externalRequest(
                    `/bibliography/external-checks/${externalRun.id}`,
                    undefined,
                    controller.signal,
                );
                setExternalRun(run);
                setExternalError('');
                if (run.analysis?.raw_content === contentRef.current) {
                    setLiveAnalysis(run.analysis);
                    setLiveErrors(run.analysis.errors);
                    setSnapshotChanged(false);
                } else {
                    setSnapshotChanged(true);
                }
            } catch (error) {
                if (!controller.signal.aborted)
                    setExternalError(
                        error instanceof Error
                            ? error.message
                            : 'Ошибка получения прогресса.',
                    );
            } finally {
                busy = false;
            }
        };
        void poll();
        const timer = window.setInterval(() => void poll(), 2500);
        return () => {
            controller.abort();
            window.clearInterval(timer);
        };
    }, [externalRun?.id, runActive]);

    const startExternalCheck = async () => {
        setExternalProcessing(true);
        setExternalError('');
        setSnapshotChanged(false);
        try {
            const run = await externalRequest(
                '/bibliography/external-checks',
                updateForm.data,
            );
            setExternalRun(run);
        } catch (error) {
            setExternalError(
                error instanceof Error
                    ? error.message
                    : 'Не удалось запустить проверку.',
            );
        } finally {
            setExternalProcessing(false);
        }
    };

    const setEditorData = updateForm.setData;
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    const lineNumbersRef = useRef<HTMLDivElement | null>(null);
    const contentViewerRef = useRef<HTMLPreElement | null>(null);
    const pendingSelectionRef = useRef<{ start: number; end: number } | null>(
        null,
    );
    const [activeSuggestionIndex, setActiveSuggestionIndex] = useState(0);
    const [typeTrigger, setTypeTrigger] = useState<{
        query: string;
        start: number;
        end: number;
    } | null>(null);

    const runLocalAnalysis = useCallback(
        (value: string) => {
            const nextAnalysis = analyzeBibtex(value, bibtexTypes);

            setLiveAnalysis(nextAnalysis);
            setLiveErrors(nextAnalysis.errors);
        },
        [bibtexTypes],
    );

    useEffect(() => {
        const nextContent = analysis?.raw_content ?? '';
        const nextAnalysis =
            analysis ?? analyzeBibtex(nextContent, bibtexTypes);

        setLiveAnalysis(nextAnalysis);
        setLiveErrors(nextAnalysis.errors);
        setEditorData({
            bib_content: nextContent,
            original_filename:
                analysis?.original_filename ?? 'created_file.bib',
        });
    }, [analysis, bibtexTypes, setEditorData]);

    const errorsByLine = useMemo(() => toErrorsMap(liveErrors), [liveErrors]);
    const suggestedTypes = useMemo(() => {
        if (!typeTrigger) {
            return [];
        }

        const normalizedQuery = typeTrigger.query.trim();

        return bibtexTypes
            .filter((type) =>
                normalizedQuery.length === 0
                    ? true
                    : type.name.toLowerCase().startsWith(normalizedQuery),
            )
            .slice(0, 8);
    }, [bibtexTypes, typeTrigger]);
    const metricLabels: Record<string, string> = {
        totalQuantity: 'Всего источников',
        amountOfLiteratureInForeignLanguages: 'Иностранные языки',
        numberOfCurrentScientificPeriodicals: 'Научная периодика',
        Literature21Century: 'Источники XXI века',
        api_found: 'Подтверждено',
        api_not_found: 'Не найдено',
        api_errors: 'Проверка не завершена',
        verified_count: 'Подтверждено',
        mismatch_count: 'С расхождениями',
        partial_count: 'Частичное подтверждение',
        ambiguous_count: 'Неоднозначно',
        not_found_count: 'Не найдено',
        incomplete_count: 'Проверка не завершена',
        not_checkable_count: 'Нет подходящей проверки',
        invalid_input_count: 'Некорректный ввод',
        api_average_similarity: 'Средний процент совпадения',
    };
    const currentEntries = liveAnalysis.entries ?? {};
    const currentMetrics = liveAnalysis.aggregated_metrics ?? {};
    const verdict = liveAnalysis.course_comparison_result;
    const isSuccessVerdict = isPositiveVerdict(verdict);
    const hasEditorContent = updateForm.data.bib_content.trim().length > 0;
    const hasExternalResults = Object.values(currentEntries).some(
        (entry) => entry.api_check,
    );
    const errorTotal = liveErrors.filter(isBlockingError).length;
    const warningTotal = liveErrors.length - errorTotal;
    useEffect(() => {
        if (!suggestedTypes.length) {
            setActiveSuggestionIndex(0);
            return;
        }

        setActiveSuggestionIndex((currentIndex) =>
            Math.min(currentIndex, suggestedTypes.length - 1),
        );
    }, [suggestedTypes]);

    useEffect(() => {
        if (!pendingSelectionRef.current || !textareaRef.current) {
            return;
        }

        const { start, end } = pendingSelectionRef.current;
        textareaRef.current.focus();
        textareaRef.current.setSelectionRange(start, end);
        pendingSelectionRef.current = null;
    }, [updateForm.data.bib_content]);

    const applyTypeSuggestion = (type: BibtexTypeTemplate) => {
        if (!typeTrigger) {
            return;
        }

        const { snippet, keyStart, keyEnd } = buildBibtexSnippet(type);
        const nextValue =
            updateForm.data.bib_content.slice(0, typeTrigger.start) +
            snippet +
            updateForm.data.bib_content.slice(typeTrigger.end);
        const selectionStart = typeTrigger.start + keyStart;
        const selectionEnd = typeTrigger.start + keyEnd;

        pendingSelectionRef.current = {
            start: selectionStart,
            end: selectionEnd,
        };

        updateForm.setData('bib_content', nextValue);
        setTypeTrigger(null);
        analyzeText(nextValue);
    };

    const syncScroll = () => {
        if (
            !textareaRef.current ||
            !lineNumbersRef.current ||
            !contentViewerRef.current
        ) {
            return;
        }

        lineNumbersRef.current.scrollTop = textareaRef.current.scrollTop;
        contentViewerRef.current.scrollTop = textareaRef.current.scrollTop;
        contentViewerRef.current.scrollLeft = textareaRef.current.scrollLeft;
    };

    const analyzeText = (value: string) => {
        runLocalAnalysis(value);
    };

    const lines = updateForm.data.bib_content.split('\n');
    const highlightedLines = lines.map((line, index) => {
        const lineErrors = errorsByLine[index + 1] ?? [];
        if (!lineErrors.length) {
            return escapeHtml(line) || ' ';
        }

        let html = escapeHtml(line);
        [...lineErrors]
            .sort((left, right) => right.column - left.column)
            .forEach((error) => {
                const start = Math.max((error.column ?? 1) - 1, 0);
                const length = error.length || 1;
                const className = isBlockingError(error)
                    ? 'border-b-2 border-rose-500 bg-rose-200/40'
                    : 'border-b-2 border-amber-500 bg-amber-200/40';

                html =
                    html.slice(0, start) +
                    `<span class="${className}">` +
                    html.slice(start, start + length) +
                    '</span>' +
                    html.slice(start + length);
            });

        return html || ' ';
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Редактор BibLaTeX" />

            <div className="flex min-h-[calc(100svh-4rem)] flex-col gap-6 p-4 md:p-6">
                <section className="rounded-3xl border border-border bg-card p-4 shadow-sm">
                    <div className="flex flex-col gap-4">
                        <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                            <label className="inline-flex w-full flex-1 cursor-pointer items-center justify-center gap-2 rounded-2xl border border-dashed border-border bg-background px-6 py-3 text-sm font-medium text-foreground transition hover:bg-accent">
                                <Upload className="h-4 w-4" />
                                <span>Загрузить файл</span>
                                <input
                                    type="file"
                                    className="hidden"
                                    onChange={(event) => {
                                        const input = event.target;
                                        const file = event.target.files?.[0];
                                        if (!file) {
                                            return;
                                        }

                                        uploadForm.clearErrors();
                                        uploadForm.setData('bib_file', file);
                                        const reader = new FileReader();

                                        reader.onload = () => {
                                            const content =
                                                typeof reader.result ===
                                                'string'
                                                    ? reader.result
                                                    : '';

                                            updateForm.setData({
                                                bib_content: content,
                                                original_filename: file.name,
                                            });
                                            runLocalAnalysis(content);
                                            input.value = '';
                                        };

                                        reader.onerror = () => {
                                            uploadForm.setError(
                                                'bib_file',
                                                'Не удалось прочитать файл.',
                                            );
                                            input.value = '';
                                        };

                                        reader.readAsText(file);
                                    }}
                                />
                            </label>

                            {uploadForm.errors.bib_file && (
                                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                                    {uploadForm.errors.bib_file}
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={() => router.get('/')}
                                className="inline-flex w-full flex-1 cursor-pointer items-center justify-center rounded-2xl bg-foreground px-6 py-3 text-sm font-semibold text-background transition hover:opacity-90"
                            >
                                Новая проверка
                            </button>
                        </div>
                    </div>
                </section>

                <div className="flex flex-col gap-6">
                    {flash?.success && (
                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            {flash.success}
                        </div>
                    )}

                    {flash?.error && (
                        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            {flash.error}
                        </div>
                    )}

                    {externalError && (
                        <p role="alert" className="text-sm text-rose-700">
                            {externalError}
                        </p>
                    )}
                    {checkHistory && (
                        <section className="rounded-3xl border border-sky-200 bg-sky-50 p-4 shadow-sm">
                            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <div className="text-sm font-semibold text-sky-900">
                                        Просмотр сохранённой проверки
                                    </div>
                                    <div className="mt-1 text-sm text-sky-800">
                                        Файл: {checkHistory.filename}
                                    </div>
                                </div>
                                <Link
                                    href="/"
                                    className="inline-flex items-center justify-center rounded-xl bg-foreground px-4 py-2 text-sm font-semibold text-background transition hover:opacity-90"
                                >
                                    Новая проверка
                                </Link>
                            </div>
                        </section>
                    )}

                    <section className="rounded-3xl border border-border bg-card p-4 shadow-sm">
                        <div className="grid gap-6 xl:grid-cols-[1.45fr_0.85fr]">
                            <form
                                className="min-w-0"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    if (analysis) {
                                        updateForm.post('/update-bib');
                                        return;
                                    }

                                    updateForm.post('/create-bib');
                                }}
                            >
                                <div className="rounded-3xl border border-border bg-background">
                                    <div className="border-b border-border px-4 py-4">
                                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                            <div>
                                                <div className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                                    Название файла
                                                </div>
                                                <input
                                                    value={
                                                        updateForm.data
                                                            .original_filename
                                                    }
                                                    onChange={(event) =>
                                                        updateForm.setData(
                                                            'original_filename',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-2 w-full max-w-sm rounded-xl border border-input bg-card px-3 py-2 text-sm font-medium text-foreground transition outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                                    placeholder="created_file.bib"
                                                />
                                                {updateForm.errors
                                                    .original_filename && (
                                                    <div className="mt-2 text-xs text-rose-600">
                                                        {
                                                            updateForm.errors
                                                                .original_filename
                                                        }
                                                    </div>
                                                )}
                                            </div>
                                            <div className="flex flex-wrap gap-2 text-xs">
                                                <span className="rounded-full border border-rose-200 bg-rose-50 px-3 py-1.5 font-medium text-rose-700">
                                                    Ошибки: {errorTotal}
                                                </span>
                                                <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 font-medium text-amber-700">
                                                    Предупреждения:{' '}
                                                    {warningTotal}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="border-b border-border px-4 py-3 text-sm text-muted-foreground">
                                        BibTeX · {lines.length} lines
                                    </div>

                                    <div className="flex min-h-[520px] overflow-hidden">
                                        <div
                                            ref={lineNumbersRef}
                                            className="w-14 overflow-hidden border-r border-border bg-muted/30 py-4 text-right font-mono text-sm text-muted-foreground"
                                        >
                                            {lines.map((_, index) => {
                                                const lineErrors =
                                                    errorsByLine[index + 1] ??
                                                    [];
                                                const title = lineErrors
                                                    .map((item) => item.message)
                                                    .join(' | ');

                                                return (
                                                    <div
                                                        key={index}
                                                        className={`h-6 px-3 ${
                                                            lineErrors.some(
                                                                isBlockingError,
                                                            )
                                                                ? 'font-semibold text-rose-600'
                                                                : lineErrors.length
                                                                  ? 'font-semibold text-amber-600'
                                                                  : ''
                                                        }`}
                                                        title={title}
                                                    >
                                                        {index + 1}
                                                    </div>
                                                );
                                            })}
                                        </div>

                                        <div className="relative min-w-0 flex-1">
                                            <pre
                                                ref={contentViewerRef}
                                                aria-hidden="true"
                                                className="pointer-events-none absolute inset-0 overflow-hidden p-4 font-mono text-sm leading-6 whitespace-pre text-transparent"
                                                dangerouslySetInnerHTML={{
                                                    __html: highlightedLines.join(
                                                        '\n',
                                                    ),
                                                }}
                                            />
                                            <textarea
                                                ref={textareaRef}
                                                spellCheck={false}
                                                value={
                                                    updateForm.data.bib_content
                                                }
                                                onChange={(event) => {
                                                    const nextValue =
                                                        event.target.value;
                                                    updateForm.setData(
                                                        'bib_content',
                                                        nextValue,
                                                    );
                                                    setTypeTrigger(
                                                        getTypeTrigger(
                                                            nextValue,
                                                            event.target
                                                                .selectionStart ??
                                                                nextValue.length,
                                                        ),
                                                    );
                                                    analyzeText(nextValue);
                                                }}
                                                onClick={(event) =>
                                                    setTypeTrigger(
                                                        getTypeTrigger(
                                                            event.currentTarget
                                                                .value,
                                                            event.currentTarget
                                                                .selectionStart ??
                                                                event
                                                                    .currentTarget
                                                                    .value
                                                                    .length,
                                                        ),
                                                    )
                                                }
                                                onKeyUp={(event) =>
                                                    setTypeTrigger(
                                                        getTypeTrigger(
                                                            event.currentTarget
                                                                .value,
                                                            event.currentTarget
                                                                .selectionStart ??
                                                                event
                                                                    .currentTarget
                                                                    .value
                                                                    .length,
                                                        ),
                                                    )
                                                }
                                                onBlur={() => {
                                                    window.setTimeout(
                                                        () =>
                                                            setTypeTrigger(
                                                                null,
                                                            ),
                                                        120,
                                                    );
                                                }}
                                                onKeyDown={(event) => {
                                                    if (
                                                        !suggestedTypes.length
                                                    ) {
                                                        return;
                                                    }

                                                    if (
                                                        event.key ===
                                                        'ArrowDown'
                                                    ) {
                                                        event.preventDefault();
                                                        setActiveSuggestionIndex(
                                                            (currentIndex) =>
                                                                (currentIndex +
                                                                    1) %
                                                                suggestedTypes.length,
                                                        );
                                                    }

                                                    if (
                                                        event.key === 'ArrowUp'
                                                    ) {
                                                        event.preventDefault();
                                                        setActiveSuggestionIndex(
                                                            (currentIndex) =>
                                                                (currentIndex -
                                                                    1 +
                                                                    suggestedTypes.length) %
                                                                suggestedTypes.length,
                                                        );
                                                    }

                                                    if (
                                                        event.key === 'Tab' ||
                                                        event.key === 'Enter'
                                                    ) {
                                                        event.preventDefault();
                                                        applyTypeSuggestion(
                                                            suggestedTypes[
                                                                activeSuggestionIndex
                                                            ],
                                                        );
                                                    }

                                                    if (
                                                        event.key === 'Escape'
                                                    ) {
                                                        setTypeTrigger(null);
                                                    }
                                                }}
                                                onScroll={syncScroll}
                                                placeholder={`@book{key,
  title = {Название},
  author = {Автор},
  year = {2024}
}`}
                                                className="relative h-full min-h-[520px] w-full resize-none overflow-auto bg-transparent p-4 font-mono text-sm leading-6 text-foreground outline-none"
                                            />
                                            {suggestedTypes.length > 0 && (
                                                <div className="absolute right-4 bottom-4 z-20 w-full max-w-md rounded-2xl border border-border bg-background/95 p-2 shadow-xl backdrop-blur">
                                                    <div className="px-2 pb-2 text-[11px] font-medium tracking-[0.12em] text-muted-foreground uppercase">
                                                        BibTeX types from
                                                        database
                                                    </div>
                                                    <div className="space-y-1">
                                                        {suggestedTypes.map(
                                                            (type, index) => (
                                                                <button
                                                                    key={
                                                                        type.id
                                                                    }
                                                                    type="button"
                                                                    onMouseDown={(
                                                                        event,
                                                                    ) => {
                                                                        event.preventDefault();
                                                                        applyTypeSuggestion(
                                                                            type,
                                                                        );
                                                                    }}
                                                                    className={`flex w-full items-start justify-between gap-3 rounded-xl px-3 py-2 text-left transition ${
                                                                        index ===
                                                                        activeSuggestionIndex
                                                                            ? 'bg-accent text-accent-foreground'
                                                                            : 'hover:bg-muted'
                                                                    }`}
                                                                >
                                                                    <div className="min-w-0">
                                                                        <div className="font-mono text-sm font-semibold">
                                                                            @
                                                                            {
                                                                                type.name
                                                                            }
                                                                        </div>
                                                                        <div className="mt-1 text-xs text-muted-foreground">
                                                                            {type
                                                                                .fields
                                                                                .length
                                                                                ? type.fields.join(
                                                                                      ', ',
                                                                                  )
                                                                                : 'No fields configured'}
                                                                        </div>
                                                                    </div>
                                                                    <div className="shrink-0 rounded-full border border-border px-2 py-1 text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                                                        {
                                                                            type
                                                                                .fields
                                                                                .length
                                                                        }{' '}
                                                                        fields
                                                                    </div>
                                                                </button>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-4 flex flex-wrap gap-3">
                                    <button
                                        type="submit"
                                        disabled={
                                            updateForm.processing ||
                                            !hasEditorContent
                                        }
                                        className="inline-flex items-center justify-center gap-2 rounded-2xl bg-foreground px-5 py-3 text-sm font-semibold text-background transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-70"
                                    >
                                        <Save className="h-4 w-4" />
                                        Сохранить проверку
                                    </button>
                                    <button
                                        type="button"
                                        disabled={
                                            externalProcessing ||
                                            runActive ||
                                            !externalCheckAvailable ||
                                            !hasEditorContent
                                        }
                                        onClick={() =>
                                            void startExternalCheck()
                                        }
                                        className="inline-flex items-center justify-center gap-2 rounded-2xl border border-border bg-background px-5 py-3 text-sm font-semibold text-foreground transition hover:bg-accent disabled:cursor-not-allowed disabled:opacity-70"
                                    >
                                        <DatabaseZap className="h-4 w-4" />
                                        {externalProcessing || runActive
                                            ? 'Проверяем внешние базы...'
                                            : 'Проверить во внешних базах'}
                                    </button>
                                </div>
                            </form>

                            <div className="space-y-4">
                                {hasEditorContent ? (
                                    <>
                                        <section
                                            className={`rounded-2xl border p-5 ${
                                                isSuccessVerdict
                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                                                    : 'border-rose-200 bg-rose-50 text-rose-900'
                                            }`}
                                        >
                                            <div className="flex items-center gap-2 text-sm font-semibold">
                                                {isSuccessVerdict ? (
                                                    <CheckCircle2 className="h-4 w-4" />
                                                ) : (
                                                    <TriangleAlert className="h-4 w-4" />
                                                )}
                                                <span>Итог проверки</span>
                                            </div>
                                            <p className="mt-3 text-sm leading-6">
                                                {verdict}
                                            </p>
                                        </section>

                                        <section className="rounded-2xl border border-border bg-background p-5">
                                            <h2 className="text-sm font-semibold text-foreground">
                                                Метрики
                                            </h2>
                                            <div className="mt-4 space-y-3">
                                                {Object.entries(
                                                    currentMetrics,
                                                ).filter(
                                                    ([key]) =>
                                                        ![
                                                            'api_found',
                                                            'api_not_found',
                                                            'api_errors',
                                                        ].includes(key) ||
                                                        !(
                                                            'verified_count' in
                                                            currentMetrics
                                                        ),
                                                ).length ? (
                                                    Object.entries(
                                                        currentMetrics,
                                                    ).map(([key, value]) => (
                                                        <div
                                                            key={key}
                                                            className="flex items-center justify-between gap-3 border-b border-border pb-3 text-sm last:border-b-0 last:pb-0"
                                                        >
                                                            <span className="text-muted-foreground">
                                                                {metricLabels[
                                                                    key
                                                                ] ?? key}
                                                            </span>
                                                            <span className="font-semibold text-foreground">
                                                                {value}
                                                                {key ===
                                                                'api_average_similarity'
                                                                    ? '%'
                                                                    : ''}
                                                            </span>
                                                        </div>
                                                    ))
                                                ) : (
                                                    <p className="text-sm text-muted-foreground">
                                                        Метрики пока недоступны.
                                                    </p>
                                                )}
                                            </div>
                                        </section>

                                        <section className="rounded-2xl border border-border bg-background p-5">
                                            <h2 className="text-sm font-semibold text-foreground">
                                                Проверка источников
                                            </h2>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Проверяются существование
                                                публикации и библиографические
                                                данные. Наличие записи не
                                                подтверждает научные выводы.
                                            </p>
                                            {snapshotChanged && (
                                                <p
                                                    role="status"
                                                    className="mt-3 text-sm"
                                                >
                                                    Проверяется предыдущая
                                                    версия текста. Результаты
                                                    сохранены в истории.
                                                </p>
                                            )}
                                            {externalRun && (
                                                <p
                                                    role="status"
                                                    className="mt-3 text-sm"
                                                >
                                                    {externalRun.status ===
                                                    'cancelled'
                                                        ? 'Проверка отменена'
                                                        : `Обработано: ${externalRun.finished_entries ?? 0} из ${externalRun.total_entries ?? '…'}`}
                                                </p>
                                            )}
                                            {runActive && (
                                                <button
                                                    type="button"
                                                    className="mt-2 text-sm underline"
                                                    onClick={async () => {
                                                        try {
                                                            setExternalRun(
                                                                await externalRequest(
                                                                    `/bibliography/external-checks/${externalRun?.id}/cancel`,
                                                                    {},
                                                                ),
                                                            );
                                                        } catch (error) {
                                                            setExternalError(
                                                                error instanceof
                                                                    Error
                                                                    ? error.message
                                                                    : 'Ошибка отмены.',
                                                            );
                                                        }
                                                    }}
                                                >
                                                    Отменить проверку
                                                </button>
                                            )}
                                            <div className="mt-4 space-y-3">
                                                {hasExternalResults ? (
                                                    Object.entries(
                                                        currentEntries,
                                                    ).map(([key, entry]) => (
                                                        <ExternalSourceResult
                                                            key={key}
                                                            entryKey={key}
                                                            check={
                                                                entry.api_check
                                                            }
                                                        />
                                                    ))
                                                ) : (
                                                    <p className="text-sm text-muted-foreground">
                                                        Запустите проверку
                                                        источников в Crossref,
                                                        Open Library, DataCite и
                                                        DBLP.
                                                    </p>
                                                )}
                                            </div>
                                        </section>
                                    </>
                                ) : (
                                    <section className="flex min-h-[300px] items-center justify-center rounded-2xl border border-dashed border-border bg-card p-8 text-center text-muted-foreground">
                                        Ожидание файла для анализа...
                                    </section>
                                )}
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
