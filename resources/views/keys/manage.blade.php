<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kyber-1024 — Gerir Chaves</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 30px; }
        .card { background: #fff; border-radius: 8px; padding: 32px; max-width: 720px; margin: 0 auto 24px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        h1 { color: #001f3f; margin-top: 0; }
        h2 { color: #001f3f; font-size: 16px; margin: 0 0 14px; }
        label { display: block; font-weight: bold; margin: 18px 0 6px; color: #333; font-size: 14px; }
        input[type=text], input[type=email], textarea {
            width: 100%; font-family: monospace; font-size: 12px;
            padding: 10px; border: 1px solid #ccc; border-radius: 4px; resize: vertical;
        }
        input[type=text], input[type=email] { font-family: Arial, sans-serif; font-size: 14px; padding: 10px; }
        .btn  { background: #76b900; color: #fff; border: none; padding: 11px 24px; border-radius: 6px; font-size: 14px; cursor: pointer; margin-top: 10px; }
        .btn:hover { background: #5a8f00; }
        .btn-outline { background: #fff; color: #001f3f; border: 1px solid #001f3f; padding: 11px 24px; border-radius: 6px; font-size: 14px; cursor: pointer; margin-top: 10px; margin-left: 8px; }
        .result { margin-top: 14px; padding: 14px; border-radius: 6px; font-family: monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; display: none; }
        .ok  { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; }
        .err { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; }
        .badge { display: inline-block; background: #001f3f; color: #76b900; border-radius: 4px; padding: 2px 10px; font-size: 12px; margin-bottom: 16px; }
        .section-num { display: inline-block; background: #76b900; color: #fff; border-radius: 50%; width: 24px; height: 24px; text-align: center; line-height: 24px; font-weight: bold; font-size: 13px; margin-right: 8px; }
        .divider { border: none; border-top: 1px solid #eee; margin: 20px 0; }
        .flow { display: flex; align-items: center; gap: 8px; background: #f8f9fa; border-radius: 6px; padding: 14px 16px; margin-bottom: 20px; font-size: 13px; color: #555; flex-wrap: wrap; }
        .flow-step { background: #001f3f; color: #76b900; border-radius: 4px; padding: 4px 10px; font-weight: bold; white-space: nowrap; }
        .flow-arrow { color: #76b900; font-size: 18px; }
        small { color: #999; font-weight: normal; }
        a.link { color: #76b900; }
    </style>
</head>
<body>

{{-- CARD 1: Fluxo visual --}}
<div class="card">
    <div class="badge">🔒 KYBER-1024</div>
    <h1>Encriptação Post-Quantum de Email</h1>
    <div class="flow">
        <div class="flow-step">1. Gerar chaves</div>
        <div class="flow-arrow">→</div>
        <div class="flow-step">2. Registar public key</div>
        <div class="flow-arrow">→</div>
        <div class="flow-step">3. Enviar email encriptado</div>
        <div class="flow-arrow">→</div>
        <div class="flow-step">4. Outlook recebe JSON</div>
        <div class="flow-arrow">→</div>
        <div class="flow-step"><a href="/decrypt" class="link" style="color:#76b900">5. Desencriptar aqui</a></div>
    </div>
    <p style="font-size:13px;color:#666;margin:0">
        A <strong>public key</strong> fica no servidor — qualquer pessoa pode encriptar para ti.<br>
        O <strong>secret key</strong> só tu tens — só tu podes ler os emails.
    </p>
</div>

{{-- CARD 2: Gerar + Registar --}}
<div class="card">
    <h2><span class="section-num">1</span> Gerar e Registar a tua Chave</h2>

    <button class="btn" onclick="gerar()">⚙️ Gerar Kyber-1024 Key Pair</button>
    <div id="res-gerar" class="result"></div>

    <label>Public Key <small>(preenchida automaticamente ao gerar)</small></label>
    <textarea id="pk-input" rows="4" placeholder="Cola aqui a tua public key (base64)..."></textarea>
    <button class="btn" onclick="registar()">💾 Registar Public Key no Servidor</button>
    <div id="res-registar" class="result"></div>

    <div id="sk-aviso" style="display:none;margin-top:16px;padding:14px;background:#fff3e0;border:1px solid #ffcc80;border-radius:6px;font-size:13px;">
        ⚠️ <strong>Guarda o teu Secret Key!</strong> Não é armazenado no servidor.<br>
        <div id="sk-box" style="margin-top:8px;font-family:monospace;font-size:11px;word-break:break-all;background:#fff;border:1px solid #eee;padding:8px;border-radius:4px;max-height:80px;overflow:auto;"></div>
        <button class="btn" style="margin-top:8px;font-size:12px;padding:6px 14px;" onclick="copiarSK()">📋 Copiar Secret Key</button>
    </div>
</div>

{{-- CARD 3: Teste rápido --}}
<div class="card">
    <h2><span class="section-num">2</span> Testar — Enviar Email Encriptado</h2>
    <p style="font-size:13px;color:#666;margin:0 0 14px">Envia um email de teste encriptado. O destinatário precisa de ter a public key registada.</p>

    <label>Para (email do destinatário)</label>
    <input type="email" id="test-to" placeholder="destinatario@email.com">

    <label>Assunto</label>
    <input type="text" id="test-subject" value="Teste Kyber-1024 — Email Encriptado">

    <label>Mensagem</label>
    <textarea id="test-body" rows="3">Este email foi encriptado com CRYSTALS-Kyber 1024 + AES-256-GCM. Só o destinatário o pode ler.</textarea>

    <button class="btn" onclick="enviarTeste()">📧 Enviar Email Encriptado</button>
    <a href="/decrypt" class="btn-outline">🔓 Ir para Desencriptar</a>
    <div id="res-envio" class="result"></div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let secretKey = '';

function show(id, data, ok) {
    const el = document.getElementById(id);
    el.style.display = 'block';
    el.className = 'result ' + (ok ? 'ok' : 'err');
    el.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
}

function gerar() {
    fetch('/api/keys/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.getElementById('pk-input').value = d.public_key;
            secretKey = d.secret_key;
            document.getElementById('sk-box').textContent = d.secret_key;
            document.getElementById('sk-aviso').style.display = 'block';
            show('res-gerar', '✅ Key pair gerado! Regista a public key e guarda o secret key.', true);
        } else {
            show('res-gerar', d, false);
        }
    })
    .catch(e => show('res-gerar', { error: e.message }, false));
}

function registar() {
    const pk = document.getElementById('pk-input').value.trim();
    if (!pk) return alert('Gera ou cola a public key primeiro.');
    fetch('/api/keys/store', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ public_key: pk })
    })
    .then(r => r.json())
    .then(d => show('res-registar', d.success ? '✅ Public key registada! Podes receber emails encriptados.' : d, d.success))
    .catch(e => show('res-registar', { error: e.message }, false));
}

function copiarSK() {
    navigator.clipboard.writeText(secretKey).then(() => alert('Secret key copiado!')).catch(() => {
        prompt('Copia manualmente:', secretKey);
    });
}

function enviarTeste() {
    const to      = document.getElementById('test-to').value.trim();
    const subject = document.getElementById('test-subject').value.trim();
    const body    = document.getElementById('test-body').value.trim();
    if (!to) return alert('Introduz o email do destinatário.');
    fetch('/api/email/send', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ to, subject, body, encrypt: true })
    })
    .then(r => r.json())
    .then(d => {
        if (d.success !== false && !d.error) {
            show('res-envio', '✅ Email enviado' + (d.encrypted ? ' (encriptado com Kyber-1024 ✓)' : ' (sem encriptação — destinatário sem public key)') + '\nVerifica o Outlook e depois vai a /decrypt para ler.', true);
        } else {
            show('res-envio', d, false);
        }
    })
    .catch(e => show('res-envio', { error: e.message }, false));
}
</script>
</body>
</html>
