<script setup lang="ts">
import DmsLogoMark from '@/components/DmsLogoMark.vue';
import { FileCheck2, Route, ScanBarcode, ShieldCheck } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();

const features = [
    { icon: ScanBarcode, text: 'Barcode intake with AI-assisted registration' },
    { icon: Route, text: 'Office-to-office routing with a complete custody trail' },
    { icon: FileCheck2, text: 'Single-step executive approvals, digitally signed' },
];

const year = new Date().getFullYear();
</script>

<template>
    <div class="flex min-h-svh flex-col bg-slate-100 text-slate-800">
        <!-- Government identity bar -->
        <header class="bg-gov-navy-dark text-white">
            <div class="flex h-1">
                <span class="flex-1 bg-gov-blue" />
                <span class="flex-1 bg-gov-orange" />
                <span class="flex-1 bg-gov-gold" />
            </div>
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-2 text-[11px] uppercase tracking-[0.18em] sm:px-6">
                <span>Republic of the Philippines</span>
                <span class="hidden text-white/70 sm:inline">City Government of San Fernando, La Union</span>
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center p-4 sm:p-5 lg:p-6">
            <div class="grid w-full max-w-5xl overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-200 lg:grid-cols-[1.05fr_1fr]">
                <!-- Identity panel -->
                <section
                    aria-label="About the system"
                    class="relative hidden flex-col justify-between overflow-hidden bg-gov-navy p-8 text-white lg:flex"
                >
                    <!-- Decorative chevrons echoing the logo -->
                    <DmsLogoMark :framed="false" aria-hidden="true" class="pointer-events-none absolute -bottom-16 -right-16 h-80 w-80 opacity-10" />

                    <img src="/images/san_fernando_logo.png" alt="Official Seal of the City of San Fernando" class="relative h-14 w-14 rounded-full bg-white p-0.5" />

                    <div class="relative mt-6">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-gov-gold">City Government of San Fernando</p>
                        <h1 class="mt-3 text-3xl font-bold leading-tight">Document Management System</h1>
                        <p class="mt-3 max-w-sm text-sm leading-relaxed text-white/75">
                            Track every incoming communication from Records intake to final action across all city offices.
                        </p>

                        <ul class="mt-6 space-y-3">
                            <li v-for="feature in features" :key="feature.text" class="flex items-start gap-3 text-sm text-white/90">
                                <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/10 text-gov-gold">
                                    <component :is="feature.icon" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                {{ feature.text }}
                            </li>
                        </ul>
                    </div>

                    <p class="relative mt-6 flex items-center gap-2 border-t border-white/15 pt-4 text-xs text-white/70">
                        <ShieldCheck class="h-4 w-4 shrink-0 text-gov-orange" aria-hidden="true" />
                        For authorized City Government personnel only. Activity is logged.
                    </p>
                </section>

                <!-- Form panel -->
                <section class="flex flex-col justify-center px-6 py-8 sm:px-10">
                    <div class="mb-5 flex items-center gap-3 lg:hidden">
                        <img src="/images/san_fernando_logo.png" alt="Official Seal of the City of San Fernando" class="h-12 w-12" />
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-gov-orange">City Government of San Fernando</p>
                            <p class="text-base font-bold text-gov-navy">Document Management System</p>
                        </div>
                    </div>

                    <div class="mb-5">
                        <span class="block h-1 w-12 rounded-full bg-gov-orange" aria-hidden="true" />
                        <h2 class="mt-3 text-2xl font-bold text-gov-navy">{{ title }}</h2>
                        <p v-if="description" class="mt-1.5 text-sm text-slate-500">{{ description }}</p>
                    </div>

                    <slot />
                </section>
            </div>
        </main>

        <footer class="border-t border-slate-200 bg-white px-4 py-3 text-center text-xs text-slate-500">
            &copy; {{ year }} City Government of San Fernando, La Union &middot; City Information and Communications Technology Office
        </footer>
    </div>
</template>
