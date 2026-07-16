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
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>"">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
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

    .search-results {
      margin-top: 0.35rem;
      border: 1px solid #d9e2ec;
      border-radius: 10px;
      max-height: 220px;
      overflow-y: auto;
      background: #fff;
      display: none;
      position: relative;
      z-index: 20;
    }

    .search-result-item {
      display: block;
      width: 100%;
      text-align: left;
      border: 0;
      background: transparent;
      padding: 0.65rem 0.75rem;
      cursor: pointer;
      border-bottom: 1px solid #eef2f7;
    }

    .search-result-item:last-child {
      border-bottom: 0;
    }

    .search-result-item:hover {
      background: #f7fafc;
    }

    .search-result-main {
      font-weight: 600;
      color: #1f2937;
      font-size: 0.92rem;
    }

    .search-result-meta {
      font-size: 0.78rem;
      color: #6b7280;
      margin-top: 0.15rem;
    }

    [data-theme="dark"] .search-results {
      border-color: #394150;
      background: #111827;
    }

    [data-theme="dark"] .search-result-item {
      border-bottom-color: #1f2937;
    }

    [data-theme="dark"] .search-result-item:hover {
      background: #1f2937;
    }

    [data-theme="dark"] .search-result-main {
      color: #f3f4f6;
    }

    [data-theme="dark"] .search-result-meta {
      color: #9ca3af;
    }
  </style>
