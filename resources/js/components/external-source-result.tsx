import type { BibtexApiCheck } from '@/lib/bibtex-validator';

const labels: Record<string, string> = {
    queued: 'В очереди',
    checking: 'Проверяется',
    verified: 'Подтверждено',
    mismatch: 'Есть расхождения',
    partial_match: 'Частичное подтверждение',
    ambiguous: 'Неоднозначный результат',
    not_found: 'Не найдено',
    not_checkable: 'Нет подходящей проверки',
    incomplete: 'Проверка не завершена',
    invalid_input: 'Некорректный ввод',
};

function safeLink(url?: string | null) {
    return url && /^https?:\/\//i.test(url) ? url : undefined;
}

function display(value: unknown): string {
    return value == null
        ? 'Нет данных'
        : Array.isArray(value)
          ? value.map(display).join(', ')
          : typeof value === 'object'
            ? Object.values(value).map(display).join(' ')
            : String(value);
}

export default function ExternalSourceResult({
    entryKey,
    check,
}: {
    entryKey: string;
    check?: BibtexApiCheck;
}) {
    const historical = check && check.schema_version !== 2;
    const verified = !historical && check?.status === 'verified';
    const url = safeLink(check?.external_data?.url);
    return (
        <div
            className={`rounded-2xl border p-4 ${verified ? 'border-emerald-200 bg-emerald-50' : 'border-border bg-card'}`}
        >
            <div className="text-sm font-semibold">
                {entryKey}:{' '}
                {historical
                    ? 'Исторический результат OpenAlex'
                    : check?.nearest_match
                      ? 'Совпадение не подтверждено'
                      : (labels[check?.status ?? 'queued'] ?? check?.status)}
            </div>
            <p className="mt-2 text-sm">{check?.message}</p>
            {check?.nearest_match && (
                <p className="mt-2 text-sm">
                    Ниже показан наиболее похожий найденный источник. Он может
                    относиться к другой публикации — проверьте его вручную.
                </p>
            )}
            {check?.warnings?.map((warning) => (
                <p key={warning} className="mt-1 text-xs">
                    {warning}
                </p>
            ))}
            <p className="mt-1 text-xs text-muted-foreground">
                {check?.provider}{' '}
                {check?.matched_by
                    ? ` · ${check.nearest_match ? 'Ближайший по названию' : `По ${check.matched_by}`}`
                    : ''}{' '}
                {check?.checked_at
                    ? ` · ${new Date(check.checked_at).toLocaleString()}`
                    : ''}
            </p>
            {check?.complete === false && (
                <p className="mt-1 text-xs">Часть проверки не выполнена.</p>
            )}
            {check?.external_title && !url && (
                <p className="mt-2 text-sm font-medium">
                    {check.external_title}
                </p>
            )}
            {url && (
                <a
                    className="mt-2 block text-sm underline"
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {check?.external_title ?? 'Найденная запись'}
                </a>
            )}
            {typeof check?.similarity === 'number' && (
                <p className="mt-1 text-xs">
                    Сходство названий: {Math.round(check.similarity)}%
                </p>
            )}
            {check?.field_checks &&
                Object.keys(check.field_checks).length > 0 && (
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr>
                                    <th>Поле</th>
                                    <th>В записи</th>
                                    <th>В базе</th>
                                    <th>Результат</th>
                                </tr>
                            </thead>
                            <tbody>
                                {Object.entries(check.field_checks).map(
                                    ([field, item]) => (
                                        <tr key={field}>
                                            <td className="py-1 pr-2">
                                                {field}
                                            </td>
                                            <td className="pr-2">
                                                {display(item.input)}
                                            </td>
                                            <td className="pr-2">
                                                {display(item.external)}
                                            </td>
                                            <td>
                                                {item.status === 'equal'
                                                    ? 'Совпадает'
                                                    : item.status ===
                                                        'different'
                                                      ? 'Расхождение'
                                                      : 'Нет данных'}
                                            </td>
                                        </tr>
                                    ),
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            {check?.status === 'ambiguous' &&
                check.candidates?.map((candidate, index) => (
                    <p className="mt-2 text-xs" key={index}>
                        {candidate.provider}:{' '}
                        {safeLink(candidate.data.url) ? (
                            <a
                                href={safeLink(candidate.data.url)}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline"
                            >
                                {candidate.data.title}
                            </a>
                        ) : (
                            candidate.data.title
                        )}{' '}
                        ({candidate.data.year ?? 'год неизвестен'})
                    </p>
                ))}
            {check?.provider_checks && (
                <p className="mt-2 text-xs text-muted-foreground">
                    Базы:{' '}
                    {check.provider_checks
                        .map(
                            (item) =>
                                `${item.provider}: ${item.status}${item.cached ? ' (кеш)' : ''}`,
                        )
                        .join('; ')}
                </p>
            )}
            {check?.retraction?.status === 'retracted' ? (
                <p className="mt-2 text-sm text-rose-700">
                    Публикация отозвана.{' '}
                    {safeLink(check.retraction.notice_url) && (
                        <a
                            className="underline"
                            href={safeLink(check.retraction.notice_url)}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Уведомление
                        </a>
                    )}
                </p>
            ) : check?.retraction?.status === 'no_notice_found' ? (
                <p className="mt-2 text-xs">Сведений об отзыве не найдено.</p>
            ) : null}
        </div>
    );
}
