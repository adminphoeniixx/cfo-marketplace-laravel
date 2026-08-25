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
                | {
                      sections?: string[];
                      sellerSections?: string[];
                      user?: { role?: string };
                  }
                | undefined,
    );

    const sections = computed<string[]>(() => auth.value?.sections ?? []);

    // The seller panel's own grant, which reaches further than `sections`
    // because its screens scope to the seller's store. See `Roles::forPanel`.
    const sellerSections = computed<string[]>(
        () => auth.value?.sellerSections ?? [],
    );

    return {
        sections,
        sellerSections,
        isAdmin: computed(() => auth.value?.user?.role === 'admin'),
        can: (section: string) => sections.value.includes(section),
        canSell: (section: string) => sellerSections.value.includes(section),
    };
}
