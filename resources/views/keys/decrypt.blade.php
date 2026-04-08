<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Desencriptar Email — ClawYard</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 30px; }
        .card { background: #fff; border-radius: 8px; padding: 32px; max-width: 750px; margin: 0 auto; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .badge { display: inline-block; background: #001f3f; color: #76b900; border-radius: 4px; padding: 2px 10px; font-size: 12px; margin-bottom: 16px; }
        h1 { color: #001f3f; margin-top: 0; font-size: 22px; }
        label { display: block; font-weight: bold; margin: 20px 0 6px; color: #333; font-size: 14px; }
        textarea { width: 100%; font-family: monospace; font-size: 12px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; resize: vertical; }
        button { background: #76b900; color: #fff; border: none; padding: 12px 32px; border-radius: 6px; font-size: 15px; cursor: pointer; margin-top: 16px; width: 100%; }
        button:hover { background: #5a8f00; }
        button:disabled { background: #aaa; cursor: not-allowed; }
        .result { margin-top: 20px; display: none; }
        .result-box { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 6px; padding: 20px; }
        .result-box h2 { margin: 0 0 6px; color: #1b5e20; font-size: 16px; }
        .subject { font-size: 18px; font-weight: bold; color: #001f3f; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #c8e6c9; }
        .body { font-size: 14px; color: #333; line-height: 1.7; white-space: pre-wrap; }
        .err { background: #ffebee; border: 1px solid #ef9a9a; border-radius: 6px; padding: 16px; color: #c62828; font-size: 14px; margin-top: 20px; display: none; }
        .step { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
        .step-num { background: #001f3f; color: #76b900; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 13px; flex-shrink: 0; margin-top: 2px; }
        .step-text { font-size: 13px; color: #555; }
        .steps { background: #f8f9fa; border-radius: 6px; padding: 16px 16px 4px; margin-bottom: 24px; }
        .loading { display: none; text-align: center; color: #76b900; margin-top: 12px; font-size: 14px; }
        a.back { display: inline-block; color: #76b900; font-size: 13px; text-decoration: none; margin-bottom: 20px; }
        a.back:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="badge">🔒 KYBER-1024</div>
    <a class="back" href="/keys">← Gerir chaves</a>
    <h1>Desencriptar Email Recebido</h1>

    <div class="steps">
        <div class="step"><div class="step-num">1</div><div class="step-text">Abre o email no Outlook e copia o bloco JSON encriptado</div></div>
        <div class="step"><div class="step-num">2</div><div class="step-text">Cola o teu Secret Key (gerado em <a href="/keys" style="color:#76b900">/keys</a>)</div></div>
        <div class="step"><div class="step-num">3</div><div class="step-text">Clica Desencriptar — a mensagem aparece aqui</div></div>
    </div>

    <label>Secret Key <small style="color:#999;font-weight:normal">(a tua chave privada Kyber-1024)</small></label>
    <textarea id="sk" rows="4" placeholder="Cola aqui o teu secret key (base64)..."></textarea>

    <label>JSON Encriptado <small style="color:#999;font-weight:normal">(copia do email recebido no Outlook)</small></label>
    <textarea id="pkg" rows="8" placeholder='Cole aqui o bloco JSON do email, ex: {"version":"kyber1024-aes256gcm-v1","kem_ciphertext":"...","iv":"...","ciphertext":"...","tag":"..."}'></textarea>

    <button id="btn" onclick="desencriptar()">🔓 Desencriptar</button>
    <div class="loading" id="loading">A desencriptar...</div>

    <div class="result" id="result">
        <div class="result-box">
            <h2>✅ Email desencriptado com sucesso</h2>
            <div class="subject" id="out-subject"></div>
            <div class="body" id="out-body"></div>
            <div id="out-attachments" style="margin-top:14px;display:none;">
                <div style="font-weight:bold;font-size:13px;color:#1b5e20;margin-bottom:8px;">📎 Anexos</div>
                <div id="out-attachments-list"></div>
            </div>
        </div>
    </div>
    <div class="err" id="err"></div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

// Auto-fill JSON from URL hash (set by email link)
window.addEventListener('DOMContentLoaded', function() {
    const hash = location.hash.slice(1);
    if (hash) {
        try {
            const json = atob(hash);
            JSON.parse(json); // validate
            document.getElementById('pkg').value = json;
        } catch(e) {}
    }
});

function desencriptar() {
    const sk  = document.getElementById('sk').value.trim();
    const pkg = document.getElementById('pkg').value.trim();

    if (!sk)  return alert('Cola o teu secret key.');
    if (!pkg) return alert('Cola o JSON encriptado do email.');

    let package_obj;
    try { package_obj = JSON.parse(pkg); }
    catch(e) { showErr('JSON inválido — certifica-te de que copiaste o bloco completo do email.'); return; }

    document.getElementById('btn').disabled = true;
    document.getElementById('loading').style.display = 'block';
    document.getElementById('result').style.display = 'none';
    document.getElementById('err').style.display = 'none';

    fetch('/api/email/decrypt', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ secret_key: sk, package: package_obj })
    })
    .then(r => r.json())
    .then(d => {
        document.getElementById('btn').disabled = false;
        document.getElementById('loading').style.display = 'none';
        if (d.success) {
            document.getElementById('out-subject').textContent = d.subject;
            document.getElementById('out-body').textContent    = d.body;
            document.getElementById('result').style.display   = 'block';

            // Render attachment download links
            const attList = document.getElementById('out-attachments-list');
            attList.innerHTML = '';
            if (d.attachments && d.attachments.length > 0) {
                d.attachments.forEach(att => {
                    const a = document.createElement('a');
                    a.href     = 'data:' + att.mime + ';base64,' + att.data;
                    a.download = att.name;
                    a.style.cssText = 'display:inline-block;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:6px 14px;margin:4px 4px 0 0;font-size:13px;color:#1b5e20;text-decoration:none;';
                    a.textContent = '⬇ ' + att.name;
                    attList.appendChild(a);
                });
                document.getElementById('out-attachments').style.display = 'block';
            } else {
                document.getElementById('out-attachments').style.display = 'none';
            }
        } else {
            showErr(d.error || 'Erro desconhecido.');
        }
    })
    .catch(e => {
        document.getElementById('btn').disabled = false;
        document.getElementById('loading').style.display = 'none';
        showErr('Erro de ligação: ' + e.message);
    });
}

function showErr(msg) {
    const el = document.getElementById('err');
    el.textContent = '❌ ' + msg;
    el.style.display = 'block';
}
</script>
</body>
</html>
