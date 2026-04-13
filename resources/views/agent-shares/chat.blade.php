<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $share->custom_title ?: $meta['name'] }}</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        :root{
            --agent-color: {{ $meta['color'] }};
            --bg:#0a0a0f;--bg2:#111118;--bg3:#1a1a24;--bg4:#0f0f18;
            --border:#2a2a3a;--text:#e2e8f0;--muted:#64748b;
        }
        html,body{height:100%;overflow:hidden}
        body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;display:flex;flex-direction:column}

        /* HEADER */
        .header{background:var(--bg2);border-bottom:1px solid var(--border);padding:0 20px;height:54px;display:flex;align-items:center;gap:12px;flex-shrink:0}
        .agent-avatar{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;background:rgba(0,0,0,.3);border:1px solid var(--agent-color)44;flex-shrink:0}
        .agent-info{flex:1;min-width:0}
        .agent-name{font-size:14px;font-weight:700;color:var(--text)}
        .agent-status{font-size:11px;color:var(--agent-color);display:flex;align-items:center;gap:4px}
        .status-dot{width:6px;height:6px;border-radius:50%;background:var(--agent-color);animation:pulse 2s infinite}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
        @if($share->show_branding)
        .branding{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px}
        .branding a{color:var(--muted);text-decoration:none}
        .branding a:hover{color:var(--text)}
        @endif

        /* CHAT AREA */
        .chat-wrap{flex:1;overflow:hidden;display:flex;flex-direction:column}
        .messages{flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:16px;scroll-behavior:smooth}
        .messages::-webkit-scrollbar{width:4px}
        .messages::-webkit-scrollbar-track{background:transparent}
        .messages::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}

        /* MESSAGES */
        .message{display:flex;gap:10px;align-items:flex-start;max-width:800px}
        .message.user{flex-direction:row-reverse;margin-left:auto}
        .avatar{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;font-weight:700}
        .message.user .avatar{background:var(--agent-color)22;border:1px solid var(--agent-color)44;color:var(--agent-color)}
        .message.assistant .avatar{background:rgba(255,255,255,.04);border:1px solid var(--border);font-size:16px}
        .bubble{padding:12px 16px;border-radius:12px;font-size:14px;line-height:1.6;max-width:680px}
        .message.user .bubble{background:var(--agent-color)18;border:1px solid var(--agent-color)30;border-radius:12px 4px 12px 12px;color:var(--text)}
        .message.assistant .bubble{background:var(--bg2);border:1px solid var(--border);border-radius:4px 12px 12px 12px;color:var(--text)}
        .bubble p{margin-bottom:.5em}.bubble p:last-child{margin-bottom:0}
        .bubble strong{color:var(--text);font-weight:700}
        .bubble code{background:rgba(255,255,255,.08);padding:1px 6px;border-radius:4px;font-size:12px;font-family:monospace}
        .bubble pre{background:rgba(0,0,0,.4);border:1px solid var(--border);border-radius:8px;padding:12px;margin:8px 0;overflow-x:auto}
        .bubble pre code{background:none;padding:0;font-size:12px}
        .bubble ul,
        .bubble ol{padding-left:20px;margin:.4em 0}
        .bubble li{margin-bottom:.25em}
        .bubble h1,.bubble h2,.bubble h3{margin:.6em 0 .3em;line-height:1.3}
        .bubble h1{font-size:17px}.bubble h2{font-size:15px}.bubble h3{font-size:14px}
        .bubble hr{border:none;border-top:1px solid var(--border);margin:.8em 0}
        .bubble a{color:var(--agent-color);text-decoration:none}
        .bubble a:hover{text-decoration:underline}

        /* TYPING */
        .typing .bubble{padding:14px 18px}
        .typing-dots{display:flex;gap:4px}
        .typing-dots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:bounce .8s infinite}
        .typing-dots span:nth-child(2){animation-delay:.15s}
        .typing-dots span:nth-child(3){animation-delay:.3s}
        @keyframes bounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-5px)}}

        /* WELCOME */
        .welcome{text-align:center;padding:32px 20px 16px;max-width:560px;margin:0 auto}
        .welcome-avatar{font-size:52px;margin-bottom:12px;filter:drop-shadow(0 0 20px var(--agent-color))}
        .welcome-name{font-size:22px;font-weight:800;color:var(--agent-color);margin-bottom:8px}
        .welcome-msg{font-size:14px;color:var(--muted);line-height:1.6;margin-bottom:18px}

        /* STARTER CHIPS */
        .starter-chips{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;max-width:560px;margin:16px auto 0}
        .chip{background:var(--bg3);border:1px solid var(--border);border-radius:20px;padding:7px 14px;font-size:12px;color:var(--muted);cursor:pointer;transition:all .15s;text-align:left;line-height:1.4}
        .chip:hover{border-color:var(--agent-color);color:var(--text);background:color-mix(in srgb,var(--agent-color) 8%,var(--bg3))}
        @media(max-width:640px){.chip{font-size:11px;padding:6px 11px}}

        /* INPUT */
        .input-area{background:var(--bg2);border-top:1px solid var(--border);padding:16px 20px;flex-shrink:0}
        .input-row{display:flex;gap:8px;align-items:flex-end;max-width:800px;margin:0 auto}
        .input-box{flex:1;background:var(--bg3);border:1px solid var(--border);border-radius:12px;padding:10px 14px;color:var(--text);font-size:14px;resize:none;outline:none;max-height:140px;min-height:44px;line-height:1.5;transition:border-color .15s;font-family:inherit}
        .input-box:focus{border-color:var(--agent-color)}
        .attach-btn{width:40px;height:40px;background:transparent;border:1px solid var(--border);border-radius:10px;color:var(--muted);font-size:17px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.15s;margin-bottom:2px}
        .attach-btn:hover{border-color:var(--agent-color);color:var(--text)}
        .send-btn{width:44px;height:44px;background:var(--agent-color);border:none;border-radius:10px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.15s}
        .send-btn svg{width:18px;height:18px;flex-shrink:0}
        .send-btn:hover{filter:brightness(1.1)}
        .send-btn:disabled{opacity:.4;cursor:not-allowed}
        .input-hint{text-align:center;font-size:11px;color:var(--muted);margin-top:8px;max-width:800px;margin:8px auto 0}
        /* File preview */
        .file-preview-bar{display:none;align-items:center;gap:8px;padding:6px 10px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;font-size:13px;color:var(--muted);margin-bottom:8px;max-width:800px;margin-left:auto;margin-right:auto}
        .file-preview-bar img{max-height:40px;border-radius:4px}
        .file-preview-bar button{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;margin-left:auto;padding:0 4px}
        .file-preview-bar button:hover{color:var(--text)}

        /* MARKDOWN render */
        @media(max-width:640px){
            .header{padding:0 12px}
            .messages{padding:12px}
            .input-area{padding:12px}
            .bubble{font-size:13px}
        }
    </style>
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="agent-avatar" style="{{ ($meta['photo'] ?? null) ? 'padding:0;overflow:hidden' : '' }}">
        @if($meta['photo'] ?? null)
            <img src="{{ $meta['photo'] }}" alt="{{ $meta['name'] }}" style="width:100%;height:100%;object-fit:cover;border-radius:7px">
        @else
            {{ $meta['emoji'] }}
        @endif
    </div>
    <div class="agent-info">
        <div class="agent-name">{{ $share->custom_title ?: $meta['name'] }}</div>
        <div class="agent-status"><span class="status-dot"></span> Online</div>
    </div>
    @if($share->show_branding)
    <div class="branding">© PartYard_B.Mont_H&amp;P Group rights reserved 2026</div>
    @endif
