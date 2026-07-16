<?php
/**
 * Constchat Floating Widget Component for Constechzhub
 */

if (!function_exists('dbh_render_constchat_markup')) {
    function dbh_render_constchat_markup() {
        $csrf = generateCSRF();
        $current = getCurrentUser();
        $fullName = htmlspecialchars($current['full_name'] ?? 'User');
        $siteUrl = SITE_URL;
        
        $role = $current['role'] ?? '';
        if ($role === 'agent' || $role === 'vip') {
            $welcomeMessage = "Hi <strong>{$fullName}</strong>! Welcome to <strong>Constchat</strong>.<br><br>As an Agent, you can ask questions or place data bundle orders directly here by typing <strong>buy data</strong>, or fund your wallet by typing <strong>fund wallet</strong>. You can also check status by typing <strong>status [order reference]</strong> or <strong>my last order</strong>.";
            $suggestionsHtml = '
                <span onclick="constchatWSuggest(\'buy data\')">Place Order</span>
                <span onclick="constchatWSuggest(\'fund wallet\')">Fund Wallet</span>
                <span onclick="constchatWSuggest(\'my last order\')">Last Order Status</span>
                <span onclick="constchatWSuggest(\'List stores\')">Stores</span>
            ';
        } else {
            $welcomeMessage = "Hi <strong>{$fullName}</strong>! Welcome to <strong>Constchat</strong>.<br><br>How can I help you today? Ask me about data bundles, wallet funding, or stores.";
            $suggestionsHtml = '
                <span onclick="constchatWSuggest(\'How to buy data?\')">Buy Data</span>
                <span onclick="constchatWSuggest(\'How to fund wallet?\')">Fund Wallet</span>
                <span onclick="constchatWSuggest(\'List stores\')">Stores</span>
            ';
        }
        
        return <<<HTML
        <!-- Constchat Widget Button -->
        <div id="constchat-widget-btn" onclick="constchatToggleBox()" title="Chat with Constchat">
            <i class="fas fa-comments"></i>
            <span class="constchat-badge"></span>
        </div>

        <!-- Constchat Widget Box -->
        <div id="constchat-widget-box">
            <div class="constchat-widget-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div class="constchat-widget-avatar"><i class="fas fa-robot"></i></div>
                    <div>
                        <div style="font-weight:600; font-size:0.9rem; line-height:1.2;">Constchat</div>
                        <div style="font-size:0.7rem; opacity:0.8;">Online Helper</div>
                    </div>
                </div>
                <div style="display:flex; gap:10px; font-size:1.1rem;">
                    <span onclick="constchatToggleBox()" style="cursor:pointer;" title="Minimize"><i class="fas fa-minus"></i></span>
                </div>
            </div>
            
            <div class="constchat-widget-tabs">
                <div class="constchat-w-tab active" onclick="constchatSwitchTab('w-chat', this)">Chat</div>
                <div class="constchat-w-tab" onclick="constchatSwitchTab('w-submit', this)">Add Q/Place</div>
            </div>

            <!-- TAB 1: Chat -->
            <div id="constchat-w-chat" class="constchat-widget-content active">
                <div class="constchat-w-messages" id="constchat-w-messages">
                    <div class="constchat-w-msg bot">
                        {$welcomeMessage}
                    </div>
                </div>
                <div class="constchat-w-typing" id="constchat-w-typing">
                    <div class="constchat-w-dot"></div>
                    <div class="constchat-w-dot"></div>
                    <div class="constchat-w-dot"></div>
                </div>
                <div class="constchat-w-suggestions">
                    {$suggestionsHtml}
                </div>
                <div class="constchat-w-input-area">
                    <input type="text" id="constchat-w-input" placeholder="Ask a question..." onkeypress="constchatWKeyPress(event)">
                    <button onclick="constchatWSend()"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>

            <!-- TAB 2: Submit Q/Place -->
            <div id="constchat-w-submit" class="constchat-widget-content">
                <form id="constchat-w-form" onsubmit="constchatWSubmitForm(event)" style="display:flex; flex-direction:column; gap:8px; height:100%;">
                    <div class="constchat-w-form-group">
                        <label style="margin-bottom: 2px;">Type</label>
                        <select id="constchat-w-type" onchange="constchatWAdjustLabels()">
                            <option value="question">Question</option>
                            <option value="place">Place / Store</option>
                            <option value="suggestion">Suggestion</option>
                        </select>
                    </div>
                    <div class="constchat-w-form-group">
                        <label id="constchat-w-lbl-title" style="margin-bottom: 2px;">Subject</label>
                        <input type="text" id="constchat-w-title" placeholder="e.g. MTN bundle issue" required>
                    </div>
                    <div class="constchat-w-form-group">
                        <label id="constchat-w-lbl-content" style="margin-bottom: 2px;">Details</label>
                        <textarea id="constchat-w-content" rows="4" placeholder="Enter details..." style="resize:none;" required></textarea>
                    </div>
                    <button type="submit" class="constchat-w-btn-submit">Submit Details</button>
                </form>
            </div>


        </div>

        <!-- Constchat Widget Styles -->
        <style>
            #constchat-widget-btn {
                position: fixed;
                bottom: 24px;
                right: 24px;
                width: 56px;
                height: 56px;
                border-radius: 50%;
                background: linear-gradient(135deg, #E63B2C, #8B5CF6);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 22px;
                cursor: pointer;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
                z-index: 99999;
                transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                animation: constchatPulse 2s infinite;
            }
            #constchat-widget-btn:hover {
                transform: scale(1.08) rotate(5deg);
            }
            @keyframes constchatPulse {
                0% { box-shadow: 0 0 0 0 rgba(230, 59, 44, 0.4); }
                70% { box-shadow: 0 0 0 12px rgba(230, 59, 44, 0); }
                100% { box-shadow: 0 0 0 0 rgba(230, 59, 44, 0); }
            }
            .constchat-badge {
                position: absolute;
                top: 2px;
                right: 2px;
                width: 10px;
                height: 10px;
                background-color: #73ED3F;
                border-radius: 50%;
                border: 2px solid white;
            }
            #constchat-widget-box {
                position: fixed;
                bottom: 90px;
                right: 24px;
                width: 340px;
                height: 450px;
                border-radius: 12px;
                background-color: var(--widget-bg, #ffffff);
                border: 1px solid var(--border-color, #e2e8f0);
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
                z-index: 99999;
                display: none;
                flex-direction: column;
                overflow: hidden;
                font-family: inherit;
                animation: constchatWSlide 0.25s ease-out;
            }
            @keyframes constchatWSlide {
                from { opacity: 0; transform: translateY(30px) scale(0.95); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            .constchat-widget-header {
                background: linear-gradient(135deg, #E63B2C, #8B5CF6);
                color: white;
                padding: 10px 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .constchat-widget-avatar {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background-color: rgba(255, 255, 255, 0.2);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
            }
            .constchat-widget-tabs {
                display: flex;
                background-color: var(--bg-color, #f7fafc);
                border-bottom: 1px solid var(--border-color, #e2e8f0);
                font-size: 0.8rem;
            }
            .constchat-w-tab {
                flex: 1;
                padding: 8px;
                text-align: center;
                cursor: pointer;
                color: var(--text-muted, #718096);
                font-weight: 600;
                transition: all 0.2s;
            }
            .constchat-w-tab.active {
                color: var(--primary-color, #E63B2C);
                background-color: var(--widget-bg, #ffffff);
                border-bottom: 2px solid var(--primary-color, #E63B2C);
            }
            .constchat-widget-content {
                flex: 1;
                display: none;
                flex-direction: column;
                overflow: hidden;
                background-color: var(--widget-bg, #ffffff);
            }
            .constchat-widget-content.active {
                display: flex;
            }
            /* Chat view inside widget */
            .constchat-w-messages {
                flex: 1;
                overflow-y: auto;
                padding: 12px;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .constchat-w-msg {
                max-width: 80%;
                padding: 8px 12px;
                border-radius: 10px;
                font-size: 0.82rem;
                line-height: 1.4;
                word-wrap: break-word;
            }
            .constchat-w-msg.bot {
                align-self: flex-start;
                background-color: var(--bg-color, #f1f5f9);
                color: var(--text-color, #1a202c);
                border-bottom-left-radius: 1px;
                border: 1px solid var(--border-color, #e2e8f0);
            }
            .constchat-w-msg.user {
                align-self: flex-end;
                background: linear-gradient(135deg, #E63B2C, #8B5CF6);
                color: white;
                border-bottom-right-radius: 1px;
            }
            .constchat-w-msg a {
                color: #3182ce;
                text-decoration: underline;
            }
            .constchat-w-msg.user a {
                color: white;
            }
            .constchat-w-typing {
                display: none;
                align-self: flex-start;
                background-color: var(--bg-color, #f1f5f9);
                border: 1px solid var(--border-color, #e2e8f0);
                padding: 8px 12px;
                border-radius: 10px;
                border-bottom-left-radius: 1px;
                gap: 3px;
                margin-left: 12px;
                margin-bottom: 6px;
            }
            .constchat-w-dot {
                width: 4px;
                height: 4px;
                background-color: #718096;
                border-radius: 50%;
                animation: constchatBounce 1.4s infinite ease-in-out both;
            }
            .constchat-w-dot:nth-child(1) { animation-delay: -0.32s; }
            .constchat-w-dot:nth-child(2) { animation-delay: -0.16s; }
            @keyframes constchatBounce {
                0%, 80%, 100% { transform: scale(0); }
                40% { transform: scale(1); }
            }
            .constchat-w-suggestions {
                display: flex;
                gap: 6px;
                padding: 0 12px 6px;
                overflow-x: auto;
                white-space: nowrap;
            }
            .constchat-w-suggestions span {
                background-color: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 0.75rem;
                cursor: pointer;
                font-weight: 600;
                transition: all 0.2s ease;
            }
            .constchat-w-suggestions span:hover {
                background-color: var(--primary-color, #E63B2C);
                color: white;
                border-color: var(--primary-color, #E63B2C);
            }
            .constchat-w-input-area {
                display: flex;
                border-top: 1px solid var(--border-color, #e2e8f0);
                padding: 8px;
                gap: 6px;
            }
            .constchat-w-input-area input {
                flex: 1;
                border: 1px solid var(--border-color, #e2e8f0);
                border-radius: 6px;
                padding: 6px 10px;
                font-size: 0.82rem;
                background-color: var(--bg-color, #ffffff);
                color: var(--text-color, #1a202c);
                outline: none;
            }
            .constchat-w-input-area input::placeholder {
                color: #94a3b8;
                opacity: 0.85;
            }
            .constchat-w-input-area button {
                background-color: var(--primary-color, #E63B2C);
                border: none;
                color: white;
                padding: 6px 12px;
                border-radius: 6px;
                cursor: pointer;
            }
            /* Form View */
            .constchat-w-form-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
                padding: 0 12px;
            }
            .constchat-w-form-group label {
                font-size: 0.75rem;
                font-weight: 600;
                color: var(--text-color, #333);
            }
            .constchat-w-form-group input, .constchat-w-form-group select, .constchat-w-form-group textarea {
                border: 1px solid var(--border-color, #e2e8f0);
                border-radius: 4px;
                padding: 6px;
                font-size: 0.8rem;
                background-color: var(--bg-color, #ffffff);
                color: var(--text-color);
                outline: none;
            }
            .constchat-w-btn-submit {
                margin: 8px 12px 12px;
                background-color: var(--primary-color, #E63B2C);
                color: white;
                border: none;
                padding: 8px;
                border-radius: 4px;
                font-weight: 600;
                cursor: pointer;
                font-size: 0.8rem;
            }
            /* History View */
            .constchat-w-history {
                flex: 1;
                overflow-y: auto;
                padding: 12px;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .constchat-w-item {
                border: 1px solid var(--border-color, #e2e8f0);
                background-color: var(--bg-color, #f8fafc);
                border-radius: 6px;
                padding: 8px;
                font-size: 0.78rem;
            }
            .constchat-w-item-header {
                display: flex;
                justify-content: space-between;
                font-size: 0.7rem;
                margin-bottom: 4px;
            }
            .constchat-w-type {
                font-weight: bold;
                text-transform: uppercase;
            }
            .constchat-w-status {
                font-weight: 600;
            }
            .constchat-w-response {
                margin-top: 6px;
                padding: 6px;
                background-color: var(--widget-bg, #ffffff);
                border-left: 2px solid var(--primary-color, #E63B2C);
                font-size: 0.75rem;
            }
            /* Dark mode overrides */
            [data-theme="dark"] #constchat-widget-box {
                background-color: #1e293b;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-widget-tabs {
                background-color: #0f172a;
                border-bottom-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-tab.active {
                background-color: #1e293b;
                color: var(--primary-color, #E63B2C);
                border-bottom-color: var(--primary-color, #E63B2C);
            }
            [data-theme="dark"] .constchat-widget-content {
                background-color: #1e293b;
            }
            [data-theme="dark"] .constchat-w-msg.bot {
                background-color: #0f172a;
                color: #e2e8f0;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-typing {
                background-color: #0f172a;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-suggestions span {
                background-color: #0f172a;
                color: #e2e8f0;
                border-color: rgba(255, 255, 255, 0.15);
            }
            [data-theme="dark"] .constchat-w-suggestions span:hover {
                background-color: var(--primary-color, #E63B2C);
                color: white;
                border-color: var(--primary-color, #E63B2C);
            }
            [data-theme="dark"] .constchat-w-input-area {
                border-top-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-input-area input {
                background-color: #0f172a;
                color: #f1f5f9;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-input-area input::placeholder {
                color: #64748b;
            }
            [data-theme="dark"] .constchat-w-form-group label {
                color: #e2e8f0;
            }
            [data-theme="dark"] .constchat-w-form-group input, 
            [data-theme="dark"] .constchat-w-form-group select, 
            [data-theme="dark"] .constchat-w-form-group textarea {
                background-color: #0f172a;
                color: #f1f5f9;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-item {
                background-color: #0f172a;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-response {
                background-color: #1e293b;
            }
        </style>

        <!-- Constchat Widget Scripts -->
        <script>
            function constchatToggleBox() {
                var box = document.getElementById('constchat-widget-box');
                if (box.style.display === 'flex') {
                    box.style.display = 'none';
                } else {
                    box.style.display = 'flex';
                    // Scroll to bottom of messages
                    var msgs = document.getElementById('constchat-w-messages');
                    msgs.scrollTop = msgs.scrollHeight;
                }
            }

            function constchatSwitchTab(tabId, el) {
                var contents = document.querySelectorAll('.constchat-widget-content');
                contents.forEach(function(c) { c.classList.remove('active'); });
                
                var tabs = document.querySelectorAll('.constchat-w-tab');
                tabs.forEach(function(t) { t.classList.remove('active'); });
                
                document.getElementById('constchat-' + tabId).classList.add('active');
                el.classList.add('active');
            }

            function constchatWAdjustLabels() {
                var type = document.getElementById('constchat-w-type').value;
                var lblTitle = document.getElementById('constchat-w-lbl-title');
                var lblContent = document.getElementById('constchat-w-lbl-content');
                var txtTitle = document.getElementById('constchat-w-title');
                var txtContent = document.getElementById('constchat-w-content');
                
                if (type === 'question') {
                    lblTitle.textContent = "Subject";
                    txtTitle.placeholder = "e.g. Wallet issue";
                    lblContent.textContent = "Your Question";
                    txtContent.placeholder = "Describe your question...";
                } else if (type === 'place') {
                    lblTitle.textContent = "Place/Store Name";
                    txtTitle.placeholder = "e.g. Accra Mall Store";
                    lblContent.textContent = "Location Details";
                    txtContent.placeholder = "Describe location, coordinates, contact...";
                } else {
                    lblTitle.textContent = "Suggestion Title";
                    txtTitle.placeholder = "e.g. Add Bitcoin payment";
                    lblContent.textContent = "Details";
                    txtContent.placeholder = "Explain your suggestion...";
                }
            }

            function constchatWKeyPress(e) {
                if (e.key === 'Enter') {
                    constchatWSend();
                }
            }

            function constchatWSuggest(text) {
                document.getElementById('constchat-w-input').value = text;
                constchatWSend();
            }

            function constchatWAppendMsg(sender, text) {
                var container = document.getElementById('constchat-w-messages');
                var msgDiv = document.createElement('div');
                msgDiv.className = 'constchat-w-msg ' + sender;
                
                var formatted = text
                    .replace(/\*\*(.*?)\*\*/g, '<strong>\$1</strong>')
                    .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="\$2" target="_blank">\$1</a>')
                    .replace(/\\n/g, '<br>');
                    
                msgDiv.innerHTML = formatted;
                container.appendChild(msgDiv);
                container.scrollTop = container.scrollHeight;
            }

            async function constchatWSend() {
                var input = document.getElementById('constchat-w-input');
                var question = input.value.trim();
                if (!question) return;
                
                constchatWAppendMsg('user', question);
                input.value = '';
                
                var typing = document.getElementById('constchat-w-typing');
                typing.style.display = 'flex';
                
                try {
                    var res = await fetch('{$siteUrl}/api/constchat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '{$csrf}'
                        },
                        body: JSON.stringify({
                            action: 'ask_question',
                            question: question
                        })
                    });
                    var data = await res.json();
                    typing.style.display = 'none';
                    
                    if (data.status === 'success') {
                        constchatWAppendMsg('bot', data.answer);
                    } else {
                        constchatWAppendMsg('bot', 'Sorry, I failed to process your question.');
                    }
                } catch (err) {
                    typing.style.display = 'none';
                    constchatWAppendMsg('bot', 'Network error. Please try again.');
                }
            }

            async function constchatWSubmitForm(e) {
                e.preventDefault();
                var btn = document.querySelector('.constchat-w-btn-submit');
                btn.disabled = true;
                btn.textContent = 'Submitting...';
                
                var type = document.getElementById('constchat-w-type').value;
                var title = document.getElementById('constchat-w-title').value.trim();
                var content = document.getElementById('constchat-w-content').value.trim();
                
                try {
                    var res = await fetch('{$siteUrl}/api/constchat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '{$csrf}'
                        },
                        body: JSON.stringify({
                            action: 'submit_item',
                            type: type,
                            title: title,
                            content: content
                        })
                    });
                    var data = await res.json();
                    btn.disabled = false;
                    btn.textContent = 'Submit Details';
                    
                    if (data.status === 'success') {
                        alert('Submission received successfully!');
                        document.getElementById('constchat-w-form').reset();
                        constchatWAdjustLabels();
                        constchatSwitchTab('w-chat', document.querySelectorAll('.constchat-w-tab')[0]);
                    } else {
                        alert(data.message || 'Submission failed');
                    }
                } catch(err) {
                    btn.disabled = false;
                    btn.textContent = 'Submit Details';
                    alert('Network error submitting details');
                }
            }

            function constchatWEscape(text) {
                if (!text) return '';
                var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
                return text.replace(/[&<>"']/g, function(m) { return map[m]; });
            }
        </script>
HTML;
    }
}

if (!function_exists('dbh_render_guest_constchat_markup')) {
    function dbh_render_guest_constchat_markup($store_slug) {
        global $db, $store;
        $store_slug_clean = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$store_slug);
        
        $store_name = 'Agent Store';
        
        // 1. Try to get store name from global $store variable
        if (isset($store) && is_array($store) && !empty($store['store_name'])) {
            $store_name = $store['store_name'];
        } 
        // 2. Try to get from session cache
        elseif (isset($_SESSION['store_cache'][$store_slug_clean]['data']['store_name'])) {
            $store_name = $_SESSION['store_cache'][$store_slug_clean]['data']['store_name'];
        } 
        // 3. Fallback to DB query, checking if connection is active first
        elseif (isset($db) && $db->getConnection() instanceof mysqli) {
            $db_active = false;
            try {
                if (@$db->getConnection()->ping()) {
                    $db_active = true;
                }
            } catch (Throwable $t) {
                $db_active = false;
            }
            
            if ($db_active) {
                $stmt = $db->prepare("
                    SELECT store_name 
                    FROM agent_stores 
                    WHERE store_slug = ? AND is_active = 1
                    LIMIT 1
                ");
                if ($stmt) {
                    $stmt->bind_param("s", $store_slug_clean);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        $res = $result->fetch_assoc();
                        if ($res) {
                            $store_name = $res['store_name'];
                        }
                    }
                    $stmt->close();
                }
            }
        }
        
        $csrf = generateCSRF();
        $siteUrl = SITE_URL;
        
        $welcomeMessage = "Hi! Welcome to <strong>" . htmlspecialchars($store_name) . "</strong> Constchat. I am your automated store assistant.<br><br>You can place a data bundle order by typing <strong>buy data</strong>, check order status by typing <strong>status [order reference]</strong>, or ask general questions.";
        
        $suggestionsHtml = '
            <span onclick="constchatWSuggest(\'buy data\')">Place Order</span>
            <span onclick="constchatWSuggest(\'status\')">Check Status</span>
        ';
        
        return <<<HTML
        <!-- Constchat Guest Widget Button -->
        <div id="constchat-widget-btn" onclick="constchatToggleBox()" title="Chat with Store Assistant">
            <i class="fas fa-comments"></i>
            <span class="constchat-badge"></span>
        </div>

        <!-- Constchat Guest Widget Box -->
        <div id="constchat-widget-box">
            <div class="constchat-widget-header">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div class="constchat-widget-avatar"><i class="fas fa-robot"></i></div>
                    <div>
                        <div style="font-weight:600; font-size:0.9rem; line-height:1.2;">Constchat</div>
                        <div style="font-size:0.7rem; opacity:0.8;">Store Helper</div>
                    </div>
                </div>
                <div style="display:flex; gap:10px; font-size:1.1rem;">
                    <span onclick="constchatToggleBox()" style="cursor:pointer;" title="Minimize"><i class="fas fa-minus"></i></span>
                </div>
            </div>
            
            <div class="constchat-widget-tabs">
                <div class="constchat-w-tab active" style="flex:1; text-align:center;">Chat</div>
            </div>

            <!-- TAB 1: Chat -->
            <div id="constchat-w-chat" class="constchat-widget-content active">
                <div class="constchat-w-messages" id="constchat-w-messages">
                    <div class="constchat-w-msg bot">
                        {$welcomeMessage}
                    </div>
                </div>
                <div class="constchat-w-typing" id="constchat-w-typing">
                    <div class="constchat-w-dot"></div>
                    <div class="constchat-w-dot"></div>
                    <div class="constchat-w-dot"></div>
                </div>
                <div class="constchat-w-suggestions">
                    {$suggestionsHtml}
                </div>
                <div class="constchat-w-input-area">
                    <input type="text" id="constchat-w-input" placeholder="Ask a question..." onkeypress="constchatWKeyPress(event)">
                    <button onclick="constchatWSend()"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </div>

        <!-- Constchat Widget Styles -->
        <style>
            #constchat-widget-btn {
                position: fixed;
                bottom: 24px;
                right: 24px;
                width: 56px;
                height: 56px;
                border-radius: 50%;
                background: linear-gradient(135deg, #E63B2C, #8B5CF6);
                color: white;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 22px;
                cursor: pointer;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
                z-index: 99999;
                transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                animation: constchatPulse 2s infinite;
            }
            #constchat-widget-btn:hover {
                transform: scale(1.08) rotate(5deg);
            }
            @keyframes constchatPulse {
                0% { box-shadow: 0 0 0 0 rgba(230, 59, 44, 0.4); }
                70% { box-shadow: 0 0 0 12px rgba(230, 59, 44, 0); }
                100% { box-shadow: 0 0 0 0 rgba(230, 59, 44, 0); }
            }
            .constchat-badge {
                position: absolute;
                top: 2px;
                right: 2px;
                width: 10px;
                height: 10px;
                background-color: #73ED3F;
                border-radius: 50%;
                border: 2px solid white;
            }
            #constchat-widget-box {
                position: fixed;
                bottom: 90px;
                right: 24px;
                width: 340px;
                height: 450px;
                border-radius: 12px;
                background-color: var(--widget-bg, #ffffff);
                border: 1px solid var(--border-color, #e2e8f0);
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
                z-index: 99999;
                display: none;
                flex-direction: column;
                overflow: hidden;
                font-family: inherit;
                animation: constchatWSlide 0.25s ease-out;
            }
            @keyframes constchatWSlide {
                from { opacity: 0; transform: translateY(30px) scale(0.95); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            .constchat-widget-header {
                background: linear-gradient(135deg, #E63B2C, #8B5CF6);
                color: white;
                padding: 10px 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .constchat-widget-avatar {
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background-color: rgba(255, 255, 255, 0.2);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
            }
            .constchat-widget-tabs {
                display: flex;
                background-color: var(--bg-color, #f7fafc);
                border-bottom: 1px solid var(--border-color, #e2e8f0);
                font-size: 0.8rem;
            }
            .constchat-w-tab {
                flex: 1;
                padding: 8px;
                text-align: center;
                color: var(--primary-color, #E63B2C);
                background-color: var(--widget-bg, #ffffff);
                border-bottom: 2px solid var(--primary-color, #E63B2C);
                font-weight: 600;
            }
            .constchat-widget-content {
                flex: 1;
                display: none;
                flex-direction: column;
                overflow: hidden;
                background-color: var(--widget-bg, #ffffff);
            }
            .constchat-widget-content.active {
                display: flex;
            }
            .constchat-w-messages {
                flex: 1;
                overflow-y: auto;
                padding: 12px;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .constchat-w-msg {
                max-width: 80%;
                padding: 8px 12px;
                border-radius: 10px;
                font-size: 0.82rem;
                line-height: 1.4;
                word-wrap: break-word;
            }
            .constchat-w-msg.bot {
                align-self: flex-start;
                background-color: var(--bg-color, #f1f5f9);
                color: var(--text-color, #1a202c);
                border-bottom-left-radius: 1px;
                border: 1px solid var(--border-color, #e2e8f0);
            }
            .constchat-w-msg.user {
                align-self: flex-end;
                background: linear-gradient(135deg, #E63B2C, #8B5CF6);
                color: white;
                border-bottom-right-radius: 1px;
            }
            .constchat-w-msg a {
                color: #3182ce;
                text-decoration: underline;
            }
            .constchat-w-msg.user a {
                color: white;
            }
            .constchat-w-typing {
                display: none;
                align-self: flex-start;
                background-color: var(--bg-color, #f1f5f9);
                border: 1px solid var(--border-color, #e2e8f0);
                padding: 8px 12px;
                border-radius: 10px;
                border-bottom-left-radius: 1px;
                gap: 3px;
                margin-left: 12px;
                margin-bottom: 6px;
            }
            .constchat-w-dot {
                width: 4px;
                height: 4px;
                background-color: #718096;
                border-radius: 50%;
                animation: constchatBounce 1.4s infinite ease-in-out both;
            }
            .constchat-w-dot:nth-child(1) { animation-delay: -0.32s; }
            .constchat-w-dot:nth-child(2) { animation-delay: -0.16s; }
            @keyframes constchatBounce {
                0%, 80%, 100% { transform: scale(0); }
                40% { transform: scale(1); }
            }
            .constchat-w-suggestions {
                display: flex;
                gap: 6px;
                padding: 0 12px 6px;
                overflow-x: auto;
                white-space: nowrap;
            }
            .constchat-w-suggestions span {
                background-color: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 0.75rem;
                cursor: pointer;
                font-weight: 600;
                transition: all 0.2s ease;
            }
            .constchat-w-suggestions span:hover {
                background-color: var(--primary-color, #E63B2C);
                color: white;
                border-color: var(--primary-color, #E63B2C);
            }
            .constchat-w-input-area {
                display: flex;
                border-top: 1px solid var(--border-color, #e2e8f0);
                padding: 8px;
                gap: 6px;
            }
            .constchat-w-input-area input {
                flex: 1;
                border: 1px solid var(--border-color, #e2e8f0);
                border-radius: 6px;
                padding: 6px 10px;
                font-size: 0.82rem;
                background-color: var(--bg-color, #ffffff);
                color: var(--text-color, #1a202c);
                outline: none;
            }
            .constchat-w-input-area input::placeholder {
                color: #94a3b8;
                opacity: 0.85;
            }
            .constchat-w-input-area button {
                background-color: var(--primary-color, #E63B2C);
                border: none;
                color: white;
                padding: 6px 12px;
                border-radius: 6px;
                cursor: pointer;
            }
            /* Dark mode overrides */
            [data-theme="dark"] #constchat-widget-box {
                background-color: #1e293b;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-widget-tabs {
                background-color: #0f172a;
                border-bottom-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-tab {
                background-color: #1e293b;
                color: var(--primary-color, #E63B2C);
                border-bottom-color: var(--primary-color, #E63B2C);
            }
            [data-theme="dark"] .constchat-widget-content {
                background-color: #1e293b;
            }
            [data-theme="dark"] .constchat-w-msg.bot {
                background-color: #0f172a;
                color: #e2e8f0;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-typing {
                background-color: #0f172a;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-suggestions span {
                background-color: #0f172a;
                color: #e2e8f0;
                border-color: rgba(255, 255, 255, 0.15);
            }
            [data-theme="dark"] .constchat-w-suggestions span:hover {
                background-color: var(--primary-color, #E63B2C);
                color: white;
                border-color: var(--primary-color, #E63B2C);
            }
            [data-theme="dark"] .constchat-w-input-area {
                border-top-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-input-area input {
                background-color: #0f172a;
                color: #f1f5f9;
                border-color: rgba(255, 255, 255, 0.1);
            }
            [data-theme="dark"] .constchat-w-input-area input::placeholder {
                color: #64748b;
            }
        </style>

        <!-- Constchat Widget Scripts -->
        <script>
            function constchatToggleBox() {
                var box = document.getElementById('constchat-widget-box');
                if (box.style.display === 'flex') {
                    box.style.display = 'none';
                } else {
                    box.style.display = 'flex';
                    var msgs = document.getElementById('constchat-w-messages');
                    msgs.scrollTop = msgs.scrollHeight;
                }
            }

            function constchatWKeyPress(e) {
                if (e.key === 'Enter') {
                    constchatWSend();
                }
            }

            function constchatWSuggest(text) {
                document.getElementById('constchat-w-input').value = text;
                constchatWSend();
            }

            function constchatWAppendMsg(sender, text) {
                var container = document.getElementById('constchat-w-messages');
                var msgDiv = document.createElement('div');
                msgDiv.className = 'constchat-w-msg ' + sender;
                
                var formatted = text
                    .replace(/\*\*(.*?)\*\*/g, '<strong>\$1</strong>')
                    .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="\$2" target="_blank">\$1</a>')
                    .replace(/\\n/g, '<br>');
                    
                msgDiv.innerHTML = formatted;
                container.appendChild(msgDiv);
                container.scrollTop = container.scrollHeight;
            }

            async function constchatWSend() {
                var input = document.getElementById('constchat-w-input');
                var question = input.value.trim();
                if (!question) return;
                
                constchatWAppendMsg('user', question);
                input.value = '';
                
                var typing = document.getElementById('constchat-w-typing');
                typing.style.display = 'flex';
                
                try {
                    var res = await fetch('{$siteUrl}/api/constchat.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': '{$csrf}'
                        },
                        body: JSON.stringify({
                            action: 'guest_ask_question',
                            question: question,
                            store_slug: '{$store_slug_clean}'
                        })
                    });
                    var data = await res.json();
                    typing.style.display = 'none';
                    
                    if (data.status === 'success') {
                        constchatWAppendMsg('bot', data.answer);
                    } else {
                        constchatWAppendMsg('bot', 'Sorry, I failed to process your question.');
                    }
                } catch (err) {
                    typing.style.display = 'none';
                    constchatWAppendMsg('bot', 'Network error. Please try again.');
                }
            }
        </script>
HTML;
    }
}
