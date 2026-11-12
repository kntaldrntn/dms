<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Search, Tag as TagIcon } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { computed, ref, watch } from 'vue';

type Office = { id: number; code: string; name: string };
type TagInfo = {
    label: string;
    tag: string;
    note: string;
    tagged_by: string | null;
    tagged_at: string;
    acknowledged_by: string | null;
    acknowledged_at: string | null;
};
type Row = {
    id: number;
    barcode: string;
    subject_matter: string;
    details_hidden: boolean;
    document_type: string | null;
    category: string;
    office: Office | null;
    held_since: string;
    held_minutes: number;
    allowed_minutes: number;
    tag: TagInfo | null;
};

const props = defineProps<{
    rows: Row[];
    tags: { value: string; label: string }[];
    offices: Office[];
    asOf: string;
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

const search = ref('');
const officeFilter = ref<number | ''>('');
const tagFilter = ref<'all' | 'untagged' | 'tagged'>('all');

const filteredRows = computed(() => {
    const term = search.value.trim().toLowerCase();
    return props.rows.filter((row) => {
        if (officeFilter.value !== '' && row.office?.id !== officeFilter.value) return false;
        if (tagFilter.value === 'untagged' && row.tag) return false;
        if (tagFilter.value === 'tagged' && !row.tag) return false;
        return !term || row.barcode.toLowerCase().includes(term) || row.subject_matter.toLowerCase().includes(term);
    });
});
const untaggedCount = computed(() => props.rows.filter((row) => !row.tag).length);

/** "2 d 5 h" from minutes: the time over the office's allowed holding time. */
function duration(minutes: number): string {
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);
    if (days > 0) return `${days} d ${hours} h`;
    return hours > 0 ? `${hours} h` : `${minutes % 60} min`;
}

const tagging = ref<Row | null>(null);
const form = useForm({ tag: '', note: '' });
function openTag(row: Row) {
    tagging.value = row;
    form.reset();
    form.clearErrors();
    form.tag = props.tags[0]?.value ?? '';
}
function submitTag() {
    if (!tagging.value) return;
    form.post(route('delinquency.tag', tagging.value.id), {
        preserveScroll: true,
        onSuccess: () => (tagging.value = null),
    });
}

const tagTone: Record<string, string> = {
    followed_up: 'border-blue-200 bg-blue-50 text-blue-800',
    justified: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    escalated: 'border-rose-200 bg-rose-50 text-rose-800',
};
const fieldClass =
    'h-9 rounded-md border border-slate-300 bg-white px-2.5 text-sm text-slate-700 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30';
</script>

