<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppLogo from '@/components/AppLogo.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import PhTimeClock from '@/components/PhTimeClock.vue';
import { getInitials } from '@/composables/useInitials';
import type { BreadcrumbItemType } from '@/types';
import { ref, computed, onBeforeUnmount, onMounted } from 'vue';
import { usePage, usePoll, Link } from '@inertiajs/vue3';
import { User, LogOut, ChevronDown, ChevronRight, Bell, Inbox } from 'lucide-vue-next';

type IncomingItem = { id: number | string; barcode: string; subject_matter: string; action: string | null; received_at: string | null; url: string };

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const isProfileOpen = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user);
const office = computed(() => page.props.auth.office as { code: string; name: string } | null);
const initials = computed(() => getInitials(user.value?.name));
const firstName = computed(() => user.value?.name?.trim().split(' ')[0] ?? '');

// Esc closes whichever header menu is open.
const closeMenus = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        isProfileOpen.value = false;
        isBellOpen.value = false;
    }
};
onMounted(() => window.addEventListener('keydown', closeMenus));
onBeforeUnmount(() => window.removeEventListener('keydown', closeMenus));

// Notification bell: documents that arrived at the user's office and nobody there has acted on yet.
const isBellOpen = ref(false);
const incoming = computed(() => (page.props.incoming as { count: number; items: IncomingItem[] } | undefined) ?? { count: 0, items: [] });
const badgeText = computed(() => (incoming.value.count > 99 ? '99+' : String(incoming.value.count)));

// Refresh only the bell and the sidebar badges every minute; Inertia pauses polling while the tab is
// hidden and runs it asynchronously so it never cancels a form submission in progress.
usePoll(60000, { only: ['incoming', 'sidebarCounts'] });

