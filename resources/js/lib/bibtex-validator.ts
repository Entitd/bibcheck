export type BibtexSeverity = 'error' | 'warning' | 'syntax' | 'info';

export type BibtexAnalysisError = {
    line: number;
    column: number;
    length?: number;
    severity: BibtexSeverity;
    message: string;
};

export type BibliographicCandidate = {
    title?: string | null;
    url?: string | null;
    year?: number | string | null;
    doi?: string | null;
};

export type BibtexApiCheck = {
    schema_version?: number;
    found?: boolean;
    complete?: boolean;
    status?: string;
    provider?: string | null;
    matched_by?: string | null;
    checked_at?: string;
    similarity?: number | null;
    message?: string;
    warnings?: string[];
    external_title?: string | null;
    external_data?: BibliographicCandidate | null;
    field_checks?: Record<
        string,
        { status: string; input?: unknown; external?: unknown }
    >;
    provider_checks?: { provider: string; status: string; cached?: boolean }[];
    candidates?: {
        provider: string;
        data: BibliographicCandidate;
        similarity?: number | null;
    }[];
    retraction?: {
        status: string;
        notice_url?: string | null;
        notice_type?: string;
    };
};

export type BibtexEntry = {
    type: string;
    line: number;
    fields: Record<string, string>;
    gost_errors?: BibtexAnalysisError[];
    api_check?: BibtexApiCheck;
};

export type BibtexAnalysis = {
    errors: BibtexAnalysisError[];
    entries: Record<string, BibtexEntry>;
    aggregated_metrics: Record<string, number | string>;
    course_comparison_result?: string;
    raw_content?: string;
    original_filename?: string;
    external_run?: {
        id: string;
        status: string;
        finished_entries?: number;
        total_entries?: number;
    };
};

export type BibtexTypeTemplate = {
    id: number;
    name: string;
    fields: string[];
};

type ParsedEntry = {
    type: string;
    key: string;
    fields: Record<string, string>;
    startLine: number;
};

type Header = {
    type: string;
    key: string;
    keyColumn: number;
    bodyOffset: number;
    bodyColumn: number;
    hasComma: boolean;
};

type PreparedBuffer = {
    buffer: string;
    lineMap: number[];
};

const DEFAULT_REQUIRED_FIELDS: Record<string, string[]> = {
    article: [
        'author',
        'title',
        'journal',
        'year',
        'pages',
        'volume',
        'number',
    ],
    book: ['author', 'title', 'year', 'address', 'publisher', 'pagetotal'],
    manual: ['organization', 'title', 'year'],
    misc: ['author', 'title', 'urldate', 'url'],
    online: ['author', 'title', 'urldate', 'url'],
    mvbook: ['author', 'title', 'year', 'address', 'publisher', 'pagetotal'],
    inbook: ['author', 'title', 'booktitle', 'year'],
    bookinbook: ['author', 'title', 'booktitle', 'year'],
    suppbook: ['author', 'title', 'booktitle', 'year'],
    booklet: ['author', 'title', 'year'],
    collection: ['editor', 'title', 'year'],
    mvcollection: ['editor', 'title', 'year'],
    incollection: ['author', 'title', 'booktitle', 'year'],
    suppcollection: ['author', 'title', 'booktitle', 'year'],
    patent: ['author', 'title', 'number', 'year'],
    periodical: ['editor', 'title', 'year'],
    suppperiodical: ['author', 'title', 'journal', 'year', 'pages'],
    proceedings: ['title', 'year'],
    mvproceedings: ['title', 'year'],
    inproceedings: [
        'author',
        'title',
        'booktitle',
        'year',
        'pages',
        'organization',
    ],
    reference: ['editor', 'title', 'year'],
    mvreference: ['editor', 'title', 'year'],
    inreference: ['author', 'title', 'booktitle', 'year'],
    report: ['author', 'title', 'type', 'institution', 'year'],
    thesis: ['author', 'title', 'type', 'institution', 'year'],
    unpublished: ['author', 'title', 'year'],
    mastersthesis: ['author', 'title', 'institution', 'year'],
    techreport: ['author', 'title', 'institution', 'year'],
    conference: [
        'author',
        'title',
        'booktitle',
        'year',
        'pages',
        'organization',
    ],
    electronic: ['author', 'title', 'urldate', 'url'],
    phdthesis: ['author', 'title', 'institution', 'year'],
    www: ['author', 'title', 'urldate', 'url'],
    school: ['author', 'title', 'institution', 'year'],
};