</div>

<!-- CHAT -->
<div class="chat-wrap">
    <div class="messages" id="messages">
        <div class="welcome" id="welcome">
            <div class="welcome-avatar" style="{{ ($meta['photo'] ?? null) ? 'font-size:0' : '' }}">
                @if($meta['photo'] ?? null)
                    <img src="{{ $meta['photo'] }}" alt="{{ $meta['name'] }}" style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid var(--agent-color);box-shadow:0 0 24px color-mix(in srgb,var(--agent-color) 33%,transparent)">
                @else
                    {{ $meta['emoji'] }}
                @endif
            </div>
            <div class="welcome-name">{{ $share->custom_title ?: $meta['name'] }}</div>
            <div class="welcome-msg">
                @if($share->welcome_message)
                    {{ $share->welcome_message }}
                @else
                    Olá! Como posso ajudar?
                @endif
            </div>
            <div class="starter-chips" id="starter-chips"></div>
        </div>
    </div>

    <div class="input-area">
        <!-- File preview bar -->
        <div class="file-preview-bar" id="file-preview-bar">
            <img id="fp-img" src="" alt="" style="display:none">
            <span id="fp-icon" style="font-size:20px"></span>
            <span id="fp-name" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
            <span id="fp-size" style="font-size:11px;color:#475569"></span>
            <button onclick="clearAttachment()" title="Remover ficheiro">✕</button>
        </div>
        <div class="input-row">
            <label for="file-input" class="attach-btn" title="Anexar ficheiro (PDF, imagem, Excel, Word, TXT)">📎</label>
            <input type="file" id="file-input" accept="image/*,.pdf,.doc,.docx,.txt,.csv,.xlsx,.xls,.pptx,.md" style="display:none">
            <textarea
                id="input"
                class="input-box"
                placeholder="Escreve a tua mensagem…"
                rows="1"
                onkeydown="handleKey(event)"
                oninput="autoResize(this)"
            ></textarea>
            <button class="send-btn" id="send-btn" onclick="sendMessage()">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </div>
        @if($share->show_branding)
        <div class="input-hint">© PartYard_B.Mont_H&amp;P Group rights reserved 2026</div>
        @endif
    </div>
