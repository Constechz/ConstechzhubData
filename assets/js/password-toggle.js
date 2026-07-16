(function () {
    'use strict';

    function getInputForButton(button) {
        const targetId = button.getAttribute('data-target');
        if (targetId) {
            return document.getElementById(targetId);
        }

        // Fallback: look for closest input sibling
        const wrapper = button.closest('.password-input-wrapper');
        if (!wrapper) {
            return null;
        }
        return wrapper.querySelector('input[type="password"], input[data-password-visible]');
    }

    function updateButtonState(button, input) {
        const isHidden = input.type === 'password';
        const icon = button.querySelector('i');

        if (icon) {
            icon.classList.toggle('fa-eye', isHidden);
            icon.classList.toggle('fa-eye-slash', !isHidden);
        }

        button.setAttribute('aria-label', isHidden ? 'Show password' : 'Hide password');
        button.setAttribute('aria-pressed', isHidden ? 'false' : 'true');
    }

    function bindToggle(button) {
        if (button.dataset.passwordToggleBound === 'true') {
            return;
        }

        const input = getInputForButton(button);
        if (!input) {
            return;
        }

        button.dataset.passwordToggleBound = 'true';

        button.addEventListener('click', function () {
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            if (isHidden) {
                input.setAttribute('data-password-visible', 'true');
            } else {
                input.removeAttribute('data-password-visible');
            }
            updateButtonState(button, input);
        });

        updateButtonState(button, input);
    }

    function ensureWrapper(input) {
        if (input.closest('.password-input-wrapper')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'password-input-wrapper';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
    }

    function ensureToggleButton(input) {
        const wrapper = input.closest('.password-input-wrapper');
        if (!wrapper) {
            return null;
        }

        let button = wrapper.querySelector('.password-toggle');
        if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'password-toggle';
            button.setAttribute('aria-label', 'Show password');
            const icon = document.createElement('i');
            icon.className = 'fas fa-eye';
            button.appendChild(icon);
            wrapper.appendChild(button);
        }

        if (input.id && !button.getAttribute('data-target')) {
            button.setAttribute('data-target', input.id);
        }

        return button;
    }

    function enhancePasswordFields() {
        const inputs = document.querySelectorAll('input[type="password"]');

        inputs.forEach(function (input) {
            if (input.dataset.passwordToggle === 'disabled') {
                return;
            }

            ensureWrapper(input);
            const button = ensureToggleButton(input);
            if (button) {
                bindToggle(button);
            }
        });

        document.querySelectorAll('.password-toggle[data-target]').forEach(function (button) {
            bindToggle(button);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhancePasswordFields);
    } else {
        enhancePasswordFields();
    }
})();
