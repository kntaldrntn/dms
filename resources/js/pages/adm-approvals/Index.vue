<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import OfficePicker from '@/components/OfficePicker.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { findStampPlacement } from '@/lib/stampPlacement';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FileText, Loader2, X } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { computed, ref, watch } from 'vue';

type Office = { id: number; code: string; name: string };

const props = defineProps<{
    documents: any;
    departments: Office[];
    /** Whether the signed-in approver has a valid PNPKI certificate on file (needed for paperless). */
    hasCertificate: boolean;
    flash?: { success?: string; error?: string };
}>();

const notyf = new Notyf({ duration: 5000, position: { x: 'right', y: 'top' } });
watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) notyf.success(flash.success);
        if (flash?.error) notyf.error(flash.error);
    },
    { immediate: true },
);

const receivingId = ref<number | null>(null);
function receive(doc: any) {
    receivingId.value = doc.id;
    router.post(
        route('adm-approvals.receive', doc.id),
        { workflow_version: doc.workflow_version },
        {
            preserveScroll: true,
            onError: (errors) => notyf.error(String(Object.values(errors)[0] ?? 'The document could not be received.')),
            onFinish: () => (receivingId.value = null),
        },
    );
}

// --- Approve & Route ---
const approving = ref<any>(null);
const preparingStamp = ref(false);
const form = useForm({
    workflow_version: 0,
    target: 'departments' as 'ocm' | 'departments',
    routed_to: [] as number[],
    primary_office_id: null as number | null,
    remarks: '',
    signed_scan: null as File | null,
    stamp_placement: null as Record<string, number> | null,
    certificate_pin: '',
});
const paperless = computed(() => approving.value?.route_mode === 'paperless');
const suggestions = computed(() =>
    Object.fromEntries(
        (approving.value?.ai_routing_suggestions ?? []).map((suggestion: any) => [
            Number(suggestion.department_id),
            { reason: suggestion.reason, priority: suggestion.priority },
        ]),
    ),
);

function openApproval(doc: any) {
    approving.value = doc;
    form.reset();
    form.clearErrors();
    form.workflow_version = doc.workflow_version;
    // Pre-tick the AI's suggested departments, Primary first.
    const suggested = (doc.ai_routing_suggestions ?? [])
        .map((suggestion: any) => Number(suggestion.department_id))
        .filter((id: number) => props.departments.some((office) => office.id === id));
    form.routed_to = doc.is_confidential ? suggested.slice(0, 1) : suggested;
    form.primary_office_id =
        Number((doc.ai_routing_suggestions ?? []).find((suggestion: any) => suggestion.priority === 'Primary')?.department_id) ||
        form.routed_to[0] ||
        null;
}

async function submitApproval() {
    const doc = approving.value;
    if (!doc) return;
    try {
        if (paperless.value) {
            preparingStamp.value = true;
            if (!doc.stamp_source_url) throw new Error('Paperless approval needs the uploaded PDF.');
            const { placement, measured } = await findStampPlacement(doc.id, doc.stamp_source_url);
            if (measured) notyf.success('A blank lower-page area was measured and used for your signature.');
            form.stamp_placement = placement;
        }
    } catch (error: any) {
        notyf.error(error?.response?.data?.error || error?.message || 'No place for the signature was found on the PDF.');
        return;
    } finally {
        preparingStamp.value = false;
    }

    form.transform((data) => {
        const payload: Record<string, unknown> = { workflow_version: data.workflow_version, target: data.target, remarks: data.remarks };
        if (data.target === 'departments') Object.assign(payload, { routed_to: data.routed_to, primary_office_id: data.primary_office_id });
        if (paperless.value) Object.assign(payload, { stamp_placement: data.stamp_placement, certificate_pin: data.certificate_pin });
        else payload.signed_scan = data.signed_scan;
        return payload;
    }).post(route('adm-approvals.approve', doc.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (approving.value = null),
        // The PIN never stays in the page after a submit.
        onFinish: () => form.reset('certificate_pin'),
    });
}

const officeCode = (id: number) => props.departments.find((office) => office.id === id)?.code ?? id;
const fieldClass =
    'w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 focus:outline-none';
const buttonClass =
    'inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50';
</script>