const LANGUAGE_FIELDS = ['language', 'langid', 'hyphenation'];

export function analyzeBibtex(
    bibText: string,
    bibtexTypes: BibtexTypeTemplate[] = [],
): BibtexAnalysis {
    const parsed = parseBibtex(bibText);
    const rules = buildRules(bibtexTypes);
    const entries: Record<string, BibtexEntry> = {};
    const validationErrors: BibtexAnalysisError[] = [];

    parsed.entries.forEach((entry) => {
        const gostErrors = validateEntry(entry, rules);
        validationErrors.push(...gostErrors);

        entries[entry.key] = {
            type: entry.type,
            line: entry.startLine,
            fields: entry.fields,
            gost_errors: gostErrors,
        };
    });

    const aggregatedMetrics = calculateMetrics(parsed.entries);

    return {
        errors: [...parsed.errors, ...validationErrors],
        entries,
        aggregated_metrics: aggregatedMetrics,
        course_comparison_result: generateVerdict(aggregatedMetrics),
        raw_content: bibText,
    };
}

function buildRules(bibtexTypes: BibtexTypeTemplate[]) {
    const rules = { ...DEFAULT_REQUIRED_FIELDS };

    bibtexTypes.forEach((type) => {
        const name = type.name.trim().toLowerCase();

        if (name) {
            rules[name] = type.fields
                .map((field) => field.trim().toLowerCase())
                .filter(Boolean);
        }
    });

    return rules;
}

function parseBibtex(text: string): {
    errors: BibtexAnalysisError[];
    entries: ParsedEntry[];
} {
    const rawBlocks = splitIntoBlocks(text);
    const usedKeys = new Map<string, number>();
    const errors: BibtexAnalysisError[] = [];
    const entries: ParsedEntry[] = [];

    rawBlocks.forEach((block) => {
        const report = parseEntry(block, usedKeys);
        errors.push(...report.errors);

        if (report.entry) {
            entries.push(report.entry);
        }
    });

    return { errors, entries };
}

function splitIntoBlocks(text: string) {
    const lines = text.split(/\r\n|\r|\n/);
    const records: Array<Array<{ line: number; text: string }>> = [];
    let currentRecord: Array<{ line: number; text: string }> | null = null;

    lines.forEach((line, index) => {
        const trimmedLine = line.trim();

        if (
            trimmedLine === '' ||
            trimmedLine.startsWith('%') ||
            trimmedLine.toLowerCase().startsWith('@comment')
        ) {
            return;
        }

        if (trimmedLine.startsWith('@')) {
            currentRecord = [];
            records.push(currentRecord);
        }

        if (currentRecord) {
            currentRecord.push({ line: index + 1, text: line });
        }
    });

    return records;
}

function parseEntry(
    recordLines: Array<{ line: number; text: string }>,
    usedKeys: Map<string, number>,
): { errors: BibtexAnalysisError[]; entry: ParsedEntry | null } {
    if (!recordLines.length) {
        return { errors: [], entry: null };
    }

    const firstLine = recordLines[0];
    const header = extractHeader(firstLine.text, firstLine.line);

    if ('error' in header) {
        return { errors: [header.error], entry: null };
    }

    const errors: BibtexAnalysisError[] = [];

    if (usedKeys.has(header.key)) {
        errors.push({
            severity: 'error',
            message: `Дублирующийся ключ записи '${header.key}'. Ранее использован на строке ${usedKeys.get(header.key)}.`,
            line: firstLine.line,
            column: header.keyColumn,
            length: header.key.length,
        });
    } else {
        usedKeys.set(header.key, firstLine.line);
    }

    const prepared = prepareBuffer(recordLines, header, firstLine.line);
    const fieldResults = processFields(
        prepared.buffer,
        prepared.lineMap,
        firstLine.line,
    );

    if (!header.hasComma) {
        fieldResults.errors.unshift({
            severity: 'syntax',
            message: 'Пропущена запятая после ключа записи.',
            line: firstLine.line,
            column: header.bodyColumn,
            length: 1,
        });
    }

    return {
        errors: [...errors, ...fieldResults.errors],
        entry: {
            type: header.type,
            key: header.key,
            fields: fieldResults.fields,
            startLine: firstLine.line,
        },
    };
}

