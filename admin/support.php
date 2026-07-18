<?php
require_once '../config/config.php';
requireRole('admin');
$current = getCurrentUser();
$csrf = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Support - Admin - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
  <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
</head>
<body>
  <div class="dashboard-wrapper">
    <nav class="sidebar">
      <div class="sidebar-brand"><h3>Admin</h3></div>
      <ul class="sidebar-nav">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="manual_topup.php"><i class="fas fa-plus-circle"></i> Manual Top-up</a></li>
        <li class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></li>
        <li class="nav-item"><a class="nav-link" href="result-checker.php"><i class="fas fa-award"></i> Result Checker</a></li>
        <li class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></li>
        <li class="nav-item"><a class="nav-link active" href="support.php"><i class="fas fa-life-ring"></i> Support</a></li>
        <li class="nav-item"><a class="nav-link" href="system-reset.php"><i class="fas fa-broom"></i> System Reset</a></li>
      </ul>
        <li class="nav-item"><a class="nav-link" href="profit-withdrawals.php"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></li>
    </nav>
    <main class="main-content">
      <header class="dashboard-header">
        <div class="header-left">
          <button class="mobile-menu-toggle">
            <i class="fas fa-bars"></i>
          </button>
          <nav class="breadcrumb">
            <div class="breadcrumb-item">
              <i class="fas fa-life-ring"></i>
            </div>
            <div class="breadcrumb-item active">Support</div>
          </nav>
        </div>
        
        <div class="header-actions">
          <button class="theme-toggle" onclick="toggleTheme()">
            <i class="fas fa-sun" id="theme-icon"></i>
          </button>
          
          <div class="user-dropdown">
            <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
              <div class="user-avatar">
                <?php echo strtoupper(substr($current['username'], 0, 1)); ?>
              </div>
              <div>
                <div style="font-weight: 500;"><?php echo htmlspecialchars($current['username']); ?></div>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Administrator</div>
              </div>
              <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
            </button>
            
            <div class="user-dropdown-menu" id="userDropdown">
              <a href="profile.php" class="dropdown-item">
                <i class="fas fa-user"></i> Profile
              </a>
              <a href="settings.php" class="dropdown-item">
                <i class="fas fa-cog"></i> Settings
              </a>
              <a href="dashboard.php" class="dropdown-item">
                <i class="fas fa-home"></i> Dashboard
              </a>
              <a href="../logout.php" class="dropdown-item">
                <i class="fas fa-sign-out-alt"></i> Logout
              </a>
            </div>
          </div>
        </div>
      </header>
      <div class="dashboard-content">
        <div class="widget">
          <div class="widget-header" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <h3 class="widget-title">Tickets</h3>
            <button class="btn btn-outline" onclick="deleteAllTickets()" style="border-color:#dc3545;color:#dc3545;">
              <i class="fas fa-trash"></i> Delete All
            </button>
          </div>
          <div class="widget-body">
            <div id="tickets"></div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Support Ticket Modal -->
  <div id="ticketModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="modalTitle">Ticket Messages</h3>
        <span class="modal-close" onclick="closeTicketModal()">&times;</span>
      </div>
      <div class="modal-body">
        <div id="ticketMessages" class="ticket-messages"></div>
        <div class="reply-section">
          <label for="replyMessage" class="form-label">Admin Reply:</label>
          <textarea id="replyMessage" class="form-control" rows="3" placeholder="Type your admin response here..."></textarea>
          <div class="modal-actions">
            <button class="btn btn-primary" onclick="sendReply()" id="sendReplyBtn">
              <i class="fas fa-paper-plane"></i> Send Reply
            </button>
            <button class="btn btn-secondary" onclick="closeTicketModal()">
              <i class="fas fa-times"></i> Close
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <style>
    /* Modal Styles with Dark Mode Support */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(46, 41, 78, 0.5);
      backdrop-filter: blur(4px);
    }

    .modal-content {
      background-color: var(--bg-color, #F1E9DA);
      margin: 5% auto;
      border: 1px solid var(--border-color, #F1E9DA);
      border-radius: 12px;
      width: 90%;
      max-width: 700px;
      max-height: 80vh;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(46, 41, 78, 0.3);
      animation: modalSlideIn 0.3s ease-out;
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.95);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      padding: 20px 24px 16px;
      border-bottom: 1px solid var(--border-color, #F1E9DA);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, var(--primary-color, #541388), var(--primary-dark, #541388));
      color: #F1E9DA;
    }

    .modal-header h3 {
      margin: 0;
      font-size: 1.25rem;
      font-weight: 600;
    }

    .modal-close {
      color: rgba(241, 233, 218, 0.8);
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
      line-height: 1;
      transition: color 0.2s;
    }

    .modal-close:hover {
      color: #F1E9DA;
    }

    .modal-body {
      padding: 24px;
      max-height: 60vh;
      overflow-y: auto;
      color: var(--text-color, #2E294E);
    }

    .ticket-messages {
      margin-bottom: 24px;
    }

    .message-item {
      padding: 16px;
      margin-bottom: 12px;
      border-radius: 8px;
      border-left: 4px solid var(--primary-color, #541388);
      background-color: var(--widget-bg, #F1E9DA);
      border: 1px solid var(--border-color, #F1E9DA);
    }

    .message-sender {
      font-weight: 600;
      color: var(--primary-color, #541388);
      margin-bottom: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .message-time {
      font-size: 0.875rem;
      color: var(--text-muted, #541388);
      font-weight: normal;
    }

    .message-content {
      margin-top: 8px;
      line-height: 1.5;
      word-wrap: break-word;
    }

    .reply-section {
      border-top: 1px solid var(--border-color, #F1E9DA);
      padding-top: 20px;
    }

    .modal-actions {
      margin-top: 16px;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }

    .modal-actions .btn {
      padding: 10px 20px;
      border-radius: 6px;
      font-weight: 500;
      transition: all 0.2s;
    }

    .modal-actions .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(46, 41, 78, 0.15);
    }

    /* Dark Mode Styles */
    [data-theme="dark"] .modal-content {
      background-color: var(--bg-dark, #2E294E);
      border-color: var(--border-dark, #2E294E);
      color: var(--text-dark, #F1E9DA);
    }

    [data-theme="dark"] .modal-header {
      border-bottom-color: var(--border-dark, #2E294E);
    }

    [data-theme="dark"] .message-item {
      background-color: var(--widget-bg-dark, #2E294E);
      border-color: var(--border-dark, #541388);
    }

    [data-theme="dark"] .reply-section {
      border-top-color: var(--border-dark, #2E294E);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .modal-content {
        width: 95%;
        margin: 10px auto;
        max-height: 90vh;
      }
      
      .modal-header,
      .modal-body {
        padding: 16px;
      }
      
      .modal-actions {
        flex-direction: column;
      }
      
      .modal-actions .btn {
        width: 100%;
      }
    }
  </style>

<script>
let currentTicketId = null;
const ticketStatusOptions = [
  { value: 'open', label: 'Open' },
  { value: 'in_progress', label: 'In Progress' },
  { value: 'resolved', label: 'Resolved' },
  { value: 'closed', label: 'Closed' }
];

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function toTitleCase(value) {
  return String(value ?? '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, c => c.toUpperCase());
}

async function loadTickets() {
  const res = await fetch('../api/support.php?action=list_tickets');
  const data = await res.json();
  const el = document.getElementById('tickets');

  if (data.status !== 'success') {
    el.innerHTML = '<div class="alert alert-danger">Failed to load tickets</div>';
    return;
  }

  if (!data.tickets.length) {
    el.innerHTML = '<div class="text-muted">No tickets</div>';
    return;
  }

  el.innerHTML = data.tickets.map(t => `
  <div class="card" style="margin-bottom:.75rem;">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;">
        <div>
          <strong>#${t.id}</strong> - ${escapeHtml(t.subject)}
          <div class="text-muted" style="font-size:.85rem;">
            ${toTitleCase(t.category)} | ${toTitleCase(t.status)} | ${toTitleCase(t.priority)} | User #${t.user_id}
          </div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
          <select onchange="updateStatus(${t.id}, this.value)" class="form-control" style="display:inline-block;width:auto;">
            ${ticketStatusOptions.map(s => `<option value="${s.value}" ${s.value === t.status ? 'selected' : ''}>${s.label}</option>`).join('')}
          </select>
          <button class="btn btn-outline" onclick="openThread(${t.id})">Open</button>
          <button class="btn btn-outline" onclick="deleteTicket(${t.id})" style="border-color:#dc3545;color:#dc3545;">Delete</button>
        </div>
      </div>
    </div>
  </div>`).join('');
}

async function updateStatus(id, status) {
  const res = await fetch('../api/support.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
    },
    body: JSON.stringify({ action: 'update_status', ticket_id: id, status })
  });
  const data = await res.json();
  if (data.status !== 'success') {
    showNotification(data.message || 'Failed to update status', 'error');
  } else {
    showNotification('Status updated successfully', 'success');
    loadTickets();
  }
}

async function openThread(id) {
  currentTicketId = id;
  const res = await fetch(`../api/support.php?action=list_messages&ticket_id=${id}`);
  const data = await res.json();

  if (data.status !== 'success') {
    showNotification('Failed to load messages', 'error');
    return;
  }

  document.getElementById('modalTitle').textContent = `Ticket #${id} - Admin View`;

  const messagesContainer = document.getElementById('ticketMessages');
  if (data.messages.length === 0) {
    messagesContainer.innerHTML = '<div class="text-muted" style="text-align:center;padding:20px;">No messages yet</div>';
  } else {
    messagesContainer.innerHTML = data.messages.map(m => `
      <div class="message-item">
        <div class="message-sender">
          <span>${escapeHtml(m.sender_name)}</span>
          <span class="message-time">${new Date(m.created_at).toLocaleString()}</span>
        </div>
        <div class="message-content">${escapeHtml(m.message).replace(/\n/g, '<br>')}</div>
      </div>
    `).join('');
  }

  document.getElementById('replyMessage').value = '';
  document.getElementById('ticketModal').style.display = 'block';
  document.body.style.overflow = 'hidden';
}

function closeTicketModal() {
  document.getElementById('ticketModal').style.display = 'none';
  document.body.style.overflow = 'auto';
  currentTicketId = null;
}

async function deleteTicket(id) {
  if (!confirm(`Delete ticket #${id}? This action cannot be undone.`)) return;

  try {
    const res = await fetch('../api/support.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
      },
      body: JSON.stringify({ action: 'delete_ticket', ticket_id: id })
    });

    const data = await res.json();
    if (data.status !== 'success') {
      showNotification(data.message || 'Failed to delete ticket', 'error');
      return;
    }

    if (currentTicketId === id) closeTicketModal();
    showNotification('Ticket deleted successfully', 'success');
    loadTickets();
  } catch (err) {
    showNotification('Network error occurred', 'error');
  }
}

async function deleteAllTickets() {
  if (!confirm('Delete all visible support tickets? This action cannot be undone.')) return;

  try {
    const res = await fetch('../api/support.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
      },
      body: JSON.stringify({ action: 'delete_all_tickets' })
    });

    const data = await res.json();
    if (data.status !== 'success') {
      showNotification(data.message || 'Failed to delete tickets', 'error');
      return;
    }

    closeTicketModal();
    showNotification(data.message || 'All tickets deleted successfully', 'success');
    loadTickets();
  } catch (err) {
    showNotification('Network error occurred', 'error');
  }
}

async function sendReply() {
  if (!currentTicketId) return;

  const replyText = document.getElementById('replyMessage').value.trim();
  if (!replyText) {
    showNotification('Please enter a reply message', 'warning');
    return;
  }

  const btn = document.getElementById('sendReplyBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Sending...';

  try {
    const r = await fetch('../api/support.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
      },
      body: JSON.stringify({
        action: 'add_message',
        ticket_id: currentTicketId,
        message: replyText
      })
    });

    const out = await r.json();

    if (out.status === 'success') {
      showNotification('Reply sent successfully', 'success');
      openThread(currentTicketId);
      loadTickets();
    } else {
      showNotification(out.message || 'Failed to send reply', 'error');
    }
  } catch (err) {
    showNotification('Network error occurred', 'error');
  }

  btn.disabled = false;
  btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply';
}

function showNotification(message, type = 'info') {
  const notification = document.createElement('div');
  notification.className = `alert alert-${type}`;
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1100;
    max-width: 300px;
    animation: slideInRight 0.3s ease-out;
    box-shadow: 0 4px 12px rgba(46, 41, 78, 0.15);
  `;
  notification.textContent = message;

  document.body.appendChild(notification);

  setTimeout(() => {
    notification.style.animation = 'slideOutRight 0.3s ease-in';
    setTimeout(() => {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    }, 300);
  }, 3000);
}

window.onclick = function(event) {
  const modal = document.getElementById('ticketModal');
  if (event.target === modal) {
    closeTicketModal();
  }
};

const notificationStyles = document.createElement('style');
notificationStyles.textContent = `
  @keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
  }
  @keyframes slideOutRight {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
  }
`;
document.head.appendChild(notificationStyles);

function initTheme() {
  const savedTheme = localStorage.getItem('theme');
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  const theme = savedTheme || (prefersDark ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', theme);
  updateThemeIcon(theme);
}

function toggleTheme() {
  const currentTheme = document.documentElement.getAttribute('data-theme');
  const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', newTheme);
  localStorage.setItem('theme', newTheme);
  updateThemeIcon(newTheme);
}

function updateThemeIcon(theme) {
  const icon = document.getElementById('theme-icon');
  if (icon) icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

function toggleUserDropdown() {
  const dropdown = document.getElementById('userDropdown');
  dropdown.classList.toggle('show');
}

document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('userDropdown');
  const toggle = document.querySelector('.user-dropdown-toggle');
  if (dropdown && toggle && !toggle.contains(event.target)) {
    dropdown.classList.remove('show');
  }
});

document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
  document.querySelector('.sidebar')?.classList.toggle('show');
});

document.addEventListener('DOMContentLoaded', function() {
  initTheme();
});

loadTickets();
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>

