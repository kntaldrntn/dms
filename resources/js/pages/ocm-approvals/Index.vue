<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { FileSignature, FileText, Loader2 } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import { ref } from 'vue';
import { findStampPlacement } from '@/lib/stampPlacement';

defineProps<{ documents: any; flash?: { success?: string; error?: string } }>();
const notyf = new Notyf({ duration: 5000, position: { x: 'right', y: 'top' } });
const workingId = ref<number | null>(null);

async function placementFor(ocmDocument: any) {
    // stamp_source_url is the newest signed PDF (e.g. after a paperless ADM approval), else the uploaded scan.
    if (!ocmDocument.stamp_source_url || !ocmDocument.attached_file?.toLowerCase().endsWith('.pdf')) throw new Error('OCM approval requires an uploaded PDF.');
    const { placement, measured } = await findStampPlacement(ocmDocument.id, ocmDocument.stamp_source_url);
    if (measured) notyf.success('A blank lower-page area was measured and used for the signature stamp.');
    return placement;
}

async function approve(ocmDocument: any) {
    workingId.value = ocmDocument.id;
    try {
        const stamp_placement = await placementFor(ocmDocument);
        const { data } = await axios.post(route('ocm-approvals.approve', ocmDocument.id), { workflow_version: ocmDocument.workflow_version, stamp_placement });
        notyf.success(data?.message || 'PDF digitally stamped and returned to Records.');
        // Partial Inertia reload keeps this page (and the toast) alive while the signed document leaves the queue.
        router.reload({ only: ['documents'] });
    } catch (error: any) {
        notyf.error(error?.response?.data?.error || error?.response?.data?.message || error?.message || 'OCM approval could not be completed.');
    } finally {
        workingId.value = null;
    }
}
</script>

<template>
    <Head title="OCM Approvals" />
    <AppLayout :breadcrumbs="[{ title: 'OCM Approvals', href: '/ocm-approvals' }]">
        <div class="flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <div><h1 class="text-2xl font-bold text-slate-800">OCM Approval Queue</h1><p class="mt-1 text-sm text-slate-500">Digitally stamp the uploaded PDF and return the approved document directly to Records.</p></div>
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full text-left text-sm text-slate-700"><thead class="bg-slate-100 text-xs uppercase text-slate-600"><tr><th class="px-4 py-3">Barcode</th><th class="px-4 py-3">Subject Matter</th><th class="px-4 py-3 text-center">Approval</th></tr></thead>
                    <tbody class="divide-y divide-slate-200">
                        <tr v-for="document in documents.data" :key="document.id">
                            <td class="px-4 py-3 font-mono font-bold text-emerald-600">{{ document.barcode }}</td>
                            <td class="px-4 py-3">{{ document.subject_matter }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-center gap-2">
                                    <a
                                        v-if="document.attached_file"
                                        :href="`/storage/${document.attached_file}`"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-4 py-1.5 font-bold text-sky-800 hover:bg-sky-100"
                                    >
                                        <FileText class="h-4 w-4" />
                                        Preview Document
                                    </a>
                                    <button
                                        @click="approve(document)"
                                        :disabled="workingId === document.id"
                                        class="inline-flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-1.5 font-bold text-amber-800 hover:bg-amber-100 disabled:opacity-50"
                                    >
                                        <Loader2 v-if="workingId === document.id" class="h-4 w-4 animate-spin" />
                                        <FileSignature v-else class="h-4 w-4" />
                                        Digitally Stamp & Return
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="documents.data.length === 0">
                            <td colspan="3" class="py-12 text-center text-slate-500">No documents are awaiting OCM approval.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
