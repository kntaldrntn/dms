<script setup lang="ts">
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, RotateCcw, Search, Trash2, X } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { ref, watch } from 'vue';

interface Person {
    id: number;
    title: string | null;
    first_name: string;
    middle_initial: string | null;
    last_name: string;
    suffix: string | null;
    position: string | null;
    department_id: number | null;
    department: { id: number; code: string; name: string } | null;
    full_name: string;
    state: number;
}

const props = defineProps<{
    people: {
        data: Person[];
        last_page: number;
        total: number;
        from: number;
        to: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    departments: Array<{ id: number; code: string; name: string }>;
    filters?: { search?: string };
    flash?: { success?: string; error?: string };
}>();

const searchTerm = ref(props.filters?.search || '');
let searchTimeout: ReturnType<typeof setTimeout>;

watch(searchTerm, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(route('people.index'), { search: searchTerm.value }, { preserveState: true, preserveScroll: true });
    }, 300);
});

const clearSearch = () => {
    searchTerm.value = '';
};

const goToPage = (url: string | null) => {
    if (url) router.get(url, { search: searchTerm.value }, { preserveState: true });
};

const notyf = new Notyf({ duration: 3000, position: { x: 'right', y: 'top' } });

watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) notyf.success(flash.success);
        if (flash?.error) notyf.error(flash.error);
    },
    { immediate: true },
);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'People Directory', href: '/people' }];

const showDialog = ref(false);
const editingId = ref<number | null>(null);
const showDeleteModal = ref(false);
const itemToDeleteUrl = ref('');
const itemToDeleteName = ref('');

const form = useForm({
    title: '',
    first_name: '',
    middle_initial: '',
    last_name: '',
    suffix: '',
    position: '',
    department_id: null as number | null,
    state: 1,
});

const fields = (person: Person) => ({
    title: person.title ?? '',
    first_name: person.first_name,
    middle_initial: person.middle_initial ?? '',
    last_name: person.last_name,
    suffix: person.suffix ?? '',
    position: person.position ?? '',
    department_id: person.department_id,
    state: person.state,
});

function openAddDialog() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    showDialog.value = true;
}

function openEditDialog(person: Person) {
    editingId.value = person.id;
    form.clearErrors();
    Object.assign(form, fields(person));
    showDialog.value = true;
}

function openDeleteModal(person: Person) {
    itemToDeleteUrl.value = route('people.destroy', person.id);
    itemToDeleteName.value = person.full_name;
    showDeleteModal.value = true;
}

function reactivate(person: Person) {
    router.put(route('people.update', person.id), { ...fields(person), state: 1 }, { preserveScroll: true });
}

function submitForm() {
    const options = {
        onSuccess: () => {
            showDialog.value = false;
            form.reset();
        },
    };
    if (editingId.value !== null) {
        form.put(route('people.update', editingId.value), options);
    } else {
        form.post(route('people.store'), options);
    }
}

const inputClass =
    'w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 placeholder-slate-400 shadow-sm';
const labelClass = 'mb-1.5 block text-sm font-medium text-slate-700';
</script>

