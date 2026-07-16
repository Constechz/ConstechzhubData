(function(window) {
    'use strict';

    const settings = window.ADMIN_REPORT_SETTINGS || {};

    function parsePayload(element) {
        if (!element || !element.dataset.reportInfo) {
            return null;
        }
        try {
            return JSON.parse(element.dataset.reportInfo);
        } catch (error) {
            console.error('Unable to parse transaction payload', error);
            return null;
        }
    }

    function formatCurrency(payload) {
        if (payload.amount_formatted) {
            return payload.amount_formatted;
        }
        if (typeof payload.amount === 'number') {
            return 'GHS ' + payload.amount.toFixed(2);
        }
        return String(payload.amount || '');
    }

    function buildWhatsAppMessage(payload) {
        const rows = [
            `Transaction #${payload.transaction_id || 'N/A'}`,
            payload.order_id ? `Order #${String(payload.order_id).padStart(6, '0')}` : '',
            payload.package ? `Package: ${payload.package}` : '',
            payload.volume ? `Volume: ${payload.volume}` : '',
            payload.network ? `Network: ${payload.network}` : '',
            payload.msisdn ? `MSISDN: ${payload.msisdn}` : '',
            `Amount: ${formatCurrency(payload)}`,
            `Status: ${payload.status || 'N/A'}`,
            `Reported from Admin Transactions`
        ].filter(Boolean);
        return encodeURIComponent(rows.join(' | '));
    }

    function openWhatsApp(payload) {
        if (!settings.whatsappNumber) {
            alert('No WhatsApp number configured for reports.');
            return;
        }
        const message = buildWhatsAppMessage(payload);
        const url = `https://wa.me/${settings.whatsappNumber}?text=${message}`;
        window.open(url, '_blank');
    }

    function sendReport(button) {
        const payload = parsePayload(button);
        if (!payload) {
            return;
        }

        const note = `Auto report from admin dashboard for transaction #${payload.transaction_id || 'N/A'}.`;
        button.disabled = true;
        button.classList.add('disabled');

        fetch(settings.apiUrl || '../api/report_transaction_issue.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': settings.csrfToken || ''
            },
            body: JSON.stringify({
                transaction_id: payload.transaction_id,
                order_id: payload.order_id || null,
                message: note
            })
        })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status !== 'success') {
                    throw new Error(data.message || 'Failed to notify email');
                }
                openWhatsApp(payload);
            })
            .catch(function(error) {
                console.error('Transaction report failed', error);
                alert(error.message || 'Unable to send report right now.');
            })
            .finally(function() {
                button.disabled = false;
                button.classList.remove('disabled');
            });
    }

    window.AdminTransactionReport = {
        send: sendReport
    };
})(window);
