<script setup lang="ts">
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, RotateCcw, History } from 'lucide-vue-next';
import { Notyf } from 'notyf';
import 'notyf/notyf.min.css';
import { ref, watch } from 'vue';

const props = defineProps<{
    users: {
        data: Array<{
            id: number;
            name: string;
            email: string;
            role: string;
            sex: string | null;
            state: number;
            department_id: number | null;
            department?: {
                id: number;
                name: string;
            };
            login_histories?: Array<{
                id: number;
                ip_address: string | null;
                user_agent: string | null;
                login_at: string;
            }>;
            is_online?: boolean;
        }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number;
        to: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    departments: Array<{
        id: number;
        name: string;
    }>;
    filters?: {
        search?: string;
    };
    flash?: {
        success?: string;
        error?: string;
    };
}>();

// Search state
const searchTerm = ref(props.filters?.search || '');
const isSearching = ref(false);

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;

const performSearch = () => {
    clearTimeout(searchTimeout);

    searchTimeout = setTimeout(() => {
        isSearching.value = true;

        router.get(
            route('users.index'),
            { search: searchTerm.value },
            {
                preserveState: true,
                preserveScroll: true,
                onFinish: () => {
                    isSearching.value = false;
                },
            },
        );
    }, 300);
};

watch(searchTerm, performSearch);

const clearSearch = () => {
    searchTerm.value = '';
    router.get(route('users.index'));
};

const goToPage = (url: string) => {
    if (!url) return;
    router.get(
        url,
        { search: searchTerm.value },
        { preserveState: true, preserveScroll: false },
    );
};

const notyf = new Notyf({
    duration: 3000,
    position: { x: 'right', y: 'top' },
});

watch(
    () => props.flash,
    (flash) => {
        if (flash?.success) notyf.success(flash.success);
        if (flash?.error) notyf.error(flash.error);
    },
    { immediate: true },
);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'User Management',
        href: '/users',
    },
];

// Modal states
const showDialog = ref(false);
const isEdit = ref(false);
const editingId = ref<number | null>(null);

const showDeleteModal = ref(false);
const itemToDeleteUrl = ref('');
const itemToDeleteName = ref('');

const showHistoryModal = ref(false);
const historyUser = ref<any>(null);

// Form state
const form = useForm({
    name: '',
    email: '',
    role: 'user',
    sex: '',
    department_id: '' as number | string,
    state: 1,
    password: '',
    password_confirmation: '',
});

function openAddDialog() {
    isEdit.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
    form.state = 1;
    form.role = 'user';
    showDialog.value = true;
}

function openEditDialog(user: any) {
    isEdit.value = true;
    editingId.value = user.id;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    form.sex = user.sex || '';
    form.department_id = user.department_id || '';
    form.state = user.state;
    form.password = ''; // Leave blank for edit unless they want to change it
    form.password_confirmation = '';
    showDialog.value = true;
}

function openDeleteModal(user: any) {
    itemToDeleteUrl.value = route('users.destroy', user.id);
    itemToDeleteName.value = user.name;
    showDeleteModal.value = true;
}

function openHistoryModal(user: any) {
    historyUser.value = user;
    showHistoryModal.value = true;
}

function reactivateUser(user: any) {
    router.put(route('users.update', user.id), {
        name: user.name,
        email: user.email,
        role: user.role,
        sex: user.sex,
        department_id: user.department_id,
        state: 1 
    }, { preserveScroll: true });
}

