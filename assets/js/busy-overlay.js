(function () {
    if (window.fwBusy) return;

    var holds = 0;
    var overlay = null;
    var submittingNative = false;

    function ensureOverlay() {
        if (overlay) return overlay;
        overlay = document.createElement('div');
        overlay.id = 'fwBusyOverlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('aria-busy', 'true');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:30000;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,0.78);';
        overlay.innerHTML =
            '<div style="background:#fff;border-radius:14px;padding:1.35rem 1.6rem;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.16);min-width:220px;">' +
                '<div class="fw-busy-spinner" role="status" aria-hidden="true" style="width:3.25rem;height:3.25rem;margin:0 auto 0.85rem;border:0.35rem solid rgba(13,110,253,0.18);border-top-color:#0d6efd;border-radius:50%;animation:fw-busy-spin 0.7s linear infinite;"></div>' +
                '<div class="fw-busy-text" style="font-weight:600;color:#212529;">Bitte warten …</div>' +
                '<div style="margin-top:0.35rem;color:#6c757d;font-size:0.9rem;">Bitte nicht erneut klicken.</div>' +
            '</div>';
        if (!document.getElementById('fwBusySpinStyle')) {
            var style = document.createElement('style');
            style.id = 'fwBusySpinStyle';
            style.textContent = '@keyframes fw-busy-spin{to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }
        document.body.appendChild(overlay);
        return overlay;
    }

    function reveal(message) {
        if (!document.body) return;
        var el = ensureOverlay();
        var text = el.querySelector('.fw-busy-text');
        if (text) text.textContent = message || 'Bitte warten …';
        el.classList.add('is-active');
        el.style.display = 'flex';
        el.setAttribute('aria-hidden', 'false');
    }

    function conceal() {
        if (!overlay) return;
        overlay.classList.remove('is-active');
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');
    }

    function show(message) {
        holds += 1;
        reveal(message);
    }

    function hide() {
        holds = Math.max(0, holds - 1);
        if (holds === 0) conceal();
    }

    function isPostForm(form) {
        if (!form || form.nodeName !== 'FORM') return false;
        if (form.hasAttribute('data-no-busy')) return false;
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return false;
        var target = (form.getAttribute('target') || '').toLowerCase();
        if (target && target !== '_self') return false;
        return true;
    }

    function messageFor(form, submitter) {
        var custom = form.getAttribute('data-busy-text');
        if (custom) return custom;
        var label = '';
        if (submitter) {
            label = (submitter.textContent || submitter.value || '').replace(/\s+/g, ' ').trim();
        }
        if (!label) {
            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (btn) label = (btn.textContent || btn.value || '').replace(/\s+/g, ' ').trim();
        }
        var low = label.toLowerCase();
        if (low.indexOf('lösch') !== -1 || low.indexOf('loesch') !== -1) return 'Wird gelöscht …';
        if (low.indexOf('send') !== -1 || low.indexOf('absend') !== -1) return 'Wird gesendet …';
        if (low.indexOf('lad') !== -1 || low.indexOf('abruf') !== -1) return 'Wird geladen …';
        if (low.indexOf('speicher') !== -1 || low.indexOf('übernehm') !== -1 || low.indexOf('uebernehm') !== -1) return 'Wird gespeichert …';
        return 'Bitte warten …';
    }

    function lockControls(form) {
        var nodes = form.querySelectorAll('button, input[type="submit"], input[type="image"]');
        nodes.forEach(function (btn) {
            var type = (btn.getAttribute('type') || '').toLowerCase();
            if (btn.tagName === 'BUTTON' && type && type !== 'submit') return;
            if (!btn.disabled) {
                btn.disabled = true;
                btn.setAttribute('data-fw-locked', '1');
            }
        });
    }

    function begin(form, submitter) {
        if (form.getAttribute('data-fw-busy') === '1') return;
        form.setAttribute('data-fw-busy', '1');
        lockControls(form);
        show(messageFor(form, submitter));
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!isPostForm(form)) return;
        if (submittingNative) return;
        var state = form.getAttribute('data-fw-busy');
        if (state === '1' || state === 'pending') {
            e.preventDefault();
            e.stopImmediatePropagation();
            return;
        }
        form.setAttribute('data-fw-busy', 'pending');
    }, true);

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!isPostForm(form)) return;
        var submitter = e.submitter || null;
        setTimeout(function () {
            if (e.defaultPrevented) {
                if (form.getAttribute('data-fw-busy') === 'pending') {
                    form.removeAttribute('data-fw-busy');
                }
                return;
            }
            begin(form, submitter);
        }, 0);
    }, false);

    var originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (!isPostForm(this)) {
            return originalSubmit.call(this);
        }
        if (this.getAttribute('data-fw-busy') === '1') {
            return;
        }
        begin(this, null);
        submittingNative = true;
        try {
            return originalSubmit.call(this);
        } finally {
            submittingNative = false;
        }
    };

    window.fwBusy = {
        show: show,
        hide: hide,
        isActive: function () { return holds > 0; }
    };
})();
