<script setup lang="ts">
import ApprovalDialog from '@/components/ApprovalDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { FileText, Loader2 } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { ref, watch } from 'vue';

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

// --- Approve & Route (shared with OCM) ---
const approving = ref<any>(null);

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
                    ADM receives the document. Once the City Administrator has signed it, record the approval and route it to the City Mayor or the
                    departments.
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
                                <span v-if="doc.details_hidden" class="text-slate-800">{{ doc.barcode }}</span>
                                <Link v-else :href="route('documents.show', doc.id)" class="text-emerald-700 hover:underline">{{ doc.barcode }}</Link>
                            </td>
                            <td class="px-4 py-2.5">
                                <!-- Confidential: the server sent no details; work from the paper copy -->
                                <p v-if="doc.details_hidden" class="font-medium text-amber-800">Confidential document</p>
                                <p v-else class="line-clamp-2 text-slate-800">{{ doc.subject_matter }}</p>
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
                                        @click="approving = doc"
                                    >
                                        {{ doc.is_paperless ? 'Approve & Route' : 'Record approval' }}
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

            <ApprovalDialog
                v-if="approving"
                :document="approving"
                :departments="departments"
                :has-certificate="hasCertificate"
                :approve-url="route('adm-approvals.approve', approving.id)"
                executive="City Administrator"
                allow-mayor
                @close="approving = null"
            />
        </div>
    </AppLayout>
</template>