function submitForm() {
    if (isEdit.value && editingId.value !== null) {
        form.put(route('users.update', editingId.value), {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    } else {
        form.post(route('users.store'), {
            onSuccess: () => {
                showDialog.value = false;
                form.reset();
            },
        });
    }
}

// Format Role for display
const formatRole = (role: string) => {
    return role.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
};

// Format Date for history
const formatDate = (dateString: string) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
};

const formatRelativeTime = (dateString: string) => {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    const now = new Date();
    const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (diffInSeconds < 60) return 'Just now';
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
    if (diffInSeconds < 2592000) return `${Math.floor(diffInSeconds / 86400)}d ago`;
    
    // Fall back to actual date if it's over a month old
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-50/50 min-h-screen">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">System Users</h1>
                    <p class="text-sm text-slate-500 mt-1">Manage personnel access and roles across departments.</p>
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search by name or email..."
                            class="block w-full rounded-xl border border-slate-200 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm"
                        />
                        <button
                            v-if="searchTerm"
                            @click="clearSearch"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-slate-400 hover:text-slate-600"
                        >
                            ×
                        </button>
                    </div>

                    <button
                        class="w-full sm:w-auto flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-emerald-700"
                        @click="openAddDialog"
                    >
                        <Plus class="h-4 w-4" />
                        Add User
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-2xl border border-slate-100 shadow-[0_2px_10px_-3px_rgba(6,81,237,0.03)] overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <thead class="bg-slate-50/80 text-xs text-slate-500 uppercase tracking-wider border-b border-slate-100">
                            <tr>
                                <th class="px-6 py-4 font-semibold">User Details</th>
                                <th class="px-6 py-4 font-semibold">Role</th>
                                <th class="px-6 py-4 font-semibold">Department</th>
                                <th class="px-6 py-4 font-semibold">Last Active</th>
                                <th class="px-6 py-4 font-semibold">Status</th>
                                <th class="px-6 py-4 text-center font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="user in users.data" :key="user.id" class="transition-colors hover:bg-slate-50/50" :class="{'opacity-60 bg-slate-50/30': user.state === 0}">
                                <td class="px-6 py-4">
                                    <div class="flex flex-col">
                                        <span class="font-medium text-slate-800">{{ user.name }}</span>
                                        <span class="text-xs text-slate-500">{{ user.email }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">
                                        {{ formatRole(user.role) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-600">
                                    {{ user.department?.name || 'No Department' }}
                                </td>
                                <td class="px-6 py-4">
                                    <!-- If they clicked something in the last 5 minutes -->
                                    <div v-if="user.is_online" class="flex items-center text-xs font-semibold text-emerald-600">
                                        <span class="relative flex h-2 w-2 mr-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                        </span>
                                        Online Now
                                    </div>
                                    
                                    <!-- If they are offline, show their last login time -->
                                    <span v-else-if="user.login_histories?.length" class="text-slate-500">
                                        {{ formatRelativeTime(user.login_histories[0].login_at) }}
                                    </span>
                                    
                                    <!-- Fresh account -->
                                    <span v-else class="text-slate-400 italic">
                                        Never
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span v-if="user.state === 1" class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span> Active
                                    </span>
                                    <span v-else class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-slate-100 text-slate-500 border border-slate-200">
                                        <span class="w-1.5 h-1.5 bg-slate-400 rounded-full mr-1.5"></span> Inactive
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-center gap-2">
                                        <button class="rounded-lg bg-amber-50 p-2 text-amber-600 transition hover:bg-amber-100 border border-amber-100" @click="openHistoryModal(user)" title="Audit Trail">
                                            <History class="h-4 w-4" />
                                        </button>

                                        <button class="rounded-lg bg-slate-50 p-2 text-slate-600 transition hover:bg-slate-100 border border-slate-200" @click="openEditDialog(user)" title="Edit">
                                            <Pencil class="h-4 w-4" />
                                        </button>

                                        <button v-if="user.state === 1" class="rounded-lg bg-red-50 p-2 text-red-600 transition hover:bg-red-100 border border-red-100" @click="openDeleteModal(user)" title="Deactivate">
                                            <Trash2 class="h-4 w-4" />
                                        </button>

                                        <button v-else class="rounded-lg bg-blue-50 p-2 text-blue-600 transition hover:bg-blue-100 border border-blue-100" @click="reactivateUser(user)" title="Reactivate">
                                            <RotateCcw class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Empty State -->
                    <div v-if="users.data.length === 0" class="py-16 text-center">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-4">
                                <Search class="h-6 w-6 text-slate-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900">No users found</h3>
                            <p class="mt-1 mb-6 text-sm text-slate-500 max-w-sm">Try adjusting your search criteria or create a new user account.</p>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="users.last_page > 1" class="flex items-center justify-between border-t border-slate-100 bg-slate-50/50 px-6 py-4">
                    <p class="text-sm text-slate-500">
                        Showing <span class="font-medium text-slate-700">{{ users.from }}</span> to <span class="font-medium text-slate-700">{{ users.to }}</span> of <span class="font-medium text-slate-700">{{ users.total }}</span> results
                    </p>
                    <div class="flex gap-1">
                        <button v-for="(link, index) in users.links.slice(1, -1)" :key="index" @click="goToPage(String(link.url))" :disabled="!link.url" class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition" :class="[link.active ? 'bg-emerald-600 text-white shadow-sm' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-50']">
                            {{ link.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Soft Delete Modal -->
            <DeleteConfirmationModal v-model:show="showDeleteModal" :deleteUrl="itemToDeleteUrl" :item-name="itemToDeleteName" title="Deactivate User" />

            <!-- Form Modal -->
            <Transition name="fade">
                <div v-if="showDialog" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl border border-slate-100 max-h-[90vh] overflow-y-auto">
                        <h2 class="mb-6 text-lg font-bold text-slate-800 tracking-tight">
                            {{ isEdit ? 'Edit User' : 'Add New User' }}
                        </h2>
                        <form @submit.prevent="submitForm" class="space-y-5">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <!-- Name -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="name">Full Name</label>
                                    <input v-model="form.name" id="name" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                                    <p v-if="form.errors.name" class="mt-1.5 text-xs text-red-500">{{ form.errors.name }}</p>
                                </div>

                                <!-- Email -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="email">Email Address</label>
                                    <input v-model="form.email" id="email" type="email" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                                    <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-500">{{ form.errors.email }}</p>
                                </div>

                                <!-- Role -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="role">System Role</label>
                                    <select v-model="form.role" id="role" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                        <option value="user">User</option>
                                        <option value="records_manager">Records Manager</option>
                                        <option value="system_administrator">System Administrator</option>
                                    </select>
                                    <p v-if="form.errors.role" class="mt-1.5 text-xs text-red-500">{{ form.errors.role }}</p>
                                </div>

                                <!-- Department -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="department_id">Department</label>
                                    <select v-model="form.department_id" id="department_id" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="" disabled>Select Department</option>
                                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                            {{ dept.name }}
                                        </option>
                                    </select>
                                    <p v-if="form.errors.department_id" class="mt-1.5 text-xs text-red-500">{{ form.errors.department_id }}</p>
                                </div>

                                <!-- Sex -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="sex">Sex (Optional)</label>
                                    <select v-model="form.sex" id="sex" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="">Not Specified</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                    <p v-if="form.errors.sex" class="mt-1.5 text-xs text-red-500">{{ form.errors.sex }}</p>
                                </div>
                            </div>

                            <hr class="border-slate-100" />

                            <!-- Password Section -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="password">
                                        Password <span v-if="isEdit" class="text-slate-400 font-normal">(Leave blank to keep current)</span>
                                    </label>
                                    <input v-model="form.password" id="password" type="password" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" :required="!isEdit" />
                                    <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-500">{{ form.errors.password }}</p>
                                </div>

                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="password_confirmation">Confirm Password</label>
                                    <input v-model="form.password_confirmation" id="password_confirmation" type="password" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" :required="form.password.length > 0" />
                                </div>
                            </div>

                            <div class="pt-4 flex justify-end gap-3 border-t border-slate-100 mt-2">
                                <button type="button" class="rounded-xl bg-white border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50" @click="showDialog = false">
                                    Cancel
                                </button>
                                <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50" :disabled="form.processing">
                                    {{ form.processing ? 'Saving...' : isEdit ? 'Update User' : 'Create User' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </Transition>

            <!-- History Modal -->
            <Transition name="fade">
                <div v-if="showHistoryModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="w-full max-w-3xl rounded-2xl bg-white p-6 shadow-xl border border-slate-100 max-h-[90vh] flex flex-col">
                        <div class="flex justify-between items-center mb-6">
                            <h2 class="text-lg font-bold text-slate-800 tracking-tight flex items-center gap-2">
                                <History class="h-5 w-5 text-amber-500" />
                                Audit Trail: {{ historyUser?.name }}
                            </h2>
                            <button @click="showHistoryModal = false" class="text-slate-400 hover:text-slate-600">×</button>
                        </div>
                        
                        <div class="overflow-y-auto flex-1 pr-2 border rounded-xl">
                            <table class="w-full text-left text-sm text-slate-700">
                                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wider border-b border-slate-100 sticky top-0">
                                    <tr>
                                        <th class="px-6 py-3 font-semibold">Date & Time</th>
                                        <th class="px-6 py-3 font-semibold">IP Address</th>
                                        <th class="px-6 py-3 font-semibold">Device / Browser</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="log in historyUser?.login_histories" :key="log.id" class="hover:bg-slate-50/50 transition-colors">
                                        <td class="px-6 py-3 whitespace-nowrap font-medium text-slate-800">{{ formatDate(log.login_at) }}</td>
                                        <td class="px-6 py-3 text-slate-500">{{ log.ip_address || 'Unknown' }}</td>
                                        <td class="px-6 py-3 text-slate-500 max-w-xs truncate" :title="log.user_agent || ''">{{ log.user_agent || 'Unknown' }}</td>
                                    </tr>
                                    <tr v-if="!historyUser?.login_histories || historyUser.login_histories.length === 0">
                                        <td colspan="3" class="px-6 py-8 text-center text-slate-500">
                                            No login history found for this user.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="pt-4 flex justify-end mt-2">
                            <button type="button" class="rounded-xl bg-white border border-slate-200 px-5 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50" @click="showHistoryModal = false">
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>