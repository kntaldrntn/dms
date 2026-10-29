// Linked documents are typed as a comma-separated list of barcodes ("C003192314, C003196677").
// The server splits on commas and spaces, so older space-separated input still saves correctly.
const SEPARATOR = /[\s,]+/;

/** The saved value (an array since the model cast, or an older plain string) as editable text. */
export function linkedDocumentsText(value: unknown): string {
    if (Array.isArray(value)) return value.filter(Boolean).join(', ');

    return typeof value === 'string' ? value.split(SEPARATOR).filter(Boolean).join(', ') : '';
}

/** The barcode currently being typed, i.e. the text after the last separator. */
export function barcodeBeingTyped(value: string): string {
    return value.split(SEPARATOR).pop() ?? '';
}

/** Replace the barcode being typed with the chosen one and start the next entry. */
export function withChosenBarcode(value: string, barcode: string): string {
    const barcodes = value.split(SEPARATOR).filter(Boolean);
    if (!SEPARATOR.test(value.slice(-1))) barcodes.pop();

    return [...barcodes, barcode].join(', ') + ', ';
}
