import { Link, router, usePage } from '@inertiajs/react';
import { BookOpen, FolderGit2, LayoutGrid, LayoutTemplate, ListChecks, Search, Settings, TextSearch, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
} from '@/components/ui/sidebar';
import type { NavItem, User } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

type PageProps = {
    auth: {
        user: User;
    };
    recentChecks?: {
        id: number;
        filename: string;
        formatted_date?: string;
        relative_time?: string;
        total_entries?: number;
        error_count?: number;
        warning_count?: number;
    }[];
};

export function AppSidebar() {
    const { auth, recentChecks = [] } = usePage<PageProps>().props;
    const [query, setQuery] = useState('');
    const mainNavItems: NavItem[] = useMemo(() => {
        const items: NavItem[] = [
            {
                title: 'Редактор',
                href: '/',
                icon: TextSearch,
            },
            {
                title: 'Профиль',
                href: '/profile',
                icon: Settings,
            },
        ];

        if (auth.user.role === 'admin') {
            items.push({
                title: 'Админка',
                href: '/admin',
                icon: LayoutGrid,
            });
        }

        return items;
    }, [auth.user.role]);

    const filteredChecks = recentChecks.filter((check) =>
        check.filename.toLowerCase().includes(query.toLowerCase()),
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <SidebarSeparator />
                <SidebarGroup className="px-2 pt-0">
                    <SidebarGroupLabel>История</SidebarGroupLabel>
                    <label className="relative block px-2 pb-2 group-data-[collapsible=icon]:hidden">
                        <Search className="pointer-events-none absolute left-4 top-4 h-3.5 w-3.5 -translate-y-1/2 text-sidebar-foreground/60" />
                        <input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Найти проверку"
                            className="h-8 w-full rounded-md border border-input bg-background py-1 pl-8 pr-2 text-xs outline-none transition focus:border-ring"
                        />
                    </label>
                    <SidebarMenu>
                        {filteredChecks.length ? (
                            filteredChecks.map((check) => (
                                <SidebarMenuItem key={check.id}>
                                    <SidebarMenuButton
                                        asChild
                                        className="hidden group-data-[collapsible=icon]:flex"
                                        tooltip={{ children: check.filename }}
                                    >
                                        <Link href={`/check-history/${check.id}`}>
                                            <ListChecks />
                                            <span>{check.filename}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                    <div
                                        role="button"
                                        tabIndex={0}
                                        onClick={() => router.visit(`/check-history/${check.id}`)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || event.key === ' ') {
                                                event.preventDefault();
                                                router.visit(`/check-history/${check.id}`);
                                            }
                                        }}
                                        className="mx-2 mb-2 cursor-pointer rounded-md border border-sidebar-border/70 bg-sidebar-accent/40 p-2 text-xs outline-none transition hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring group-data-[collapsible=icon]:hidden"
                                    >
                                        <div className="truncate font-medium text-sidebar-foreground">
                                            {check.filename}
                                        </div>
                                        <div className="mt-1 text-sidebar-foreground/70">
                                            {check.relative_time ?? check.formatted_date}
                                        </div>
                                        <div className="mt-2 flex items-center justify-between gap-2 text-sidebar-foreground/70">
                                            <span>{check.total_entries ?? 0} записей</span>
                                            <button
                                                type="button"
                                                onClick={(event) => {
                                                    event.stopPropagation();
                                                    router.delete(`/api/check-history/${check.id}`);
                                                }}
                                                className="inline-flex items-center gap-1 text-rose-600 transition hover:text-rose-700"
                                            >
                                                <X className="h-3 w-3" />
                                                <span>Удалить</span>
                                            </button>
                                        </div>
                                    </div>
                                </SidebarMenuItem>
                            ))
                        ) : (
                            <div className="mx-2 rounded-md border border-dashed border-sidebar-border px-3 py-4 text-xs text-sidebar-foreground/70 group-data-[collapsible=icon]:hidden">
                                История проверок пока пуста.
                            </div>
                        )}
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
