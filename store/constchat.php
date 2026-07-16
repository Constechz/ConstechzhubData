<?php
require_once __DIR__ . '/../config/config.php';

// Prevent browser caching for real-time updates
preventBrowserCaching();
ensureDataPackageStockStatusColumn();

if (getSetting('enable_agent_stores', '1') === '0') {
    require_once __DIR__ . '/store-offline.php';
    exit();
}

$store_slug = $_GET['store'] ?? $_POST['store'] ?? '';
if (empty($store_slug)) {
    header('HTTP/1.0 404 Not Found');
    include '../404.php';
    exit();
}

// Fetch store + agent info for branding
$store = null;
if (isset($_SESSION['store_cache'][$store_slug])) {
    $cached = $_SESSION['store_cache'][$store_slug];
    if (is_array($cached) && !empty($cached['data']) && !empty($cached['ts']) && (time() - (int) $cached['ts']) < 300) {
        $store = $cached['data'];
    }
}

if (!$store) {
    $stmt = $db->prepare("
        SELECT ast.store_name, ast.store_slug, ast.agent_id, u.full_name AS agent_name, u.email AS agent_email
        FROM agent_stores ast
        JOIN users u ON ast.agent_id = u.id
        WHERE ast.store_slug = ? AND ast.is_active = TRUE AND COALESCE(ast.admin_active, 1) = 1 AND u.status = 'active'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $store_slug);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $store = $res->fetch_assoc();
            $_SESSION['store_cache'][$store_slug] = [
                'data' => $store,
                'ts' => time()
            ];
        }
        $stmt->close();
    }
    
    if (!$store) {
        header('HTTP/1.0 404 Not Found');
        include '../404.php';
        exit();
    }
}

$csrf = generateCSRF();
$page_title = 'Constchat AI';

