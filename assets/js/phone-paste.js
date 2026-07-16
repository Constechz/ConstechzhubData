(function () {
    'use strict';

    const ENHANCED_ATTR = 'data-phone-paste-enhanced';
    const FIELD_SELECTOR = [
        'input[type="tel"]',
        'input[name*="phone" i]',
        'input[id*="phone" i]',
        'input[name*="mobile" i]',
        'input[id*="mobile" i]',
        'input[name*="beneficiary" i]',
        'input[id*="beneficiary" i]',
        'input[name*="recipient" i]',
        'input[id*="recipient" i]'
    ].join(',');

    function labelLooksLikePhone(input) {
        if (!input) return false;
        const id = input.id || '';
        let labelText = '';
        if (id) {
            const label = document.querySelector('label[for="' + CSS.escape(id) + '"]');
            if (label) labelText += ' ' + label.textContent;
        }
        const parentLabel = input.closest('label');
        if (parentLabel) labelText += ' ' + parentLabel.textContent;
        return /phone|mobile|recipient|beneficiary|sms number/i.test(labelText);
    }

    function isPhoneField(input) {
        if (!input || input.disabled || input.readOnly) return false;
        if (input.matches(FIELD_SELECTOR)) return true;
        return labelLooksLikePhone(input);
    }

    function cleanPhoneText(text) {
        let value = String(text || '').trim();
        if (!value) return '';
        value = value.replace(/[^\d+]/g, '');
        if (value.indexOf('+233') === 0) {
            value = '233' + value.slice(4);
        }
        return value;
    }

    function setInputValue(input, value) {
        const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
        if (setter && typeof setter.set === 'function') {
            setter.set.call(input, value);
        } else {
            input.value = value;
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    }

    function showPasteState(button, state) {
        const original = button.getAttribute('data-original-title') || button.title || 'Paste phone number';
        if (!button.getAttribute('data-original-title')) {
            button.setAttribute('data-original-title', original);
        }
        button.classList.remove('phone-paste-ok', 'phone-paste-error');
        if (state === 'ok') {
            button.classList.add('phone-paste-ok');
            button.title = 'Pasted';
        } else if (state === 'error') {
            button.classList.add('phone-paste-error');
            button.title = 'Clipboard blocked';
        }
        window.setTimeout(function () {
            button.classList.remove('phone-paste-ok', 'phone-paste-error');
            button.title = original;
        }, 1400);
    }

    async function pasteInto(input, button) {
        try {
            if (!navigator.clipboard || typeof navigator.clipboard.readText !== 'function') {
                throw new Error('Clipboard API unavailable');
            }
            const text = await navigator.clipboard.readText();
            const cleaned = cleanPhoneText(text);
            if (!cleaned) {
                throw new Error('Clipboard does not contain a phone number');
            }
            setInputValue(input, cleaned);
            showPasteState(button, 'ok');
        } catch (error) {
            showPasteState(button, 'error');
        }
    }

    function ensureStyles() {
        if (document.getElementById('phonePasteStyles')) return;
        const style = document.createElement('style');
        style.id = 'phonePasteStyles';
        style.textContent = [
            '.phone-paste-wrap{display:flex;align-items:stretch;gap:.45rem;width:100%;}',
            '.phone-paste-wrap>input{flex:1 1 auto;min-width:0;}',
            '.phone-paste-btn{flex:0 0 46px;width:46px;min-height:46px;border:1px solid #d8e0eb;border-radius:14px;background:#fff;color:#334155;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(15,23,42,.06);}',
            '.phone-paste-btn:hover{border-color:#94a3b8;background:#f8fafc;}',
            '.phone-paste-btn.phone-paste-ok{border-color:#16a34a;color:#166534;background:#dcfce7;}',
            '.phone-paste-btn.phone-paste-error{border-color:#dc2626;color:#991b1b;background:#fee2e2;}',
            '.phone-paste-btn i{pointer-events:none;}'
        ].join('');
        document.head.appendChild(style);
    }

    function enhanceInput(input) {
        if (!isPhoneField(input) || input.getAttribute(ENHANCED_ATTR) === '1') return;
        if (input.closest('.phone-paste-wrap')) return;

        ensureStyles();

        const wrap = document.createElement('div');
        wrap.className = 'phone-paste-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'phone-paste-btn';
        button.title = 'Paste phone number';
        button.setAttribute('aria-label', 'Paste phone number');
        button.innerHTML = '<i class="fas fa-paste" aria-hidden="true"></i>';
        button.addEventListener('click', function () {
            pasteInto(input, button);
        });
        wrap.appendChild(button);
        input.setAttribute(ENHANCED_ATTR, '1');
    }

    function enhanceAll() {
        Array.from(document.querySelectorAll('input')).forEach(enhanceInput);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceAll);
    } else {
        enhanceAll();
    }

    const observer = new MutationObserver(enhanceAll);
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
