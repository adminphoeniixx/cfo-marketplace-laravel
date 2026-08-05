import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The admin sections the signed-in role may open, as shared by
 * `HandleInertiaRequests`. Routes are gated server-side; this only keeps the
 * UI from offering links that would land on a 403.
 */
export function useSections() {
    const page = usePage();

    const auth = computed(
        () =>
            page.props.auth as
                { sections?: string[]; user?: { role?: string } } | undefined,
    );

    const sections = computed<string[]>(() => auth.value?.sections ?? []);

    return {
        sections,
        isAdmin: computed(() => auth.value?.user?.role === 'admin'),
        can: (section: string) => sections.value.includes(section),
    };
}
