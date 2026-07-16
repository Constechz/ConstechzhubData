(function(window, document) {
    'use strict';

    const state = {
        currentOrder: null,
        currentTrigger: null,
    };

    const dom = {
        modal: null,
        details: null,
        message: null,
        feedback: null,
        submitBtn: null,
    };

    function getSettings() {
        return window.ORDER_ESCALATION_SETTINGS || {};
    }

    function ensureDom() {
        if (!dom.modal) {
            dom.modal = document.getElementById('orderIssueModal');
            dom.details = document.getElementById('orderIssueDetails');
            dom.message = document.getElementById('orderIssueMessage');
            dom.feedback = document.getElementById('orderIssueFeedback');
            dom.submitBtn = document.getElementById('orderIssueSubmit');
        }
    }

    function parseOrder(button) {
        if (!button || !button.dataset.orderInfo) {
            return null;
        }
        try {
            return JSON.parse(button.dataset.orderInfo);
        } catch (error) {
            console.error('Failed to parse order payload', error);
            return null;
        }
    }

    function handleReportClick(button) {
        ensureDom();
        const order = parseOrder(button);
        if (!order) {
            return;
        }

        if (button.dataset.canReport === '0') {
            const reason = button.dataset.reportBlocked || 'You cannot report this order yet.';
            alert(reason);
            return;
        }

        state.currentOrder = order;
        state.currentTrigger = button;
        openModal(order);
    }

    function openModal(order) {
        ensureDom();
        if (!dom.modal) {
            return;
        }
        dom.modal.classList.add('order-issue-modal--visible');
        dom.modal.setAttribute('aria-hidden', 'false');
        dom.message.value = '';
        dom.message.focus();
        dom.feedback.textContent = '';
        dom.feedback.className = 'order-issue-modal__feedback';

        if (dom.details) {
            dom.details.innerHTML = `
                <div><strong>Order:</strong> #${String(order.id).padStart(6, '0')} ${order.reference ? '(' + order.reference + ')' : ''}</div>
                <div><strong>Package:</strong> ${order.package || 'N/A'} · ${order.network || ''}</div>
                <div><strong>Recipient:</strong> ${order.phone || 'N/A'}</div>
                <div><strong>Amount:</strong> ${order.amount_formatted || order.amount || ''}</div>
                <div><strong>Placed:</strong> ${order.created_at || ''}</div>
                <div><strong>Status:</strong> ${order.status || ''}</div>
            `;
        }
    }

    function closeModal() {
        ensureDom();
        if (!dom.modal) {
            return;
        }
        dom.modal.classList.remove('order-issue-modal--visible');
        dom.modal.setAttribute('aria-hidden', 'true');
        state.currentOrder = null;
        state.currentTrigger = null;
    }

    function submitReport(event) {
        event.preventDefault();
        ensureDom();
        if (!state.currentOrder) {
            return;
        }

        const settings = getSettings();
        const message = (dom.message.value || '').trim();
        if (message.length < 5) {
            showFeedback('Please describe the delivery issue (min 5 characters).', 'error');
            return;
        }

        if (dom.submitBtn) {
            dom.submitBtn.disabled = true;
            dom.submitBtn.textContent = 'Sending...';
        }

        fetch(settings.apiUrl || '../api/order_issues.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': settings.csrfToken || ''
            },
            body: JSON.stringify({
                action: 'report_issue',
                order_id: state.currentOrder.id,
                message: message
            })
        })
        .then((response) => response.json())
        .then((data) => {
            if (data.status === 'success') {
                showFeedback(data.message || 'Issue reported successfully.', 'success');
                if (state.currentTrigger) {
                    state.currentTrigger.dataset.canReport = '0';
                    state.currentTrigger.dataset.reportBlocked = 'Issue already reported for this order.';
                    state.currentTrigger.classList.add('order-issue-btn--disabled');
                }
                setTimeout(closeModal, 1500);
            } else {
                showFeedback(data.message || 'Unable to submit report. Please try again.', 'error');
            }
        })
        .catch((error) => {
            console.error('Order issue error', error);
            showFeedback('Unable to submit report. Please try again.', 'error');
        })
        .finally(() => {
            if (dom.submitBtn) {
                dom.submitBtn.disabled = false;
                dom.submitBtn.textContent = 'Submit Report';
            }
        });
    }

    function showFeedback(message, type) {
        ensureDom();
        if (!dom.feedback) {
            return;
        }
        dom.feedback.textContent = message;
        dom.feedback.className = 'order-issue-modal__feedback order-issue-modal__feedback--' + (type || 'info');
    }

    function handleWhatsAppClick(button) {
        const order = parseOrder(button);
        const settings = getSettings();
        if (!order || !settings.whatsappNumber) {
            return;
        }

        const rows = [
            `Order #${String(order.id).padStart(6, '0')}`,
            `Package: ${order.package || 'N/A'} (${order.network || ''})`,
            `Amount: ${order.amount_formatted || order.amount || ''}`,
            `Recipient: ${order.phone || 'N/A'}`,
            `Status: ${order.status || order.raw_status || 'Pending'}`,
        ];
        const message = encodeURIComponent(rows.join(' | '));
        const url = `https://wa.me/${settings.whatsappNumber}?text=${message}`;
        window.open(url, '_blank');
    }

    function handleBackdropClick(event) {
        if (event.target === dom.modal) {
            closeModal();
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        ensureDom();
        if (dom.modal) {
            dom.modal.addEventListener('click', handleBackdropClick);
        }
        const form = document.getElementById('orderIssueForm');
        if (form) {
            form.addEventListener('submit', submitReport);
        }
        const closeBtn = document.getElementById('orderIssueClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
    });

    window.OrderEscalation = {
        handleReportClick,
        handleWhatsAppClick,
        closeModal
    };
})(window, document);