function extractHeader(
    line: string,
    lineNumber: number,
): Header | { error: BibtexAnalysisError } {
    let position = 0;

    position = skipWhitespace(line, position);

    if (line[position] !== '@') {
        return makeHeaderError(line, lineNumber);
    }

    position += 1;
    const typeStart = position;

    while (position < line.length && isIdentifierChar(line[position])) {
        position += 1;
    }

    const type = line.slice(typeStart, position).toLowerCase();

    if (!type) {
        return makeHeaderError(line, lineNumber);
    }

    position = skipWhitespace(line, position);

    if (line[position] !== '{') {
        return makeHeaderError(line, lineNumber);
    }

    position += 1;
    position = skipWhitespace(line, position);

    const keyStart = position;

    while (position < line.length && ![',', '}'].includes(line[position])) {
        position += 1;
    }

    const key = line.slice(keyStart, position).trim();

    if (!key) {
        return makeHeaderError(line, lineNumber);
    }

    const hasComma = line[position] === ',';
    const bodyOffset = hasComma ? position + 1 : position;
    const bodyColumn = hasComma ? bodyOffset + 1 : position + 1;

    return {
        type,
        key,
        keyColumn: keyStart + 1,
        bodyOffset,
        bodyColumn,
        hasComma,
    };
}

function makeHeaderError(line: string, lineNumber: number) {
    return {
        error: {
            severity: 'error' as const,
            message: "Неверный формат заголовка. Ожидается '@type{key,'",
            line: lineNumber,
            column: 1,
            length: Math.max(line.length, 1),
        },
    };
}

function prepareBuffer(
    recordLines: Array<{ line: number; text: string }>,
    header: Header,
    firstLineNumber: number,
): PreparedBuffer {
    let buffer = '';
    const lineMap: number[] = [];

    recordLines.forEach(({ line, text }) => {
        const lineText =
            line === firstLineNumber ? text.slice(header.bodyOffset) : text;
        const startPosition = buffer.length;

        buffer += `${lineText}\n`;

        for (let index = startPosition; index < buffer.length; index += 1) {
            lineMap[index] = line;
        }
    });

    return { buffer, lineMap };
}

function processFields(buffer: string, lineMap: number[], defaultLine: number) {
    const errors: BibtexAnalysisError[] = [];
    const fields: Record<string, string> = {};
    let position = 0;

    while (position < buffer.length) {
        position = skipWhitespace(buffer, position);

        if (position >= buffer.length || buffer[position] === '}') {
            break;
        }

        if (buffer[position] === ',') {
            position += 1;
            continue;
        }

        const fieldOffset = position;

        if (!isIdentifierChar(buffer[position])) {
            errors.push(
                makeSyntaxError(
                    buffer,
                    lineMap,
                    defaultLine,
                    fieldOffset,
                    'Неожиданный символ при разборе поля.',
                    1,
                ),
            );
            position += 1;
            continue;
        }

        const readField = readIdentifier(buffer, position);
        const fieldName = readField.value.toLowerCase();
        position = skipWhitespace(buffer, readField.position);

        if (buffer[position] !== '=') {
            errors.push(
                makeSyntaxError(
                    buffer,
                    lineMap,
                    defaultLine,
                    position,
                    `После имени поля '${fieldName}' ожидается '='.`,
                    1,
                ),
            );
            position = recoverToNextFieldBoundary(buffer, position);
            continue;
        }

        position += 1;
        position = skipWhitespace(buffer, position);

        const valueResult = readFieldValue(buffer, position);
        position = valueResult.position;

        if (valueResult.error) {
            errors.push(
                makeSyntaxError(
                    buffer,
                    lineMap,
                    defaultLine,
                    fieldOffset,
                    valueResult.error,
                    Math.max(fieldName.length, 1),
                ),
            );
            position = recoverToNextFieldBoundary(buffer, position);
            continue;
        }

        if (Object.hasOwn(fields, fieldName)) {
            errors.push(
                makeSyntaxError(
                    buffer,
                    lineMap,
                    defaultLine,
                    fieldOffset,
                    `Поле '${fieldName}' объявлено повторно.`,
                    fieldName.length,
                ),
            );
            position = consumeSeparator(buffer, position);
            continue;
        }

        fields[fieldName] = sanitizeFieldValue(valueResult.value);

        const separatorPosition = skipWhitespace(buffer, position);
        const separator = buffer[separatorPosition];

        if (separator === ',') {
            position = separatorPosition + 1;
            continue;
        }

        if (separator === '}' || separator === undefined) {
            position = separatorPosition;
            continue;
        }

        errors.push(
            makeSyntaxError(
                buffer,
                lineMap,
                defaultLine,
                separatorPosition,
                `После поля '${fieldName}' ожидается запятая.`,
                1,
            ),
        );
        position = separatorPosition;
    }

    return { errors, fields };
}

