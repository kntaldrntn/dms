<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { Mail, Send } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { computed, watch } from 'vue';

interface MailFields {
    host: string | null;
    port: number | null;
    username: string | null;
    from_address: string | null;
    from_name: string | null;
}

const props = defineProps<{
    setting: (MailFields & { has_password: boolean; updated_at: string | null; updated_by: string | null }) | null;
    defaults: MailFields & { has_password: boolean };
    flash?: { success?: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Email Settings', href: route('mail-settings.edit') }];

const initial = props.setting ?? props.defaults;
const form = useForm({
    host: initial.host ?? '',
    port: initial.port ?? 587,
    username: initial.username ?? '',
    // Never pre-filled: neither the saved nor the .env password is sent to the browser; blank keeps it.
    password: '',
    from_address: initial.from_address ?? '',
    from_name: initial.from_name ?? '',
});
const testForm = useForm({ test_address: '' });

const passwordPlaceholder = computed(() => {
    if (props.setting) return 'Saved — leave blank to keep it';
    return props.defaults.has_password ? 'From the .env file — leave blank to use it' : '';
});

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });
watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) notyf.success(flash.success);
    },
    { immediate: true },
);

function save() {
    form.put(route('mail-settings.update'), { preserveScroll: true, onFinish: () => form.reset('password') });
}

function sendTest() {
    testForm.post(route('mail-settings.test'), { preserveScroll: true });
}

const labelClass = 'mb-1 block text-sm font-medium text-slate-700';
const fieldClass =
    'block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-[#265f92] focus:outline-none focus:ring-1 focus:ring-[#265f92]';
</script>

<template>
    <Head title="Email Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-800">Email Settings</h1>
                <p class="mt-1 text-sm text-slate-500">The account the system sends email from: client status updates and password reset links.</p>
            </div>

            <div class="grid max-w-5xl gap-6 lg:grid-cols-[1fr_20rem]">
                <form class="rounded-lg border border-slate-200 bg-white p-6" @submit.prevent="save">
                    <div class="mb-5 flex items-center gap-2 text-slate-800">
                        <Mail class="h-5 w-5 text-[#265f92]" aria-hidden="true" />
                        <h2 class="text-base font-semibold">SMTP account</h2>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                        <div>
                            <label for="host" :class="labelClass">SMTP host</label>
                            <input id="host" v-model="form.host" type="text" required :class="fieldClass" placeholder="smtp.gmail.com" />
                            <InputError :message="form.errors.host" />
                        </div>
                        <div>
                            <label for="port" :class="labelClass">Port</label>
                            <input id="port" v-model.number="form.port" type="number" min="1" max="65535" required :class="fieldClass" />
                            <InputError :message="form.errors.port" />
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Gmail: smtp.gmail.com, port 587.</p>

                    <div class="mt-4">
                        <label for="username" :class="labelClass">Email account</label>
                        <input id="username" v-model="form.username" type="text" autocomplete="off" required :class="fieldClass" />
                        <InputError :message="form.errors.username" />
                    </div>

                    <div class="mt-4">
                        <label for="password" :class="labelClass">Password</label>
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            :required="!setting && !defaults.has_password"
                            :placeholder="passwordPlaceholder"
                            :class="fieldClass"
                            aria-describedby="password-help"
                        />
                        <p id="password-help" class="mt-1 text-xs text-slate-500">
                            For Gmail, use a 16-letter app password (Google Account → Security → App passwords), not the account password. Stored
                            encrypted and never shown again.
                        </p>
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="from_name" :class="labelClass">Sender name</label>
                            <input
                                id="from_name"
                                v-model="form.from_name"
                                type="text"
                                required
                                :class="fieldClass"
                                placeholder="City of San Fernando DMS"
                            />
                            <InputError :message="form.errors.from_name" />
                        </div>
                        <div>
                            <label for="from_address" :class="labelClass">Sender email</label>
                            <input id="from_address" v-model="form.from_address" type="email" required :class="fieldClass" />
                            <InputError :message="form.errors.from_address" />
                        </div>
                    </div>

                    <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-4">
                        <p class="text-xs text-slate-500">
                            <template v-if="setting"
                                >Last saved {{ setting.updated_at }}<template v-if="setting.updated_by"> by {{ setting.updated_by }}</template
                                >.</template
                            >
                            <template v-else>Not saved yet: the values shown come from the server's .env file.</template>
                        </p>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="rounded-md bg-[#265f92] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1f4f7a] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#265f92] focus-visible:ring-offset-2 disabled:opacity-60"
                        >
                            Save settings
                        </button>
                    </div>
                </form>

                <form class="h-fit rounded-lg border border-slate-200 bg-white p-6" @submit.prevent="sendTest">
                    <div class="mb-3 flex items-center gap-2 text-slate-800">
                        <Send class="h-5 w-5 text-[#265f92]" aria-hidden="true" />
                        <h2 class="text-base font-semibold">Send a test email</h2>
                    </div>
                    <p class="mb-4 text-sm text-slate-500">Uses the saved settings. Save first if you changed anything.</p>
                    <label for="test_address" :class="labelClass">Send to</label>
                    <input
                        id="test_address"
                        v-model="testForm.test_address"
                        type="email"
                        required
                        :class="fieldClass"
                        placeholder="name@example.com"
                    />
                    <InputError :message="testForm.errors.test_address" />
                    <button
                        type="submit"
                        :disabled="testForm.processing || form.isDirty"
                        class="mt-4 w-full rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-[#265f92] disabled:opacity-60"
                    >
                        {{ testForm.processing ? 'Sending…' : 'Send test email' }}
                    </button>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
