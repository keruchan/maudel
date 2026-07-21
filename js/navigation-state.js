/**
 * Keep authenticated dashboard navigation from feeling like it "jumps" after
 * same-page clicks or form posts. The PHP app still renders normal pages; this
 * script only prevents redundant reloads and restores the user's local context.
 */
(function () {
    'use strict';

    var pageKey = window.location.pathname + window.location.search;
    var restoreKey = 'sked:restore-scroll';
    var scrollKey = 'sked:scroll:' + pageKey;
    var sidebarKey = 'sked:sidebar-scroll';

    function resolveUrl(value) {
        try {
            return new URL(value || window.location.href, window.location.href);
        } catch (error) {
            return null;
        }
    }

    function samePage(url) {
        return url
            && url.origin === window.location.origin
            && url.pathname === window.location.pathname
            && url.search === window.location.search;
    }

    function savePageScroll() {
        try {
            sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
            sessionStorage.setItem(restoreKey, pageKey);
        } catch (error) {
            // Session storage can be unavailable in some privacy modes.
        }
    }

    function saveSidebarScroll() {
        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) {
            return;
        }

        try {
            sessionStorage.setItem(sidebarKey, String(sidebar.scrollTop || 0));
        } catch (error) {
            // Non-critical enhancement only.
        }
    }

    function restorePageScroll() {
        var shouldRestore;
        var savedY;

        try {
            shouldRestore = sessionStorage.getItem(restoreKey) === pageKey;
            savedY = parseInt(sessionStorage.getItem(scrollKey) || '', 10);
            sessionStorage.removeItem(restoreKey);
        } catch (error) {
            return;
        }

        if (!shouldRestore || Number.isNaN(savedY)) {
            return;
        }

        requestAnimationFrame(function () {
            window.scrollTo(0, savedY);
        });
    }

    function keepActiveNavVisible(container) {
        var active = container.querySelector('.nav-panel a.active');
        var savedTop;

        try {
            savedTop = parseInt(sessionStorage.getItem(sidebarKey) || '', 10);
        } catch (error) {
            savedTop = NaN;
        }

        if (!Number.isNaN(savedTop)) {
            container.scrollTop = savedTop;
            return;
        }

        if (!active) {
            return;
        }

        var activeTop = active.offsetTop;
        var activeBottom = activeTop + active.offsetHeight;
        var visibleTop = container.scrollTop;
        var visibleBottom = visibleTop + container.clientHeight;

        if (activeTop < visibleTop || activeBottom > visibleBottom) {
            container.scrollTop = Math.max(0, activeTop - Math.round(container.clientHeight / 2));
        }
    }

    function restoreNavigationScroll() {
        document.querySelectorAll('.sidebar, .offcanvas-body').forEach(keepActiveNavVisible);
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        var url;

        if (!link) {
            return;
        }

        if (link.closest('.nav-panel')) {
            saveSidebarScroll();
        }

        url = resolveUrl(link.getAttribute('href'));
        if (!samePage(url) || url.hash) {
            return;
        }

        event.preventDefault();
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        var action = resolveUrl(form.getAttribute('action'));

        if (method === 'post' && samePage(action)) {
            savePageScroll();
            saveSidebarScroll();
        }
    }, true);

    window.addEventListener('beforeunload', saveSidebarScroll);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            restoreNavigationScroll();
            restorePageScroll();
        });
    } else {
        restoreNavigationScroll();
        restorePageScroll();
    }
}());
