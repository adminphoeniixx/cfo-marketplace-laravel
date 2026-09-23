import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SellerLayout from '@/layouts/SellerLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            // Public seller signup and the "waiting for approval" screen. They
            // stand alone: one is reached before there is an account and the
            // other while the panel behind `SellerLayout` is still closed.
            case name.startsWith('sell/'):
                return null;
            // Terms and privacy, read by people with no account at all —
            // including an app store reviewer who has not installed anything.
            case name.startsWith('legal/'):
                return null;
            // How to delete an account, read by somebody who has already
            // uninstalled the app — so it cannot sit behind one.
            case name.startsWith('account/'):
                return null;
            case name.startsWith('admin/'):
                return AdminLayout;
            case name.startsWith('seller/'):
                return SellerLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
