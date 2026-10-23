<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps<{
    canSign: boolean;
    certificate: { subject: string; valid_until: string; is_valid: boolean } | null;
    hasSignatureImage: boolean;
    status?: string;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Signing certificate', href: '/settings/certificate' }];

const form = useForm({
    certificate: null as File | null,
    certificate_pin: '',
});

function upload() {
    form.post(route('certificate.update'), {
        forceFormData: true,
        preserveScroll: true,
        // The PIN is never kept in the page after a submit, successful or not.
        onFinish: () => form.reset('certificate_pin'),
        onSuccess: () => form.reset(),
    });
}

function remove() {
    if (window.confirm('Remove your signing certificate? You will not be able to approve paperless documents until you upload one again.')) {
        router.delete(route('certificate.destroy'), { preserveScroll: true });
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Signing certificate" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Signing certificate"
                    description="Your own PNPKI certificate (.p12). Paperless approvals are digitally signed with it; you type its PIN each time you sign."
                />

                <p v-if="!canSign" class="text-sm text-slate-600">
                    Only approvers (City Administrator, City Mayor, Department Heads) sign documents.
                </p>

                <template v-else>
                    <div class="rounded-md border border-slate-200 p-3 text-sm" role="status">
                        <template v-if="certificate">
                            <p class="font-medium text-slate-800">{{ certificate.subject }}</p>
                            <p :class="certificate.is_valid ? 'text-slate-600' : 'font-medium text-red-600'">
                                {{ certificate.is_valid ? 'Valid until' : 'Expired on' }} {{ certificate.valid_until }}
                            </p>
                        </template>
                        <p v-else class="text-slate-600">No certificate uploaded yet.</p>
                        <p v-if="!hasSignatureImage" class="mt-1 text-amber-700">
                            Your e-signature image is not on file. Ask the System Administrator to upload it in User Management; it is the visible
                            part of the stamp.
                        </p>
                    </div>

                    <form class="space-y-4" @submit.prevent="upload">
                        <div class="grid gap-2">
                            <Label for="certificate">{{ certificate ? 'Replace certificate' : 'Certificate file' }} (.p12 or .pfx)</Label>
                            <input
                                id="certificate"
                                type="file"
                                accept=".p12,.pfx"
                                required
                                class="w-full rounded-md border border-slate-300 bg-white py-1 pl-1 pr-2.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-2.5 file:py-1 file:text-xs file:font-medium file:text-slate-700"
                                @change="(e: any) => (form.certificate = e.target.files[0] ?? null)"
                            />
                            <InputError :message="form.errors.certificate" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="certificate_pin">Certificate PIN</Label>
                            <Input id="certificate_pin" v-model="form.certificate_pin" type="password" autocomplete="off" required />
                            <p class="text-xs text-slate-500">Used once to check that the certificate opens. It is not saved.</p>
                            <InputError :message="form.errors.certificate_pin" />
                        </div>

                        <div class="flex items-center gap-3">
                            <Button :disabled="form.processing">{{ certificate ? 'Replace certificate' : 'Upload certificate' }}</Button>
                            <button v-if="certificate" type="button" class="text-sm text-red-600 hover:underline" @click="remove">Remove</button>
                            <p v-if="status === 'certificate-saved'" class="text-sm text-slate-600">Saved</p>
                        </div>
                    </form>
                </template>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
