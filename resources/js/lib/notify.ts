import { toast, type ExternalToast } from 'vue-sonner';

type Kind = 'success' | 'error' | 'info' | 'warning';

/** How long a toast stays: errors longer, so slower readers can finish them. */
const DURATION: Record<Kind, number> = { success: 5000, info: 5000, warning: 8000, error: 8000 };

/**
 * Toasts for the whole app (vue-sonner). Use this instead of `toast` directly.
 *
 * vue-sonner pauses a toast while the mouse is over it, and its paused state can get stuck when the matching
 * mouseleave never arrives (vue-sonner issue #165, still open in 2.0.9), leaving the toast on screen until closed by
 * hand. So every toast is also dismissed by us a little after its normal time.
 */
function show(kind: Kind, message: string, options: ExternalToast = {}) {
    const duration = options.duration ?? DURATION[kind];
    const id = toast[kind](message, { ...options, duration });
    if (Number.isFinite(duration)) {
        setTimeout(() => toast.dismiss(id), duration + 3000);
    }

    return id;
}

export const notify = {
    success: (message: string, options?: ExternalToast) => show('success', message, options),
    error: (message: string, options?: ExternalToast) => show('error', message, options),
    info: (message: string, options?: ExternalToast) => show('info', message, options),
    warning: (message: string, options?: ExternalToast) => show('warning', message, options),
};