const formatReceived = (value: string | null) => {
    if (!value) return '';
    const date = new Date(value);
    return isNaN(date.getTime())
        ? ''
        : date.toLocaleString('en-US', { timeZone: 'Asia/Manila', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
};

const formatRole = (role: string) => {
    if (!role) return 'User';
    return role.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
};
</script>

<template>
    <AppShell variant="sidebar">
        
        <!-- FIXED 100% WIDTH TOP NAVBAR (CUSTOM BLUE THEME) -->
        <header class="fixed top-0 inset-x-0 z-50 flex h-16 items-center bg-[#265f92] border-b border-white/10 shadow-sm">
            
            <!-- Static Logo Area (Perfectly matches Sidebar width) -->
            <div class="flex h-full items-center px-4 w-[var(--sidebar-width,16rem)] shrink-0 border-r border-white/10 bg-[#265f92]">
                <Link :href="route('dashboard')" class="flex items-center transition hover:opacity-80 text-white">
                    <AppLogo />
                </Link>
            </div>
            
            <!-- Navbar Content (Trigger, Breadcrumbs, Profile) -->
            <div class="flex flex-1 items-center justify-between px-4 sm:px-6 h-full">
                
                <!-- Left Side: Trigger & Breadcrumbs -->
                <div class="flex items-center gap-5">
                    <SidebarTrigger class="flex h-9 w-9 items-center justify-center rounded-lg border border-white/20 bg-white/5 text-white/80 shadow-sm transition hover:bg-white/15 hover:text-white" />
                    
                    <div class="hidden sm:flex items-center gap-2 text-sm font-medium text-white/80">
                        <!-- The last crumb is the page the user is on: shown bold and larger, not as a link. -->
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <span v-if="index === breadcrumbs.length - 1" class="text-base font-semibold text-white" aria-current="page">{{ item.title }}</span>
                            <Link v-else-if="item.href" :href="item.href" class="hover:text-white hover:underline transition">
                                {{ item.title }}
                            </Link>
                            <span v-else>{{ item.title }}</span>
                            <ChevronRight v-if="index < breadcrumbs.length - 1" class="w-4 h-4 text-white/40" />
                        </template>
                    </div>
                </div>

                <!-- Right Side: Icons & Profile -->
                <div class="flex items-center gap-4 sm:gap-6">
                    <PhTimeClock />
                    <div class="hidden sm:block h-6 w-px bg-white/20"></div>

                    <!-- Incoming Documents Bell -->
                    <div class="relative">
                        <button
                            type="button"
                            @click="isBellOpen = !isBellOpen; isProfileOpen = false"
                            title="Incoming documents"
                            class="relative flex h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-white/5 text-white/90 shadow-sm transition hover:bg-white/15 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                            :aria-label="incoming.count ? `${incoming.count} incoming document${incoming.count === 1 ? '' : 's'}` : 'No incoming documents'"
                            :aria-expanded="isBellOpen"
                            aria-haspopup="true"
                        >
                            <Bell class="h-4 w-4" aria-hidden="true" />
                            <span
                                v-if="incoming.count"
                                class="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-[#265f92]"
                                aria-hidden="true"
                            >
                                {{ badgeText }}
                            </span>
                        </button>

                        <div v-if="isBellOpen" @click="isBellOpen = false" class="fixed inset-0 z-40"></div>

                        <transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="transform opacity-0 scale-95"
                            enter-to-class="transform opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="transform opacity-100 scale-100"
                            leave-to-class="transform opacity-0 scale-95"
                        >
                            <div v-if="isBellOpen" class="absolute right-0 z-50 mt-3 w-80 origin-top-right overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)]" role="menu" aria-label="Incoming documents">
                                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/50 px-4 py-3">
                                    <span class="text-sm font-bold text-slate-800">Incoming Documents</span>
                                    <span v-if="incoming.count" class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-600">{{ incoming.count }} new</span>
                                </div>

                                <ul v-if="incoming.items.length" class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
                                    <li v-for="item in incoming.items" :key="item.id">
                                        <Link :href="item.url" @click="isBellOpen = false" class="block px-4 py-3 transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none" role="menuitem">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-mono text-xs font-bold text-emerald-600">{{ item.barcode }}</span>
                                                <span class="text-[10px] text-slate-400">{{ formatReceived(item.received_at) }}</span>
                                            </div>
                                            <p class="mt-0.5 line-clamp-2 text-xs font-medium text-slate-700">{{ item.subject_matter }}</p>
                                            <p v-if="item.action" class="mt-0.5 text-[11px] text-slate-500">{{ item.action }}</p>
                                        </Link>
                                    </li>
                                </ul>
                                <p v-else class="px-4 py-8 text-center text-sm text-slate-500">No incoming documents for your office.</p>

                                <Link
                                    :href="route('transactions.inbox')"
                                    @click="isBellOpen = false"
                                    class="flex items-center justify-center gap-2 border-t border-slate-100 py-2.5 text-xs font-semibold text-indigo-600 transition hover:bg-slate-50"
                                >
                                    <Inbox class="h-3.5 w-3.5" aria-hidden="true" /> Open Office Inbox
                                </Link>
                            </div>
                        </transition>
                    </div>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <!-- Trigger Icon (Adapted for Custom Dark Theme) -->
                        <button
                            type="button"
                            :aria-expanded="isProfileOpen"
                            aria-haspopup="menu"
                            :aria-label="`Account menu for ${user?.name ?? 'your account'}`"
                            @click="isProfileOpen = !isProfileOpen; isBellOpen = false"
                            class="flex h-9 items-center gap-2 rounded-full border border-white/30 bg-white/10 pl-0.5 pr-0.5 text-white shadow-sm transition hover:bg-white/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/70 lg:pr-3"
                        >
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/15 text-xs font-bold tracking-wide" aria-hidden="true">
                                {{ initials || '?' }}
                            </span>
                            <!-- Wide screens: the first name and an arrow, so it reads as "my account menu". -->
                            <span class="hidden max-w-[8rem] truncate text-sm font-medium lg:inline" aria-hidden="true">{{ firstName }}</span>
                            <ChevronDown class="hidden h-4 w-4 text-white/70 transition lg:block" :class="{ 'rotate-180': isProfileOpen }" aria-hidden="true" />
                        </button>

                        <div v-if="isProfileOpen" @click="isProfileOpen = false" class="fixed inset-0 z-40"></div>

                        <transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="transform opacity-0 scale-95"
                            enter-to-class="transform opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="transform opacity-100 scale-100"
                            leave-to-class="transform opacity-0 scale-95"
                        >
                            <!-- The dropdown keeps the original white background so it pops over the workspace -->
                            <div
                                v-if="isProfileOpen"
                                role="menu"
                                aria-label="Account"
                                class="absolute right-0 z-50 mt-3 w-72 origin-top-right overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg"
                            >
                                <!-- Who is signed in: initials, name, email, then role and office on their own line so nothing wraps awkwardly. -->
                                <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#265f92] text-sm font-bold text-white" aria-hidden="true">
                                        {{ initials || '?' }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-800">{{ user?.name }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ user?.email }}</p>
                                    </div>
                                </div>
                                <p class="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs text-slate-600">
                                    <span class="font-medium text-slate-700">{{ formatRole(user?.role) }}</span>
                                    <template v-if="office"> · {{ office.name }} ({{ office.code }})</template>
                                </p>

                                <div class="p-1.5">
                                    <Link
                                        :href="route('profile.edit')"
                                        role="menuitem"
                                        class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-100 focus:bg-slate-100 focus:outline-none"
                                        @click="isProfileOpen = false"
                                    >
                                        <User class="h-4 w-4 text-slate-400" aria-hidden="true" />
                                        My Profile
                                    </Link>
                                    <Link
                                        :href="route('logout')"
                                        method="post"
                                        as="button"
                                        role="menuitem"
                                        class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-red-50 hover:text-red-700 focus:bg-red-50 focus:text-red-700 focus:outline-none"
                                    >
                                        <LogOut class="h-4 w-4 text-slate-400" aria-hidden="true" />
                                        Log out
                                    </Link>
                                </div>
                            </div>
                        </transition>
                    </div>
                </div>
            </div>
        </header>

        <AppSidebar />
        
        <AppContent variant="sidebar" class="relative flex flex-col min-h-screen pt-16">
            <main class="flex-1 overflow-x-hidden bg-slate-50/50">
                <slot />
            </main>
        </AppContent>
    </AppShell>
</template>
