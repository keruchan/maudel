/**
 * ============================================================
 * File     : js/notifications.js
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Polling-based notification bell (P10, spec 4.5). Loaded once
 *            per page by render_sked_notification_bell() in
 *            includes/navigation.php, which also emits the markup this
 *            script hooks into (data-notif-* attributes).
 *
 * Every protected page lives two directories under the site root
 * (pages/<role>/file.php), so the relative API_URL below resolves
 * consistently everywhere it's loaded from.
 * ============================================================
 */
(function () {
    'use strict';

    var POLL_INTERVAL_MS = 45000;
    var API_URL = '../api/notifications.php';
    var LOGIN_TIMEOUT_URL = '../auth/login.php?timeout=1';

    document.addEventListener('DOMContentLoaded', function () {
        var bellBtn = document.querySelector('[data-notif-bell]');
        var panel = document.querySelector('[data-notif-panel]');
        var backdrop = document.querySelector('[data-notif-backdrop]');
        var badge = document.querySelector('[data-notif-badge]');
        var list = document.querySelector('[data-notif-list]');
        var markAllBtn = document.querySelector('[data-notif-markall]');

        if (!bellBtn || !panel || !badge || !list || !markAllBtn) {
            return;
        }

        var csrfToken = bellBtn.getAttribute('data-csrf') || '';

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        }

        function setBadge(count) {
            if (count > 0) {
                badge.hidden = false;
                badge.textContent = count > 99 ? '99+' : String(count);
            } else {
                badge.hidden = true;
                badge.textContent = '0';
            }
            markAllBtn.disabled = count === 0;
        }

        function renderList(notifications) {
            if (!notifications.length) {
                list.innerHTML = '<div class="notif-state"><i class="bi bi-bell-slash d-block mb-2"></i>No notifications yet.</div>';
                return;
            }

            list.innerHTML = notifications.map(function (n) {
                var unreadClass = n.is_read ? '' : ' is-unread';
                return (
                    '<div class="notif-item' + unreadClass + '">' +
                        '<button type="button" class="notif-item-main" data-id="' + n.id + '" data-link="' + escapeHtml(n.link) + '">' +
                            '<span class="notif-ico ' + escapeHtml(n.accent) + '"><i class="bi ' + escapeHtml(n.icon) + '"></i></span>' +
                            '<span class="notif-body">' +
                                '<span class="notif-title">' + escapeHtml(n.title) + '</span>' +
                                '<span class="notif-msg">' + escapeHtml(n.message) + '</span>' +
                                '<span class="notif-when">' + escapeHtml(n.when) + '</span>' +
                            '</span>' +
                        '</button>' +
                        '<span class="notif-item-actions">' +
                            '<button type="button" class="notif-action notif-action-delete" data-notif-delete data-id="' + n.id + '" title="Delete notification" aria-label="Delete notification">' +
                                '<i class="bi bi-trash"></i>' +
                            '</button>' +
                        '</span>' +
                    '</div>'
                );
            }).join('');

            Array.prototype.forEach.call(list.querySelectorAll('.notif-item-main'), function (btn) {
                btn.addEventListener('click', function () {
                    var id = btn.getAttribute('data-id');
                    var link = btn.getAttribute('data-link');
                    markOne(id).then(function () {
                        if (link) {
                            window.location.href = link;
                        }
                    });
                });
            });

            Array.prototype.forEach.call(list.querySelectorAll('[data-notif-delete]'), function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    deleteOne(btn.getAttribute('data-id'));
                });
            });
        }

        function parseJsonResponse(r) {
            if (r.status === 401) {
                window.location.href = LOGIN_TIMEOUT_URL;
                return null;
            }
            if (!r.ok) {
                throw new Error('Notification request failed');
            }
            return r.json();
        }

        function refresh(renderIfOpen) {
            return fetch(API_URL, { credentials: 'same-origin' })
                .then(parseJsonResponse)
                .then(function (data) {
                    if (!data) { return null; }
                    setBadge(data.unread_count || 0);
                    if (renderIfOpen && panel.classList.contains('is-open')) {
                        renderList(data.notifications || []);
                    }
                    return data;
                })
                .catch(function () {});
        }

        function postAction(action, extra) {
            var body = new URLSearchParams(Object.assign({ action: action, csrf_token: csrfToken }, extra || {}));
            return fetch(API_URL, { method: 'POST', credentials: 'same-origin', body: body })
                .then(parseJsonResponse);
        }

        function markAll() {
            return postAction('mark_all').then(function (data) {
                if (!data) { return; }
                setBadge(data.unread_count || 0);
                renderList(data.notifications || []);
            });
        }

        function markOne(id) {
            return postAction('mark_one', { id: id }).then(function (data) {
                if (!data) { return; }
                setBadge(data.unread_count || 0);
            });
        }

        function deleteOne(id) {
            return postAction('delete_one', { id: id }).then(function (data) {
                if (!data) { return; }
                setBadge(data.unread_count || 0);
                renderList(data.notifications || []);
            });
        }

        function openPanel() {
            panel.classList.add('is-open');
            backdrop.hidden = false;
            bellBtn.setAttribute('aria-expanded', 'true');
            refresh(false).then(function (data) {
                if (data) {
                    renderList(data.notifications || []);
                }
            });
        }

        function closePanel() {
            panel.classList.remove('is-open');
            backdrop.hidden = true;
            bellBtn.setAttribute('aria-expanded', 'false');
        }

        bellBtn.addEventListener('click', function () {
            if (panel.classList.contains('is-open')) {
                closePanel();
            } else {
                openPanel();
            }
        });
        backdrop.addEventListener('click', closePanel);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closePanel();
            }
        });
        markAllBtn.addEventListener('click', markAll);

        refresh(true);
        setInterval(function () { refresh(true); }, POLL_INTERVAL_MS);
    });
})();