function readFieldValue(buffer: string, position: number) {
    if (position >= buffer.length) {
        return {
            value: '',
            position,
            error: 'После "=" ожидается значение поля.',
        };
    }

    if (buffer[position] === '{') {
        return readBraceValue(buffer, position);
    }

    if (buffer[position] === '"') {
        return readQuotedValue(buffer, position);
    }

    return readBareValue(buffer, position);
}

function readBraceValue(buffer: string, position: number) {
    const start = position;
    let depth = 0;

    while (position < buffer.length) {
        const char = buffer[position];

        if (char === '{') {
            depth += 1;
        } else if (char === '}') {
            depth -= 1;

            if (depth === 0) {
                position += 1;

                return {
                    value: buffer.slice(start, position),
                    position,
                    error: null,
                };
            }
        }

        position += 1;
    }

    return {
        value: buffer.slice(start),
        position,
        error: 'У значения в фигурных скобках нет закрывающей скобки.',
    };
}

function readQuotedValue(buffer: string, position: number) {
    const start = position;
    let escaped = false;

    position += 1;

    while (position < buffer.length) {
        const char = buffer[position];

        if (escaped) {
            escaped = false;
            position += 1;
            continue;
        }

        if (char === '\\') {
            escaped = true;
            position += 1;
            continue;
        }

        if (char === '"') {
            position += 1;

            return {
                value: buffer.slice(start, position),
                position,
                error: null,
            };
        }

        position += 1;
    }

    return {
        value: buffer.slice(start),
        position,
        error: 'У значения в кавычках нет закрывающей кавычки.',
    };
}

function readBareValue(buffer: string, position: number) {
    const start = position;

    while (position < buffer.length) {
        const char = buffer[position];

        if (char === ',' || char === '}' || char === '\n' || char === '\r') {
            break;
        }

        position += 1;
    }

    const value = buffer.slice(start, position).trimEnd();

    if (!value) {
        return {
            value: '',
            position,
            error: 'После "=" ожидается значение поля.',
        };
    }

    return { value, position, error: null };
}