</div>

<script>
const TOKEN      = '{{ $share->token }}';
const CSRF       = document.querySelector('meta[name="csrf-token"]').content;
const SESSION_ID = 'share_' + Date.now() + '_' + Math.random().toString(36).substr(2,6);
const AGENT_EMOJI = '{{ $meta['emoji'] }}';
const AGENT_PHOTO = '{{ $meta['photo'] ?? '' }}';
const AGENT_COLOR = '{{ $meta['color'] }}';
const AGENT_KEY   = '{{ $share->agent_key }}';
let history = [];
let isStreaming = false;

// ── File attachment state ──────────────────────────────────────────────────
let attachImg     = null;   // base64 image string
let attachImgType = 'image/jpeg';
let attachFile    = null;   // { name, type, ext, b64, text, size }

const FILE_ICONS = {'pdf':'📄','doc':'📝','docx':'📝','txt':'📃','csv':'📊','xlsx':'📊','xls':'📊','pptx':'📑','md':'📃'};
function getFileIcon(n){ const e=n.split('.').pop().toLowerCase(); return FILE_ICONS[e]||'📎'; }
function humanSize(b){ if(b<1024) return b+' B'; if(b<1048576) return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(1)+' MB'; }

document.getElementById('file-input').addEventListener('change', function(e){
    const file = e.target.files[0];
    if (!file) return;
    const isImage = file.type.startsWith('image/');
    const reader  = new FileReader();
    if (isImage) {
        reader.onload = ev => {
            attachImg     = ev.target.result.split(',')[1];
            attachImgType = file.type || 'image/jpeg';
            attachFile    = null;
            document.getElementById('fp-img').src     = ev.target.result;
            document.getElementById('fp-img').style.display = 'block';
            document.getElementById('fp-icon').textContent = '';
            document.getElementById('fp-name').textContent = file.name;
            document.getElementById('fp-size').textContent = humanSize(file.size);
            document.getElementById('file-preview-bar').style.display = 'flex';
        };
        reader.readAsDataURL(file);
    } else {
        const ext = file.name.split('.').pop().toLowerCase();
        const asText = ['txt','csv','md'].includes(ext);
        reader.onload = ev => {
            attachImg  = null;
            attachFile = { name: file.name, type: file.type||'application/octet-stream', ext,
                           b64: asText ? null : ev.target.result.split(',')[1],
                           text: asText ? ev.target.result : null, size: humanSize(file.size) };
            document.getElementById('fp-img').style.display  = 'none';
            document.getElementById('fp-icon').textContent   = getFileIcon(file.name);
            document.getElementById('fp-name').textContent   = file.name;
            document.getElementById('fp-size').textContent   = humanSize(file.size);
            document.getElementById('file-preview-bar').style.display = 'flex';
        };
        if (asText) reader.readAsText(file);
        else        reader.readAsDataURL(file);
    }
});

