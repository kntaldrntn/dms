import { usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

export interface IntakeDocumentType {
    id: number;
    document_type: string;
    signature_type: 'digital' | 'handwritten' | null;
    may_be_confidential: boolean;
    default_transaction_type_id: number | null;
    classifications: Array<{ id: number }>;
}

interface IntakeForm {
    document_type_id: number | string;
    transaction_type_id: number | string;
    classification_id: number | string;
    is_confidential: boolean;
    route_mode: 'paperless' | 'paper';
}

/**
 * FGD document-type rulebook on the intake forms: the type proposes the category, limits the
 * classifications and decides whether the document may travel paperless. The server enforces the same rules.
 */
export function useIntakeRulebook(
    form: IntakeForm,
    documentTypes: () => IntakeDocumentType[],
    classifications: () => Array<{ id: number; name: string }>,
) {
    const selectedType = computed(() => documentTypes().find((type) => type.id === Number(form.document_type_id)) ?? null);

    const allowedClassifications = computed(() => {
        const allowedIds = selectedType.value?.classifications.map((classification) => classification.id) ?? [];

        return allowedIds.length ? classifications().filter((classification) => allowedIds.includes(classification.id)) : classifications();
    });

    // The paperless (PNPKI) route can be switched off server-wide (DMS_PAPERLESS_ENABLED); then everything goes by paper.
    const page = usePage<{ features?: { paperless?: boolean } }>();
    const paperlessEnabled = computed(() => Boolean(page.props.features?.paperless));
    const paperlessAllowed = computed(() => paperlessEnabled.value && selectedType.value?.signature_type === 'digital' && !form.is_confidential);

    // Only on change, so the edit form keeps the values already saved on the document.
    watch(
        () => form.document_type_id,
        () => {
            const type = selectedType.value;
            if (!type) return;
            if (type.default_transaction_type_id) form.transaction_type_id = type.default_transaction_type_id;
            if (
                form.classification_id &&
                !allowedClassifications.value.some((classification) => classification.id === Number(form.classification_id))
            ) {
                form.classification_id = '';
            }
        },
    );

    // Rulebook default route; Records may still choose paper for a paperless-capable document.
    watch([() => form.document_type_id, () => form.is_confidential], () => {
        form.route_mode = paperlessAllowed.value ? 'paperless' : 'paper';
    });

    if (!paperlessEnabled.value) form.route_mode = 'paper';

    return { selectedType, allowedClassifications, paperlessAllowed, paperlessEnabled };
}
