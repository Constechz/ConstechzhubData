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
  <title>Constchat - Agent - <?php echo SITE_NAME; ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/style.css')); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/dashboard.css')); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/css/icon-fixes.css')); ?>">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(dbh_asset('assets/vendor/fontawesome/css/all.min.css')); ?>">
  <script src="<?php echo htmlspecialchars(dbh_asset('assets/js/font-awesome-loader.js')); ?>"></script>
  
  <style>
    /* Constchat-specific Styles */
    .constchat-container {
      display: flex;
      flex-direction: column;
      height: 70vh;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid var(--border-color, #e2e8f0);
      background-color: var(--widget-bg, #ffffff);
    }
    
    .constchat-tabs {
      display: flex;
      background-color: var(--bg-color, #f7fafc);
      border-bottom: 1px solid var(--border-color, #e2e8f0);
    }
    
    .constchat-tab {
      padding: 16px 24px;
      font-weight: 600;
      color: var(--text-muted, #718096);
      cursor: pointer;
      transition: all 0.2s ease;
      border-bottom: 3px solid transparent;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    
    .constchat-tab:hover {
      color: var(--primary-color, #E63B2C);
      background-color: rgba(230, 59, 44, 0.05);
    }
    
    .constchat-tab.active {
      color: var(--primary-color, #E63B2C);
      border-bottom-color: var(--primary-color, #E63B2C);
      background-color: var(--widget-bg, #ffffff);
    }
    
    .constchat-content {
      flex: 1;
      display: none;
      padding: 24px;
      overflow-y: auto;
      position: relative;
    }
    
    .constchat-content.active {
      display: flex;
      flex-direction: column;
    }
    
    /* Chat View */
    .chat-messages {
      flex: 1;
      overflow-y: auto;
      padding-bottom: 16px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      max-height: 48vh;
    }
    
    .message {
      max-width: 75%;
      padding: 12px 16px;
      border-radius: 12px;
      line-height: 1.5;
      font-size: 0.95rem;
      word-wrap: break-word;
      animation: messageFadeIn 0.25s ease-out;
    }
    
    @keyframes messageFadeIn {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    .message.bot {
      align-self: flex-start;
      background-color: var(--bg-color, #f1f5f9);
      color: var(--text-color, #1a202c);
      border-bottom-left-radius: 2px;
      border: 1px solid var(--border-color, #e2e8f0);
    }
    
    .message.user {
      align-self: flex-end;
      background: linear-gradient(135deg, var(--primary-color, #E63B2C), var(--brand-color, #8B5CF6));
      color: white;
      border-bottom-right-radius: 2px;
    }
    
    .chat-input-area {
      display: flex;
      gap: 12px;
      padding-top: 16px;
      border-top: 1px solid var(--border-color, #e2e8f0);
    }
    
    .chat-input {
      flex: 1;
      padding: 12px 16px;
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 8px;
      font-size: 0.95rem;
      background-color: var(--bg-color, #ffffff);
      color: var(--text-color, #1a202c);
      outline: none;
      transition: border-color 0.2s;
    }
    
    .chat-input:focus {
      border-color: var(--primary-color, #E63B2C);
    }
    
    /* Suggestions/Quick Actions */
    .chat-suggestions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 12px;
    }
    
    .suggestion-pill {
      background-color: rgba(230, 59, 44, 0.08);
      color: var(--primary-color, #E63B2C);
      border: 1px solid rgba(230, 59, 44, 0.15);
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 0.85rem;
      cursor: pointer;
      transition: all 0.2s ease;
      font-weight: 500;
    }
    
    .suggestion-pill:hover {
      background-color: var(--primary-color, #E63B2C);
      color: white;
      transform: translateY(-1px);
    }
    
    /* Typing Indicator */
    .typing-indicator {
      display: none;
      align-self: flex-start;
      background-color: var(--bg-color, #f1f5f9);
      border: 1px solid var(--border-color, #e2e8f0);
      padding: 12px 16px;
      border-radius: 12px;
      border-bottom-left-radius: 2px;
      gap: 4px;
      align-items: center;
    }
    
    .typing-dot {
      width: 6px;
      height: 6px;
      background-color: var(--text-muted, #718096);
      border-radius: 50%;
      animation: typingBounce 1.4s infinite ease-in-out both;
    }
    
    .typing-dot:nth-child(1) { animation-delay: -0.32s; }
    .typing-dot:nth-child(2) { animation-delay: -0.16s; }
    
    @keyframes typingBounce {
      0%, 80%, 100% { transform: scale(0); }
      40% { transform: scale(1); }
    }
    
    /* Submissions List */
    .submissions-list {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    
    .submission-card {
      border: 1px solid var(--border-color, #e2e8f0);
      border-radius: 8px;
      padding: 16px;
      background-color: var(--bg-color, #f8fafc);
      transition: transform 0.2s;
    }
    
    .submission-card:hover {
      transform: translateY(-2px);
    }
    
    .submission-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }
    
    .submission-type {
      font-size: 0.75rem;
      font-weight: bold;
      text-transform: uppercase;
      padding: 2px 8px;
      border-radius: 4px;
    }
    
    .type-question { background-color: #ebf8ff; color: #2b6cb0; }
    .type-place { background-color: #f0fff4; color: #2f855a; }
    .type-suggestion { background-color: #faf5ff; color: #6b46c1; }
    
    .submission-status {
      font-size: 0.75rem;
      font-weight: 600;
      padding: 2px 8px;
      border-radius: 99px;
    }
    
    .status-pending { background-color: #feebc8; color: #c05621; }
    .status-reviewed { background-color: #e2e8f0; color: #4a5568; }
    .status-replied { background-color: #c6f6d5; color: #22543d; }
    
    .submission-date {
      font-size: 0.8rem;
      color: var(--text-muted, #718096);
    }
    
    .submission-body {
      margin-top: 8px;
      font-size: 0.95rem;
      line-height: 1.5;
    }
    
    .submission-response {
      margin-top: 12px;
      padding: 12px;
      background-color: var(--widget-bg, #ffffff);
      border-left: 4px solid var(--primary-color, #E63B2C);
      border-radius: 4px;
      font-size: 0.9rem;
    }
    
    /* Markdown Styles inside Chat */
    .message a {
      color: #3182ce;
      text-decoration: underline;
    }
    .message.user a {
      color: #fff;
      text-decoration: underline;
    }
    .message ul, .message ol {
      margin-left: 20px;
      margin-top: 4px;
      margin-bottom: 4px;
    }
    
    /* Dark Mode overrides */
    [data-theme="dark"] .constchat-container {
      background-color: var(--widget-bg-dark, #2d3748);
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .constchat-tabs {
      background-color: #1a202c;
      border-bottom-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .constchat-tab.active {
      background-color: var(--widget-bg-dark, #2d3748);
    }
    [data-theme="dark"] .message.bot {
      background-color: #1a202c;
      color: #e2e8f0;
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .chat-input {
      background-color: #1a202c;
      color: #e2e8f0;
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .chat-input-area {
      border-top-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .submission-card {
      background-color: #1a202c;
      border-color: var(--border-dark, #4a5568);
    }
    [data-theme="dark"] .submission-response {
      background-color: var(--widget-bg-dark, #2d3748);
    }
    [data-theme="dark"] .type-question { background-color: #2b6cb0; color: #ebf8ff; }
    [data-theme="dark"] .type-place { background-color: #2f855a; color: #f0fff4; }
    [data-theme="dark"] .type-suggestion { background-color: #6b46c1; color: #faf5ff; }
  </style>
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
              <i class="fas fa-comments"></i>
            </div>
            <div class="breadcrumb-item active">Constchat</div>
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
        <div class="constchat-container">
          <div class="constchat-tabs">
            <div class="constchat-tab active" onclick="switchTab('chatTab', this)">
              <i class="fas fa-robot"></i> Constchat AI
            </div>
            <div class="constchat-tab" onclick="switchTab('submitTab', this)">
              <i class="fas fa-plus-circle"></i> Add Q/Place/Feedback
            </div>
            <div class="constchat-tab" onclick="switchTab('listTab', this)">
              <i class="fas fa-history"></i> My Submissions
            </div>
          </div>
          
          <!-- TAB 1: Chat View -->
          <div id="chatTab" class="constchat-content active">
            <div class="chat-messages" id="chatMessages">
              <div class="message bot">
                Hello <strong><?php echo htmlspecialchars($current['full_name']); ?></strong>! Welcome to <strong>Constchat</strong>. I am your automated Constechzhub agent assistant.<br><br>
                Ask me about MTN Business, AT Business, Telecel Business, result checkers, or custom pricing. You can also recommend new store places or submit suggestions. What would you like to know?
              </div>
            </div>
            
            <div class="typing-indicator" id="typingIndicator">
              <div class="typing-dot"></div>
              <div class="typing-dot"></div>
              <div class="typing-dot"></div>
            </div>
            
            <div style="margin-top: 12px;">
              <div class="chat-suggestions" id="chatSuggestions">
                <div class="suggestion-pill" onclick="sendSuggestion('What is Agent Store?')">What is Agent Store?</div>
                <div class="suggestion-pill" onclick="sendSuggestion('How to fund wallet?')">How to fund wallet?</div>
                <div class="suggestion-pill" onclick="sendSuggestion('Office locations')">Office Locations</div>
                <div class="suggestion-pill" onclick="sendSuggestion('What is Result Checker?')">Result Checkers</div>
              </div>
              
              <div class="chat-input-area">
                <input type="text" class="chat-input" id="chatInput" placeholder="Type your message or question here..." onkeypress="handleKeyPress(event)">
                <button class="btn btn-primary" onclick="sendMessage()"><i class="fas fa-paper-plane"></i> Send</button>
              </div>
            </div>
          </div>
          
          <!-- TAB 2: Submit Form -->
          <div id="submitTab" class="constchat-content">
            <div class="widget-header" style="padding:0; margin-bottom:16px;">
              <h3 class="widget-title">Submit a Question, Place, or Suggestion</h3>
              <p class="text-muted" style="font-size:0.9rem;">Your submission will be saved in our system and reviewed by an admin or agent.</p>
            </div>
            
            <form id="submissionForm" onsubmit="submitItem(event)">
              <div class="form-group">
                <label class="form-label" for="submitType">Submission Type</label>
                <select class="form-control" id="submitType" onchange="adjustFormLabels()">
                  <option value="question">Custom Question (Ask Admin)</option>
                  <option value="place">Place / Store Location Recommendation</option>
                  <option value="suggestion">General System Suggestion</option>
                </select>
              </div>
              
              <div class="form-group">
                <label class="form-label" id="labelTitle" for="submitTitle">Subject / Place Name</label>
                <input type="text" class="form-control" id="submitTitle" placeholder="e.g. MTN bundle question or Accra New Town agent store" required>
              </div>
              
              <div class="form-group">
                <label class="form-label" id="labelContent" for="submitContent">Details</label>
                <textarea class="form-control" id="submitContent" rows="6" placeholder="Describe your question, details about the store place, or your ideas for system improvements..." required></textarea>
              </div>
              
              <button type="submit" class="btn btn-primary" id="submitBtn"><i class="fas fa-check-circle"></i> Submit Details</button>
            </form>
          </div>
          
          <!-- TAB 3: Submissions History -->
          <div id="listTab" class="constchat-content">
            <div class="widget-header" style="padding:0; margin-bottom:16px;">
              <h3 class="widget-title">My Submissions & Feedback</h3>
            </div>
            
            <div class="submissions-list" id="submissionsList">
              <div class="text-muted" style="text-align:center; padding: 20px;">Loading your submissions...</div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
  
  <script>
    // Tab switching logic
    function switchTab(tabId, el) {
      document.querySelectorAll('.constchat-content').forEach(c => c.classList.remove('active'));
      document.querySelectorAll('.constchat-tab').forEach(t => t.classList.remove('active'));
      
      document.getElementById(tabId).classList.add('active');
      el.classList.add('active');
      
      if (tabId === 'listTab') {
        loadSubmissions();
      }
    }
    
    // Label adjustments based on type
    function adjustFormLabels() {
      const type = document.getElementById('submitType').value;
      const labelTitle = document.getElementById('labelTitle');
      const labelContent = document.getElementById('labelContent');
      const inputTitle = document.getElementById('submitTitle');
      const textContent = document.getElementById('submitContent');
      
      if (type === 'question') {
        labelTitle.textContent = "Subject";
        inputTitle.placeholder = "e.g. API access question";
        labelContent.textContent = "Your Question / Message";
        textContent.placeholder = "Describe your question in detail...";
      } else if (type === 'place') {
        labelTitle.textContent = "Agent Store / Place Name";
        inputTitle.placeholder = "e.g. Constechz Kumasi Hub";
        labelContent.textContent = "Location Details / Description";
        textContent.placeholder = "Provide location address, contact phone, operating hours, and agent details...";
      } else {
        labelTitle.textContent = "Suggestion Title";
        inputTitle.placeholder = "e.g. Dynamic SMS notifications custom sender ID";
        labelContent.textContent = "Suggestion Details";
        textContent.placeholder = "Explain your feature suggestion and how it would improve Constechzhub...";
      }
    }
    
    // Chat logic
    function handleKeyPress(e) {
      if (e.key === 'Enter') {
        sendMessage();
      }
    }
    
    function appendMessage(sender, text) {
      const chatMessages = document.getElementById('chatMessages');
      const msgDiv = document.createElement('div');
      msgDiv.className = `message ${sender}`;
      
      // Parse markdown-style syntax: bold (**), links ([text](url)), newlines (\n)
      let formattedText = text
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank">$1</a>')
        .replace(/\n/g, '<br>');
        
      msgDiv.innerHTML = formattedText;
      chatMessages.appendChild(msgDiv);
      chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    function sendSuggestion(text) {
      document.getElementById('chatInput').value = text;
      sendMessage();
    }
    
    async function sendMessage() {
      const input = document.getElementById('chatInput');
      const question = input.value.trim();
      if (!question) return;
      
      appendMessage('user', question);
      input.value = '';
      
      const indicator = document.getElementById('typingIndicator');
      indicator.style.display = 'flex';
      
      try {
        const res = await fetch('../api/constchat.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
          },
          body: JSON.stringify({
            action: 'ask_question',
            question: question
          })
        });
        
        const data = await res.json();
        indicator.style.display = 'none';
        
        if (data.status === 'success') {
          appendMessage('bot', data.answer);
          if (data.auto_saved) {
            showNotification('Unanswered question auto-saved to Submissions', 'info');
          }
        } else {
          appendMessage('bot', 'Sorry, I encountered an error. Please try again.');
        }
      } catch (err) {
        indicator.style.display = 'none';
        appendMessage('bot', 'Network error. Please make sure you are connected.');
      }
    }
    
    // Submit custom place/question
    async function submitItem(e) {
      e.preventDefault();
      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Submitting...';
      
      const type = document.getElementById('submitType').value;
      const title = document.getElementById('submitTitle').value.trim();
      const content = document.getElementById('submitContent').value.trim();
      
      try {
        const res = await fetch('../api/constchat.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo htmlspecialchars($csrf); ?>'
          },
          body: JSON.stringify({
            action: 'submit_item',
            type: type,
            title: title,
            content: content
          })
        });
        
        const data = await res.json();
        if (data.status === 'success') {
          showNotification(data.message, 'success');
          document.getElementById('submissionForm').reset();
          adjustFormLabels();
          // Switch to submissions list
          switchTab('listTab', document.querySelectorAll('.constchat-tab')[2]);
        } else {
          showNotification(data.message || 'Submission failed', 'error');
        }
      } catch(err) {
        showNotification('Network error occurred', 'error');
      }
      
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check-circle"></i> Submit Details';
    }
    
    // Load submissions
    async function loadSubmissions() {
      const container = document.getElementById('submissionsList');
      container.innerHTML = '<div class="text-muted" style="text-align:center; padding: 20px;">Loading your submissions...</div>';
      
      try {
        const res = await fetch('../api/constchat.php?action=list_my_submissions');
        const data = await res.json();
        
        if (data.status !== 'success') {
          container.innerHTML = '<div class="alert alert-danger">Failed to load submissions</div>';
          return;
        }
        
        if (data.submissions.length === 0) {
          container.innerHTML = '<div class="text-muted" style="text-align:center; padding: 20px;">You have not made any custom questions or place suggestions yet.</div>';
          return;
        }
        
        container.innerHTML = data.submissions.map(s => {
          let typeLabel = s.type === 'question' ? 'Question' : (s.type === 'place' ? 'Place/Store' : 'Suggestion');
          let statusLabel = s.status === 'pending' ? 'Pending' : (s.status === 'reviewed' ? 'Reviewed' : 'Answered/Replied');
          
          return `
            <div class="submission-card">
              <div class="submission-header">
                <div>
                  <span class="submission-type type-${s.type}">${typeLabel}</span>
                  <span class="submission-date" style="margin-left:10px;">${new Date(s.created_at).toLocaleString()}</span>
                </div>
                <span class="submission-status status-${s.status}">${statusLabel}</span>
              </div>
              <h4 style="margin: 8px 0; font-weight:600; color:var(--text-color);">${escapeHtml(s.title)}</h4>
              <p class="submission-body">${escapeHtml(s.content).replace(/\n/g, '<br>')}</p>
              ${s.response ? `
                <div class="submission-response">
                  <strong>Response:</strong>
                  <p style="margin-top: 4px; line-height:1.5;">${escapeHtml(s.response).replace(/\n/g, '<br>')}</p>
                </div>
              ` : ''}
            </div>
          `;
        }).join('');
      } catch (err) {
        container.innerHTML = '<div class="alert alert-danger">Network error loading submissions</div>';
      }
    }
    
    function escapeHtml(text) {
      const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      };
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

    document.addEventListener('DOMContentLoaded', initTheme);
  </script>
  <!-- IMMEDIATE Icon Fix for square placeholder issues -->
  <script src="../immediate_icon_fix.js"></script>
</body>
</html>