<template>
    <Head title="ADM Approvals" />
    <AppLayout :breadcrumbs="[{ title: 'ADM Approvals', href: '/adm-approvals' }]">
        <div class="flex min-h-screen w-full flex-col gap-4 bg-slate-50 p-4">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">ADM Approvals</h1>
                <p class="text-sm text-slate-500">
                    ADM receives the document; the City Administrator approves it once and routes it to the City Mayor or the departments.
                </p>
            </div>

            <section
                class="overflow-x-auto rounded-lg border border-slate-200 bg-white"
                aria-label="Documents waiting on City Administrator approval"
            >
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Barcode</th>
                            <th class="px-4 py-2 font-medium">Subject Matter</th>
                            <th class="px-4 py-2 font-medium">Received</th>
                            <th class="px-4 py-2 text-center font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="doc in documents.data" :key="doc.id" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono">
                                <Link :href="route('documents.show', doc.id)" class="text-emerald-700 hover:underline">{{ doc.barcode }}</Link>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="line-clamp-2 text-slate-800">{{ doc.subject_matter }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ doc.document_type?.document_type }} · {{ doc.route_mode === 'paperless' ? 'Paperless' : 'Paper' }}
                                    <span v-if="doc.is_confidential" class="font-medium text-amber-700">· Confidential</span>
                                </p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <span v-if="doc.primary_entry?.receiver" class="text-slate-700">{{ doc.primary_entry.receiver.name }}</span>
                                <span v-else class="text-slate-500">Not yet received</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap items-center justify-center gap-1.5">
                                    <a
                                        v-if="doc.attached_file"
                                        :href="`/storage/${doc.attached_file}`"
                                        target="_blank"
                                        rel="noopener"
                                        :class="buttonClass"
                                    >
                                        <FileText class="h-3.5 w-3.5" aria-hidden="true" /> Preview
                                    </a>
                                    <button
                                        v-if="doc.can_receive"
                                        type="button"
                                        :class="buttonClass"
                                        :disabled="receivingId === doc.id"
                                        @click="receive(doc)"
                                    >
                                        <Loader2 v-if="receivingId === doc.id" class="h-3.5 w-3.5 animate-spin" aria-hidden="true" /> Receive
                                    </button>
                                    <button
                                        v-if="doc.can_approve"
                                        type="button"
                                        class="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                                        @click="openApproval(doc)"
                                    >
                                        Approve &amp; Route
                                    </button>
                                    <span v-if="!doc.can_receive && !doc.can_approve" class="text-xs text-slate-500">
                                        {{ doc.primary_entry?.receiver ? 'Waiting for the City Administrator' : 'Waiting to be received' }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="4" class="py-10 text-center text-slate-500">No documents are waiting on City Administrator approval.</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Approve & Route dialog -->
            <div v-if="approving" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @keydown.esc="approving = null">
                <form
                    class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-lg border border-slate-200 bg-white"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="approve-title"
                    @submit.prevent="submitApproval"
                >
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h2 id="approve-title" class="text-base font-semibold text-slate-800">
                            Approve &amp; Route <span class="font-mono text-emerald-700">{{ approving.barcode }}</span>
                        </h2>
                        <button
                            type="button"
                            class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                            aria-label="Close"
                            @click="approving = null"
                        >
                            <X class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                        <p v-if="form.errors.workflow_version || form.errors.document" role="alert" class="text-sm text-red-600">
                            {{ form.errors.workflow_version || form.errors.document }}
                        </p>

                        <fieldset>
                            <legend class="mb-1 text-sm text-slate-700">Send to</legend>
                            <div class="flex gap-4 text-sm">
                                <label class="flex items-center gap-1.5">
                                    <input v-model="form.target" type="radio" value="departments" class="text-emerald-600 focus:ring-emerald-600" />
                                    Departments
                                </label>
                                <label class="flex items-center gap-1.5">
                                    <input v-model="form.target" type="radio" value="ocm" class="text-emerald-600 focus:ring-emerald-600" /> City
                                    Mayor (OCM approval)
                                </label>
                            </div>
                        </fieldset>

                        <div v-if="form.target === 'departments'" class="flex min-h-0 flex-col">
                            <OfficePicker
                                v-model="form.routed_to"
                                v-model:primary="form.primary_office_id"
                                :offices="departments"
                                :suggestions="suggestions"
                                :single="approving.is_confidential"
                            />
                            <InputError
                                class="mt-1 text-xs"
                                :message="form.errors.routed_to || form.errors['routed_to.0'] || form.errors.primary_office_id"
                            />
                            <p v-if="form.routed_to.length" class="mt-1 text-xs text-slate-500">
                                Primary: {{ form.primary_office_id ? officeCode(form.primary_office_id) : '—' }}
                            </p>
                        </div>

                        <div>
                            <label for="approve_remarks" class="mb-1 block text-sm text-slate-700"
                                >Remarks <span class="text-slate-400">(optional)</span></label
                            >
                            <textarea id="approve_remarks" v-model="form.remarks" rows="2" :class="fieldClass"></textarea>
                            <InputError class="mt-1 text-xs" :message="form.errors.remarks" />
                        </div>

                        <div v-if="paperless" class="space-y-2">
                            <p class="text-sm text-slate-700">
                                Paperless: your e-signature is stamped on a blank area of the PDF and signed with your PNPKI certificate.
                            </p>
                            <p v-if="!hasCertificate" role="alert" class="text-sm text-amber-700">
                                You have no valid signing certificate. Upload it in
                                <Link :href="route('certificate.edit')" class="underline">Settings → Signing certificate</Link>.
                            </p>
                            <div>
                                <label for="approve_certificate_pin" class="mb-1 block text-sm text-slate-700">Certificate PIN</label>
                                <input
                                    id="approve_certificate_pin"
                                    v-model="form.certificate_pin"
                                    type="password"
                                    autocomplete="off"
                                    required
                                    :class="fieldClass"
                                />
                                <InputError class="mt-1 text-xs" :message="form.errors.certificate_pin" />
                            </div>
                            <InputError class="text-xs" :message="form.errors.stamp_placement" />
                        </div>
                        <div v-else>
                            <label for="approve_signed_scan" class="mb-1 block text-sm text-slate-700">Scan of the wet-signed document</label>
                            <input
                                id="approve_signed_scan"
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                required
                                class="w-full rounded-md border border-slate-300 bg-white py-1 pl-1 pr-2.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-slate-700"
                                @change="(e: any) => (form.signed_scan = e.target.files[0] ?? null)"
                            />
                            <InputError class="mt-1 text-xs" :message="form.errors.signed_scan" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 px-4 py-3">
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-4 py-1.5 text-sm text-slate-700 hover:bg-slate-50"
                            @click="approving = null"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing || preparingStamp || (form.target === 'departments' && !form.routed_to.length)"
                            class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <Loader2 v-if="form.processing || preparingStamp" class="h-4 w-4 animate-spin" aria-hidden="true" />
                            {{ preparingStamp ? 'Placing signature...' : form.target === 'ocm' ? 'Approve & send to City Mayor' : 'Approve & route' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