<template>
    <Head title="People Directory" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex min-h-screen w-full flex-col gap-6 bg-slate-100 p-6">
            <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-800">People Directory</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Office heads and frequent signatories. Intake autofill uses this list to complete sender names hidden under signatures.
                    </p>
                </div>

                <div class="flex flex-col items-center gap-3 sm:flex-row">
                    <div class="relative w-full sm:w-72">
                        <label for="people-search" class="sr-only">Search people</label>
                        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden="true" />
                        <input
                            id="people-search"
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search name or position..."
                            class="block w-full rounded-xl border border-slate-300 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                        />
                        <button
                            v-if="searchTerm"
                            type="button"
                            aria-label="Clear search"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                            @click="clearSearch"
                        >
                            ×
                        </button>
                    </div>

                    <button
                        type="button"
                        class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-emerald-700 sm:w-auto"
                        @click="openAddDialog"
                    >
                        <Plus class="h-4 w-4" aria-hidden="true" />
                        Add Person
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="border-b border-slate-200 bg-slate-100 text-xs uppercase tracking-wider text-slate-600">
                            <tr>
                                <th class="px-4 py-2.5 font-bold">Name</th>
                                <th class="px-4 py-2.5 font-bold">Position</th>
                                <th class="px-4 py-2.5 font-bold">Office</th>
                                <th class="w-32 px-4 py-2.5 font-bold">Status</th>
                                <th class="w-40 px-4 py-2.5 text-center font-bold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            <tr
                                v-for="person in people.data"
                                :key="person.id"
                                class="transition-colors hover:bg-slate-50"
                                :class="{ 'bg-slate-50 opacity-75': person.state === 0 }"
                            >
                                <td class="px-4 py-2 font-medium text-slate-800">{{ person.full_name }}</td>
                                <td class="px-4 py-2">{{ person.position || '—' }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="person.department" :title="person.department.name">{{ person.department.code }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span
                                        v-if="person.state === 1"
                                        class="inline-flex items-center rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700"
                                    >
                                        Active
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center rounded border border-slate-200 bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600"
                                    >
                                        Inactive
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex justify-center gap-1.5">
                                        <button
                                            type="button"
                                            class="rounded-md border border-slate-200 bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100"
                                            :aria-label="`Edit ${person.full_name}`"
                                            title="Edit"
                                            @click="openEditDialog(person)"
                                        >
                                            <Pencil class="h-4 w-4" aria-hidden="true" />
                                        </button>
                                        <button
                                            v-if="person.state === 1"
                                            type="button"
                                            class="rounded-md border border-red-100 bg-red-50 p-1.5 text-red-600 transition hover:bg-red-100"
                                            :aria-label="`Deactivate ${person.full_name}`"
                                            title="Deactivate"
                                            @click="openDeleteModal(person)"
                                        >
                                            <Trash2 class="h-4 w-4" aria-hidden="true" />
                                        </button>
                                        <button
                                            v-else
                                            type="button"
                                            class="rounded-md border border-blue-100 bg-blue-50 p-1.5 text-blue-600 transition hover:bg-blue-100"
                                            :aria-label="`Reactivate ${person.full_name}`"
                                            title="Reactivate"
                                            @click="reactivate(person)"
                                        >
                                            <RotateCcw class="h-4 w-4" aria-hidden="true" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="people.data.length === 0" class="py-12 text-center">
                        <h3 class="text-base font-semibold text-slate-900">No people found</h3>
                        <p class="mt-1 text-sm text-slate-500">
                            {{
                                searchTerm
                                    ? 'Nothing matches your search.'
                                    : 'Add office heads and frequent signatories, e.g. the City Administrator.'
                            }}
                        </p>
                    </div>
                </div>

                <div v-if="people.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-sm text-slate-500">Showing {{ people.from }} to {{ people.to }} of {{ people.total }}</p>
                    <div class="flex gap-1">
                        <button
                            v-for="(link, index) in people.links.slice(1, -1)"
                            :key="index"
                            type="button"
                            :disabled="!link.url"
                            :aria-current="link.active ? 'page' : undefined"
                            class="rounded-lg border px-3 py-1 text-sm font-medium transition"
                            :class="
                                link.active
                                    ? 'border-emerald-600 bg-emerald-600 text-white shadow-sm'
                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                            "
                            @click="goToPage(link.url)"
                        >
                            {{ link.label }}
                        </button>
                    </div>
                </div>
            </div>

            <DeleteConfirmationModal
                v-model:show="showDeleteModal"
                :deleteUrl="itemToDeleteUrl"
                :item-name="itemToDeleteName"
                title="Deactivate Person"
            />

            <div
                v-if="showDialog"
                class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4 backdrop-blur-sm"
                @keydown.esc="showDialog = false"
            >
                <div
                    class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 shadow-xl"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="person-dialog-title"
                >
                    <button
                        type="button"
                        class="absolute right-4 top-4 rounded-full p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                        aria-label="Close"
                        @click="showDialog = false"
                    >
                        <X class="h-5 w-5" aria-hidden="true" />
                    </button>

                    <h2 id="person-dialog-title" class="mb-6 pr-8 text-lg font-bold tracking-tight text-slate-800">
                        {{ editingId !== null ? 'Edit Person' : 'Add Person' }}
                    </h2>

                    <form class="space-y-4" @submit.prevent="submitForm">
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label :class="labelClass" for="title">Title</label>
                                <input id="title" v-model="form.title" type="text" :class="inputClass" placeholder="Atty." />
                                <InputError :message="form.errors.title" />
                            </div>
                            <div class="col-span-2">
                                <label :class="labelClass" for="first_name">First name</label>
                                <input id="first_name" v-model="form.first_name" type="text" :class="inputClass" required />
                                <InputError :message="form.errors.first_name" />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label :class="labelClass" for="middle_initial">Middle initial</label>
                                <input
                                    id="middle_initial"
                                    v-model="form.middle_initial"
                                    type="text"
                                    :class="inputClass"
                                    placeholder="L."
                                    maxlength="5"
                                />
                                <InputError :message="form.errors.middle_initial" />
                            </div>
                            <div class="col-span-2">
                                <label :class="labelClass" for="last_name">Surname</label>
                                <input id="last_name" v-model="form.last_name" type="text" :class="inputClass" required />
                                <InputError :message="form.errors.last_name" />
                            </div>
                        </div>
                        <div>
                            <label :class="labelClass" for="suffix">Suffix or degree</label>
                            <input id="suffix" v-model="form.suffix" type="text" :class="inputClass" placeholder="Jr., CPA, MIT" />
                            <InputError :message="form.errors.suffix" />
                        </div>
                        <div>
                            <label :class="labelClass" for="position">Position</label>
                            <input id="position" v-model="form.position" type="text" :class="inputClass" placeholder="City Administrator" />
                            <InputError :message="form.errors.position" />
                        </div>
                        <div>
                            <label :class="labelClass" for="department_id">Office</label>
                            <select id="department_id" v-model="form.department_id" :class="inputClass">
                                <option :value="null">Not a city office (external)</option>
                                <option v-for="department in departments" :key="department.id" :value="department.id">
                                    {{ department.code }} — {{ department.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.department_id" />
                        </div>

                        <div class="flex justify-end gap-3 pt-3">
                            <button
                                type="button"
                                class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                                @click="showDialog = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50"
                                :disabled="form.processing"
                            >
                                {{ form.processing ? 'Saving...' : editingId !== null ? 'Update Person' : 'Add Person' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