function sanitizeFieldValue(value: string) {
    const trimmed = value.trim();

    if (!trimmed) {
        return '';
    }

    if (
        (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
        (trimmed.startsWith('"') && trimmed.endsWith('"'))
    ) {
        return trimmed.slice(1, -1).trim();
    }

    return trimmed;
}

function validateEntry(
    entry: ParsedEntry,
    rules: Record<string, string[]>,
): BibtexAnalysisError[] {
    const entryRules = rules[entry.type];
    const errors: BibtexAnalysisError[] = [];

    if (!entryRules) {
        return [
            {
                severity: 'warning',
                message: `Неизвестный тип записи '@${entry.type}'.`,
                line: entry.startLine,
                column: 1,
                length: entry.type.length + 1,
            },
        ];
    }

    entryRules.forEach((field) => {
        if (!Object.hasOwn(entry.fields, field)) {
            errors.push({
                severity: 'error',
                message: `У '@${entry.type}' отсутствует обязательное поле '${field}'.`,
                line: entry.startLine,
                column: 1,
                length: Math.max(entry.type.length + 1, 1),
            });
        }
    });

    if (!LANGUAGE_FIELDS.some((field) => Object.hasOwn(entry.fields, field))) {
        errors.push({
            severity: 'info',
            message: "Для ГОСТ рекомендуется добавить 'language' или 'langid'.",
            line: entry.startLine,
            column: 1,
            length: 1,
        });
    }

    Object.keys(entry.fields).forEach((field) => {
        if (!entryRules.includes(field) && !LANGUAGE_FIELDS.includes(field)) {
            errors.push({
                severity: 'info',
                message: `Поле '${field}' не стандартно для '@${entry.type}'.`,
                line: entry.startLine,
                column: 1,
                length: 1,
            });
        }
    });

    return errors;
}

function calculateMetrics(entries: ParsedEntry[]) {
    return {
        totalQuantity: entries.length,
        amountOfLiteratureInForeignLanguages: entries.reduce(
            (total, entry) => total + isForeignLanguage(entry.fields),
            0,
        ),
        numberOfCurrentScientificPeriodicals: entries.filter((entry) =>
            ['article', 'inproceedings', 'incollection'].includes(entry.type),
        ).length,
        Literature21Century: entries.filter((entry) => {
            const year = Number(
                String(entry.fields.year ?? '').replace(/[^0-9]/g, ''),
            );

            return Number.isFinite(year) && year >= 2001;
        }).length,
    };
}

function isForeignLanguage(fields: Record<string, string>) {
    const hyphenation = (fields.hyphenation ?? '').toLowerCase();
    const title = fields.title ?? '';

    if (['russian', 'russia', 'rus'].includes(hyphenation)) {
        return 0;
    }

    if (!title) {
        return 0;
    }

    if (/[а-яё]/iu.test(title)) {
        return 0;
    }

    return hyphenation ? 1 : 0;
}

function generateVerdict(metrics: Record<string, number | string>) {
    const total = Number(metrics.totalQuantity ?? 0);

    if (total === 0) {
        return 'Не удалось проанализировать ни одной записи.';
    }

    const foreign = Number(metrics.amountOfLiteratureInForeignLanguages ?? 0);
    const periodicals = Number(
        metrics.numberOfCurrentScientificPeriodicals ?? 0,
    );
    const modern = Number(metrics.Literature21Century ?? 0);
    const issues: string[] = [];

    if (total < 10) {
        issues.push(`мало источников (${total}, рекомендуется от 10)`);
    }

    if (foreign === 0) {
        issues.push('нет источников на иностранных языках');
    }

    if (periodicals === 0) {
        issues.push('нет научных периодических изданий');
    }

    if (modern === 0) {
        issues.push('нет источников XXI века');
    }

    if (!issues.length) {
        return `Полностью соответствует требованиям. Источников: ${total}, иностранных: ${foreign}, периодика: ${periodicals}, современные: ${modern}.`;
    }

    return `Не соответствует требованиям кафедры: ${issues.join(', ')}.`;
}

function makeSyntaxError(
    buffer: string,
    lineMap: number[],
    defaultLine: number,
    offset: number,
    message: string,
    length = 1,
): BibtexAnalysisError {
    const safeOffset = Math.max(offset, 0);

    return {
        severity: 'syntax',
        message,
        line: lineMap[safeOffset] ?? defaultLine,
        column: calculateColumn(buffer, safeOffset),
        length: Math.max(length, 1),
    };
}

function calculateColumn(buffer: string, offset: number) {
    const safeOffset = Math.min(Math.max(offset, 0), buffer.length);
    const lineStartPosition = buffer.lastIndexOf('\n', safeOffset - 1) + 1;

    return safeOffset - lineStartPosition + 1;
}

function skipWhitespace(buffer: string, position: number) {
    while (position < buffer.length && /\s/.test(buffer[position])) {
        position += 1;
    }

    return position;
}

function readIdentifier(buffer: string, position: number) {
    const start = position;

    while (position < buffer.length && isIdentifierChar(buffer[position])) {
        position += 1;
    }

    return {
        value: buffer.slice(start, position),
        position,
    };
}

function isIdentifierChar(char?: string) {
    return Boolean(char && /[A-Za-z0-9_-]/.test(char));
}

function recoverToNextFieldBoundary(buffer: string, position: number) {
    while (position < buffer.length) {
        if ([',', '\n', '\r', '}'].includes(buffer[position])) {
            return position;
        }

        position += 1;
    }

    return position;
}

function consumeSeparator(buffer: string, position: number) {
    position = skipWhitespace(buffer, position);

    return buffer[position] === ',' ? position + 1 : position;
}
