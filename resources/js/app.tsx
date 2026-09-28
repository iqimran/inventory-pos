import '../css/app.css';

import { type SharedData } from '@/types';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

// Organization name and logo come from Settings → Organization (shared on every page).
let appName = import.meta.env.VITE_APP_NAME || 'Mobile Shop POS';

function applyBranding(props: Partial<SharedData>) {
    if (props.name) {
        appName = props.name;
    }

    const logo = props.organization?.logo_url ?? null;
    let icon = document.querySelector<HTMLLinkElement>('link[data-organization-logo]');

    if (logo) {
        if (!icon) {
            icon = document.createElement('link');
            icon.rel = 'icon';
            icon.dataset.organizationLogo = '';
            document.head.appendChild(icon);
        }

        if (icon.href !== logo) {
            icon.href = logo;
        }
    } else {
        icon?.remove();
    }
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        applyBranding(props.initialPage.props as unknown as SharedData);

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    // Top loading bar for page visits slower than 250ms.
    progress: {
        delay: 250,
        color: '#4B5563',
        showSpinner: false,
    },
});

// Keep the tab title and icon in step when the organization settings change.
router.on('navigate', (event) => applyBranding(event.detail.page.props as unknown as SharedData));

// This will set light / dark mode on load...
initializeTheme();
