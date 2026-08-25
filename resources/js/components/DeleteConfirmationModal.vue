<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Loader, X } from 'lucide-vue-next';
import { ref } from 'vue';

// --- Props & Emits ---

const props = defineProps<{
    show: boolean;
    title?: string;
    itemName: string;
    deleteUrl: string;
}>();

const emit = defineEmits<{
    (e: 'update:show', value: boolean): void;
}>();

// --- Internal State ---

const isDeleting = ref(false);

// --- Methods ---

const closeModal = () => {
    if (isDeleting.value) return; // Don't close while deleting
    emit('update:show', false);
};

const confirmDelete = () => {
    isDeleting.value = true;
    router.delete(props.deleteUrl, {
        preserveScroll: true,
        onSuccess: () => {
            isDeleting.value = false;
            closeModal();
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
};
</script>

<template>
    <Transition name="fade">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4">
            <div
                class="relative w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="title || 'Confirm Deletion'"
            >
                <div class="p-6">
                    <div class="flex items-start gap-4">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 sm:mx-0">
                            <AlertTriangle class="h-6 w-6 text-red-600" />
                        </div>

                        <div class="mt-0 flex-1 text-left">
                            <h3 class="text-lg leading-6 font-semibold text-gray-900" id="modal-title">
                                {{ title || 'Confirm Deletion' }}
                            </h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-600">
                                    Are you sure you want to delete
                                    <strong class="font-medium text-gray-800">"{{ itemName }}"</strong>? This action cannot be undone.
                                </p>
                            </div>
                        </div>

                        <button
                            @click="closeModal"
                            class="absolute top-3 right-3 rounded-full p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
                            title="Close"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-3 bg-gray-50 px-6 py-4 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        @click="closeModal"
                        :disabled="isDeleting"
                        class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="confirmDelete"
                        :disabled="isDeleting"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:bg-red-400 sm:w-auto"
                    >
                        <Loader v-if="isDeleting" class="h-4 w-4 animate-spin" />
                        {{ isDeleting ? 'Deleting...' : 'Delete' }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
.fade-enter-to,
.fade-leave-from {
    opacity: 1;
}
</style>