</head>
<body>
  <div class="dashboard-wrapper">
    <nav class="sidebar">
      <div class="sidebar-brand"><h3>Admin</h3></div>
                  <?php renderAdminSidebar(); ?>
        <li class="nav-item"><a class="nav-link" href="profit-withdrawals.php"><i class="fas fa-hand-holding-usd"></i> Profit Withdrawals</a></li>
    </nav>
    <main class="main-content">
      <header class="dashboard-header">
        <div class="header-left">
          <button class="mobile-menu-toggle" type="button"><i class="fas fa-bars"></i></button>
          <h2>Manual Top-up (Admin to Agent/Customer)</h2>
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
                <label class="form-label">Target Type</label>
                <select class="form-control" id="target_type" required>
                  <option value="agent">Agent</option>
                  <option value="customer">Customer</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" id="identifier_label">Agent (Email or Phone)</label>
                <input type="text" class="form-control" id="user_identifier" placeholder="agent@example.com or 233xxxxxxxxx" required>
                <input type="hidden" id="selected_user_id" value="">
                <div id="user_search_results" class="search-results"></div>
                <small class="form-text text-muted">Type at least 2 characters to search and select.</small>
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
        const targetType = document.getElementById('target_type').value;
        const isCustomer = targetType === 'customer';
        const identifierValue = document.getElementById('user_identifier').value.trim();
        const selectedUserId = parseInt(document.getElementById('selected_user_id').value || '0', 10);
        if (!identifierValue) {
          alert('Please enter a valid ' + (isCustomer ? 'customer' : 'agent') + ' identifier.');
          btn.disabled = false; btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Apply Adjustment';
          return;
        }
        const action = isCustomer ? 'admin_to_customer' : 'admin_to_agent';
        const payload = new URLSearchParams({
          action: action,
          amount: String(amountValue),
          note: document.getElementById('note').value.trim(),
          csrf_token: '<?php echo htmlspecialchars($csrf); ?>'
        });
        if (Number.isFinite(selectedUserId) && selectedUserId > 0) {
          payload.set(isCustomer ? 'customer_id' : 'agent_id', String(selectedUserId));
        } else {
          payload.set(isCustomer ? 'customer_identifier' : 'agent_identifier', identifierValue);
        }
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
      const targetType = document.getElementById('target_type');
      const identifierLabel = document.getElementById('identifier_label');
      const identifierInput = document.getElementById('user_identifier');
      const selectedUserIdInput = document.getElementById('selected_user_id');
      const searchResults = document.getElementById('user_search_results');
      let searchTimer = null;
      let searchRequestCounter = 0;

      function clearSearchResults() {
        if (searchResults) {
          searchResults.innerHTML = '';
          searchResults.style.display = 'none';
        }
      }

      function formatResultMeta(user) {
        const bits = [];
        if (user.email) bits.push(user.email);
        if (user.phone) bits.push(user.phone);
        if (user.username) bits.push('@' + user.username);
        return bits.join(' | ');
      }

      function selectUser(user) {
        if (!identifierInput || !selectedUserIdInput) return;
        const label = user.full_name && user.full_name.trim() !== '' ? user.full_name : ('User #' + user.id);
        const meta = user.email || user.phone || user.username || '';
        identifierInput.value = meta ? (label + ' (' + meta + ')') : label;
        selectedUserIdInput.value = String(user.id || '');
        clearSearchResults();
      }

      function renderSearchResults(users) {
        if (!searchResults) return;
        if (!Array.isArray(users) || users.length === 0) {
          searchResults.innerHTML = '';
          searchResults.style.display = 'none';
          return;
        }
        searchResults.innerHTML = '';
        const fragment = document.createDocumentFragment();
        users.forEach(function(user) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'search-result-item';

          const name = (user.full_name && String(user.full_name).trim() !== '') ? user.full_name : ('User #' + user.id);
          const meta = formatResultMeta(user) || 'No contact details';

          const main = document.createElement('div');
          main.className = 'search-result-main';
          main.textContent = name;

          const metaEl = document.createElement('div');
          metaEl.className = 'search-result-meta';
          metaEl.textContent = meta;

          btn.appendChild(main);
          btn.appendChild(metaEl);
          btn.addEventListener('click', function() {
            selectUser(user);
          });
          fragment.appendChild(btn);
        });
        searchResults.appendChild(fragment);
        searchResults.style.display = 'block';
      }

      async function fetchUsers(queryText) {
        if (!targetType) return;
        const requestId = ++searchRequestCounter;
        const payload = new URLSearchParams({
          action: 'admin_search_users',
          role: targetType.value === 'customer' ? 'customer' : 'agent',
          query: queryText,
          csrf_token: '<?php echo htmlspecialchars($csrf); ?>'
        });

        try {
          const res = await fetch('../api/manual_topup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: payload.toString()
          });
          const parsed = await readJsonResponse(res);
          if (requestId !== searchRequestCounter) return;
          if (!parsed.ok || !parsed.data || parsed.data.status !== 'success') {
            clearSearchResults();
            return;
          }
          renderSearchResults(parsed.data.users || []);
        } catch (err) {
          clearSearchResults();
        }
      }

      function syncIdentifierUI() {
        const isCustomer = targetType && targetType.value === 'customer';
        if (identifierLabel) {
          identifierLabel.textContent = isCustomer ? 'Customer (Email or Phone)' : 'Agent (Email or Phone)';
        }
        if (identifierInput) {
          identifierInput.placeholder = isCustomer
            ? 'customer@example.com or 233xxxxxxxxx'
            : 'agent@example.com or 233xxxxxxxxx';
          identifierInput.value = '';
        }
        if (selectedUserIdInput) {
          selectedUserIdInput.value = '';
        }
        clearSearchResults();
      }

      if (targetType) {
        targetType.addEventListener('change', syncIdentifierUI);
        syncIdentifierUI();
      }

      if (identifierInput) {
        identifierInput.addEventListener('input', function() {
          if (selectedUserIdInput) {
            selectedUserIdInput.value = '';
          }
          const term = identifierInput.value.trim();
          if (searchTimer) {
            clearTimeout(searchTimer);
          }
          if (term.length < 2) {
            clearSearchResults();
            return;
          }
          searchTimer = setTimeout(function() {
            fetchUsers(term);
          }, 250);
        });

        identifierInput.addEventListener('focus', function() {
          const term = identifierInput.value.trim();
          if (term.length >= 2) {
            fetchUsers(term);
          }
        });
      }

      document.addEventListener('click', function(event) {
        const withinSearch = searchResults && searchResults.contains(event.target);
        const onInput = identifierInput && (event.target === identifierInput);
        if (!withinSearch && !onInput) {
          clearSearchResults();
        }
      });

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


