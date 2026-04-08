<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Kyber-1024 — Gerir Chaves</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 30px; }
        .card { background: #fff; border-radius: 8px; padding: 32px; max-width: 700px; margin: 0 auto; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        h1 { color: #001f3f; margin-top: 0; }
        label { display: block; font-weight: bold; margin: 20px 0 6px; color: #333; }
        textarea { width: 100%; box-sizing: border-box; font-family: monospace; font-size: 12px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; resize: vertical; }
        button { background: #76b900; color: #fff; border: none; padding: 12px 28px; border-radius: 6px; font-size: 15px; cursor: pointer; margin-top: 12px; }
        button:hover { background: #5a8f00; }
        .result { margin-top: 16px; padding: 14px; border-radius: 6px; font-family: monospace; font-size: 13px; white-space: pre-wrap; word-break: break-all; display: none; }
        .ok  { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; }
        .err { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; }
        .badge { display: inline-block; background: #001f3f; color: #76b900; border-radius: 4px; padding: 2px 10px; font-size: 12px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="card">
    <div class="badge">🔒 KYBER-1024</div>
    <h1>Gerir Chaves de Encriptação</h1>

    {{-- Gerar par de chaves --}}
    <label>1. Gerar novo par de chaves</label>
    <button onclick="gerar()">Gerar Kyber-1024 Key Pair</button>
    <div id="res-gerar" class="result"></div>

    {{-- Registar chave pública --}}
    <label>2. Registar a tua Public Key</label>
    <textarea id="pk-input" rows="5" placeholder="Cola aqui a tua public key (base64)..."></textarea>
    <button onclick="registar()">Registar Public Key</button>
    <div id="res-registar" class="result"></div>
</div>

<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;

function show(id, data, ok) {
    const el = document.getElementById(id);
    el.style.display = 'block';
    el.className = 'result ' + (ok ? 'ok' : 'err');
    el.textContent = JSON.stringify(data, null, 2);
}

function gerar() {
    fetch('/api/keys/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }
    })
    .then(r => r.json())
    .then(d => {
        show('res-gerar', d, d.success);
        if (d.public_key) document.getElementById('pk-input').value = d.public_key;
    })
    .catch(e => show('res-gerar', { error: e.message }, false));
}

function registar() {
    const pk = document.getElementById('pk-input').value.trim();
    if (!pk) return alert('Cola a public key primeiro.');
    fetch('/api/keys/store', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ public_key: pk })
    })
    .then(r => r.json())
    .then(d => show('res-registar', d, d.success))
    .catch(e => show('res-registar', { error: e.message }, false));
}
</script>
</body>
</html>
