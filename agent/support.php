<?php
require_once '../config/config.php';
requireRole('agent');
$current = getCurrentUser();
$csrf = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Support - Agent - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
  <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>""></script>
</head>
<body>
  <div class="dashboard-wrapper">
    <nav class="sidebar">
      <div class="sidebar-brand"><h3>Agent</h3></div>
      <?php renderAgentSidebar(); ?>
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
                            <?php echo strtoupper(substr($current['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div style="font-weight: 500;"><?php echo htmlspecialchars($current['full_name']); ?></div>
                            <div style="font-size: 0.75rem; color: var(--text-muted);">Agent</div>
                        </div>
                        <i class="fas fa-chevron-down" style="margin-left: 0.5rem;"></i>
                    </button>
                    
                    <div class="user-dropdown-menu" id="userDropdown">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a href="wallet.php" class="dropdown-item">
                            <i class="fas fa-wallet"></i> Wallet
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
        <div class="dashboard-grid">
          <div class="widget">
            <div class="widget-header"><h3 class="widget-title">Create Ticket to Admin</h3></div>
            <div class="widget-body">
              <form id="createTicketForm">
                <div class="form-group">
                  <label class="form-label">Subject</label>
                  <input type="text" class="form-control" id="subject" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Category</label>
                  <select class="form-control" id="category">
                    <option value="technical">Technical Issue</option>
                    <option value="payment">Payment Issue</option>
                    <option value="api">API Support</option>
                    <option value="account">Account Issue</option>
                    <option value="other" selected>Other</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Priority</label>
                  <select class="form-control" id="priority">
                    <option value="low">Low</option>
                    <option value="medium" selected>Medium</option>
                    <option value="high">High</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Message</label>
                  <textarea class="form-control" id="message" rows="4" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary" id="createBtn"><i class="fas fa-paper-plane"></i> Submit Ticket to Admin</button>
              </form>
            </div>
          </div>
          <div class="widget">
            <div class="widget-header"><h3 class="widget-title">Customer Tickets (Assigned to Me)</h3></div>
            <div class="widget-body">
              <div id="customerTickets"></div>
            </div>
          </div>
          <div class="widget">
            <div class="widget-header"><h3 class="widget-title">My Tickets to Admin</h3></div>
            <div class="widget-body">
              <div id="myTickets"></div>
            </div>
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
          <label for="replyMessage" class="form-label">Agent Reply:</label>
          <textarea id="replyMessage" class="form-control" rows="3" placeholder="Type your agent response here..."></textarea>
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
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(4px);
    }

    .modal-content {
      background-color: var(--bg-color, #ffffff);
      margin: 5% auto;
      border: 1px solid var(--border-color, #ddd);
      border-radius: 12px;
      width: 90%;
      max-width: 700px;
      max-height: 80vh;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
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
      border-bottom: 1px solid var(--border-color, #eee);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: linear-gradient(135deg, var(--primary-color, #007bff), var(--primary-dark, #0056b3));
      color: white;
    }

    .modal-header h3 {
      margin: 0;
      font-size: 1.25rem;
      font-weight: 600;
    }

    .modal-close {
      color: rgba(255, 255, 255, 0.8);
      font-size: 28px;
      font-weight: bold;
      cursor: pointer;
      line-height: 1;
      transition: color 0.2s;
    }

    .modal-close:hover {
      color: white;
    }

    .modal-body {
      padding: 24px;
      max-height: 60vh;
      overflow-y: auto;
      color: var(--text-color, #333);
    }

    .ticket-messages {
      margin-bottom: 24px;
    }

    .message-item {
      padding: 16px;
      margin-bottom: 12px;
      border-radius: 8px;
      border-left: 4px solid var(--primary-color, #007bff);
      background-color: var(--widget-bg, #f8f9fa);
      border: 1px solid var(--border-color, #e9ecef);
    }

    .message-sender {
      font-weight: 600;
      color: var(--primary-color, #007bff);
      margin-bottom: 4px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .message-time {
      font-size: 0.875rem;
      color: var(--text-muted, #6c757d);
      font-weight: normal;
    }

    .message-content {
      margin-top: 8px;
      line-height: 1.5;
      word-wrap: break-word;
    }

    .reply-section {
      border-top: 1px solid var(--border-color, #eee);
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
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    /* Dark Mode Styles */
    [data-theme="dark"] .modal-content {
      background-color: var(--bg-dark, #2d3748);
      border-color: var(--border-dark, #4a5568);
      color: var(--text-dark, #e2e8f0);
    }

    [data-theme="dark"] .modal-header {
      border-bottom-color: var(--border-dark, #4a5568);
    }

    [data-theme="dark"] .message-item {
      background-color: var(--widget-bg-dark, #4a5568);
      border-color: var(--border-dark, #718096);
    }

    [data-theme="dark"] .reply-section {
      border-top-color: var(--border-dark, #4a5568);
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
async function loadCustomerTickets(){
  const res = await fetch('../api/support.php?action=list_tickets&type=customer');
  const data = await res.json();
  const el = document.getElementById('customerTickets');
  if (data.status !== 'success'){ el.innerHTML = '<div class="alert alert-danger">Failed to load</div>'; return; }
  if (!data.tickets.length){ el.innerHTML = '<div class="text-muted">No customer tickets assigned</div>'; return; }
  el.innerHTML = data.tickets.map(t => `
  <div class=card style="margin-bottom:.75rem;">
    <div class=card-body>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <strong>#${t.id}</strong> - ${t.subject}
          <div class=text-muted style="font-size:.85rem;">${t.category} &bull; ${t.status} &bull; ${t.priority} &bull; Customer#${t.user_id}</div>
        </div>
        <div>
          <select onchange="updateStatus(${t.id}, this.value)" class="form-control" style="display:inline-block;width:auto;">
            ${['open','in_progress','resolved','closed'].map(s => `<option value="${s}" ${s===t.status?'selected':''}>${s}</option>`).join('')}
          </select>
          <button class="btn btn-outline" onclick="openThread(${t.id})">Open</button>
        </div>
      </div>
    </div>
  </div>`).join('');
}

async function loadMyTickets(){
  const res = await fetch('../api/support.php?action=list_tickets&type=my');
  const data = await res.json();
  const el = document.getElementById('myTickets');
  if (data.status !== 'success'){ el.innerHTML = '<div class="alert alert-danger">Failed to load</div>'; return; }
  if (!data.tickets.length){ el.innerHTML = '<div class="text-muted">No tickets to admin</div>'; return; }
  el.innerHTML = data.tickets.map(t => `
  <div class=card style="margin-bottom:.75rem;">
    <div class=card-body>
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <strong>#${t.id}</strong> - ${t.subject}
          <div class=text-muted style="font-size:.85rem;">${t.category} &bull; ${t.status} &bull; ${t.priority}</div>
        </div>
        <div>
          <button class="btn btn-outline" onclick="openThread(${t.id})">Open</button>
        </div>
      </div>
    </div>
  </div>`).join('');
}

async function updateStatus(id, status){
  const res = await fetch('../api/support.php', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':'<?php echo htmlspecialchars($csrf); ?>'}, body: JSON.stringify({ action:'update_status', ticket_id:id, status }) });
  const data = await res.json();
  if (data.status !== 'success') showNotification(data.message||'Failed to update status', 'error');
  else showNotification('Status updated successfully', 'success');
}

async function openThread(id){
  currentTicketId = id;
  const res = await fetch('../api/support.php?action=list_messages&ticket_id='+id);
  const data = await res.json();
  
  if (data.status !== 'success'){
    showNotification('Failed to load messages', 'error');
    return;
  }
  
  // Update modal title
  document.getElementById('modalTitle').textContent = `Ticket #${id} - Agent View`;
  
  // Display messages
  const messagesContainer = document.getElementById('ticketMessages');
  if (data.messages.length === 0) {
    messagesContainer.innerHTML = '<div class="text-muted" style="text-align: center; padding: 20px;">No messages yet</div>';
  } else {
    messagesContainer.innerHTML = data.messages.map(m => `
      <div class="message-item">
        <div class="message-sender">
          <span>${m.sender_name}</span>
          <span class="message-time">${new Date(m.created_at).toLocaleString()}</span>
        </div>
        <div class="message-content">${m.message.replace(/\n/g, '<br>')}</div>
      </div>
    `).join('');
  }
  
  // Clear reply field
  document.getElementById('replyMessage').value = '';
  
  // Show modal
  document.getElementById('ticketModal').style.display = 'block';
  document.body.style.overflow = 'hidden';
}

function closeTicketModal() {
  document.getElementById('ticketModal').style.display = 'none';
  document.body.style.overflow = 'auto';
  currentTicketId = null;
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
      // Refresh the messages
      openThread(currentTicketId);
      loadCustomerTickets(); // Refresh ticket lists
      loadMyTickets();
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
  // Create notification element
  const notification = document.createElement('div');
  notification.className = `alert alert-${type}`;
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1100;
    max-width: 300px;
    animation: slideInRight 0.3s ease-out;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  `;
  notification.textContent = message;
  
  document.body.appendChild(notification);
  
  // Remove after 3 seconds
  setTimeout(() => {
    notification.style.animation = 'slideOutRight 0.3s ease-in';
    setTimeout(() => {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    }, 300);
  }, 3000);
}

// Close modal when clicking outside
window.onclick = function(event) {
  const modal = document.getElementById('ticketModal');
  if (event.target === modal) {
    closeTicketModal();
  }
}

// Add CSS for notifications
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

// Theme management
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
  // Show OPPOSITE icon: moon for light theme (to switch TO dark), sun for dark theme (to switch TO light)
  if (icon) icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

// User dropdown
function toggleUserDropdown() {
  const dropdown = document.getElementById('userDropdown');
  dropdown.classList.toggle('show');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('userDropdown');
  const toggle = document.querySelector('.user-dropdown-toggle');
  
  if (dropdown && toggle && !toggle.contains(event.target)) {
    dropdown.classList.remove('show');
  }
});

// Mobile menu toggle
document.querySelector('.mobile-menu-toggle')?.addEventListener('click', function() {
  document.querySelector('.sidebar')?.classList.toggle('show');
});

// Initialize theme on page load
document.addEventListener('DOMContentLoaded', function() {
  initTheme();
});

document.getElementById('createTicketForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const btn = document.getElementById('createBtn');
  btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Submitting...';
  try {
    const res = await fetch('../api/support.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>' }, body: JSON.stringify({ action: 'create_ticket', subject: document.getElementById('subject').value.trim(), category: document.getElementById('category').value, priority: document.getElementById('priority').value, message: document.getElementById('message').value.trim() }) });
    const data = await res.json();
    if (data.status === 'success') { 
      showNotification('Ticket created successfully: #' + data.ticket_id, 'success'); 
      loadMyTickets(); 
      document.getElementById('createTicketForm').reset();
    }
    else { showNotification(data.message || 'Failed to create ticket', 'error'); }
  } catch(err) { showNotification('Network error occurred', 'error'); }
  btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Ticket to Admin';
});

loadCustomerTickets();
loadMyTickets();
</script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


