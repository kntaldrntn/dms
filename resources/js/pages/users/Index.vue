<script setup lang="ts">
import DeleteConfirmationModal from '@/components/DeleteConfirmationModal.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Trash2, RotateCcw, History, X } from 'lucide-vue-next';
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
            audit_logs?: Array<any>;
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
const activeHistoryTab = ref<'logins' | 'audit'>('logins');

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
    activeHistoryTab.value = 'logins';
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
        <!-- Main Wrapper: Solid bg-slate-100 for better contrast -->
        <div class="flex flex-col gap-6 p-6 w-full mx-auto bg-slate-100 min-h-screen">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-800 tracking-tight">System Users</h1>
                    <p class="text-sm text-slate-500 mt-1">Manage personnel access and roles across departments.</p>
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <!-- Search -->
                    <div class="relative w-full sm:w-72">
                        <Search class="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search by name or email..."
                            class="block w-full rounded-xl border border-slate-300 bg-white py-2 pl-9 pr-10 text-sm placeholder-slate-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm"
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

            <!-- Table Card: Darkened border and shadow -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-700">
                        <!-- Header: Solid bg-slate-100, border-slate-200, tightened padding -->
                        <thead class="bg-slate-100 text-xs text-slate-600 uppercase tracking-wider border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-2.5 font-bold">User Details</th>
                                <th class="px-4 py-2.5 font-bold">Role</th>
                                <th class="px-4 py-2.5 font-bold">Department</th>
                                <th class="px-4 py-2.5 font-bold">Last Active</th>
                                <th class="px-4 py-2.5 font-bold">Status</th>
                                <th class="px-4 py-2.5 text-center font-bold">Actions</th>
                            </tr>
                        </thead>
                        <!-- Body: Darkened row dividers -->
                        <tbody class="divide-y divide-slate-200">
                            <!-- Rows: Solid hover bg, adjusted inactive opacity, tightened padding -->
                            <tr v-for="user in users.data" :key="user.id" class="transition-colors hover:bg-slate-50" :class="{'opacity-75 bg-slate-50': user.state === 0}">
                                <td class="px-4 py-2">
                                    <div class="flex flex-col">
                                        <span class="font-medium text-slate-800">{{ user.name }}</span>
                                        <span class="text-xs text-slate-500">{{ user.email }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">
                                        {{ formatRole(user.role) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-slate-600">
                                    {{ user.department?.name || 'No Department' }}
                                </td>
                                <td class="px-4 py-2">
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
                                <td class="px-4 py-2">
                                    <span v-if="user.state === 1" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-100">
                                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full mr-1.5"></span> Active
                                    </span>
                                    <span v-else class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                                        <span class="w-1.5 h-1.5 bg-slate-400 rounded-full mr-1.5"></span> Inactive
                                    </span>
                                </td>
                                <td class="px-4 py-2">
                                    <div class="flex justify-center gap-1.5">
                                        <button class="rounded-md bg-amber-50 p-1.5 text-amber-600 transition hover:bg-amber-100 border border-amber-100" @click="openHistoryModal(user)" title="Audit Trail">
                                            <History class="h-4 w-4" />
                                        </button>

                                        <button class="rounded-md bg-slate-50 p-1.5 text-slate-600 transition hover:bg-slate-100 border border-slate-200" @click="openEditDialog(user)" title="Edit">
                                            <Pencil class="h-4 w-4" />
                                        </button>

                                        <button v-if="user.state === 1" class="rounded-md bg-red-50 p-1.5 text-red-600 transition hover:bg-red-100 border border-red-100" @click="openDeleteModal(user)" title="Deactivate">
                                            <Trash2 class="h-4 w-4" />
                                        </button>

                                        <button v-else class="rounded-md bg-blue-50 p-1.5 text-blue-600 transition hover:bg-blue-100 border border-blue-100" @click="reactivateUser(user)" title="Reactivate">
                                            <RotateCcw class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Empty State -->
                    <div v-if="users.data.length === 0" class="py-12 text-center">
                        <div class="flex flex-col items-center">
                            <div class="w-12 h-12 bg-slate-50 rounded-full flex items-center justify-center mb-3 border border-slate-200">
                                <Search class="h-6 w-6 text-slate-400" />
                            </div>
                            <h3 class="text-base font-semibold text-slate-900">No users found</h3>
                            <p class="mt-1 mb-5 text-sm text-slate-500 max-w-sm">
                                {{ searchTerm ? 'We couldn’t find anything matching your search. Try adjusting your keywords.' : 'Get started by creating your first user account.' }}
                            </p>
                            <button
                                v-if="!searchTerm"
                                @click="openAddDialog"
                                class="rounded-xl bg-emerald-600 px-5 py-2 font-medium text-white shadow-sm hover:bg-emerald-700 transition"
                            >
                                Add User
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Pagination: Solidified the background and border -->
                <div v-if="users.last_page > 1" class="flex items-center justify-between border-t border-slate-200 bg-slate-50 px-4 py-3">
                    <p class="text-sm text-slate-500">
                        Showing <span class="font-medium text-slate-700">{{ users.from }}</span> to <span class="font-medium text-slate-700">{{ users.to }}</span> of <span class="font-medium text-slate-700">{{ users.total }}</span> results
                    </p>
                    <div class="flex gap-1">
                        <button v-for="(link, index) in users.links.slice(1, -1)" :key="index" @click="goToPage(String(link.url))" :disabled="!link.url" class="rounded-lg px-3 py-1 text-sm font-medium transition border" :class="[link.active ? 'bg-emerald-600 text-white border-emerald-600 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50']">
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
                    <div class="relative w-full max-w-2xl rounded-2xl bg-white p-6 shadow-xl border border-slate-200 max-h-[90vh] overflow-y-auto">
                        
                        <button
                            @click="showDialog = false"
                            class="absolute top-4 right-4 rounded-full p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                            title="Close"
                        >
                            <X class="h-5 w-5" />
                        </button>

                        <h2 class="mb-6 pr-8 text-lg font-bold text-slate-800 tracking-tight">
                            {{ isEdit ? 'Edit User' : 'Add New User' }}
                        </h2>
                        
                        <form @submit.prevent="submitForm" class="space-y-4">

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <!-- Name -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="name">Full Name</label>
                                    <input v-model="form.name" id="name" type="text" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                                </div>

                                <!-- Email -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="email">Email Address</label>
                                    <input v-model="form.email" id="email" type="email" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required />
                                    <p v-if="form.errors.email" class="mt-1 text-xs text-red-500">{{ form.errors.email }}</p>
                                </div>

                                <!-- Role -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="role">System Role</label>
                                    <select v-model="form.role" id="role" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" required>
                                        <option value="user">User</option>
                                        <option value="records_manager">Records Manager</option>
                                        <option value="system_administrator">System Administrator</option>
                                    </select>
                                    <p v-if="form.errors.role" class="mt-1 text-xs text-red-500">{{ form.errors.role }}</p>
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
                                    <p v-if="form.errors.department_id" class="mt-1 text-xs text-red-500">{{ form.errors.department_id }}</p>
                                </div>

                                <!-- Sex -->
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="sex">Sex (Optional)</label>
                                    <select v-model="form.sex" id="sex" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm">
                                        <option value="">Not Specified</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                    <p v-if="form.errors.sex" class="mt-1 text-xs text-red-500">{{ form.errors.sex }}</p>
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
                                    <p v-if="form.errors.password" class="mt-1 text-xs text-red-500">{{ form.errors.password }}</p>
                                </div>

                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700" for="password_confirmation">Confirm Password</label>
                                    <input v-model="form.password_confirmation" id="password_confirmation" type="password" class="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 shadow-sm" :required="form.password.length > 0" />
                                </div>
                            </div>

                            <div class="pt-3 flex justify-end gap-3 border-t border-slate-100 mt-2">
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

            <!-- History & Audit Modal -->
            <Transition name="fade">
                <div v-if="showHistoryModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm px-4">
                    <div class="w-full max-w-3xl rounded-2xl bg-white shadow-xl border border-slate-200 flex flex-col max-h-[85vh]">
                        
                        <!-- Header -->
                        <div class="px-6 py-5 border-b border-slate-100">
                            <div class="flex justify-between items-start mb-4">
                                <h2 class="text-lg font-bold text-slate-800 tracking-tight flex items-center gap-2">
                                    <History class="h-5 w-5 text-indigo-500" />
                                    Activity Record: <span class="text-slate-500 font-normal">{{ historyUser?.name }}</span>
                                </h2>
                                
                                <button 
                                    @click="showHistoryModal = false" 
                                    class="rounded-full p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                                    title="Close"
                                >
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            
                            <!-- Tabs -->
                            <div class="flex space-x-1 rounded-xl bg-slate-100 p-1 w-full sm:w-fit">
                                <button 
                                    @click="activeHistoryTab = 'logins'"
                                    :class="['px-4 py-2 text-sm font-medium rounded-lg transition-all', activeHistoryTab === 'logins' ? 'bg-white text-slate-900 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-700']"
                                >
                                    Login History
                                </button>
                                <button 
                                    @click="activeHistoryTab = 'audit'"
                                    :class="['px-4 py-2 text-sm font-medium rounded-lg transition-all', activeHistoryTab === 'audit' ? 'bg-white text-slate-900 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-700']"
                                >
                                    Audit Trail
                                </button>
                            </div>
                        </div>
                        
                        <!-- Scrollable Content -->
                        <div class="overflow-y-auto flex-1 p-6 bg-slate-50/50">
                            
                            <!-- LOGIN TAB -->
                            <div v-if="activeHistoryTab === 'logins'" class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
                                <table class="w-full text-left text-sm text-slate-700">
                                    <thead class="bg-slate-100 text-xs text-slate-600 uppercase tracking-wider border-b border-slate-200">
                                        <tr>
                                            <th class="px-4 py-2.5 font-bold">Date & Time</th>
                                            <th class="px-4 py-2.5 font-bold">IP Address</th>
                                            <th class="px-4 py-2.5 font-bold">Device / Browser</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200">
                                        <tr v-for="log in historyUser?.login_histories" :key="log.id" class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-2 whitespace-nowrap font-medium text-slate-800">{{ formatDate(log.login_at) }}</td>
                                            <td class="px-4 py-2 text-slate-500">{{ log.ip_address || 'Unknown' }}</td>
                                            <td class="px-4 py-2 text-slate-500 max-w-xs truncate" :title="log.user_agent || ''">{{ log.user_agent || 'Unknown' }}</td>
                                        </tr>
                                        <tr v-if="!historyUser?.login_histories || historyUser.login_histories.length === 0">
                                            <td colspan="3" class="px-4 py-8 text-center text-slate-500">
                                                No login history found for this user.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- AUDIT TRAIL TAB -->
                            <div v-if="activeHistoryTab === 'audit'" class="space-y-4">
                                <div v-for="log in historyUser?.audit_logs" :key="log.id" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                                    <div class="flex items-center justify-between mb-3 pb-3 border-b border-slate-100">
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center">
                                                <span class="text-indigo-600 font-bold text-xs">{{ log.actor?.name?.charAt(0) || '?' }}</span>
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold text-slate-800">{{ log.actor?.name || 'System' }}</p>
                                                <p class="text-[11px] text-slate-500 font-medium uppercase tracking-wider">Updated Profile</p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs text-slate-500">{{ formatDate(log.created_at) }}</span>
                                        </div>
                                    </div>
                                    
                                    <!-- JSON Split-View Visualizer -->
                                    <div class="mt-6 border border-slate-200 rounded-xl overflow-hidden bg-slate-50/30 shadow-sm">
                                        <!-- Split Header -->
                                        <div class="grid grid-cols-2 gap-4 px-4 py-2.5 bg-slate-100/80 border-b border-slate-200">
                                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider">Previous State</div>
                                            <div class="text-xs font-bold text-slate-500 uppercase tracking-wider pl-2">New State</div>
                                        </div>

                                        <!-- Rows -->
                                        <div class="p-2 space-y-0.5">
                                            <div v-for="(newValue, key) in log.new_values" :key="key" class="grid grid-cols-2 gap-4 px-2 py-1.5 font-mono text-xs rounded-lg transition-colors hover:bg-slate-100/50">
                                                
                                                <!-- LEFT COLUMN (OLD) -->
                                                <div class="flex items-center pr-4 border-r border-slate-200/60">
                                                    <span class="font-bold text-slate-500 uppercase tracking-wider w-36 shrink-0">{{ key }}</span>
                                                    
                                                    <span v-if="log.old_values[key] == newValue" class="text-slate-700 break-all">
                                                        = {{ log.old_values[key] === null || log.old_values[key] === '' ? 'null' : log.old_values[key] }}
                                                    </span>
                                                    
                                                    <div v-else class="flex items-center gap-1.5 bg-rose-50 text-rose-700 px-2.5 py-1 rounded border border-rose-100 shadow-sm break-all flex-1">
                                                        <span class="text-rose-400 font-bold leading-none">-</span>
                                                        <span class="line-through opacity-70 leading-none">{{ log.old_values[key] === null || log.old_values[key] === '' ? 'null' : log.old_values[key] }}</span>
                                                    </div>
                                                </div>

                                                <!-- RIGHT COLUMN (NEW) -->
                                                <div class="flex items-center pl-2">
                                                    <span class="font-bold text-slate-500 uppercase tracking-wider w-36 shrink-0">{{ key }}</span>
                                                    
                                                    <span v-if="log.old_values[key] == newValue" class="text-slate-700 break-all">
                                                        = {{ newValue === null || newValue === '' ? 'null' : newValue }}
                                                    </span>
                                                    
                                                    <div v-else class="flex items-center gap-1.5 bg-emerald-50 text-emerald-700 px-2.5 py-1 rounded border border-emerald-100 shadow-sm break-all flex-1">
                                                        <span class="text-emerald-400 font-bold leading-none">+</span>
                                                        <span class="font-semibold leading-none">{{ newValue === null || newValue === '' ? 'null' : newValue }}</span>
                                                    </div>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="!historyUser?.audit_logs || historyUser.audit_logs.length === 0" class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500">
                                    No changes have been recorded for this user yet.
                                </div>
                            </div>
                            
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