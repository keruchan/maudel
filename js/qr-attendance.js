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
        this._audioCtx = null;
        this._flashTimer = null;
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
        // Create/unlock the AudioContext here, synchronously inside the
        // button-click handler that called us — some browsers (notably iOS
        // Safari) only allow audio playback if the context was created or
        // resumed directly from a genuine user gesture, not from an async
        // callback later on (like the camera-decode success handler).
        this._beep_warm();
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

    // Feedback is deliberately multi-modal (sound + a colored flash right on
    // the camera view + vibration on phones), not just the text banner
    // below. Officials are looking at the card/camera, not the banner, at
    // the exact instant a scan resolves — a beep and a flash on the thing
    // they're already looking at is much harder to miss than text that may
    // be scrolled out of view or just outside their attention.
    SkedScanner.prototype._emit = function (data) {
        var tone = SkedScanner.resultTone(data);
        this._beep(tone);
        this._flash(tone);
        if (global.navigator && typeof global.navigator.vibrate === 'function') {
            global.navigator.vibrate(tone === 'success' ? 60 : (tone === 'warning' ? [50, 40, 50] : [120, 60, 120]));
        }
        if (typeof this.opts.onResult === 'function') { this.opts.onResult(data); }
    };

    /** Create (or resume) the shared AudioContext; returns null if unsupported. */
    SkedScanner.prototype._ensureAudio = function () {
        var Ctx = global.AudioContext || global.webkitAudioContext;
        if (!Ctx) { return null; }
        if (!this._audioCtx) {
            try {
                this._audioCtx = new Ctx();
            } catch (e) {
                return null;
            }
        }
        if (this._audioCtx.state === 'suspended' && typeof this._audioCtx.resume === 'function') {
            this._audioCtx.resume().catch(function () { /* still locked until a user gesture; harmless */ });
        }
        return this._audioCtx;
    };

    /** Called from a real button-click gesture (start()) to unlock audio early. */
    SkedScanner.prototype._beep_warm = function () {
        this._ensureAudio();
    };

    /** Short success/duplicate/error tone via Web Audio — no asset file needed. */
    SkedScanner.prototype._beep = function (tone) {
        var ctx = this._ensureAudio();
        if (!ctx) { return; }
        try {
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            var freq = tone === 'success' ? 880 : (tone === 'warning' ? 520 : 220);
            var dur = tone === 'success' ? 0.16 : 0.32;
            osc.type = 'sine';
            osc.frequency.value = freq;
            var now = ctx.currentTime;
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.3, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + dur);
            osc.start(now);
            osc.stop(now + dur + 0.02);
        } catch (e) { /* audio blocked/unavailable — the visual feedback still fires */ }
    };

    /** Brief colored glow around the camera viewport itself (see css/dashboard.css .scan-flash-*). */
    SkedScanner.prototype._flash = function (tone) {
        var el = this.opts.mount && global.document.getElementById(this.opts.mount);
        if (!el) { return; }
        var cls = 'scan-flash-' + tone;
        el.classList.add('scan-flash', cls);
        global.clearTimeout(this._flashTimer);
        this._flashTimer = global.setTimeout(function () {
            el.classList.remove('scan-flash', cls);
        }, 550);
    };

    SkedScanner.resultTone = function (data) {
        if (data && data.result === 'marked') { return 'success'; }
        if (data && data.result === 'duplicate') { return 'warning'; }
        return 'danger';
    };

    SkedScanner.resultIcon = function (tone) {
        if (tone === 'success') { return 'bi-check-circle-fill'; }
        if (tone === 'warning') { return 'bi-exclamation-circle-fill'; }
        return 'bi-x-circle-fill';
    };

    SkedScanner.resultTitle = function (data) {
        if (data && data.result === 'marked') { return 'QR scan successful'; }
        if (data && data.result === 'duplicate') { return 'Already scanned'; }
        return 'QR scan unsuccessful';
    };

    SkedScanner.renderResult = function (target, data, options) {
        if (!target) { return; }
        options = options || {};
        data = data || {};

        var tone = SkedScanner.resultTone(data);
        var icon = SkedScanner.resultIcon(tone);
        var message = data.message || 'That code was not accepted.';
        var title = options.title || SkedScanner.resultTitle(data);

        target.className = 'alert alert-' + tone + ' mb-3';
        target.setAttribute('role', 'status');
        target.setAttribute('aria-live', 'polite');
        target.innerHTML = '<div class="d-flex gap-2 align-items-start">' +
            '<i class="bi ' + icon + ' mt-1"></i>' +
            '<div><div class="fw-semibold">' + escapeHtml(title) + '</div>' +
            '<div>' + escapeHtml(message) + '</div>' +
            (options.linkHref && options.linkText
                ? ' <a class="alert-link" href="' + escapeAttr(options.linkHref) + '">' + escapeHtml(options.linkText) + '</a>'
                : '') +
            '</div></div>';

        // On a phone the camera viewport fills most of the screen, so this
        // banner (rendered just above it) can end up scrolled out of view
        // right when it appears — pull it back on screen instead of leaving
        // scans to look like they silently succeed with no feedback.
        if (typeof target.scrollIntoView === 'function') {
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    };

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
        });
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    global.SkedScanner = SkedScanner;
})(window);
