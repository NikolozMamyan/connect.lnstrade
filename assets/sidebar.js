const GROUPS_STORAGE_KEY = 'lnstrade.sidebar.groups';
const COLLAPSED_STORAGE_KEY = 'lnstrade.sidebar.collapsed';

function readStoredGroups() {
    try {
        return JSON.parse(window.localStorage.getItem(GROUPS_STORAGE_KEY) || '{}');
    } catch (error) {
        return {};
    }
}

function storeGroups(groups) {
    try {
        window.localStorage.setItem(GROUPS_STORAGE_KEY, JSON.stringify(groups));
    } catch (error) {
        // La navigation reste fonctionnelle lorsque le stockage est indisponible.
    }
}

function initSidebar() {
    const sidebar = document.querySelector('[data-sidebar]');

    if (!sidebar || sidebar.dataset.enhanced === 'true') {
        return;
    }

    sidebar.dataset.enhanced = 'true';
    const storedGroups = readStoredGroups();
    const groups = Array.from(sidebar.querySelectorAll('[data-sidebar-group]'));
    const collapseButton = sidebar.querySelector('[data-sidebar-collapse]');

    const setGroupExpanded = (group, expanded) => {
        group.classList.toggle('is-open', expanded);
        group.querySelector(':scope > .nav-group-toggle')?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };

    const setSidebarCollapsed = (collapsed) => {
        sidebar.classList.toggle('is-collapsed', collapsed);

        if (collapseButton) {
            const label = collapsed ? 'Déployer la navigation' : 'Réduire la navigation';
            collapseButton.setAttribute('aria-label', label);
            collapseButton.setAttribute('title', label);
        }
    };

    const storeSidebarCollapsed = (collapsed) => {
        try {
            window.localStorage.setItem(COLLAPSED_STORAGE_KEY, collapsed ? 'true' : 'false');
        } catch (error) {
            // Le repli reste disponible pour la page courante.
        }
    };

    groups.forEach((group) => {
        const name = group.dataset.sidebarGroup;
        const isActive = group.dataset.active === 'true';
        const defaultOpen = group.dataset.defaultOpen === 'true';
        const expanded = isActive || (typeof storedGroups[name] === 'boolean' ? storedGroups[name] : defaultOpen);
        const toggle = group.querySelector(':scope > .nav-group-toggle');

        setGroupExpanded(group, expanded);

        toggle?.addEventListener('click', () => {
            if (sidebar.classList.contains('is-collapsed')) {
                setSidebarCollapsed(false);
                storeSidebarCollapsed(false);
                setGroupExpanded(group, true);
            } else {
                setGroupExpanded(group, !group.classList.contains('is-open'));
            }

            storedGroups[name] = group.classList.contains('is-open');
            storeGroups(storedGroups);
        });
    });

    let initiallyCollapsed = false;

    try {
        initiallyCollapsed = window.localStorage.getItem(COLLAPSED_STORAGE_KEY) === 'true';
    } catch (error) {
        initiallyCollapsed = false;
    }

    setSidebarCollapsed(initiallyCollapsed && window.matchMedia('(min-width: 769px)').matches);

    collapseButton?.addEventListener('click', () => {
        const collapsed = !sidebar.classList.contains('is-collapsed');
        setSidebarCollapsed(collapsed);
        storeSidebarCollapsed(collapsed);
    });
}

document.addEventListener('DOMContentLoaded', initSidebar);
document.addEventListener('turbo:load', initSidebar);
