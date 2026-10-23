import { BrowserMultiFormatReader } from '@zxing/browser';
import { BarcodeFormat, DecodeHintType } from '@zxing/library';
import * as pdfjsLib from 'pdfjs-dist';
import Tesseract from 'tesseract.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = `//cdnjs.cloudflare.com/ajax/libs/pdf.js/${pdfjsLib.version}/pdf.worker.min.mjs`;

export type IntakeScan = { text: string; barcode: string | null };

// Signatures usually sit on page 2 or the last page, and page 1 is often an attached referral slip,
// so page 1 alone misses the sender. Three pages keep OCR time and AI tokens bounded.
const pagesToRead = (pageCount: number) => [...new Set([1, 2, pageCount])].filter((page) => page <= pageCount);

const BARCODE_PATTERN = /^[A-Z][0-9]{9}$/;

/** Read the tracking sticker exactly; OCR often misreads it (e.g. "CoR3192314"). */
function decodeBarcode(canvas: HTMLCanvasElement): string | null {
    const hints = new Map<DecodeHintType, unknown>([
        [DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128, BarcodeFormat.CODE_39]],
        [DecodeHintType.TRY_HARDER, true],
    ]);
    try {
        const text = new BrowserMultiFormatReader(hints).decodeFromCanvas(canvas).getText().trim().toUpperCase();

        return BARCODE_PATTERN.test(text) ? text : null;
    } catch {
        // No readable sticker (clipped or missing): the AI falls back to the OCR text.
        return null;
    }
}

async function renderPdfPage(pdf: pdfjsLib.PDFDocumentProxy, pageNumber: number): Promise<HTMLCanvasElement> {
    const page = await pdf.getPage(pageNumber);
    const viewport = page.getViewport({ scale: 2.0 });
    const canvas = document.createElement('canvas');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    await page.render({ canvasContext: canvas.getContext('2d') as CanvasRenderingContext2D, viewport }).promise;

    return canvas;
}

async function imageCanvas(file: File): Promise<HTMLCanvasElement> {
    const bitmap = await createImageBitmap(file);
    const canvas = document.createElement('canvas');
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0);
    bitmap.close();

    return canvas;
}

/** OCR a scanned PDF or image in the browser and decode its barcode sticker. */
export async function scanIntakeFile(file: File): Promise<IntakeScan> {
    const worker = await Tesseract.createWorker('eng');
    try {
        if (file.type !== 'application/pdf') {
            const canvas = await imageCanvas(file);
            const { data } = await worker.recognize(canvas);

            return { text: data.text, barcode: decodeBarcode(canvas) };
        }

        const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        const pages: string[] = [];
        let barcode: string | null = null;
        for (const pageNumber of pagesToRead(pdf.numPages)) {
            const canvas = await renderPdfPage(pdf, pageNumber);
            barcode ??= decodeBarcode(canvas);
            const { data } = await worker.recognize(canvas);
            pages.push(`--- Page ${pageNumber} ---\n${data.text}`);
        }

        return { text: pages.join('\n'), barcode };
    } finally {
        await worker.terminate();
    }
}
