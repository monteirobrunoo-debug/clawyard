<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ClawYard — AI Assistant</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f0f0f;
            color: #e5e5e5;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 24px;
            border-bottom: 1px solid #1e1e1e;
            background: #111;
        }

        header .logo {
            font-size: 20px;
            font-weight: 700;
            color: #76b900;
            letter-spacing: -0.5px;
        }

        header .badge {
            font-size: 11px;
            background: #76b900;
            color: #000;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        header .model {
            margin-left: auto;
            font-size: 12px;
            color: #555;
        }

        #agent-select {
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            color: #e5e5e5;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 12px;
            cursor: pointer;
            outline: none;
        }

        #agent-select:focus { border-color: #76b900; }

        .icon-btn {
            width: 48px;
            height: 48px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
            font-size: 18px;
        }

        .icon-btn:hover { border-color: #76b900; }
        .icon-btn.active { background: #76b900; border-color: #76b900; }

        #image-preview {
            display: none;
            position: relative;
            padding: 8px 24px 0;
        }

        #image-preview img {
            height: 80px;
            border-radius: 8px;
            border: 1px solid #2a2a2a;
        }

        #remove-image {
            position: absolute;
            top: 4px;
            left: 88px;
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .recording { animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        #chat {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .message {
            display: flex;
            gap: 12px;
            max-width: 800px;
            width: 100%;
        }

        .message.user { align-self: flex-end; flex-direction: row-reverse; }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .message.user .avatar { background: #76b900; color: #000; }
        .message.ai .avatar { background: #1e1e1e; color: #76b900; }

        .bubble {
            padding: 12px 16px;
            border-radius: 16px;
            font-size: 14px;
            line-height: 1.6;
            max-width: calc(100% - 48px);
            white-space: pre-wrap;
        }

        .message.user .bubble {
            background: #76b900;
            color: #000;
            border-bottom-right-radius: 4px;
        }

        .message.ai .bubble {
            background: #1a1a1a;
            color: #e5e5e5;
            border-bottom-left-radius: 4px;
            border: 1px solid #2a2a2a;
        }

        .typing .bubble {
            display: flex;
            gap: 4px;
            align-items: center;
            padding: 16px;
        }

        .dot {
            width: 8px;
            height: 8px;
            background: #555;
            border-radius: 50%;
            animation: bounce 1.2s infinite;
        }

        .dot:nth-child(2) { animation-delay: 0.2s; }
        .dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes bounce {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-6px); }
        }

        #input-area {
            padding: 16px 24px;
            border-top: 1px solid #1e1e1e;
            background: #111;
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        #message-input {
            flex: 1;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 12px 16px;
            color: #e5e5e5;
            font-size: 14px;
            resize: none;
            outline: none;
            min-height: 48px;
            max-height: 160px;
            font-family: inherit;
            line-height: 1.5;
            transition: border-color 0.2s;
        }

        #message-input:focus { border-color: #76b900; }
        #message-input::placeholder { color: #444; }

        #send-btn {
            width: 48px;
            height: 48px;
            background: #76b900;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
            flex-shrink: 0;
        }

        #send-btn:hover { background: #8fd400; }
        #send-btn:disabled { background: #333; cursor: not-allowed; }

        #send-btn svg { width: 20px; height: 20px; }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .empty-state h2 { font-size: 28px; color: #76b900; font-weight: 700; }
        .empty-state p { font-size: 14px; color: #444; }

        /* Email Card */
        .email-card {
            background: #111; border: 1px solid #2a2a2a; border-radius: 16px;
            overflow: hidden; margin-top: 8px; width: 100%;
        }
        .email-card-header {
            background: #0f0f0f; padding: 14px 18px;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #1e1e1e;
        }
        .email-card-header span { font-size: 12px; font-weight: 700; color: #76b900; }
        .email-card-header small { font-size: 11px; color: #555; }
        .email-field { padding: 10px 18px; border-bottom: 1px solid #1a1a1a; display: flex; align-items: center; gap: 10px; }
        .email-field label { font-size: 11px; color: #555; min-width: 50px; font-weight: 600; text-transform: uppercase; }
        .email-field input {
            flex: 1; background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 8px;
            padding: 6px 10px; color: #e5e5e5; font-size: 13px; outline: none;
        }
        .email-field input:focus { border-color: #76b900; }
        .email-body-area {
            padding: 16px 18px; font-size: 13px; color: #ccc; line-height: 1.7;
            white-space: pre-wrap; max-height: 280px; overflow-y: auto;
            border-bottom: 1px solid #1a1a1a;
        }
        .email-body-area[contenteditable] { outline: none; }
        .email-body-area[contenteditable]:focus { background: #131313; }
        .email-actions {
            padding: 12px 18px; display: flex; gap: 10px; align-items: center;
        }
        .email-send-btn {
            background: #76b900; color: #000; border: none; padding: 10px 24px;
            border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; gap: 6px;
        }
        .email-send-btn:hover { background: #8fd400; }
        .email-send-btn:disabled { background: #333; color: #666; cursor: not-allowed; }
        .email-copy-btn {
            background: none; color: #555; border: 1px solid #2a2a2a;
            padding: 10px 18px; border-radius: 10px; font-size: 12px; cursor: pointer;
        }
        .email-copy-btn:hover { border-color: #555; color: #aaa; }
        .email-status { font-size: 12px; margin-left: auto; }
        .email-status.sent { color: #76b900; }
        .email-status.error { color: #ff4444; }
    </style>
</head>
<body>

<header>
    <a href="/dashboard" style="color:#555;text-decoration:none;font-size:20px;margin-right:4px;">←</a>
    <span class="logo">🐾 ClawYard</span>
    <span class="badge">NVIDIA NeMo</span>
    <select id="agent-select">
        <option value="orchestrator">🌐 All Agents</option>
        <option value="auto">🤖 Auto Route</option>
        <option value="sales">💼 Sales</option>
        <option value="support">🔧 Support</option>
        <option value="email">📧 Email</option>
        <option value="sap">📊 SAP</option>
        <option value="document">📄 Document</option>
        <option value="claude">🧠 Claude</option>
        <option value="nvidia">⚡ NVIDIA NeMo</option>
    </select>
    <span class="model" id="model-name">auto</span>
</header>

<div id="chat">
    <div class="empty-state" id="empty-state">
        <h2>ClawYard AI</h2>
        <p>Powered by NVIDIA NeMo — Start a conversation</p>
    </div>
</div>

<div id="image-preview">
    <img id="preview-img" src="" alt="preview">
    <button id="remove-image">✕</button>
</div>

<div id="input-area">
    <button class="icon-btn" id="voice-btn" title="Voice input">🎤</button>
    <button class="icon-btn" id="image-btn" title="Upload image">📎</button>
    <input type="file" id="image-input" accept="image/*" style="display:none">
    <textarea
        id="message-input"
        placeholder="Type a message... (Enter to send, Shift+Enter for new line)"
        rows="1"
    ></textarea>
    <button id="send-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"></line>
            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
        </svg>
    </button>
</div>

<script>
    const chat = document.getElementById('chat');
    const input = document.getElementById('message-input');
    const sendBtn = document.getElementById('send-btn');
    const modelName = document.getElementById('model-name');
    const voiceBtn = document.getElementById('voice-btn');
    const imageBtn = document.getElementById('image-btn');
    const imageInput = document.getElementById('image-input');
    const imagePreview = document.getElementById('image-preview');
    const previewImg = document.getElementById('preview-img');
    const removeImage = document.getElementById('remove-image');

    const agentSelect = document.getElementById('agent-select');
    const history = [];
    const sessionId = 'session_' + Date.now();

    // Pre-select agent from URL param
    const urlAgent = new URLSearchParams(window.location.search).get('agent');
    if (urlAgent && agentSelect) {
        agentSelect.value = urlAgent;
    }
    let currentImageB64 = null;
    let recognition = null;
    let isRecording = false;

    // Voice Input (Web Speech API)
    if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new SpeechRecognition();
        recognition.continuous = false;
        recognition.interimResults = false;
        recognition.lang = 'pt-PT';

        recognition.onresult = (e) => {
            input.value = e.results[0][0].transcript;
            voiceBtn.classList.remove('active', 'recording');
            isRecording = false;
            sendMessage();
        };

        recognition.onerror = () => {
            voiceBtn.classList.remove('active', 'recording');
            isRecording = false;
        };
    }

    voiceBtn.addEventListener('click', () => {
        if (!recognition) { alert('Voice not supported in this browser'); return; }
        if (isRecording) {
            recognition.stop();
            voiceBtn.classList.remove('active', 'recording');
            isRecording = false;
        } else {
            recognition.start();
            voiceBtn.classList.add('active', 'recording');
            isRecording = true;
        }
    });

    // Image Upload (Multimodal)
    imageBtn.addEventListener('click', () => imageInput.click());

    imageInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (ev) => {
            currentImageB64 = ev.target.result.split(',')[1];
            previewImg.src = ev.target.result;
            imagePreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    });

    removeImage.addEventListener('click', () => {
        currentImageB64 = null;
        imagePreview.style.display = 'none';
        imageInput.value = '';
    });

    // Voice Output (Text-to-Speech)
    function speak(text) {
        if ('speechSynthesis' in window) {
            const clean = text.replace(/[#*`]/g, '').substring(0, 300);
            const utterance = new SpeechSynthesisUtterance(clean);
            utterance.lang = 'pt-PT';
            utterance.rate = 1.0;
            speechSynthesis.speak(utterance);
        }
    }

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 160) + 'px';
    });

    sendBtn.addEventListener('click', sendMessage);

    function addMessage(role, text) {
        const emptyState = document.getElementById('empty-state');
        if (emptyState) emptyState.remove();

        const msg = document.createElement('div');
        msg.className = `message ${role}`;

        // Check if this is an email response
        if (role === 'ai' && text.startsWith('__EMAIL__')) {
            const emailData = JSON.parse(text.replace('__EMAIL__', ''));
            msg.innerHTML = `
                <div class="avatar">📧</div>
                <div style="flex:1;max-width:calc(100% - 48px)">
                    <div style="font-size:11px;color:#555;margin-bottom:6px">✉️ Daniel Email — Email gerado</div>
                    ${buildEmailCard(emailData)}
                </div>
            `;
        } else {
            msg.innerHTML = `
                <div class="avatar">${role === 'user' ? '{{ substr(Auth::user()->name, 0, 1) }}' : '🤖'}</div>
                <div class="bubble">${escapeHtml(text)}</div>
            `;
        }

        chat.appendChild(msg);
        chat.scrollTop = chat.scrollHeight;
        return msg;
    }

    function buildEmailCard(data) {
        const id = 'email_' + Date.now();
        return `
        <div class="email-card" id="${id}">
            <div class="email-card-header">
                <span>📧 Email Draft</span>
                <small>${data.template || ''} · ${data.language === 'pt' ? '🇵🇹 PT' : data.language === 'es' ? '🇪🇸 ES' : '🇬🇧 EN'}</small>
            </div>
            <div class="email-field">
                <label>Para</label>
                <input type="email" id="${id}_to" value="${escapeHtml(data.to || '')}" placeholder="destinatario@empresa.com">
            </div>
            <div class="email-field">
                <label>CC</label>
                <input type="email" id="${id}_cc" placeholder="cc@empresa.com (opcional)">
            </div>
            <div class="email-field">
                <label>Assunto</label>
                <input type="text" id="${id}_subject" value="${escapeHtml(data.subject || '')}">
            </div>
            <div class="email-body-area" id="${id}_body" contenteditable="true">${escapeHtml(data.body || '')}</div>
            <div class="email-actions">
                <button class="email-send-btn" onclick="sendEmail('${id}')">
                    ✈️ Enviar Email
                </button>
                <button class="email-copy-btn" onclick="copyEmail('${id}')">📋 Copiar</button>
                <span class="email-status" id="${id}_status"></span>
            </div>
        </div>`;
    }

    async function sendEmail(id) {
        const to      = document.getElementById(id + '_to').value.trim();
        const cc      = document.getElementById(id + '_cc').value.trim();
        const subject = document.getElementById(id + '_subject').value.trim();
        const body    = document.getElementById(id + '_body').innerText.trim();
        const statusEl = document.getElementById(id + '_status');
        const btn = document.querySelector(`#${id} .email-send-btn`);

        if (!to) { statusEl.textContent = '⚠️ Insira o email do destinatário'; statusEl.className = 'email-status error'; return; }
        if (!subject) { statusEl.textContent = '⚠️ Insira o assunto'; statusEl.className = 'email-status error'; return; }

        btn.disabled = true;
        btn.textContent = '⏳ A enviar...';
        statusEl.textContent = '';

        try {
            const res = await fetch('/api/email/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ to, cc, subject, body }),
            });
            const data = await res.json();
            if (data.success) {
                statusEl.textContent = '✅ Enviado para ' + to;
                statusEl.className = 'email-status sent';
                btn.textContent = '✅ Enviado';
            } else {
                statusEl.textContent = '❌ ' + data.error;
                statusEl.className = 'email-status error';
                btn.disabled = false;
                btn.textContent = '✈️ Enviar Email';
            }
        } catch (e) {
            statusEl.textContent = '❌ Erro de ligação';
            statusEl.className = 'email-status error';
            btn.disabled = false;
            btn.textContent = '✈️ Enviar Email';
        }
    }

    function copyEmail(id) {
        const subject = document.getElementById(id + '_subject').value;
        const body    = document.getElementById(id + '_body').innerText;
        navigator.clipboard.writeText('Assunto: ' + subject + '\n\n' + body);
        const btn = document.querySelector(`#${id} .email-copy-btn`);
        btn.textContent = '✅ Copiado!';
        setTimeout(() => btn.textContent = '📋 Copiar', 2000);
    }

    function addTyping() {
        const msg = document.createElement('div');
        msg.className = 'message ai typing';
        msg.innerHTML = `
            <div class="avatar">AI</div>
            <div class="bubble">
                <div class="dot"></div>
                <div class="dot"></div>
                <div class="dot"></div>
            </div>
        `;
        chat.appendChild(msg);
        chat.scrollTop = chat.scrollHeight;
        return msg;
    }

    function escapeHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function sendMessage() {
        const text = input.value.trim();
        if (!text) return;

        input.value = '';
        input.style.height = 'auto';
        sendBtn.disabled = true;

        addMessage('user', text);
        history.push({ role: 'user', content: text });

        const typing = addTyping();

        try {
            const selectedAgent = agentSelect ? agentSelect.value : 'auto';

            const payload = {
                message: text,
                agent: selectedAgent,
                session_id: sessionId,
            };

            if (currentImageB64) {
                payload.image = currentImageB64;
                currentImageB64 = null;
                imagePreview.style.display = 'none';
                imageInput.value = '';
            }

            const res = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload),
            });

            const data = await res.json();
            typing.remove();

            if (data.success) {
                addMessage('ai', data.reply);
                history.push({ role: 'assistant', content: data.reply });
                modelName.textContent = data.model || data.agents?.join(', ') || selectedAgent;
                // Voice output
                if (selectedAgent !== 'orchestrator') speak(data.reply);
            } else {
                addMessage('ai', '❌ Error: ' + data.error);
            }
        } catch (err) {
            typing.remove();
            addMessage('ai', '❌ Connection error. Please try again.');
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }
</script>

</body>
</html>
