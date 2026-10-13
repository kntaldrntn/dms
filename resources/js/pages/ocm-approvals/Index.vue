<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { FileSignature, FileText, Loader2 } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';
import { ref } from 'vue';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

const props = defineProps<{ documents: any; flash?: { success?: string; error?: string } }>();
const notyf = new Notyf({ duration: 5000, position: { x: 'right', y: 'top' } });
const workingId = ref<number | null>(null);

type StampPlacement = { page: number; x: number; y: number; width: number; height: number };
type PagePreview = { page: number; canvas: HTMLCanvasElement };

/**
 * Find a mostly blank area in the lower half of a scanned page when the vision
 * service cannot return one. This is deliberately conservative: it only uses
 * an area with very little dark ink and favors the lower-right of later pages.
 */
function localFallbackPlacement(previews: PagePreview[]): StampPlacement | null {
    const placementWidth = 260;
    const placementHeight = 105;
    let best: { placement: StampPlacement; score: number; inkRatio: number } | null = null;

    for (const preview of [...previews].reverse()) {
        const context = preview.canvas.getContext('2d', { willReadFrequently: true });
        if (!context) continue;

        const image = context.getImageData(0, 0, preview.canvas.width, preview.canvas.height);
        for (let y = 800; y >= 430; y -= 35) {
            for (let x = 700; x >= 40; x -= 35) {
                const xStart = Math.round((x / 1000) * preview.canvas.width);
                const yStart = Math.round((y / 1000) * preview.canvas.height);
                const xEnd = Math.round(((x + placementWidth) / 1000) * preview.canvas.width);
                const yEnd = Math.round(((y + placementHeight) / 1000) * preview.canvas.height);
                let sampled = 0;
                let darkPixels = 0;

                for (let py = yStart; py < yEnd; py += 4) {
                    for (let px = xStart; px < xEnd; px += 4) {
                        const offset = (py * preview.canvas.width + px) * 4;
                        const brightness = (image.data[offset] * 0.299) + (image.data[offset + 1] * 0.587) + (image.data[offset + 2] * 0.114);
                        sampled++;
                        if (brightness < 180) darkPixels++;
                    }
                }

                const inkRatio = darkPixels / sampled;
                // Favor a clean space on the lower-right, but never select an area with visible content.
                const score = inkRatio - (y / 1000) * 0.002 - ((x + placementWidth) / 1000) * 0.0005;
                if (!best || score < best.score) best = { placement: { page: preview.page, x, y, width: placementWidth, height: placementHeight }, score, inkRatio };
            }
        }
    }

    return best && best.inkRatio <= 0.01 ? best.placement : null;
}

async function placementFor(ocmDocument: any) {
    if (!ocmDocument.attached_file?.toLowerCase().endsWith('.pdf')) throw new Error('OCM approval requires an uploaded PDF.');
    const response = await fetch(`/storage/${ocmDocument.attached_file}`);
    if (!response.ok) throw new Error('The uploaded PDF could not be read.');
    const pdf = await pdfjsLib.getDocument({ data: await response.arrayBuffer() }).promise;
    const form = new FormData();
    const previews: PagePreview[] = [];
    for (let number = 1; number <= Math.min(pdf.numPages, 5); number++) {
        const page = await pdf.getPage(number);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = window.document.createElement('canvas');
        canvas.width = viewport.width; canvas.height = viewport.height;
        await page.render({ canvasContext: canvas.getContext('2d')!, viewport }).promise;
        previews.push({ page: number, canvas });
        const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('Unable to render PDF page.')), 'image/jpeg', 0.82));
        form.append('thumbnails[]', new File([blob], `page-${number}.jpg`, { type: 'image/jpeg' }));
        form.append(`pages[${number - 1}][page]`, String(number));
    }

    try {
        return (await axios.post(route('referral-slips.stamp-placement', ocmDocument.id), form)).data.placement as StampPlacement;
    } catch (error) {
        const fallback = localFallbackPlacement(previews);
        if (!fallback) throw error;

        notyf.success('AI did not select a position, so a blank lower-page area was selected automatically.');
        return fallback;
    }
}

async function approve(ocmDocument: any) {
    workingId.value = ocmDocument.id;
    try {
        const stamp_placement = await placementFor(ocmDocument);
        await axios.post(route('ocm-approvals.approve', ocmDocument.id), { workflow_version: ocmDocument.workflow_version, stamp_placement });
        notyf.success('PDF digitally stamped and returned to Records.');
        window.location.reload();
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