// Load store header
require_once __DIR__ . '/includes/header.php';
?>
<style>
    body {
        background: radial-gradient(circle at top left, rgba(230, 59, 44, 0.08), transparent 30%), #eff4fb;
    }
    .checkout-shell {
        max-width: 680px;
        margin: 0 auto;
        padding: 1.5rem 0.85rem 2.5rem;
    }
    .checkout-back-link {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        margin-bottom: 0.9rem;
        color: #475569;
        font-size: 0.92rem;
        font-weight: 600;
        text-decoration: none;
    }
    .checkout-back-link:hover {
        color: var(--primary-color, #E63B2C);
    }
    .checkout-card {
        background: #ffffff;
        border: 1px solid rgba(148, 163, 184, 0.15);
        border-radius: 24px;
        box-shadow: 0 18px 36px rgba(15, 23, 42, 0.06);
    }
    
    /* Message styling */
    .message {
      max-width: 80%;
      padding: 12px 16px;
      border-radius: 16px;
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
      background-color: #f1f5f9;
      color: #0f172a;
      border-bottom-left-radius: 2px;
      border: 1px solid rgba(148, 163, 184, 0.15);
    }
    
    .message.user {
      align-self: flex-end;
      background: linear-gradient(135deg, var(--primary-color, #E63B2C), var(--brand-color, #8B5CF6));
      color: white;
      border-bottom-right-radius: 2px;
    }
    
    .suggestion-pill {
      background-color: #f1f5f9;
      color: #334155;
      border: 1px solid #cbd5e1;
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 0.85rem;
      cursor: pointer;
      transition: all 0.2s ease;
      font-weight: 600;
    }
    
    .suggestion-pill:hover {
      background-color: var(--primary-color, #E63B2C);
      color: white;
      border-color: var(--primary-color, #E63B2C);
      transform: translateY(-1px);
    }
    
    .chat-input::placeholder {
      color: #94a3b8;
      opacity: 0.85;
    }
    
    .message a {
      color: #3182ce;
      text-decoration: underline;
      font-weight: 600;
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
    
    /* Dark Mode styling */
    [data-theme="dark"] .checkout-card {
        background: #0f172a;
        border-color: rgba(255, 255, 255, 0.1);
    }
    [data-theme="dark"] .message.bot {
      background-color: #1e293b;
      color: #f1f5f9;
      border-color: rgba(255, 255, 255, 0.1);
    }
    [data-theme="dark"] .chat-input {
      background-color: #1e293b !important;
      color: #f1f5f9 !important;
      border-color: rgba(255, 255, 255, 0.1) !important;
    }
    [data-theme="dark"] .chat-input::placeholder {
      color: #64748b;
    }
    [data-theme="dark"] .suggestion-pill {
      background-color: #1e293b;
      color: #f1f5f9;
      border-color: #475569;
    }
</style>

<div class="checkout-shell">
    <a href="index.php?store=<?php echo urlencode($store_slug); ?>" class="checkout-back-link">
        <i class="fas fa-arrow-left"></i> Back to Store
    </a>
    
    <div class="checkout-card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; height: 75vh;">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, var(--primary-color, #E63B2C), #8B5CF6); color: white; padding: 20px; display: flex; align-items: center; gap: 12px;">
            <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem;">
                <i class="fas fa-comments"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700;">Constchat AI</h3>
                <p style="margin: 4px 0 0; font-size: 0.85rem; opacity: 0.9;">Store Assistant for <?php echo htmlspecialchars($store['store_name']); ?></p>
            </div>
        </div>
        
        <!-- Chat Area -->
        <div style="flex: 1; display: flex; flex-direction: column; padding: 20px; overflow: hidden; background: var(--bg-color, #f8fafc);">
            <div class="chat-messages" id="chatMessages" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px; padding-right: 5px;">
                <div class="message bot">
                    Hello! Welcome to <strong><?php echo htmlspecialchars($store['store_name']); ?></strong> Constchat.<br><br>
                    I am your automated storefront assistant. You can buy data bundles directly inside the chat, check order statuses, or ask general questions.<br><br>
                    Type <strong>buy data</strong> to start ordering!
                </div>
            </div>
            
            <div class="typing-indicator" id="typingIndicator" style="display: none; align-self: flex-start; background-color: #f1f5f9; border: 1px solid #e2e8f0; padding: 10px 14px; border-radius: 12px; border-bottom-left-radius: 2px; gap: 4px; align-items: center; margin-bottom: 16px; height: 36px;">
              <div class="typing-dot" style="width: 6px; height: 6px; background-color: #718096; border-radius: 50%; animation: typingBounce 1.4s infinite ease-in-out both;"></div>
              <div class="typing-dot" style="width: 6px; height: 6px; background-color: #718096; border-radius: 50%; animation: typingBounce 1.4s infinite ease-in-out both; animation-delay: -0.16s;"></div>
              <div class="typing-dot" style="width: 6px; height: 6px; background-color: #718096; border-radius: 50%; animation: typingBounce 1.4s infinite ease-in-out both; animation-delay: -0.32s;"></div>
            </div>
            
            <div>
              <div class="chat-suggestions" id="chatSuggestions" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px;">
                <div class="suggestion-pill" onclick="sendSuggestion('buy data')">Buy Data</div>
                <div class="suggestion-pill" onclick="sendSuggestion('status')">Check Order Status</div>
                <div class="suggestion-pill" onclick="sendSuggestion('office locations')">Office Locations</div>
              </div>
              
              <div class="chat-input-area" style="display: flex; gap: 12px; padding-top: 16px; border-top: 1px solid rgba(148, 163, 184, 0.15);">
                <input type="text" class="chat-input" id="chatInput" placeholder="Type your message here..." onkeypress="handleKeyPress(event)" style="flex: 1; padding: 12px 16px; border: 1px solid rgba(148, 163, 184, 0.25); border-radius: 8px; outline: none; background: #fff; color: #1a202c; font-size: 0.95rem;">
                <button class="btn btn-primary" onclick="sendMessage()" style="padding: 12px 20px; font-weight: 600; display: flex; align-items: center; justify-content: center; border-radius: 8px;"><i class="fas fa-paper-plane"></i></button>
              </div>
            </div>
        </div>
    </div>
</div>

<script>
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
            action: 'guest_ask_question',
            question: question,
            store_slug: '<?php echo htmlspecialchars($store_slug); ?>'
          })
        });
        
        const data = await res.json();
        indicator.style.display = 'none';
        
        if (data.status === 'success') {
          appendMessage('bot', data.answer);
        } else {
          appendMessage('bot', 'Sorry, I encountered an error. Please try again.');
        }
      } catch (err) {
        indicator.style.display = 'none';
        appendMessage('bot', 'Network error. Please make sure you are connected.');
      }
    }
</script>

<?php
// Load store footer
require_once __DIR__ . '/includes/footer.php';
?>
