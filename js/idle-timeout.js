/**
 * ============================================================
 * File     : js/idle-timeout.js
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Client-side proactive idle-session timer. After 30 minutes
 *            with no mouse/keyboard/touch/scroll activity, auto-submits the
 *            sidebar's logout form (marking reason=idle) so the user is
 *            signed out immediately, without waiting for their next click.
 *
 * This is a UX nicety layered on top of the real security boundary: the
 * server independently enforces the same 30-minute timeout in
 * require_roles() (see SKED_IDLE_TIMEOUT_SECONDS in includes/auth.php) on
 * every page request regardless of whether this script runs at all.
 *
 * Loaded once per protected page by render_sked_navigation() in
 * includes/navigation.php, which also renders the logout form this hooks
 * into (data-logout-form / data-logout-reason attributes).
 * ============================================================
 */
(function () {
    'use strict';

    var TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes — keep in sync with SKED_IDLE_TIMEOUT_SECONDS
    var ACTIVITY_EVENTS = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart'];
    var THROTTLE_MS = 1000; // don't reset the timer on every single pixel of mouse movement

    document.addEventListener('DOMContentLoaded', function () {
        var logoutForm = document.querySelector('[data-logout-form]');
        var reasonField = document.querySelector('[data-logout-reason]');
        if (!logoutForm || !reasonField) {
            return;
        }

        var timer = null;
        var lastReset = 0;

        function doIdleLogout() {
            reasonField.value = 'idle';
            logoutForm.submit();
        }

        function resetTimer() {
            var now = Date.now();
            if (now - lastReset < THROTTLE_MS) {
                return;
            }
            lastReset = now;
            if (timer) {
                clearTimeout(timer);
            }
            timer = setTimeout(doIdleLogout, TIMEOUT_MS);
        }

        ACTIVITY_EVENTS.forEach(function (evt) {
            document.addEventListener(evt, resetTimer, { passive: true });
        });

        resetTimer();
    });
})();
