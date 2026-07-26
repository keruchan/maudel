/**
 * ============================================================
 * File     : js/qr-attendance.js
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared camera-scanner controller for QR attendance (P19).
 *            Used by the official's scanner (scans youth ID cards) and by
 *            the youth self check-in page (scans the event poster) — the
 *            only difference is the action it posts.
 *
 * Wraps html5-qrcode (CDN) and posts decoded payloads to
 * pages/api/attendance.php. Deliberately defensive about two real-world
 * failure modes:
 *
 *   1. Camera needs a secure context. http://localhost counts as secure,
 *      but a plain-http LAN address (e.g. http://192.168.x.x) does not, so
 *      getUserMedia is simply absent there. We detect that up front and
 *      say so plainly instead of failing with a cryptic browser error —
 *      the manual entry box next to the scanner still works.
 *   2. A held-up QR decodes many times per second. We ignore a repeat of
 *      the same payload inside a short window so one person in the queue
 *      produces one request, not thirty.
 * ============================================================
 */
(function (global) {
    'use strict';

    var REPEAT_WINDOW_MS = 3000;

    function SkedScanner(options) {
        this.opts = options || {};
        this.scanner = null;
        this.running = false;
        this.lastPayload = '';
        this.lastAt = 0;
        this.busy = false;
    }

    /** Camera access requires a secure context (https, or localhost). */
    SkedScanner.cameraAvailable = function () {
        return !!(global.isSecureContext &&
            global.navigator &&
            global.navigator.mediaDevices &&
            global.navigator.mediaDevices.getUserMedia);
    };

    SkedScanner.unavailableReason = function () {
        if (!global.isSecureContext) {
            return 'The camera is blocked because this page is not on a secure origin. ' +
                'Open SKed via http://localhost or set up HTTPS to scan. You can still type the ID below.';
        }
        if (!(global.navigator && global.navigator.mediaDevices)) {
            return 'This browser does not expose a camera API. You can still type the ID below.';
        }
        return 'No camera is available. You can still type the ID below.';
    };

    SkedScanner.prototype.start = function () {
        var self = this;
        if (this.running) { return; }
        if (typeof global.Html5Qrcode === 'undefined') {
            this._emit({ ok: false, result: 'rejected', message: 'Scanner library failed to load. Check your connection, or use manual entry.' });
            return;
        }

        this.scanner = new global.Html5Qrcode(this.opts.mount);
        this.scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            function (decoded) { self.handle(decoded); },
            function () { /* per-frame "no QR in view" — expected, ignore */ }
        ).then(function () {
            self.running = true;
            if (typeof self.opts.onStateChange === 'function') { self.opts.onStateChange(true); }
        }).catch(function (err) {
            self._emit({
                ok: false,
                result: 'rejected',
                message: 'Could not start the camera: ' + err + '. Use manual entry instead.'
            });
        });
    };

    SkedScanner.prototype.stop = function () {
        var self = this;
        if (!this.scanner || !this.running) { return; }
        this.scanner.stop().then(function () {
            self.running = false;
            self.scanner.clear();
            if (typeof self.opts.onStateChange === 'function') { self.opts.onStateChange(false); }
        }).catch(function () { /* already stopped */ });
    };

    /** Decoded a code (from camera or manual box) — submit it. */
    SkedScanner.prototype.handle = function (payload) {
        var now = Date.now();
        if (this.busy) { return; }
        if (payload === this.lastPayload && (now - this.lastAt) < REPEAT_WINDOW_MS) { return; }
        this.lastPayload = payload;
        this.lastAt = now;
        this.submit(payload);
    };

    SkedScanner.prototype.submit = function (payload) {
        var self = this;
        this.busy = true;

        var body = new URLSearchParams();
        body.set('csrf_token', this.opts.csrf);
        body.set('action', this.opts.action);
        body.set('payload', payload);
        if (this.opts.eventId) { body.set('event_id', this.opts.eventId); }

        fetch(this.opts.api, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, result: 'rejected', message: 'Server returned an unreadable response (HTTP ' + r.status + ').' };
            });
        }).then(function (data) {
            self._emit(data);
        }).catch(function (err) {
            self._emit({ ok: false, result: 'rejected', message: 'Network error: ' + err });
        }).then(function () {
            self.busy = false;
        });
    };

    SkedScanner.prototype._emit = function (data) {
        if (typeof this.opts.onResult === 'function') { this.opts.onResult(data); }
    };

    global.SkedScanner = SkedScanner;
})(window);
