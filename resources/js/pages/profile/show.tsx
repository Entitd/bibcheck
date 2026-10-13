import { Transition } from '@headlessui/react';
import { Head, useForm, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, User } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Профиль',
        href: '/profile',
    },
];

type GuestSession = {
    expires_at: string;
    remaining_seconds: number;
    remaining_formatted: string;
    is_expiring_soon: boolean;
};

type PageProps = {
    flash?: {
        success?: string;
        error?: string;
    };
};

type ProfileProps = {
    user: User & {
        guest_session?: GuestSession | null;
    };
};

export default function ProfileShow({ user }: ProfileProps) {
    const { flash } = usePage<PageProps>().props;
    const roleLabel =
        user.role === 'admin'
            ? 'Администратор'
            : user.role === 'guest'
              ? 'Гость'
              : 'Пользователь';

    const nameForm = useForm({
        name: user.name,
    });

    const apiKeyForm = useForm({
        openalex_api_key: user.openalex_api_key ?? '',
    });

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Профиль" />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
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

                <section className="rounded-3xl border border-border bg-card p-6 shadow-sm">
                    <div className="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                                Личный кабинет
                            </div>
                            <h1 className="mt-2 text-3xl font-bold text-foreground">
                                Профиль пользователя
                            </h1>
                            <p className="mt-3 max-w-2xl text-sm leading-7 text-muted-foreground">
                                Здесь можно управлять именем и паролем. Для
                                проверки источников персональный ключ не
                                требуется.
                            </p>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-2xl border border-border bg-background px-4 py-3">
                                <div className="text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                    Роль
                                </div>
                                <div className="mt-2 font-semibold text-foreground">
                                    {roleLabel}
                                </div>
                            </div>
                            <div className="rounded-2xl border border-border bg-background px-4 py-3">
                                <div className="text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                    OpenAlex
                                </div>
                                <div className="mt-2 font-semibold text-foreground">
                                    {user.openalex_api_key
                                        ? 'Ключ подключён'
                                        : 'Ключ не задан'}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {user.is_guest && user.guest_session && (
                    <section className="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <div className="text-sm font-semibold text-amber-950">
                                    Гостевая сессия активна
                                </div>
                                <div className="mt-1 text-sm text-amber-900">
                                    До окончания осталось:{' '}
                                    {user.guest_session.remaining_formatted}.
                                    {user.guest_session.is_expiring_soon &&
                                        ' Сессия истекает меньше чем через час.'}
                                </div>
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    onClick={() =>
                                        nameForm.post('/profile/guest/extend')
                                    }
                                    className="inline-flex items-center justify-center rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-900 transition hover:bg-amber-100"
                                >
                                    Продлить сессию
                                </button>
                                <a
                                    href="/register"
                                    className="inline-flex items-center justify-center rounded-xl bg-foreground px-4 py-2 text-sm font-semibold text-background transition hover:opacity-90"
                                >
                                    Зарегистрироваться
                                </a>
                            </div>
                        </div>
                    </section>
                )}

                <div className="grid gap-6 xl:grid-cols-[1fr_1.3fr]">
                    <section className="rounded-3xl border border-border bg-card p-6 shadow-sm">
                        <div className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Данные аккаунта
                        </div>

                        <div className="mt-5 flex items-start gap-4">
                            <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-foreground text-lg font-bold text-background">
                                {user.name.slice(0, 2).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <div className="text-xl font-bold text-foreground">
                                    {user.name}
                                </div>
                                <div className="mt-1 text-sm break-all text-muted-foreground">
                                    {user.email}
                                </div>
                            </div>
                        </div>

                        <dl className="mt-6 space-y-4 text-sm">
                            <div className="flex items-center justify-between gap-4 border-b border-border pb-4">
                                <dt className="text-muted-foreground">
                                    ID пользователя
                                </dt>
                                <dd className="font-semibold text-foreground">
                                    {user.id}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-4 border-b border-border pb-4">
                                <dt className="text-muted-foreground">Email</dt>
                                <dd className="font-semibold text-foreground">
                                    {user.email}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-4 border-b border-border pb-4">
                                <dt className="text-muted-foreground">Роль</dt>
                                <dd className="font-semibold text-foreground">
                                    {roleLabel}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    OpenAlex
                                </dt>
                                <dd className="text-right font-semibold text-foreground">
                                    {user.openalex_api_key
                                        ? 'Персональный ключ подключён'
                                        : 'Используется ключ приложения'}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section className="rounded-3xl border border-border bg-card p-6 shadow-sm">
                        <div className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            OpenAlex
                        </div>
                        <h2 className="mt-2 text-2xl font-bold text-foreground">
                            API key OpenAlex
                        </h2>
                        <p className="mt-2 text-sm leading-7 text-muted-foreground">
                            Это прежняя настройка профиля. Проверка источников
                            использует настройки сервера и не обращается к этому
                            ключу.
                        </p>

                        <form
                            className="mt-6 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                apiKeyForm.put('/profile/api-key', {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <div>
                                <label
                                    htmlFor="openalex_api_key"
                                    className="mb-2 block text-sm font-medium text-foreground"
                                >
                                    OpenAlex API key
                                </label>
                                <input
                                    id="openalex_api_key"
                                    type="password"
                                    value={apiKeyForm.data.openalex_api_key}
                                    onChange={(event) =>
                                        apiKeyForm.setData(
                                            'openalex_api_key',
                                            event.target.value,
                                        )
                                    }
                                    className="w-full rounded-2xl border border-input bg-background px-4 py-3 text-sm text-foreground transition outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                    placeholder="Введите ваш API key"
                                    autoComplete="off"
                                />
                                <InputError
                                    className="mt-2"
                                    message={apiKeyForm.errors.openalex_api_key}
                                />
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <button
                                    type="submit"
                                    disabled={apiKeyForm.processing}
                                    className="inline-flex items-center justify-center rounded-xl bg-foreground px-4 py-2 text-sm font-semibold text-background transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    {user.openalex_api_key
                                        ? 'Обновить ключ'
                                        : 'Сохранить ключ'}
                                </button>

                                {user.openalex_api_key && (
                                    <button
                                        type="button"
                                        disabled={apiKeyForm.processing}
                                        onClick={() =>
                                            apiKeyForm.delete(
                                                '/profile/api-key',
                                                {
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                        className="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-70"
                                    >
                                        Удалить ключ
                                    </button>
                                )}
                            </div>

                            <Transition
                                show={apiKeyForm.recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-emerald-600">
                                    Сохранено
                                </p>
                            </Transition>
                        </form>
                    </section>
                </div>

                <section className="rounded-3xl border border-border bg-card p-6 shadow-sm">
                    <div className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                        Профиль
                    </div>
                    <h2 className="mt-2 text-2xl font-bold text-foreground">
                        Изменить имя
                    </h2>

                    <form
                        className="mt-6 space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            nameForm.put('/profile/name', {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div>
                            <label
                                htmlFor="name"
                                className="mb-2 block text-sm font-medium text-foreground"
                            >
                                Имя
                            </label>
                            <input
                                id="name"
                                value={nameForm.data.name}
                                onChange={(event) =>
                                    nameForm.setData('name', event.target.value)
                                }
                                className="w-full rounded-2xl border border-input bg-background px-4 py-3 text-sm text-foreground transition outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                            />
                            <InputError
                                className="mt-2"
                                message={nameForm.errors.name}
                            />
                        </div>

                        <div className="flex items-center gap-4">
                            <button
                                type="submit"
                                disabled={nameForm.processing}
                                className="inline-flex items-center justify-center rounded-xl bg-foreground px-4 py-2 text-sm font-semibold text-background transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-70"
                            >
                                Сохранить имя
                            </button>
                            <Transition
                                show={nameForm.recentlySuccessful}
                                enter="transition ease-in-out"
                                enterFrom="opacity-0"
                                leave="transition ease-in-out"
                                leaveTo="opacity-0"
                            >
                                <p className="text-sm text-emerald-600">
                                    Сохранено
                                </p>
                            </Transition>
                        </div>
                    </form>
                </section>

                {!user.is_guest && (
                    <section className="rounded-3xl border border-border bg-card p-6 shadow-sm">
                        <div className="text-xs font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                            Безопасность
                        </div>
                        <h2 className="mt-2 text-2xl font-bold text-foreground">
                            Изменить пароль
                        </h2>

                        <form
                            className="mt-6 grid gap-4 md:grid-cols-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                passwordForm.put('/profile/password', {
                                    preserveScroll: true,
                                    onSuccess: () => passwordForm.reset(),
                                });
                            }}
                        >
                            <div>
                                <label
                                    htmlFor="current_password"
                                    className="mb-2 block text-sm font-medium text-foreground"
                                >
                                    Текущий пароль
                                </label>
                                <input
                                    id="current_password"
                                    type="password"
                                    value={passwordForm.data.current_password}
                                    onChange={(event) =>
                                        passwordForm.setData(
                                            'current_password',
                                            event.target.value,
                                        )
                                    }
                                    className="w-full rounded-2xl border border-input bg-background px-4 py-3 text-sm text-foreground transition outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                />
                                <InputError
                                    className="mt-2"
                                    message={
                                        passwordForm.errors.current_password
                                    }
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="password"
                                    className="mb-2 block text-sm font-medium text-foreground"
                                >
                                    Новый пароль
                                </label>
                                <input
                                    id="password"
                                    type="password"
                                    value={passwordForm.data.password}
                                    onChange={(event) =>
                                        passwordForm.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                    className="w-full rounded-2xl border border-input bg-background px-4 py-3 text-sm text-foreground transition outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                />
                                <InputError
                                    className="mt-2"
                                    message={passwordForm.errors.password}
                                />
                            </div>

                            <div>
                                <label
                                    htmlFor="password_confirmation"
                                    className="mb-2 block text-sm font-medium text-foreground"
                                >
                                    Подтверждение
                                </label>
                                <input
                                    id="password_confirmation"
                                    type="password"
                                    value={
                                        passwordForm.data.password_confirmation
                                    }
                                    onChange={(event) =>
                                        passwordForm.setData(
                                            'password_confirmation',
                                            event.target.value,
                                        )
                                    }
                                    className="w-full rounded-2xl border border-input bg-background px-4 py-3 text-sm text-foreground transition outline-none focus:border-ring focus:ring-2 focus:ring-ring/30"
                                />
                                <InputError
                                    className="mt-2"
                                    message={
                                        passwordForm.errors
                                            .password_confirmation
                                    }
                                />
                            </div>

                            <div className="flex items-center gap-4 md:col-span-3">
                                <button
                                    type="submit"
                                    disabled={passwordForm.processing}
                                    className="inline-flex items-center justify-center rounded-xl bg-foreground px-4 py-2 text-sm font-semibold text-background transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-70"
                                >
                                    Обновить пароль
                                </button>
                                <Transition
                                    show={passwordForm.recentlySuccessful}
                                    enter="transition ease-in-out"
                                    enterFrom="opacity-0"
                                    leave="transition ease-in-out"
                                    leaveTo="opacity-0"
                                >
                                    <p className="text-sm text-emerald-600">
                                        Пароль обновлён
                                    </p>
                                </Transition>
                            </div>
                        </form>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
