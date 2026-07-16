<?php
require_once '../config/config.php';
requireRole('agent');
$current_user = getCurrentUser();
$csrf = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Top-up Customer Wallet - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
</head>
<body>
  <div class="dashboard-wrapper">
    <nav class="sidebar">
      <div class="sidebar-brand">
        <h3><?php echo htmlspecialchars(getSiteName()); ?></h3>
      </div>
      <?php renderAgentSidebar(); ?>
    </nav>
    <main class="main-content">
      <header class="dashboard-header">
        <div class="header-left">
          <button class="mobile-menu-toggle"><i class="fas fa-bars"></i></button>
          <nav class="breadcrumb">
            <div class="breadcrumb-item"><i class="fas fa-user-plus"></i></div>
            <div class="breadcrumb-item">Operations</div>
            <div class="breadcrumb-item active">Customer Top-up</div>
          </nav>
        </div>
        <div class="header-actions">
          <button class="theme-toggle" onclick="toggleTheme()">
            <i class="fas fa-sun" id="theme-icon"></i>
          </button>
          <div class="user-dropdown">
            <button class="user-dropdown-toggle" onclick="toggleUserDropdown()">
              <div class="user-avatar">
                <?php echo strtoupper(substr($current_user['full_name'], 0, 1)); ?>
              </div>
              <div>
                <div style="font-weight: 500;"><?php echo htmlspecialchars($current_user['full_name']); ?></div>
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
              <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid var(--border-color);">
              <a href="../logout.php" class="dropdown-item">
                <i class="fas fa-sign-out-alt"></i> Logout
              </a>
            </div>
          </div>
        </div>
      </header>
      <div class="dashboard-content">
        <div class="widget" style="max-width:640px;">
          <div class="widget-header"><h3 class="widget-title">Adjust Customer Wallet</h3></div>
          <div class="widget-body">
            <form id="agentToCustomerForm">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
              <div class="form-group">
                <label class="form-label">Customer (Email or Phone)</label>
                <input type="text" class="form-control" id="customer_identifier" placeholder="customer@example.com or 233xxxxxxxxx" required>
              </div>
              <div class="form-group">
                <label class="form-label">Amount (<?php echo CURRENCY; ?>)</label>
                <input type="number" class="form-control" id="amount" step="0.01" placeholder="Enter amount e.g. 50 or -20" required>
                <small class="text-muted">Positive values credit the customer (debited from you). Negative values deduct from the customer and return to your wallet.</small>
              </div>
              <div class="form-group">
                <label class="form-label">Note (optional)</label>
                <input type="text" class="form-control" id="note" placeholder="Reason or memo">
              </div>
              <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-exchange-alt"></i> Apply Adjustment</button>
            </form>
            <div style="margin-top:1rem;" class="alert alert-info">
              <i class="fas fa-info-circle"></i> Credits move funds from your wallet to the customer. Negative amounts reverse funds back to your wallet as a deduction from the customer.
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    async function readJsonResponse(res) {
      const text = await res.text();
      try {
        return { ok: res.ok, status: res.status, data: JSON.parse(text), raw: text };
      } catch (err) {
        return { ok: res.ok, status: res.status, data: null, raw: text };
      }
    }

    document.getElementById('agentToCustomerForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const btn = document.getElementById('submitBtn');
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Processing...';
      try {
        const amountValue = parseFloat(document.getElementById('amount').value);
        if (!Number.isFinite(amountValue) || amountValue === 0) {
          alert('Please enter a non-zero amount. Positive credits the customer, negative deducts and refunds you.');
          btn.disabled = false; btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Apply Adjustment';
          return;
        }
        const payload = new URLSearchParams({
          action: 'agent_to_customer',
          customer_identifier: document.getElementById('customer_identifier').value.trim(),
          amount: String(amountValue),
          note: document.getElementById('note').value.trim(),
          csrf_token: '<?php echo htmlspecialchars($csrf); ?>'
        });
        const res = await fetch('../api/manual_topup.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          credentials: 'same-origin',
          body: payload.toString()
        });
        const parsed = await readJsonResponse(res);
        if (!parsed.ok) {
          const message = parsed.data && parsed.data.message
            ? parsed.data.message
            : ('Request failed (' + parsed.status + ').');
          alert(message);
          if (!parsed.data && parsed.raw) {
            console.error('Manual topup response:', parsed.raw);
          }
          return;
        }
        if (!parsed.data) {
          alert('Unexpected response from server. Check the console for details.');
          if (parsed.raw) {
            console.error('Manual topup response:', parsed.raw);
          }
          return;
        }
        if (parsed.data.status === 'success') {
          alert('Success: ' + parsed.data.message + '\nRef: ' + (parsed.data.reference || ''));
          location.reload();
        } else {
          alert(parsed.data.message || 'Operation failed');
        }
      } catch(err) {
        alert('Network error. Please try again.');
        console.error(err);
      }
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Apply Adjustment';
    });

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
      if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }

    function toggleUserDropdown() {
      const dropdown = document.getElementById('userDropdown');
      if (dropdown) dropdown.classList.toggle('show');
    }

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
      initTheme();
      const mobileToggle = document.querySelector('.mobile-menu-toggle');
      const sidebar = document.querySelector('.sidebar');
      if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
          sidebar.classList.toggle('show');
        });
      }
    });

    document.addEventListener('click', function(event) {
      const dropdown = document.getElementById('userDropdown');
      const toggle = document.querySelector('.user-dropdown-toggle');
      if (dropdown && toggle && !toggle.contains(event.target)) {
        dropdown.classList.remove('show');
      }
    });
  </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>