<template>
    <Head title="Delinquency Monitor" />
    <AppLayout :breadcrumbs="[{ title: 'Delinquency Monitor', href: '/delinquency-monitor' }]">
        <div class="flex min-h-screen w-full flex-col gap-4 bg-slate-50 p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-slate-800">Delinquency Monitor</h1>
                    <p class="text-sm text-slate-500">
                        Documents an office has held longer than its allowed time (24 hours unless calibrated). Tag each one with a follow-up note:
                        the office sees it in its inbox and bell.
                    </p>
                </div>
                <p class="text-xs text-slate-500">
                    As of {{ asOf }} · <strong class="text-slate-700">{{ rows.length }}</strong> delinquent ·
                    <strong class="text-slate-700">{{ untaggedCount }}</strong> not yet tagged
                </p>
            </div>

            <div class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-3">
                <div class="flex flex-col gap-1">
                    <label for="delinquency_search" class="text-xs font-medium text-slate-600">Search</label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" aria-hidden="true" />
                        <input
                            id="delinquency_search"
                            v-model="search"
                            type="search"
                            placeholder="Barcode or subject"
                            :class="[fieldClass, 'w-60 pl-8']"
                        />
                    </div>
                </div>
                <div class="flex flex-col gap-1">
                    <label for="delinquency_office" class="text-xs font-medium text-slate-600">Office</label>
                    <select id="delinquency_office" v-model="officeFilter" :class="fieldClass">
                        <option value="">All offices</option>
                        <option v-for="office in offices" :key="office.id" :value="office.id">{{ office.code }} · {{ office.name }}</option>
                    </select>
                </div>
                <div class="flex flex-col gap-1">
                    <label for="delinquency_tagged" class="text-xs font-medium text-slate-600">Tag</label>
                    <select id="delinquency_tagged" v-model="tagFilter" :class="fieldClass">
                        <option value="all">All</option>
                        <option value="untagged">Not yet tagged</option>
                        <option value="tagged">Tagged</option>
                    </select>
                </div>
            </div>

            <section class="overflow-x-auto rounded-lg border border-slate-200 bg-white" aria-label="Delinquent documents">
                <table class="w-full text-left text-sm text-slate-700">
                    <thead class="border-b border-slate-200 text-xs uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-2 font-medium">Barcode</th>
                            <th class="px-4 py-2 font-medium">Subject Matter</th>
                            <th class="px-4 py-2 font-medium">Office</th>
                            <th class="px-4 py-2 font-medium">Held since</th>
                            <th class="px-4 py-2 font-medium">Over by</th>
                            <th class="px-4 py-2 font-medium">ADM tag</th>
                            <th class="px-4 py-2 text-center font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in filteredRows" :key="row.id" class="align-top hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-2.5 font-mono">
                                <span v-if="row.details_hidden" class="text-slate-800">{{ row.barcode }}</span>
                                <Link v-else :href="route('documents.show', row.id)" class="text-emerald-700 hover:underline">{{ row.barcode }}</Link>
                            </td>
                            <td class="px-4 py-2.5">
                                <p class="line-clamp-2" :class="row.details_hidden ? 'font-medium text-amber-800' : 'text-slate-800'">
                                    {{ row.subject_matter }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ row.document_type }} · {{ row.category }}</p>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5">
                                <span
                                    class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs font-semibold text-slate-700"
                                    :title="row.office?.name"
                                >
                                    {{ row.office?.code }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2.5 text-xs text-slate-600">{{ row.held_since }}</td>
                            <td class="whitespace-nowrap px-4 py-2.5 font-medium text-rose-700">
                                {{ duration(row.held_minutes - row.allowed_minutes) }}
                                <span class="block text-[11px] font-normal text-slate-500">allowed {{ duration(row.allowed_minutes) }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-2.5">
                                <template v-if="row.tag">
                                    <span class="inline-block rounded border px-1.5 py-0.5 text-xs font-semibold" :class="tagTone[row.tag.tag]">
                                        {{ row.tag.label }}
                                    </span>
                                    <p class="mt-1 line-clamp-3 text-xs text-slate-700">{{ row.tag.note }}</p>
                                    <p class="mt-0.5 text-[11px] text-slate-500">{{ row.tag.tagged_by }} · {{ row.tag.tagged_at }}</p>
                                    <p class="text-[11px]" :class="row.tag.acknowledged_at ? 'text-emerald-700' : 'text-amber-700'">
                                        {{ row.tag.acknowledged_at ? `Noted by ${row.tag.acknowledged_by}` : 'Not yet noted by the office' }}
                                    </p>
                                </template>
                                <span v-else class="text-xs text-slate-500">Not tagged</span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
                                    :aria-label="`${row.tag ? 'Tag again' : 'Tag'} ${row.barcode}`"
                                    @click="openTag(row)"
                                >
                                    <TagIcon class="h-3.5 w-3.5" aria-hidden="true" /> {{ row.tag ? 'Tag again' : 'Tag' }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="filteredRows.length === 0">
                            <td colspan="7" class="py-10 text-center text-slate-500">
                                {{
                                    rows.length
                                        ? 'No delinquent documents match the filters.'
                                        : 'No delinquent documents: every office is within its allowed time.'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <Dialog :open="tagging !== null" @update:open="(open) => !open && (tagging = null)">
                <DialogContent>
                    <form class="space-y-4" @submit.prevent="submitTag">
                        <DialogHeader>
                            <DialogTitle>Tag {{ tagging?.barcode }}</DialogTitle>
                            <DialogDescription>
                                {{ tagging?.office?.name }} has held it since {{ tagging?.held_since }}. The office sees this tag and note in its
                                inbox.
                            </DialogDescription>
                        </DialogHeader>

                        <fieldset>
                            <legend class="mb-1.5 text-sm font-medium text-slate-700">Tag</legend>
                            <div class="flex flex-wrap gap-2">
                                <label
                                    v-for="option in tags"
                                    :key="option.value"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-sm focus-within:ring-2 focus-within:ring-emerald-500/40"
                                    :class="
                                        form.tag === option.value
                                            ? 'border-emerald-500 bg-emerald-50 text-emerald-800'
                                            : 'border-slate-300 text-slate-700'
                                    "
                                >
                                    <input v-model="form.tag" type="radio" name="tag" :value="option.value" class="sr-only" />
                                    {{ option.label }}
                                </label>
                            </div>
                            <InputError :message="form.errors.tag" />
                        </fieldset>

                        <div>
                            <Label for="tag_note">Note for the office</Label>
                            <textarea
                                id="tag_note"
                                v-model="form.note"
                                rows="3"
                                maxlength="500"
                                aria-describedby="tag_note_hint"
                                class="mt-1.5 w-full rounded-md border border-slate-300 px-2.5 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/30"
                            ></textarea>
                            <p id="tag_note_hint" class="text-xs text-slate-500">
                                E.g. who you talked to, why it is late, or what the office must do.
                            </p>
                            <InputError :message="form.errors.note" />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="secondary" @click="tagging = null">Cancel</Button>
                            <Button type="submit" :disabled="form.processing">Save tag</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