function clearAttachment() {
    attachImg = null; attachImgType = 'image/jpeg'; attachFile = null;
    document.getElementById('file-preview-bar').style.display = 'none';
    document.getElementById('fp-img').style.display = 'none';
    document.getElementById('file-input').value = '';
}

// ── Starter chips per agent ───────────────────────────────────────────────
const AGENT_CHIPS = @json(\App\Services\AgentChipsService::all());

function renderStarterChips() {
    const chips = AGENT_CHIPS[AGENT_KEY] || AGENT_CHIPS['auto'] || [];
    const container = document.getElementById('starter-chips');
    if (!container || !chips.length) return;
    container.innerHTML = chips.map(c =>
        `<button class="chip" onclick="useChip(this)">${c}</button>`
    ).join('');
}

function useChip(btn) {
    const input = document.getElementById('input');
    input.value = btn.textContent;
    autoResize(input);
    input.focus();
    // Remove chips after selection
    document.getElementById('starter-chips')?.remove();
}

// ── Auto-resize textarea ──
function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 140) + 'px';
}

function handleKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

// ── Send message ──
async function sendMessage() {
    const input = document.getElementById('input');
    let text    = input.value.trim();
    if ((!text && !attachImg && !attachFile) || isStreaming) return;
    if (!text) text = attachImg ? 'O que vês nesta imagem?' : (attachFile?.name || '?');

    input.value = '';
    autoResize(input);
    document.getElementById('welcome')?.remove();
    document.getElementById('starter-chips')?.remove();
    document.getElementById('send-btn').disabled = true;
    isStreaming = true;

    addMessage('user', text);
    history.push({ role: 'user', content: text });

    // Build payload — use FormData when file is attached (avoids JSON body size limits)
    let fetchBody, fetchHeaders;
    const hasFile  = !!(attachImg || (attachFile && attachFile.b64));
    const hasTxt   = !!(attachFile && attachFile.text !== null);

    if (hasFile) {
        // Multipart FormData for binary files/images
        const fd = new FormData();
        fd.append('message',    text);
        fd.append('session_id', SESSION_ID);
        history.slice(-20).forEach((m, i) => {
            fd.append(`history[${i}][role]`,    m.role);
            fd.append(`history[${i}][content]`, typeof m.content === 'string' ? m.content : JSON.stringify(m.content));
        });
        if (attachImg) {
            // Convert base64 back to Blob for FormData
            const byteChars = atob(attachImg);
            const byteArr   = new Uint8Array(byteChars.length);
            for (let i = 0; i < byteChars.length; i++) byteArr[i] = byteChars.charCodeAt(i);
            fd.append('image_blob', new Blob([byteArr], { type: attachImgType }), 'image');
            fd.append('image_type', attachImgType);
        } else {
            const f = attachFile;
            const byteChars = atob(f.b64);
            const byteArr   = new Uint8Array(byteChars.length);
            for (let i = 0; i < byteChars.length; i++) byteArr[i] = byteChars.charCodeAt(i);
            fd.append('file_upload', new Blob([byteArr], { type: f.type }), f.name);
            fd.append('file_name',   f.name);
            fd.append('file_type',   f.type);
        }
        fetchBody    = fd;
        fetchHeaders = { 'Accept': 'text/event-stream' }; // no Content-Type; browser sets multipart boundary
        clearAttachment();
    } else {
        // Plain JSON for text-only or text-file messages
        const payload = { message: text, history: history.slice(-20), session_id: SESSION_ID };
        if (hasTxt) {
            const f = attachFile;
            payload.message += `\n\n---\n**Ficheiro: ${f.name}**\n\`\`\`\n${f.text.substring(0,15000)}\n\`\`\``;
            clearAttachment();
        }
        fetchBody    = JSON.stringify(payload);
        fetchHeaders = { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' };
    }

    const typingEl = addTyping();

    try {
        const resp = await fetch(`/api/a/${TOKEN}/stream`, {
            method: 'POST',
            headers: fetchHeaders,
            body:    fetchBody,
        });

        // Handle HTTP errors (413 Too Large, 422 Unprocessable, 500, etc.)
        if (!resp.ok) {
            typingEl?.remove();
            let errMsg = `Erro ${resp.status}`;
            try { const j = await resp.json(); errMsg = j.error || errMsg; } catch(_){}
            addMessage('assistant', `❌ ${errMsg}`);
            document.getElementById('send-btn').disabled = false;
            isStreaming = false;
            return;
        }

        typingEl.remove();
        const bubble = addAssistantBubble();

        let full = '';
        const reader = resp.body.getReader();
        const decoder = new TextDecoder();
        let buf = '';

        while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            buf += decoder.decode(value, { stream: true });
            const lines = buf.split('\n');
            buf = lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                const raw = line.slice(6).trim();
                if (raw === '[DONE]') break;
                if (raw.startsWith(':')) continue;
                try {
                    const evt = JSON.parse(raw);
                    if (evt.chunk) {
                        full += evt.chunk;
                        bubble.innerHTML = renderMarkdown(full);
                        scrollBottom();
                    }
                    if (evt.error) {
                        bubble.innerHTML = '<span style="color:#ef4444">❌ ' + evt.error + '</span>';
                    }
                } catch(e) {}
            }
        }

        if (full) history.push({ role: 'assistant', content: full });

    } catch(e) {
        typingEl?.remove();
        addMessage('assistant', '❌ Erro de ligação: ' + (e.message || 'Tenta novamente.'));
    }

    document.getElementById('send-btn').disabled = false;
    isStreaming = false;
}

