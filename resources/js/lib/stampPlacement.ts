import axios from 'axios';
import * as pdfjsLib from 'pdfjs-dist';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

/** Normalized 0-1000 rectangle from the page's top-left corner, as the server's stamp service expects. */
export type StampPlacement = { page: number; x: number; y: number; width: number; height: number };
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
    if (!placement || placement.x < 0 || placement.y < 0 || placement.x + placement.width > 1000 || placement.y + placement.height > 1000)
        return false;
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

/**
 * Where to stamp the PDF at `pdfUrl`: the AI's suggestion when it is really blank, else a measured blank area.
 * `measured` is true when the local fallback was used. Throws when no blank area is large enough.
 */
export async function findStampPlacement(documentId: number, pdfUrl: string): Promise<{ placement: StampPlacement; measured: boolean }> {
    const response = await fetch(pdfUrl);
    if (!response.ok) throw new Error('The PDF to sign could not be read.');
    const pdf = await pdfjsLib.getDocument({ data: await response.arrayBuffer() }).promise;
    const form = new FormData();
    const previews: PagePreview[] = [];
    for (let number = 1; number <= Math.min(pdf.numPages, 5); number++) {
        const page = await pdf.getPage(number);
        const viewport = page.getViewport({ scale: 1.5 });
        const canvas = window.document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        const context = canvas.getContext('2d', { willReadFrequently: true })!;
        await page.render({ canvasContext: context, viewport }).promise;
        previews.push({ page: number, image: context.getImageData(0, 0, canvas.width, canvas.height) });
        const blob = await new Promise<Blob>((resolve, reject) =>
            canvas.toBlob((value) => (value ? resolve(value) : reject(new Error('Unable to render PDF page.'))), 'image/jpeg', 0.82),
        );
        form.append('thumbnails[]', new File([blob], `page-${number}.jpg`, { type: 'image/jpeg' }));
        form.append(`pages[${number - 1}][page]`, String(number));
    }

    let aiError: unknown = null;
    try {
        const placement = (await axios.post(route('referral-slips.stamp-placement', documentId), form)).data.placement as StampPlacement;
        if (isUsablePlacement(placement, previews)) return { placement, measured: false };
    } catch (error) {
        aiError = error;
    }

    const fallback = localFallbackPlacement(previews);
    if (!fallback)
        throw aiError ?? new Error('No blank area large enough for the signature stamp was found. Add a clear signature space to the PDF.');

    return { placement: fallback, measured: true };
}
