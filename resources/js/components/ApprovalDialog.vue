<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import OfficePicker from '@/components/OfficePicker.vue';
import { findStampPlacement } from '@/lib/stampPlacement';
import { Link, useForm } from '@inertiajs/vue3';
import { Loader2, X } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import { computed, ref } from 'vue';

type Office = { id: number; code: string; name: string };

/**
 * Records an executive approval (City Administrator or City Mayor) and routes the document on.
 * Paper (hybrid): the executive signs the paper; office staff record it here, with an optional scan of the signed
 * copy. Paperless: the signed-in executive's e-signature is stamped and signed with their PNPKI certificate.
 */
const props = defineProps<{
    document: any;
    departments: Office[];
    hasCertificate: boolean;
    approveUrl: string;
    /** Name of the signing executive, e.g. "City Administrator". */
    executive: string;
    /** ADM may send the document on to the City Mayor instead of the departments. */
    allowMayor?: boolean;
}>();
const emit = defineEmits<{ close: [] }>();

const notyf = new Notyf({ duration: 5000, position: { x: 'right', y: 'top' } });
const paperless = computed(() => Boolean(props.document.is_paperless));
const suggestions = computed(() =>
    Object.fromEntries(
        (props.document.ai_routing_suggestions ?? []).map((suggestion: any) => [
            Number(suggestion.department_id),
            { reason: suggestion.reason, priority: suggestion.priority },
        ]),
    ),
);

// Pre-tick the AI's suggested departments, Primary first.
const suggested = (props.document.ai_routing_suggestions ?? [])
    .map((suggestion: any) => Number(suggestion.department_id))
    .filter((id: number) => props.departments.some((office) => office.id === id));
const routedTo: number[] = props.document.is_confidential ? suggested.slice(0, 1) : suggested;
const form = useForm({
    workflow_version: props.document.workflow_version as number,
    target: 'departments' as 'ocm' | 'departments',
    routed_to: routedTo,
    primary_office_id: (Number(
        (props.document.ai_routing_suggestions ?? []).find((suggestion: any) => suggestion.priority === 'Primary')?.department_id,
    ) ||
        routedTo[0] ||
        null) as number | null,
    remarks: '',
    signed_scan: null as File | null,
    stamp_placement: null as Record<string, number> | null,
    certificate_pin: '',
});
const preparingStamp = ref(false);
const toDepartments = computed(() => form.target === 'departments');
const officeCode = (id: number) => props.departments.find((office) => office.id === id)?.code ?? id;

async function submit() {
    try {
        if (paperless.value) {
            preparingStamp.value = true;
            if (!props.document.stamp_source_url) throw new Error('Paperless approval needs the uploaded PDF.');
            const { placement, measured } = await findStampPlacement(props.document.id, props.document.stamp_source_url);
            if (measured) notyf.success('A blank lower-page area was measured and used for the signature.');
            form.stamp_placement = placement;
        }
    } catch (error: any) {
        notyf.error(error?.response?.data?.error || error?.message || 'No place for the signature was found on the PDF.');
        return;
    } finally {
        preparingStamp.value = false;
    }

    form.transform((data) => {
        const payload: Record<string, unknown> = { workflow_version: data.workflow_version, remarks: data.remarks };
        if (props.allowMayor) payload.target = data.target;
        if (toDepartments.value) Object.assign(payload, { routed_to: data.routed_to, primary_office_id: data.primary_office_id });
        if (paperless.value) Object.assign(payload, { stamp_placement: data.stamp_placement, certificate_pin: data.certificate_pin });
        else if (data.signed_scan) payload.signed_scan = data.signed_scan;
        return payload;
    }).post(props.approveUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => emit('close'),
        // The PIN never stays in the page after a submit.
        onFinish: () => form.reset('certificate_pin'),
    });
}

const fieldClass =
    'w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 focus:outline-none';
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @keydown.esc="emit('close')">
        <form
            class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-lg border border-slate-200 bg-white"
            role="dialog"
            aria-modal="true"
            aria-labelledby="approve-title"
            @submit.prevent="submit"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 id="approve-title" class="text-base font-semibold text-slate-800">
                    {{ paperless ? 'Approve & Route' : `Record ${executive} approval` }}
                    <span class="font-mono text-emerald-700">{{ document.barcode }}</span>
                </h2>
                <button
                    type="button"
                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                    aria-label="Close"
                    @click="emit('close')"
                >
                    <X class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                <p v-if="!paperless" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    Paper route: record this once the {{ executive }} has signed the paper copy. Then send the paper copy to the Primary office.
                </p>
                <p v-if="form.errors.workflow_version || form.errors.document" role="alert" class="text-sm text-red-600">
                    {{ form.errors.workflow_version || form.errors.document }}
                </p>

                <fieldset v-if="allowMayor">
                    <legend class="mb-1 text-sm text-slate-700">Send to</legend>
                    <div class="flex gap-4 text-sm">
                        <label class="flex items-center gap-1.5">
                            <input v-model="form.target" type="radio" value="departments" class="text-emerald-600 focus:ring-emerald-600" />
                            Departments
                        </label>
                        <label class="flex items-center gap-1.5">
                            <input v-model="form.target" type="radio" value="ocm" class="text-emerald-600 focus:ring-emerald-600" />
                            City Mayor (OCM approval)
                        </label>
                    </div>
                </fieldset>

                <div v-if="toDepartments" class="flex min-h-0 flex-col">
                    <OfficePicker
                        v-model="form.routed_to"
                        v-model:primary="form.primary_office_id"
                        :offices="departments"
                        :suggestions="suggestions"
                        :single="document.is_confidential"
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
                    <InputError class="text-xs" :message="form.errors.stamp_placement || form.errors.attached_file" />
                </div>
                <div v-else>
                    <label for="approve_signed_scan" class="mb-1 block text-sm text-slate-700">
                        Scan of the signed copy <span class="text-slate-400">(optional)</span>
                    </label>
                    <input
                        id="approve_signed_scan"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png"
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
                    @click="emit('close')"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="form.processing || preparingStamp || (toDepartments && !form.routed_to.length)"
                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50"
                >
                    <Loader2 v-if="form.processing || preparingStamp" class="h-4 w-4 animate-spin" aria-hidden="true" />
                    {{ preparingStamp ? 'Placing signature...' : toDepartments ? 'Approve & route' : 'Approve & send to City Mayor' }}
                </button>
            </div>
        </form>
    </div>
</template>