function makeAgentAvatar() {
    const avatar = document.createElement('div');
    avatar.className = 'avatar';
    if (AGENT_PHOTO) {
        avatar.style.cssText = 'padding:0;overflow:hidden;border:1.5px solid var(--border)';
        avatar.innerHTML = `<img src="${AGENT_PHOTO}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%">`;
    } else {
        avatar.textContent = AGENT_EMOJI;
    }
    return avatar;
}

function addMessage(role, text) {
    const msgs = document.getElementById('messages');
    const div  = document.createElement('div');
    div.className = 'message ' + role;

    const avatar = role === 'user' ? (() => {
        const a = document.createElement('div');
        a.className = 'avatar';
        a.textContent = 'You';
        return a;
    })() : makeAgentAvatar();

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.innerHTML = role === 'user' ? escapeHtml(text) : renderMarkdown(text);

    div.appendChild(avatar);
    div.appendChild(bubble);
    msgs.appendChild(div);
    scrollBottom();
    return bubble;
}

function addAssistantBubble() {
    const msgs = document.getElementById('messages');
    const div  = document.createElement('div');
    div.className = 'message assistant';

    const bubble = document.createElement('div');
    bubble.className = 'bubble';

    div.appendChild(makeAgentAvatar());
    div.appendChild(bubble);
    msgs.appendChild(div);
    scrollBottom();
    return bubble;
}

function addTyping() {
    const msgs = document.getElementById('messages');
    const div  = document.createElement('div');
    div.className = 'message assistant typing';

    const avatar = makeAgentAvatar();

    const bubble = document.createElement('div');
    bubble.className = 'bubble';
    bubble.innerHTML = '<div class="typing-dots"><span></span><span></span><span></span></div>';

    div.appendChild(avatar);
    div.appendChild(bubble);
    msgs.appendChild(div);
    scrollBottom();
    return div;
}

function scrollBottom() {
    const msgs = document.getElementById('messages');
    msgs.scrollTop = msgs.scrollHeight;
}

function escapeHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}

// Render chips on load
renderStarterChips();

// ── Simple markdown renderer ──
function renderMarkdown(md) {
    let html = md
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        // Code blocks
        .replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>')
        // Inline code
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        // Bold
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        // Italic
        .replace(/\*([^*]+)\*/g, '<em>$1</em>')
        // HR
        .replace(/^---$/gm, '<hr>')
        // H3
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        // H2
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        // H1
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        // Bullet lists
        .replace(/^[-•] (.+)$/gm, '<li>$1</li>')
        // Numbered lists
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        // Links
        .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
        // Newlines → paragraphs
        .split(/\n{2,}/).map(p => {
            if (p.startsWith('<h') || p.startsWith('<pre') || p.startsWith('<hr')) return p;
            if (p.includes('<li>')) return '<ul>' + p + '</ul>';
            return '<p>' + p.replace(/\n/g,'<br>') + '</p>';
        }).join('');

    return html;
}
</script>
</body>
</html>
