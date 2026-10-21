<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { FileSignature, FileText, Loader2 } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';
import { ref } from 'vue';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

defineProps<{ documents: any; flash?: { success?: string; error?: string } }>();
const notyf = new Notyf({ duration: 5000, position: { x: 'right', y: 'top' } });
const workingId = ref<number | null>(null);

type StampPlacement = { page: number; x: number; y: number; width: number; height: number };
type PagePreview = { page: number; image: ImageData };

// Share of sampled pixels that are dark; above this an area is treated as having visible content.
const MAX_INK_RATIO = 0.01;

/** Ratio of dark pixels inside a normalized (0-1000) rectangle of a rendered page. */
function inkRatio(preview: PagePreview, placement: StampPlacement): number {
    const { width, height, data } = preview.image;
    const xStart = Math.round((placement.x / 1000) * width);
    const yStart = Math.round((placement.y / 1000) * height);
    const xEnd = Math.min(width, Math.round(((placement.x + placement.width) / 1000) * width));
    const yEnd = Math.min(height, Math.round(((placement.y + placement.height) / 1000) * height));
    let sampled = 0;
    let darkPixels = 0;

    for (let py = yStart; py < yEnd; py += 4) {
        for (let px = xStart; px < xEnd; px += 4) {
            const offset = (py * width + px) * 4;
            const brightness = data[offset] * 0.299 + data[offset + 1] * 0.587 + data[offset + 2] * 0.114;
            sampled++;
            if (brightness < 180) darkPixels++;
        }
    }

    return sampled ? darkPixels / sampled : 1;
}

/** The AI's rectangle is only used when it lies on the page and actually covers blank paper. */
function isUsablePlacement(placement: StampPlacement | undefined, previews: PagePreview[]): placement is StampPlacement {
    if (!placement || placement.x < 0 || placement.y < 0 || placement.x + placement.width > 1000 || placement.y + placement.height > 1000) return false;
    const preview = previews.find((candidate) => candidate.page === placement.page);

    return !!preview && inkRatio(preview, placement) <= MAX_INK_RATIO;
}

/**
 * Find a blank area in the lower half of a page by measuring the rendered pixels.
 * Favors the lower-right of later pages and never selects an area with visible content.
 */
function localFallbackPlacement(previews: PagePreview[]): StampPlacement | null {
    const placementWidth = 300;
    const placementHeight = 105;
    let best: { placement: StampPlacement; score: number; inkRatio: number } | null = null;

    for (const preview of [...previews].reverse()) {
        for (let y = 800; y >= 430; y -= 35) {
            for (let x = 1000 - placementWidth - 30; x >= 40; x -= 35) {
                const placement = { page: preview.page, x, y, width: placementWidth, height: placementHeight };
                const ratio = inkRatio(preview, placement);
                const score = ratio - (y / 1000) * 0.002 - ((x + placementWidth) / 1000) * 0.0005;
                if (!best || score < best.score) best = { placement, score, inkRatio: ratio };
            }
        }
    }

    return best && best.inkRatio <= MAX_INK_RATIO ? best.placement : null;
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
        const context = canvas.getContext('2d', { willReadFrequently: true })!;
        await page.render({ canvasContext: context, viewport }).promise;
        previews.push({ page: number, image: context.getImageData(0, 0, canvas.width, canvas.height) });
        const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob(value => value ? resolve(value) : reject(new Error('Unable to render PDF page.')), 'image/jpeg', 0.82));
        form.append('thumbnails[]', new File([blob], `page-${number}.jpg`, { type: 'image/jpeg' }));
        form.append(`pages[${number - 1}][page]`, String(number));
    }

    let aiError: unknown = null;
    try {
        const placement = (await axios.post(route('referral-slips.stamp-placement', ocmDocument.id), form)).data.placement as StampPlacement;
        if (isUsablePlacement(placement, previews)) return placement;
    } catch (error) {
        aiError = error;
    }

    const fallback = localFallbackPlacement(previews);
    if (!fallback) throw aiError ?? new Error('No blank area large enough for the signature stamp was found. Add a clear signature space to the PDF.');

    notyf.success('A blank lower-page area was measured and used for the signature stamp.');
    return fallback;
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
