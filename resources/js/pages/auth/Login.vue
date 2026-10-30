<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Barcode, Eye, EyeOff, Info, KeyRound, LoaderCircle, LockKeyhole, Mail, Search, UserRound, Users } from 'lucide-vue-next';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'radix-vue';
import { computed, ref, watch } from 'vue';

interface TrackingResult {
    barcode: string;
    subject: string | null;
    received_at: string;
    status: string;
    updated_at: string;
}

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    initialTab: 'staff' | 'guest';
    tracking?: TrackingResult | null;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const activeTab = ref<'staff' | 'guest'>(props.tracking ? 'guest' : props.initialTab);
const heading = computed(() =>
    activeTab.value === 'staff'
        ? { title: 'Sign in', description: 'Use the official account issued to you by the System Administrator.' }
        : { title: 'Track a document', description: 'Check the status of a document you submitted to the City Government.' },
);

const trackForm = useForm({ barcode: '', access_code: '' });
// 'tracking' is the form-level error (no match, too many tries); the field errors cover empty inputs.
const trackingError = computed(() => (trackForm.errors as Record<string, string | undefined>).tracking);
const result = ref<TrackingResult | null>(props.tracking ?? null);
watch(
    () => props.tracking,
    (tracking) => {
        if (tracking) result.value = tracking;
    },
);

const track = () => {
    trackForm.post(route('track'), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => trackForm.reset(),
    });
};

