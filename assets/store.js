(function () {
    const cookieMaxAge = 60 * 60 * 24 * 180;

    function setCookie(name, value) {
        document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;max-age=' + cookieMaxAge + ';SameSite=Lax';
    }

    function getCookie(name) {
        return document.cookie.split('; ').find((part) => part.startsWith(name + '='))?.split('=').slice(1).join('=') || '';
    }

    function readJson(key, fallback) {
        try {
            const value = localStorage.getItem(key);
            return value ? JSON.parse(value) : fallback;
        } catch (error) {
            return fallback;
        }
    }

    function writeJson(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            // Storage can be disabled by the browser.
        }
    }

    function readText(key) {
        try {
            return localStorage.getItem(key) || '';
        } catch (error) {
            return '';
        }
    }

    function writeText(key, value) {
        try {
            if (value) {
                localStorage.setItem(key, value);
            } else {
                localStorage.removeItem(key);
            }
        } catch (error) {
            // Storage can be disabled by the browser.
        }
    }

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value || '');
        return node.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function safeUrl(value, fallback = '') {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            if (!['http:', 'https:'].includes(url.protocol)) return fallback;
            return url.href;
        } catch (error) {
            return fallback;
        }
    }

    function trackStoreEvent(name, params = {}) {
        if (typeof window.gtag === 'function') {
            window.gtag('event', name, params);
        }
        if (typeof window.fbq === 'function') {
            const map = {
                add_to_cart: 'AddToCart',
                begin_checkout: 'InitiateCheckout',
                search: 'Search',
                view_item: 'ViewContent'
            };
            window.fbq('track', map[name] || name, params);
        }
    }

    function rememberSearch(value) {
        const term = value.trim();
        if (!term) return;
        setCookie('sx_last_search', term);
        const searches = readJson('sx_recent_searches', []).filter((item) => item.toLowerCase() !== term.toLowerCase());
        searches.unshift(term);
        writeJson('sx_recent_searches', searches.slice(0, 20));
    }

    function rememberProduct() {
        const node = document.querySelector('[data-product-view]');
        if (!node) return;
        const item = {
            name: node.dataset.name || 'Producto',
            url: node.dataset.url || window.location.pathname,
            image: node.dataset.image || '',
            price: node.dataset.price || ''
        };
        const views = readJson('sx_recent_views', []).filter((view) => view.url !== item.url);
        views.unshift(item);
        writeJson('sx_recent_views', views.slice(0, 12));
    }

    function renderRecentViews() {
        const section = document.querySelector('[data-recent-viewed]');
        const track = document.querySelector('[data-recent-viewed-track]');
        if (!section || !track) return;
        const views = readJson('sx_recent_views', []).slice(0, 8);
        if (!views.length) return;
        track.innerHTML = views.map((item) => {
            const imageUrl = safeUrl(item.image);
            const productUrl = safeUrl(item.url, '/');
            const image = imageUrl ? '<img src="' + escapeHtml(imageUrl) + '" alt="">' : '<span class="recent-fallback">SX</span>';
            return '<a class="recent-card" href="' + escapeHtml(productUrl) + '">' + image + '<strong>' + escapeHtml(item.name) + '</strong><span>' + escapeHtml(item.price || 'Ver producto') + '</span></a>';
        }).join('');
        section.hidden = false;
        document.querySelectorAll('[data-history-empty]').forEach((item) => { item.hidden = true; });
    }

    function renderSearchHistory() {
        const list = document.querySelector('[data-search-history]');
        if (!list) return;
        const searches = readJson('sx_recent_searches', []).slice(0, 20);
        if (!searches.length) {
            list.innerHTML = '<div class="empty-cart">Todavia no hay busquedas en este navegador.</div>';
            return;
        }
        list.innerHTML = searches.map((item) => '<a href="/buscar.php?q=' + encodeURIComponent(item) + '"><strong>' + escapeHtml(item) + '</strong><span>Buscar otra vez</span></a>').join('');
    }

    function rememberCurrentSearch() {
        if (!window.location.pathname.endsWith('/buscar.php')) return;
        const term = new URLSearchParams(window.location.search).get('q') || '';
        if (!term.trim()) return;
        rememberSearch(term);
        writeText('sx_search_draft', '');
    }

    function wireSearchForms() {
        document.querySelectorAll('form[action="/buscar.php"], form.header-search').forEach((form) => {
            form.addEventListener('submit', () => {
                const input = form.querySelector('input[name="q"], input[type="search"], input[data-search]');
                if (input) {
                    rememberSearch(input.value);
                    writeText('sx_search_draft', '');
                    trackStoreEvent('search', { search_term: input.value.trim() });
                }
            });

            const input = form.querySelector('input[name="q"], input[type="search"], input[data-search]');
            if (!input) return;
            const draft = readText('sx_search_draft');
            if (!input.value && draft) input.value = draft;
            input.addEventListener('input', () => writeText('sx_search_draft', input.value.trim()));
        });
    }

    function renderSearchAssist() {
        document.querySelectorAll('form.header-search').forEach((form) => {
            const input = form.querySelector('input[name="q"]');
            if (!input || form.querySelector('.search-assist')) return;
            const panel = document.createElement('div');
            panel.className = 'search-assist';
            panel.hidden = true;
            form.appendChild(panel);
            const fixed = ['Xbox', 'Nintendo Switch', 'PlayStation 5', 'Controles', 'Videojuegos', 'Accesorios', 'Servicio tecnico'];
            const values = () => {
                const history = readJson('sx_recent_searches', []);
                const options = Array.from(document.querySelectorAll('#store-search-suggestions option')).map((item) => item.value).filter(Boolean);
                return Array.from(new Set([...history, ...fixed, ...options])).slice(0, 10);
            };
            const draw = () => {
                const term = input.value.trim().toLowerCase();
                const matches = values().filter((item) => !term || item.toLowerCase().includes(term)).slice(0, 7);
                panel.innerHTML = matches.map((item) => '<button type="button">' + item + '</button>').join('');
                panel.hidden = !matches.length || document.activeElement !== input;
            };
            panel.addEventListener('click', (event) => {
                const button = event.target.closest('button');
                if (!button) return;
                input.value = button.textContent.trim();
                rememberSearch(input.value);
                writeText('sx_search_draft', '');
                form.submit();
            });
            input.addEventListener('focus', draw);
            input.addEventListener('input', draw);
            document.addEventListener('click', (event) => {
                if (!form.contains(event.target)) panel.hidden = true;
            });
        });
    }

    function wireFilters() {
        document.querySelectorAll('[data-filter-toggle]').forEach((button) => {
            const panel = document.querySelector('[data-filter-panel]');
            if (!panel) return;
            button.addEventListener('click', () => {
                panel.classList.toggle('is-open');
                button.classList.toggle('is-active', panel.classList.contains('is-open'));
            });
        });
    }

    function wireProductZoom() {
        const image = document.querySelector('[data-zoom-image]');
        if (!image) return;
        image.addEventListener('mousemove', (event) => {
            const rect = image.getBoundingClientRect();
            const x = ((event.clientX - rect.left) / rect.width) * 100;
            const y = ((event.clientY - rect.top) / rect.height) * 100;
            image.style.transformOrigin = x + '% ' + y + '%';
            image.classList.add('is-zoomed');
        });
        image.addEventListener('mouseleave', () => {
            image.classList.remove('is-zoomed');
            image.style.transformOrigin = '';
        });
    }

    function wireToasts() {
        document.querySelectorAll('form').forEach((form) => {
            form.addEventListener('submit', () => {
                const action = form.querySelector('input[name="action"]')?.value || '';
                if (action === 'add_cart') {
                    trackStoreEvent('add_to_cart');
                }
                if (action === 'checkout') {
                    trackStoreEvent('begin_checkout');
                }
                if (!['add_cart', 'toggle_favorite', 'add_to_list'].includes(action)) return;
                const toast = document.createElement('div');
                toast.className = 'ui-toast';
                toast.textContent = action === 'add_cart' ? 'Producto agregado' : 'Accion guardada';
                document.body.appendChild(toast);
                window.setTimeout(() => toast.remove(), 1800);
            });
        });
    }

    function wireCookieNotice() {
        const note = document.querySelector('[data-cookie-note]');
        const button = document.querySelector('[data-cookie-accept]');
        if (!note || !button) return;
        if (getCookie('sx_cookie_ok') !== '1') note.hidden = false;
        button.addEventListener('click', () => {
            setCookie('sx_cookie_ok', '1');
            note.hidden = true;
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        wireCookieNotice();
        rememberCurrentSearch();
        wireSearchForms();
        renderSearchAssist();
        wireFilters();
        wireProductZoom();
        wireToasts();
        rememberProduct();
        if (document.querySelector('[data-product-view]')) {
            trackStoreEvent('view_item');
        }
        renderRecentViews();
        renderSearchHistory();
    });
})();
