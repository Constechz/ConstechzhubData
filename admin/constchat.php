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
  <title>Constchat Panel - Admin - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
  <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>"></script>
  
  <style>
    /* Styling for Admin Constchat Panel */
    .admin-constchat-tabs {
      display: flex;
      background-color: var(--widget-bg, #ffffff);
      border-bottom: 1px solid var(--border-color, #e2e8f0);
      margin-bottom: 20px;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid var(--border-color, #e2e8f0);
    }
    .admin-constchat-tab {
      padding: 14px 20px;
      font-weight: 600;
      color: var(--text-muted, #718096);
      cursor: pointer;
      border-bottom: 3px solid transparent;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .admin-constchat-tab:hover {
      color: var(--primary-color, #E63B2C);
      background-color: rgba(230, 59, 44, 0.05);
    }
    .admin-constchat-tab.active {
      color: var(--primary-color, #E63B2C);
      border-bottom-color: var(--primary-color, #E63B2C);
      background-color: var(--bg-color, #f7fafc);
    }
    
    .panel-view {
      display: none;
    }
    .panel-view.active {
      display: block;
    }
    
    /* Table styles */
    .table-container {
      width: 100%;
      overflow-x: auto;
      background-color: var(--widget-bg, #ffffff);
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    }
    .constchat-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.9rem;
    }
    .constchat-table th, .constchat-table td {
      padding: 12px 16px;
      text-align: left;
      border-bottom: 1px solid var(--border-color, #e2e8f0);
    }
    .constchat-table th {
      background-color: var(--bg-color, #f7fafc);
      font-weight: 600;
      color: var(--text-color, #4a5568);
    }
    .constchat-table tbody tr:hover {
      background-color: rgba(0,0,0,0.02);
    }
    
    .type-badge {
      font-size: 0.75rem;
      font-weight: bold;
      text-transform: uppercase;
      padding: 2px 6px;
      border-radius: 4px;
    }
    .type-question { background-color: #ebf8ff; color: #2b6cb0; }
    .type-place { background-color: #f0fff4; color: #2f855a; }
    .type-suggestion { background-color: #faf5ff; color: #6b46c1; }
    
    .status-badge {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 99px;
    }
    .status-pending { background-color: #feebc8; color: #c05621; }
    .status-replied { background-color: #c6f6d5; color: #22543d; }
    
    /* Dynamic KB Training Box inside Modal */
    .kb-training-box {
      display: none;
      margin-top: 16px;
      padding: 16px;
      border: 1px dashed var(--primary-color, #E63B2C);
      background-color: rgba(230, 59, 44, 0.02);
      border-radius: 8px;
    }
    
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      backdrop-filter: blur(4px);
    }
    .modal-content {
      background-color: var(--widget-bg, #ffffff);
      margin: 5% auto;
      border: 1px solid var(--border-color, #ddd);
      border-radius: 12px;
      width: 90%;
      max-width: 650px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.25);
      animation: modalSlideIn 0.3s ease-out;
      overflow: hidden;
    }
    @keyframes modalSlideIn {
      from { opacity: 0; transform: translateY(-45px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .modal-header {
      padding: 16px 20px;
      background: linear-gradient(135deg, #E63B2C, #8B5CF6);
      color: white;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .modal-close {
      font-size: 24px;
      font-weight: bold;
      cursor: pointer;
    }
    .modal-body {
      padding: 20px;
      max-height: 75vh;
      overflow-y: auto;
    }
    .modal-footer {
      padding: 14px 20px;
      border-top: 1px solid var(--border-color, #e2e8f0);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }
    
    [data-theme="dark"] .admin-constchat-tabs {
      background-color: var(--widget-bg-dark, #2d3748);
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .admin-constchat-tab.active {
      background-color: #1a202c;
    }
    [data-theme="dark"] .table-container {
      background-color: var(--widget-bg-dark, #2d3748);
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .constchat-table th {
      background-color: #1a202c;
      color: #e2e8f0;
    }
    [data-theme="dark"] .constchat-table td {
      border-bottom-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .modal-content {
      background-color: var(--widget-bg-dark, #2d3748);
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .modal-footer {
      border-top-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .type-question { background-color: #2b6cb0; color: #ebf8ff; }
    [data-theme="dark"] .type-place { background-color: #2f855a; color: #f0fff4; }
    [data-theme="dark"] .type-suggestion { background-color: #6b46c1; color: #faf5ff; }
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
          <button class="mobile-menu-toggle">
            <i class="fas fa-bars"></i>
          </button>
          <nav class="breadcrumb">
            <div class="breadcrumb-item">
              <i class="fas fa-comments"></i>
            </div>
            <div class="breadcrumb-item">Constchat</div>
            <div class="breadcrumb-item active">Submissions</div>
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
              <a href="profile.php" class="dropdown-item"><i class="fas fa-user"></i> Profile</a>
              <a href="settings.php" class="dropdown-item"><i class="fas fa-cog"></i> Settings</a>
              <a href="dashboard.php" class="dropdown-item"><i class="fas fa-home"></i> Dashboard</a>
              <a href="../logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
          </div>
        </div>
      </header>
      
      <div class="dashboard-content">
        <div class="admin-constchat-tabs">
          <div class="admin-constchat-tab active" onclick="switchView('submissionsView', this)">
            <i class="fas fa-envelope-open-text"></i> User Submissions
          </div>
          <div class="admin-constchat-tab" onclick="switchView('knowledgeView', this)">
            <i class="fas fa-brain"></i> AI Knowledge Base
          </div>
        </div>
        
        <!-- VIEW 1: Submissions -->
        <div id="submissionsView" class="panel-view active">
          <div class="table-container">
            <table class="constchat-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Type</th>
                  <th>Title/Subject</th>
                  <th>Status</th>
                  <th>Submitted At</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="submissionsTableBody">
                <tr>
                  <td colspan="6" style="text-align:center; padding:20px; color:var(--text-muted);">Loading submissions...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        
        <!-- VIEW 2: Knowledge Base -->
        <div id="knowledgeView" class="panel-view">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <h3 style="margin:0; font-weight:600; color:var(--text-color);">Q&A Knowledge Base</h3>
            <button class="btn btn-primary" onclick="openKnowledgeModal(0)"><i class="fas fa-plus"></i> Add Q&A</button>
          </div>
          
          <div class="table-container">
            <table class="constchat-table">
              <thead>
                <tr>
                  <th>Category</th>
                  <th>Question</th>
                  <th>Answer</th>
                  <th>Keywords</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="knowledgeTableBody">
                <tr>
                  <td colspan="5" style="text-align:center; padding:20px; color:var(--text-muted);">Loading knowledge base...</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </main>
  </div>
  
  <!-- Reply Submissions Modal -->
  <div id="replyModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="replyModalTitle">Reply to Submission</h3>
        <span class="modal-close" onclick="closeReplyModal()">&times;</span>
      </div>
      <form id="replyForm" onsubmit="submitReply(event)">
        <input type="hidden" id="replyId">
        <div class="modal-body">
          <div id="submissionDetails" style="margin-bottom:16px; font-size:0.92rem; padding:12px; border-radius:6px; background-color:var(--bg-color, #f7fafc);">
            <!-- Dynamic Content -->
          </div>
          
          <div class="form-group">
            <label class="form-label" for="replyResponse">Your Response</label>
            <textarea class="form-control" id="replyResponse" rows="4" placeholder="Type your response to the user here..." required></textarea>
          </div>
          
          <div class="form-group" style="display:flex; align-items:center; gap:8px; margin-top:12px;">
            <input type="checkbox" id="replyAddToKb" onchange="toggleKbTrainingBox()">
            <label for="replyAddToKb" style="font-weight:600; cursor:pointer; margin:0;">Train Chatbot: Add this response to Bot Knowledge Base?</label>
          </div>
          
          <div class="kb-training-box" id="kbTrainingBox">
            <h4 style="margin:0 0 10px; font-weight:600; font-size:0.9rem;">Chatbot Q&A Details</h4>
            <div class="form-group">
              <label class="form-label" for="kbCategory">Category</label>
              <select class="form-control" id="kbCategory">
                <option value="general">General</option>
                <option value="data_bundle">Data Bundle</option>
                <option value="wallet">Wallet & Top-up</option>
                <option value="result_checker">Result Checker</option>
                <option value="places">Places / Stores</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="kbQuestion">Question Text (What triggers this answer)</label>
              <input type="text" class="form-control" id="kbQuestion" placeholder="e.g. How to buy data bundles?">
            </div>
            <div class="form-group">
              <label class="form-label" for="kbKeywords">Keywords (comma-separated for matching)</label>
              <input type="text" class="form-control" id="kbKeywords" placeholder="e.g. buy,data,bundle,order">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeReplyModal()">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnReplySubmit">Submit Reply</button>
        </div>
      </form>
    </div>
  </div>
  
  <!-- Add/Edit Q&A Modal -->
  <div id="knowledgeModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="knowledgeModalTitle">Add Q&A Entry</h3>
        <span class="modal-close" onclick="closeKnowledgeModal()">&times;</span>
      </div>
      <form id="knowledgeForm" onsubmit="saveKnowledge(event)">
        <input type="hidden" id="kbId">
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="editKbCategory">Category</label>
            <select class="form-control" id="editKbCategory">
              <option value="general">General</option>
              <option value="data_bundle">Data Bundle</option>
              <option value="wallet">Wallet & Top-up</option>
              <option value="result_checker">Result Checker</option>
              <option value="places">Places / Stores</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" for="editKbQuestion">Question</label>
            <input type="text" class="form-control" id="editKbQuestion" placeholder="e.g. What is Constechzhub?" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="editKbAnswer">Answer</label>
            <textarea class="form-control" id="editKbAnswer" rows="5" placeholder="Enter answer..." required></textarea>
          </div>
          <div class="form-group">
            <label class="form-label" for="editKbKeywords">Keywords (comma-separated)</label>
            <input type="text" class="form-control" id="editKbKeywords" placeholder="e.g. about,what,services">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeKnowledgeModal()">Cancel</button>
          <button type="submit" class="btn btn-primary" id="btnKbSave">Save Entry</button>
        </div>
      </form>
    </div>
  </div>
  
  <script>
    let activeSubmissions = [];
    let activeKnowledge = [];
    
    function switchView(viewId, el) {
      document.querySelectorAll('.panel-view').forEach(v => v.classList.remove('active'));
      document.querySelectorAll('.admin-constchat-tab').forEach(t => t.classList.remove('active'));
      
      document.getElementById(viewId).classList.add('active');
      el.classList.add('active');
      
      if (viewId === 'submissionsView') {
        loadSubmissions();
      } else {
        loadKnowledge();
      }
    }
    
    // Submissions
    async function loadSubmissions() {
      const container = document.getElementById('submissionsTableBody');
      container.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px;">Loading submissions...</td></tr>';
      
      try {
        const res = await fetch('../api/constchat.php?action=admin_list_submissions');
        const data = await res.json();
        
        if (data.status !== 'success') {
          container.innerHTML = '<tr><td colspan="6" style="text-align:center; color:red;">Failed to load submissions</td></tr>';
          return;
        }
        
        activeSubmissions = data.submissions;
        
        if (activeSubmissions.length === 0) {
          container.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px;">No user submissions found.</td></tr>';
          return;
        }
        
        container.innerHTML = activeSubmissions.map(s => {
          const typeLabel = s.type === 'question' ? 'Question' : (s.type === 'place' ? 'Place/Store' : 'Suggestion');
          const statusLabel = s.status === 'pending' ? 'Pending' : 'Replied';
          
          return `
            <tr>
              <td>
                <strong>${escapeHtml(s.full_name || 'Guest')}</strong><br>
                <small class="text-muted">${escapeHtml(s.email || '')}</small>
              </td>
              <td><span class="type-badge type-${s.type}">${typeLabel}</span></td>
              <td>
                <strong>${escapeHtml(s.title)}</strong><br>
                <small style="display:block; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${escapeHtml(s.content)}</small>
              </td>
              <td><span class="status-badge status-${s.status}">${statusLabel}</span></td>
              <td>${new Date(s.created_at).toLocaleString()}</td>
              <td>
                <button class="btn btn-sm btn-primary" onclick="openReplyModal(${s.id})">
                  <i class="fas ${s.status === 'pending' ? 'fa-reply' : 'fa-edit'}"></i> ${s.status === 'pending' ? 'Reply' : 'View/Edit'}
                </button>
              </td>
            </tr>
          `;
        }).join('');
      } catch (err) {
        container.innerHTML = '<tr><td colspan="6" style="text-align:center; color:red;">Network error loading submissions</td></tr>';
      }
    }
    
    function openReplyModal(id) {
      const s = activeSubmissions.find(sub => sub.id === id);
      if (!s) return;
      
      document.getElementById('replyId').value = s.id;
      document.getElementById('replyResponse').value = s.response || '';
      document.getElementById('replyAddToKb').checked = false;
      document.getElementById('kbTrainingBox').style.display = 'none';
      
      // Auto-fill chatbot question field with the user submission title/content
      document.getElementById('kbQuestion').value = s.content.length > 80 ? s.content.substring(0, 80) : s.content;
      document.getElementById('kbCategory').value = s.type === 'place' ? 'places' : 'general';
      document.getElementById('kbKeywords').value = s.title.toLowerCase().replace(/[^a-z0-9\s]/g, '').split(' ').filter(w => w.length > 2).join(',');
      
      let typeLabel = s.type === 'question' ? 'Question' : (s.type === 'place' ? 'PlaceRecommendation' : 'Suggestion');
      
      document.getElementById('submissionDetails').innerHTML = `
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
          <strong>From: ${escapeHtml(s.full_name || 'Guest')} (${escapeHtml(s.email || '')})</strong>
          <span class="type-badge type-${s.type}">${typeLabel}</span>
        </div>
        <div style="font-weight:600; margin-bottom:4px;">Title: ${escapeHtml(s.title)}</div>
        <div style="white-space: pre-wrap; line-height: 1.4;">${escapeHtml(s.content)}</div>
      `;
      
      document.getElementById('replyModal').style.display = 'block';
    }
    
    function closeReplyModal() {
      document.getElementById('replyModal').style.display = 'none';
    }
    
    function toggleKbTrainingBox() {
      const chk = document.getElementById('replyAddToKb');
      document.getElementById('kbTrainingBox').style.display = chk.checked ? 'block' : 'none';
    }
    
    async function submitReply(e) {
      e.preventDefault();
      const btn = document.getElementById('btnReplySubmit');
      btn.disabled = true;
      btn.textContent = 'Saving...';
      
      const id = parseInt(document.getElementById('replyId').value);
      const response = document.getElementById('replyResponse').value.trim();
      const addToKb = document.getElementById('replyAddToKb').checked;
      
      const payload = {
        action: 'admin_reply_submission',
        id: id,
        response: response,
        add_to_kb: addToKb
      };
      
      if (addToKb) {
        payload.question = document.getElementById('kbQuestion').value.trim();
        payload.category = document.getElementById('kbCategory').value;
        payload.keywords = document.getElementById('kbKeywords').value.trim();
      }
      
      try {
        const res = await fetch('../api/constchat.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
          },
          body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        if (data.status === 'success') {
          showNotification(data.message, 'success');
          closeReplyModal();
          loadSubmissions();
        } else {
          showNotification(data.message || 'Failed to save reply', 'error');
        }
      } catch(err) {
        showNotification('Network error occurred', 'error');
      }
      btn.disabled = false;
      btn.textContent = 'Submit Reply';
    }
    
    // Knowledge Base
    async function loadKnowledge() {
      const container = document.getElementById('knowledgeTableBody');
      container.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">Loading knowledge base...</td></tr>';
      
      try {
        const res = await fetch('../api/constchat.php?action=admin_list_knowledge');
        const data = await res.json();
        
        if (data.status !== 'success') {
          container.innerHTML = '<tr><td colspan="5" style="text-align:center; color:red;">Failed to load knowledge base</td></tr>';
          return;
        }
        
        activeKnowledge = data.knowledge;
        
        if (activeKnowledge.length === 0) {
          container.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px;">No chatbot entries found. Add one to start training the bot!</td></tr>';
          return;
        }
        
        container.innerHTML = activeKnowledge.map(k => `
          <tr>
            <td><strong style="text-transform:uppercase;">${k.category}</strong></td>
            <td><strong>${escapeHtml(k.question)}</strong></td>
            <td style="max-width:260px;"><div style="max-height: 80px; overflow-y:auto; line-height:1.4;">${escapeHtml(k.answer).replace(/\n/g, '<br>')}</div></td>
            <td><small class="text-muted">${escapeHtml(k.keywords || '-')}</small></td>
            <td>
              <div style="display:flex; gap:6px;">
                <button class="btn btn-sm btn-outline" onclick="openKnowledgeModal(${k.id})" style="padding:4px 8px; font-size:0.8rem;"><i class="fas fa-edit"></i> Edit</button>
                <button class="btn btn-sm btn-danger" onclick="deleteKnowledge(${k.id})" style="padding:4px 8px; font-size:0.8rem; background-color:#e53e3e;"><i class="fas fa-trash-alt"></i> Delete</button>
              </div>
            </td>
          </tr>
        `).join('');
      } catch (err) {
        container.innerHTML = '<tr><td colspan="5" style="text-align:center; color:red;">Network error loading knowledge base</td></tr>';
      }
    }
    
    function openKnowledgeModal(id) {
      if (id > 0) {
        const k = activeKnowledge.find(entry => entry.id === id);
        if (!k) return;
        document.getElementById('kbId').value = k.id;
        document.getElementById('editKbCategory').value = k.category;
        document.getElementById('editKbQuestion').value = k.question;
        document.getElementById('editKbAnswer').value = k.answer;
        document.getElementById('editKbKeywords').value = k.keywords || '';
        document.getElementById('knowledgeModalTitle').textContent = "Edit Q&A Entry";
      } else {
        document.getElementById('kbId').value = '';
        document.getElementById('knowledgeForm').reset();
        document.getElementById('knowledgeModalTitle').textContent = "Add Q&A Entry";
      }
      document.getElementById('knowledgeModal').style.display = 'block';
    }
    
    function closeKnowledgeModal() {
      document.getElementById('knowledgeModal').style.display = 'none';
    }
    
    async function saveKnowledge(e) {
      e.preventDefault();
      const btn = document.getElementById('btnKbSave');
      btn.disabled = true;
      btn.textContent = 'Saving...';
      
      const id = document.getElementById('kbId').value ? parseInt(document.getElementById('kbId').value) : 0;
      const category = document.getElementById('editKbCategory').value;
      const question = document.getElementById('editKbQuestion').value.trim();
      const answer = document.getElementById('editKbAnswer').value.trim();
      const keywords = document.getElementById('editKbKeywords').value.trim();
      
      try {
        const res = await fetch('../api/constchat.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
          },
          body: JSON.stringify({
            action: 'admin_save_knowledge',
            id: id,
            category: category,
            question: question,
            answer: answer,
            keywords: keywords
          })
        });
        
        const data = await res.json();
        if (data.status === 'success') {
          showNotification(data.message, 'success');
          closeKnowledgeModal();
          loadKnowledge();
        } else {
          showNotification(data.message || 'Failed to save entry', 'error');
        }
      } catch (err) {
        showNotification('Network error occurred', 'error');
      }
      btn.disabled = false;
      btn.textContent = 'Save Entry';
    }
    
    async function deleteKnowledge(id) {
      if (!confirm('Are you sure you want to delete this Q&A entry?')) return;
      
      try {
        const res = await fetch('../api/constchat.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
          },
          body: JSON.stringify({
            action: 'admin_delete_knowledge',
            id: id
          })
        });
        
        const data = await res.json();
        if (data.status === 'success') {
          showNotification(data.message, 'success');
          loadKnowledge();
        } else {
          showNotification(data.message || 'Failed to delete entry', 'error');
        }
      } catch (err) {
        showNotification('Network error occurred', 'error');
      }
    }
    
    function escapeHtml(text) {
      if (!text) return '';
      const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
      return text.replace(/[&<>"']/g, function(m) { return map[m]; });
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
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      `;
      notification.textContent = message;
      document.body.appendChild(notification);
      
      setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease-in';
        setTimeout(() => {
          if (notification.parentNode) notification.parentNode.removeChild(notification);
        }, 300);
      }, 3000);
    }
    
    // Add CSS for slide-in notification animation
    const styles = document.createElement('style');
    styles.textContent = `
      @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
      @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
    `;
    document.head.appendChild(styles);

    // Theme toggle & Dropdown logic
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

    window.onclick = function(event) {
      const replyM = document.getElementById('replyModal');
      const knowledgeM = document.getElementById('knowledgeModal');
      if (event.target === replyM) closeReplyModal();
      if (event.target === knowledgeM) closeKnowledgeModal();
    }

    document.addEventListener('DOMContentLoaded', function() {
      initTheme();
      loadSubmissions();
    });
  </script>
  <!-- IMMEDIATE Icon Fix for square placeholder issues -->
  <script src="../immediate_icon_fix.js"></script>
</body>
</html>
