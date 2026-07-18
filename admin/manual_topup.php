<?php
require_once '../config/config.php';
requireRole('admin');
$current = getCurrentUser();
$csrf = generateCSRF();

// Clear any existing flash messages to prevent logout messages from appearing
unset($_SESSION['flash_message']);

$flash = getFlashMessage();
$error = $flash && $flash['type'] !== 'success' ? $flash['message'] : '';
$success = $flash && $flash['type'] === 'success' ? $flash['message'] : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Manual Top-up - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>?v=<?php echo time(); ?>">
  <style>
    .header-actions.action-buttons .btn i {
      margin-right: 0.5rem;
    }

    @media (max-width: 768px) {
      .dashboard-header .header-left h2 {
        font-size: 1.2rem;
        line-height: 1.3;
      }
    }

    @media (max-width: 480px) {
      .dashboard-header .header-left h2 {
        font-size: 1.05rem;
      }
    }
  </style>
</head>
<body class="fa-ready">
  <div class="dashboard-wrapper">
    <nav class="sidebar">
      <div class="sidebar-brand"><h3>Admin</h3></div>
      <ul class="sidebar-nav">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="agents.php"><i class="fas fa-users"></i> Agents</a></li>
        <li class="nav-item"><a href="afa-registration.php" class="nav-link"><i class="fas fa-user-check"></i> AFA Registration</a></li>
        <li class="nav-item"><a class="nav-link" href="result-checker.php"><i class="fas fa-award"></i> Result Checker</a></li>
        <li class="nav-item"><a class="nav-link" href="transactions.php"><i class="fas fa-exchange-alt"></i> Transactions</a></li>
        <li class="nav-item"><a href="epayment.php" class="nav-link"><i class="fas fa-wallet"></i> ePayment</a></li>
        <li class="nav-item"><a class="nav-link active" href="manual_topup.php"><i class="fas fa-plus-circle"></i> Manual Top-up</a></li>
        <li class="nav-item"><a class="nav-link" href="support.php"><i class="fas fa-life-ring"></i> Support</a></li>
        <li class="nav-item"><a class="nav-link" href="system-reset.php"><i class="fas fa-broom"></i> System Reset</a></li>
      </ul>
        <li class="nav-item"><a class="nav-link" href="profit-withdrawals.php"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></li>
    </nav>
    <main class="main-content">
      <header class="dashboard-header">
        <div class="header-left">
          <button class="mobile-menu-toggle" type="button"><i class="fas fa-bars"></i></button>
          <h2>Manual Top-up (Admin → User)</h2>
        </div>
        <div class="header-actions action-buttons">
          <button type="button" class="btn btn-outline header-action-btn" id="backButton">
            <i class="fas fa-arrow-left"></i> Back
          </button>
          <a class="btn btn-primary header-action-btn" href="dashboard.php">
            <i class="fas fa-home"></i> Dashboard
          </a>
        </div>
      </header>
      <div class="dashboard-content">
        <div class="widget" style="max-width:640px;">
          <div class="widget-header"><h3 class="widget-title">Adjust User Wallet</h3></div>
          <div class="widget-body">
            <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
            <?php if ($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
 
            <form id="adminTopupForm">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
              <div class="form-group">
                <label class="form-label">User (Email or Phone)</label>
                <input type="text" class="form-control" id="agent_identifier" placeholder="user@example.com or phone number (e.g. 05xxxxxxxx)" required>
              </div>
              <div class="form-group">
                <label class="form-label">Amount (<?php echo CURRENCY; ?>)</label>
                <input type="number" class="form-control" id="amount" step="0.01" placeholder="Enter amount e.g. 100 or -50" required>
                <small class="form-text text-muted">Enter positive values to credit and negative values to deduct.</small>
              </div>
              <div class="form-group">
                <label class="form-label">Note (optional)</label>
                <input type="text" class="form-control" id="note" placeholder="Reason or memo">
              </div>
              <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-exchange-alt"></i> Apply Adjustment</button>
            </form>
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

    document.getElementById('adminTopupForm').addEventListener('submit', async function(e){
      e.preventDefault();
      const btn = document.getElementById('submitBtn');
      btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Processing...';
      try {
        const amountValue = parseFloat(document.getElementById('amount').value);
        if (!Number.isFinite(amountValue) || amountValue === 0) {
          alert('Please enter a non-zero amount. Positive credits the wallet, negative deducts.');
          btn.disabled = false; btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Apply Adjustment';
          return;
        }
        const payload = new URLSearchParams({
          action: 'admin_to_agent',
          agent_identifier: document.getElementById('agent_identifier').value.trim(),
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
          alert('Success: ' + parsed.data.message + '\nRef: ' + parsed.data.reference);
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

    // Initialize theme on page load
    document.addEventListener('DOMContentLoaded', function() {
      initTheme();
      const backButton = document.getElementById('backButton');
      if (backButton) {
        backButton.addEventListener('click', function() {
          const referrer = document.referrer;
          if (referrer && referrer.indexOf(window.location.origin) === 0) {
            window.location.href = referrer;
          } else {
            window.location.href = 'dashboard.php';
          }
        });
      }
      const mobileToggle = document.querySelector('.mobile-menu-toggle');
      if (mobileToggle) {
        mobileToggle.addEventListener('click', function() {
          const sidebar = document.querySelector('.sidebar');
          if (sidebar) {
            sidebar.classList.toggle('show');
            sidebar.classList.toggle('active');
          }
        });
      }
    });
  </script>
    <!-- IMMEDIATE Icon Fix for square placeholder issues -->
    <script src="../immediate_icon_fix.js"></script>
</body>
</html>