const trackAnother = () => {
    result.value = null;
    trackForm.clearErrors();
};

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase :title="heading.title" :description="heading.description">
        <Head title="Sign in" />

        <TabsRoot v-model="activeTab">
            <TabsList aria-label="Sign-in type" class="mb-5 grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1">
                <TabsTrigger
                    value="staff"
                    class="flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 transition hover:text-gov-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gov-blue data-[state=active]:bg-white data-[state=active]:text-gov-navy data-[state=active]:shadow-sm"
                >
                    <UserRound class="h-4 w-4" aria-hidden="true" />
                    Employee
                </TabsTrigger>
                <TabsTrigger
                    value="guest"
                    class="flex items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-500 transition hover:text-gov-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gov-blue data-[state=active]:bg-white data-[state=active]:text-gov-navy data-[state=active]:shadow-sm"
                >
                    <Users class="h-4 w-4" aria-hidden="true" />
                    Guest
                </TabsTrigger>
            </TabsList>

            <TabsContent value="staff" class="focus-visible:outline-none">
                <div
                    v-if="status"
                    role="status"
                    class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700"
                >
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="email" class="text-slate-700">Email address</Label>
                        <div class="relative">
                            <Mail class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                            <Input
                                id="email"
                                type="email"
                                required
                                autofocus
                                autocomplete="email"
                                v-model="form.email"
                                placeholder="name@example.com"
                                class="h-10 pl-9 focus-visible:ring-gov-blue"
                                :aria-invalid="!!form.errors.email"
                                aria-describedby="email-error"
                            />
                        </div>
                        <InputError id="email-error" :message="form.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="password" class="text-slate-700">Password</Label>
                            <!-- <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm text-gov-blue">
                                Forgot password?
                            </TextLink> -->
                        </div>
                        <div class="relative">
                            <LockKeyhole
                                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                                aria-hidden="true"
                            />
                            <Input
                                id="password"
                                :type="showPassword ? 'text' : 'password'"
                                required
                                autocomplete="current-password"
                                v-model="form.password"
                                placeholder="Password"
                                class="h-10 pl-9 pr-10 focus-visible:ring-gov-blue"
                                :aria-invalid="!!form.errors.password"
                                aria-describedby="password-error"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-slate-500 hover:text-gov-navy focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gov-blue"
                                :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                :aria-pressed="showPassword"
                            >
                                <EyeOff v-if="showPassword" class="h-4 w-4" aria-hidden="true" />
                                <Eye v-else class="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                        <InputError id="password-error" :message="form.errors.password" />
                    </div>

                    <Label for="remember" class="flex w-fit items-center gap-3 font-normal text-slate-600">
                        <Checkbox id="remember" v-model:checked="form.remember" />
                        <span>Keep me signed in on this device</span>
                    </Label>

                    <Button
                        type="submit"
                        class="mt-1 h-10 w-full bg-gov-navy text-white hover:bg-gov-navy-dark focus-visible:ring-gov-orange"
                        :disabled="form.processing"
                    >
                        <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                        Sign in
                    </Button>
                </form>

                <p
                    class="mt-5 flex items-start gap-2 rounded-lg border border-gov-gold/40 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-slate-600"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-gov-orange" aria-hidden="true" />
                    <span
                        >Accounts are created by the System Administrator. If you need access or a password reset, contact your Department Head or the
                        CICTO.</span
                    >
                </p>
            </TabsContent>

            <TabsContent value="guest" class="focus-visible:outline-none">
                <section v-if="result" aria-labelledby="tracking-result-heading" aria-live="polite">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <h3 id="tracking-result-heading" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Latest status</h3>
                        <p class="mt-1 text-lg font-bold text-gov-navy">{{ result.status }}</p>
                        <p class="text-xs text-slate-500">As of {{ result.updated_at }}</p>

                        <dl class="mt-4 grid gap-2 border-t border-slate-200 pt-3 text-sm">
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-slate-500">Barcode</dt>
                                <dd class="font-mono font-semibold text-slate-800">{{ result.barcode }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-24 shrink-0 text-slate-500">Received</dt>
                                <dd class="text-slate-800">{{ result.received_at }}</dd>
                            </div>
                            <div v-if="result.subject" class="flex gap-2">
                                <dt class="w-24 shrink-0 text-slate-500">Subject</dt>
                                <dd class="text-slate-800">{{ result.subject }}</dd>
                            </div>
                        </dl>
                    </div>

                    <Button type="button" variant="outline" class="mt-4 h-10 w-full" @click="trackAnother">
                        <Search class="h-4 w-4" aria-hidden="true" />
                        Track another document
                    </Button>
                </section>

                <form v-else @submit.prevent="track" class="flex flex-col gap-4">
                    <div class="grid gap-2">
                        <Label for="guest-barcode" class="text-slate-700">Barcode Number</Label>
                        <div class="relative">
                            <Barcode class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                            <Input
                                id="guest-barcode"
                                v-model="trackForm.barcode"
                                required
                                autocomplete="off"
                                maxlength="20"
                                placeholder="e.g. C003196677"
                                class="h-10 pl-9 font-mono uppercase focus-visible:ring-gov-blue"
                                :aria-invalid="!!trackForm.errors.barcode"
                                aria-describedby="guest-barcode-hint guest-barcode-error"
                            />
                        </div>
                        <p id="guest-barcode-hint" class="text-xs text-slate-500">
                            On your receiving copy and in the email from the Records Section.
                        </p>
                        <InputError id="guest-barcode-error" :message="trackForm.errors.barcode" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="guest-access-code" class="text-slate-700">Access Code</Label>
                        <div class="relative">
                            <KeyRound
                                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                                aria-hidden="true"
                            />
                            <Input
                                id="guest-access-code"
                                v-model="trackForm.access_code"
                                required
                                autocomplete="off"
                                maxlength="20"
                                placeholder="6-character code"
                                class="h-10 pl-9 font-mono uppercase focus-visible:ring-gov-blue"
                                :aria-invalid="!!trackForm.errors.access_code"
                                aria-describedby="guest-access-code-error"
                            />
                        </div>
                        <InputError id="guest-access-code-error" :message="trackForm.errors.access_code" />
                    </div>

                    <InputError :message="trackingError" role="alert" />

                    <Button
                        type="submit"
                        :disabled="trackForm.processing"
                        class="mt-1 h-10 w-full bg-gov-navy text-white hover:bg-gov-navy-dark focus-visible:ring-gov-orange"
                    >
                        <LoaderCircle v-if="trackForm.processing" class="h-4 w-4 animate-spin" aria-hidden="true" />
                        <Search v-else class="h-4 w-4" aria-hidden="true" />
                        Track document
                    </Button>
                </form>

                <p
                    class="mt-5 flex items-start gap-2 rounded-lg border border-gov-blue/30 bg-sky-50 px-3 py-2 text-xs leading-relaxed text-slate-600"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0 text-gov-blue" aria-hidden="true" />
                    <span>Only the latest status is shown. For details, please inquire at the Records Section.</span>
                </p>
            </TabsContent>
        </TabsRoot>
    </AuthBase>
</template>
