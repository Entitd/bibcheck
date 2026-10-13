import { usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const page = usePage();
    const showHelpButton = page.component === 'bib/editor';
    const helpSteps = [
        'Нажмите «Загрузить файл», если у вас уже есть готовый `.bib`, или вставьте записи вручную прямо в редактор.',
        'После загрузки сервис автоматически выполнит проверку структуры BibTeX и подсветит ошибки или предупреждения по строкам.',
        'Исправляйте текст в редакторе: после паузы проверка запускается повторно, а подсветка обновляется без перезагрузки страницы.',
        'Если создаёте файл с нуля, укажите имя файла и нажмите «Создать и проверить». Если редактируете существующий файл, используйте «Сохранить изменения».',
        'Справа смотрите итог проверки, сводные метрики и результаты проверки источников во внешних базах, чтобы быстро понять качество библиографии.',
    ];
    const validExample = `@book{ivanov2024,
  title = {Методы анализа данных},
  author = {Иванов И. И.},
  year = {2024},
  publisher = {Наука}
}`;
    const invalidExample = `@book{ivanov2024
  title = {Методы анализа данных}
  author = {Иванов И. И.}
}`;

    return (
        <header className="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            {showHelpButton && (
                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="outline"
                            size="sm"
                            className="shrink-0"
                        >
                            Справка
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="max-h-[85svh] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Справка по использованию</DialogTitle>
                            <DialogDescription>
                                Подробная инструкция по работе с редактором
                                BibTeX, проверкой записей и исправлением ошибок.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-6 text-sm leading-6 text-foreground">
                            <section className="space-y-3">
                                <h3 className="text-base font-semibold">
                                    Как работать с сервисом
                                </h3>
                                {helpSteps.map((step, index) => (
                                    <div key={step} className="flex gap-3">
                                        <span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-100 font-semibold text-sky-700">
                                            {index + 1}
                                        </span>
                                        <span>{step}</span>
                                    </div>
                                ))}
                            </section>

                            <section className="space-y-3">
                                <h3 className="text-base font-semibold">
                                    Пример корректной записи
                                </h3>
                                <p className="text-muted-foreground">
                                    Ниже пример простой записи `@book`. Такой
                                    формат сервис сможет разобрать и проверить:
                                </p>
                                <pre className="overflow-x-auto rounded-2xl border border-border bg-muted/40 p-4 font-mono text-xs leading-6">
                                    {validExample}
                                </pre>
                            </section>

                            <section className="space-y-3">
                                <h3 className="text-base font-semibold">
                                    Пример типичной ошибки
                                </h3>
                                <p className="text-muted-foreground">
                                    В этом примере пропущены запятая после ключа
                                    записи и разделители между полями. Такие
                                    места редактор обычно подсветит:
                                </p>
                                <pre className="overflow-x-auto rounded-2xl border border-rose-200 bg-rose-50 p-4 font-mono text-xs leading-6 text-rose-900">
                                    {invalidExample}
                                </pre>
                            </section>

                            <section className="space-y-3">
                                <h3 className="text-base font-semibold">
                                    Как читать результаты проверки
                                </h3>
                                <div className="space-y-2 text-muted-foreground">
                                    <p>
                                        `Ошибки` означают, что в записи есть
                                        проблемы, мешающие корректному разбору
                                        или проверке.
                                    </p>
                                    <p>
                                        `Предупреждения` показывают спорные или
                                        неполные места, которые стоит
                                        перепроверить вручную.
                                    </p>
                                    <p>
                                        Блок `Итог проверки` показывает общий
                                        вывод по всему файлу.
                                    </p>
                                    <p>
                                        Блок `Метрики` помогает оценить состав
                                        библиографии: количество источников,
                                        долю иностранных публикаций и другие
                                        показатели.
                                    </p>
                                    <p>
                                        Блок «Проверка источников» показывает
                                        прогресс, использованные базы и
                                        расхождения в метаданных. Ключи
                                        пользователя не нужны.
                                    </p>
                                </div>
                            </section>

                            <section className="space-y-3">
                                <h3 className="text-base font-semibold">
                                    Практический сценарий
                                </h3>
                                <div className="space-y-2 text-muted-foreground">
                                    <p>
                                        1. Загрузите файл преподавателя или
                                        черновик своей библиографии.
                                    </p>
                                    <p>
                                        2. Посмотрите, какие строки подсвечены
                                        слева по номерам.
                                    </p>
                                    <p>
                                        3. Исправьте структуру записи, например
                                        добавьте пропущенные запятые, фигурные
                                        скобки или обязательные поля.
                                    </p>
                                    <p>
                                        4. Сверьтесь с правой панелью: если
                                        ошибок стало меньше, вы движетесь в
                                        нужную сторону.
                                    </p>
                                    <p>
                                        5. После завершения проверки сохраните
                                        исправленный `.bib` файл под нужным
                                        именем.
                                    </p>
                                </div>
                            </section>
                        </div>
                    </DialogContent>
                </Dialog>
            )}
        </header>
    );
}
