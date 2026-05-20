function debounce(callback, wait = 220) {
    let timeoutId = null;

    return (...args) => {
        if (timeoutId !== null) {
            clearTimeout(timeoutId);
        }

        timeoutId = window.setTimeout(() => callback(...args), wait);
    };
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function initTopbar() {
    const topbar = document.querySelector('.topbar[data-search-url]');

    if (!topbar || topbar.dataset.enhanced === 'true') {
        return;
    }

    topbar.dataset.enhanced = 'true';

    const searchInput = topbar.querySelector('#topbar-search-input');
    const searchPanel = topbar.querySelector('#topbar-search-panel');
    const searchState = topbar.querySelector('[data-search-state]');
    const searchResults = topbar.querySelector('[data-search-results]');
    const notificationsButton = topbar.querySelector('#btn-notifications');
    const notificationsPanel = topbar.querySelector('#topbar-notifications-panel');
    const searchUrl = topbar.dataset.searchUrl || '';
    let activeIndex = -1;

    const closeSearchPanel = () => {
        if (searchPanel) {
            searchPanel.hidden = true;
        }
    };

    const updateActiveResult = () => {
        if (!searchResults) {
            return;
        }

        searchResults.querySelectorAll('.topbar-search-result').forEach((item, index) => {
            item.classList.toggle('is-active', index === activeIndex);
        });
    };

    const renderSearchResults = (results) => {
        if (!searchPanel || !searchResults || !searchState) {
            return;
        }

        searchResults.innerHTML = '';
        activeIndex = -1;

        if (results.length === 0) {
            searchState.hidden = false;
            searchState.textContent = 'Aucun resultat.';
            searchPanel.hidden = false;

            return;
        }

        searchState.hidden = true;

        results.forEach((result, index) => {
            const link = document.createElement('a');
            link.href = result.url;
            link.className = 'topbar-search-result';
            link.dataset.index = String(index);
            link.innerHTML = `
                <span class="topbar-search-result__text">
                    <span class="topbar-search-result__title">${escapeHtml(result.title)}</span>
                    <span class="topbar-search-result__subtitle">${escapeHtml(result.subtitle)}</span>
                </span>
                <span class="topbar-search-result__section">${escapeHtml(result.section)}</span>
            `;
            link.addEventListener('mouseenter', () => {
                activeIndex = index;
                updateActiveResult();
            });
            searchResults.appendChild(link);
        });

        searchPanel.hidden = false;
    };

    const runSearch = debounce(async (term) => {
        if (!searchPanel || !searchResults || !searchState || searchUrl === '') {
            return;
        }

        if (term.length < 2) {
            searchResults.innerHTML = '';
            searchState.hidden = false;
            searchState.textContent = 'Tapez au moins 2 caracteres pour lancer une recherche.';
            searchPanel.hidden = false;

            return;
        }

        searchState.hidden = false;
        searchState.textContent = 'Recherche en cours...';
        searchResults.innerHTML = '';
        searchPanel.hidden = false;

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(term)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const payload = await response.json();
            renderSearchResults(Array.isArray(payload.results) ? payload.results : []);
        } catch (error) {
            searchResults.innerHTML = '';
            searchState.hidden = false;
            searchState.textContent = 'La recherche est indisponible pour le moment.';
            searchPanel.hidden = false;
        }
    });

    if (searchInput) {
        searchInput.addEventListener('focus', () => {
            if (searchPanel) {
                searchPanel.hidden = false;
            }
        });

        searchInput.addEventListener('input', (event) => {
            runSearch(event.target.value.trim());
        });

        searchInput.addEventListener('keydown', (event) => {
            const items = searchResults ? Array.from(searchResults.querySelectorAll('.topbar-search-result')) : [];

            if (event.key === 'ArrowDown' && items.length > 0) {
                event.preventDefault();
                activeIndex = activeIndex < items.length - 1 ? activeIndex + 1 : 0;
                updateActiveResult();
            }

            if (event.key === 'ArrowUp' && items.length > 0) {
                event.preventDefault();
                activeIndex = activeIndex > 0 ? activeIndex - 1 : items.length - 1;
                updateActiveResult();
            }

            if (event.key === 'Enter' && activeIndex >= 0 && items[activeIndex]) {
                event.preventDefault();
                window.location.href = items[activeIndex].href;
            }

            if (event.key === 'Escape') {
                closeSearchPanel();
            }
        });
    }

    if (notificationsButton && notificationsPanel) {
        notificationsButton.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const isOpening = notificationsPanel.hidden;
            notificationsPanel.hidden = !isOpening;
            notificationsButton.setAttribute('aria-expanded', isOpening ? 'true' : 'false');
        });
    }

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (searchPanel && searchInput && !searchPanel.contains(target) && target !== searchInput) {
            closeSearchPanel();
        }

        if (
            notificationsPanel &&
            notificationsButton &&
            !notificationsPanel.contains(target) &&
            !notificationsButton.contains(target)
        ) {
            notificationsPanel.hidden = true;
            notificationsButton.setAttribute('aria-expanded', 'false');
        }
    });
}

document.addEventListener('DOMContentLoaded', initTopbar);
document.addEventListener('turbo:load', initTopbar);

document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        document.querySelector('#topbar-search-input')?.focus();
    }
});
