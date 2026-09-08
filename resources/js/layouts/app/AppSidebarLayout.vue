<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppLogo from '@/components/AppLogo.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';
import { ref, computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { Search, Bell, Grid, User, LogOut, ChevronRight } from 'lucide-vue-next';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const isProfileOpen = ref(false);
const page = usePage();
const user = computed(() => page.props.auth.user);

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
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <Link v-if="item.href" :href="item.href" class="hover:text-white transition">
                                {{ item.title }}
                            </Link>
                            <span v-else class="text-white font-semibold">{{ item.title }}</span>
                            <ChevronRight v-if="index < breadcrumbs.length - 1" class="w-4 h-4 text-white/40" />
                        </template>
                    </div>
                </div>

                <!-- Right Side: Icons & Profile -->
                <div class="flex items-center gap-4 sm:gap-6">
                    
                    <div class="hidden sm:flex items-center gap-4 text-white/80">
                        <button class="hover:text-white transition"><Search class="w-5 h-5" /></button>
                        <button class="hover:text-white transition relative">
                            <Bell class="w-5 h-5" />
                            <!-- Notification dot adapted for dark theme -->
                            <span class="absolute top-0 right-0 w-2 h-2 bg-rose-500 rounded-full border border-[#265f92]"></span>
                        </button>
                        <button class="hover:text-white transition"><Grid class="w-5 h-5" /></button>
                    </div>

                    <div class="hidden sm:block h-6 w-px bg-white/20"></div>

                    <!-- Profile Dropdown -->
                    <div class="relative">
                        <!-- Trigger Icon (Adapted for Custom Dark Theme) -->
                        <button 
                            @click="isProfileOpen = !isProfileOpen" 
                            class="flex items-center justify-center h-9 w-9 rounded-full bg-white/5 border border-white/20 text-white/90 shadow-sm focus:outline-none transition transform hover:scale-105 hover:bg-white/15 hover:text-white"
                        >
                            <User class="w-4 h-4" />
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
                            <div v-if="isProfileOpen" class="absolute right-0 mt-3 w-72 origin-top-right rounded-2xl bg-white shadow-[0_10px_40px_-10px_rgba(0,0,0,0.1)] border border-slate-100 z-50 overflow-hidden">
                                
                                <div class="flex items-center gap-3 p-4 border-b border-slate-100 bg-slate-50/50">
                                    <div class="flex items-center justify-center h-11 w-11 rounded-full bg-emerald-100 border border-emerald-200 text-emerald-700 shadow-sm">
                                        <User class="w-5 h-5" />
                                    </div>
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-bold text-slate-800">{{ user?.name }}</span>
                                            <span class="rounded bg-slate-100 border border-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 uppercase tracking-wider">
                                                {{ formatRole(user?.role) }}
                                            </span>
                                        </div>
                                        <span class="text-xs text-slate-500 font-medium">{{ user?.email }}</span>
                                    </div>
                                </div>

                                <div class="py-2 px-3 flex flex-col gap-1 border-b border-slate-100">
                                    <Link :href="route('profile.edit')" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 hover:text-emerald-600 transition">
                                        <User class="w-4 h-4 text-slate-400" />
                                        My Profile
                                    </Link>
                                </div>

                                <div class="p-4 bg-white">
                                    <Link 
                                        :href="route('logout')" 
                                        method="post" 
                                        as="button" 
                                        class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 hover:text-rose-600 shadow-sm"
                                    >
                                        <LogOut class="w-4 h-4 opacity-50" />
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