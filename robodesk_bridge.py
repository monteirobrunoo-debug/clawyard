#!/usr/bin/env python3
"""
RoboDesk Bridge — ClawYard local Mac control agent
====================================================
Corre no teu Mac. Expõe uma HTTP API que o ClawYard usa
para controlar o ecrã via Anthropic Computer Use API.

INSTALAR:
    pip install pyautogui pillow flask

CONFIGURAR:
    1. Copia o ROBODESK_SECRET do .env do Forge para aqui (linha SECRET=)
    2. Corre:  python robodesk_bridge.py
    3. Expõe:  ngrok http 7771
    4. Cola o URL ngrok no .env do Forge: ROBODESK_BRIDGE_URL=https://xyz.ngrok-free.app

REQUISITOS macOS:
    - Vai a Preferências do Sistema → Privacidade e Segurança → Acessibilidade
      e adiciona o Terminal (ou iTerm) à lista permitida.
    - Faz o mesmo em Gravação de Ecrã para permitir screenshots.
"""

from flask import Flask, request, jsonify
import pyautogui
import base64
import io
import time
import sys
import os
import platform

try:
    from PIL import ImageGrab, Image
except ImportError:
    print("❌ Instala as dependências: pip install pyautogui pillow flask")
    sys.exit(1)

app = Flask(__name__)

# ── Configuração ──────────────────────────────────────────────────────────────
SECRET  = os.environ.get('ROBODESK_SECRET', 'robodesk-secret-change-me')
PORT    = int(os.environ.get('ROBODESK_PORT', 7771))

# pyautogui safety
pyautogui.FAILSAFE  = True   # mover rato para canto superior-esquerdo para parar
pyautogui.PAUSE     = 0.25   # pausa entre acções (segundos)

# ── Auth ──────────────────────────────────────────────────────────────────────
def authorized(req):
    return req.headers.get('X-RoboDesk-Secret') == SECRET


# ── Endpoints ────────────────────────────────────────────────────────────────

@app.route('/ping')
def ping():
    """Verificação de conectividade — não requer autenticação."""
    w, h = pyautogui.size()
    return jsonify({
        'status':   'ok',
        'platform': platform.system(),
        'screen':   {'width': w, 'height': h},
    })


@app.route('/screenshot')
def screenshot():
    """Tira screenshot do ecrã completo. Devolve PNG em base64."""
    if not authorized(request):
        return jsonify({'error': 'unauthorized'}), 401
    try:
        img = ImageGrab.grab()
        # Reduz resolução para 1280px de largura — mais rápido para enviar ao Claude
        max_w = 1280
        if img.width > max_w:
            ratio = max_w / img.width
            img = img.resize((max_w, int(img.height * ratio)), Image.LANCZOS)
        buf = io.BytesIO()
        img.save(buf, format='PNG', optimize=True)
        b64 = base64.b64encode(buf.getvalue()).decode()
        return jsonify({
            'image':  b64,
            'width':  img.width,
            'height': img.height,
        })
    except Exception as e:
        return jsonify({'error': str(e)}), 500


@app.route('/action', methods=['POST'])
def action():
    """
    Executa uma acção no ecrã.
    Body JSON: { "action": "left_click", "x": 500, "y": 300, ... }

    Acções suportadas (alinhadas com Anthropic Computer Use API):
      screenshot         — tira screenshot (devolve imagem)
      left_click         — clique esquerdo em (x, y)
      right_click        — clique direito em (x, y)
      double_click       — duplo clique em (x, y)
      middle_click       — clique do meio em (x, y)
      left_click_drag    — arrasta de (start_x, start_y) para (x, y)
      type               — escreve texto (campo "text")
      key                — pressiona tecla(s) (campo "text", ex: "ctrl+c", "Return")
      scroll             — scroll em (x, y), campo "direction" up/down/left/right,
                           campo "coordinate" (lista [x, y])
      mouse_move         — move rato para (x, y) sem clicar
      cursor_position    — devolve posição actual do rato
    """
    if not authorized(request):
        return jsonify({'error': 'unauthorized'}), 401

    data   = request.get_json(force=True) or {}
    action = data.get('action', '')

    try:
        result = _execute_action(action, data)
        time.sleep(0.4)   # deixa o UI actualizar
        return jsonify(result)
    except Exception as e:
        return jsonify({'ok': False, 'error': str(e)}), 500


def _execute_action(action: str, d: dict) -> dict:
    x = d.get('x') or (d.get('coordinate', [0, 0])[0] if 'coordinate' in d else None)
    y = d.get('y') or (d.get('coordinate', [0, 0])[1] if 'coordinate' in d else None)

    if action == 'screenshot':
        # Inline screenshot dentro do loop de acções
        img = ImageGrab.grab()
        if img.width > 1280:
            ratio = 1280 / img.width
            img = img.resize((1280, int(img.height * ratio)), Image.LANCZOS)
        buf = io.BytesIO()
        img.save(buf, format='PNG', optimize=True)
        return {
            'ok':     True,
            'type':   'screenshot',
            'image':  base64.b64encode(buf.getvalue()).decode(),
            'width':  img.width,
            'height': img.height,
        }

    elif action == 'left_click':
        pyautogui.click(x, y, button='left')

    elif action == 'right_click':
        pyautogui.click(x, y, button='right')

    elif action == 'double_click':
        pyautogui.doubleClick(x, y)

    elif action == 'middle_click':
        pyautogui.click(x, y, button='middle')

    elif action == 'left_click_drag':
        sx = d.get('start_x') or (d.get('start_coordinate', [0, 0])[0])
        sy = d.get('start_y') or (d.get('start_coordinate', [0, 0])[1])
        pyautogui.mouseDown(sx, sy, button='left')
        time.sleep(0.1)
        pyautogui.moveTo(x, y, duration=0.4)
        pyautogui.mouseUp(button='left')

    elif action == 'type':
        text = d.get('text', '')
        # typewrite tem problemas com caracteres especiais — usa pyperclip se disponível
        try:
            import pyperclip
            pyperclip.copy(text)
            pyautogui.hotkey('cmd', 'v')
        except ImportError:
            pyautogui.typewrite(text, interval=0.04)

    elif action == 'key':
        keys_str = d.get('text', '')
        # Suporta "ctrl+c", "Return", "cmd+a", etc.
        keys = [k.strip() for k in keys_str.replace('+', ' ').split()]
        if len(keys) > 1:
            pyautogui.hotkey(*keys)
        else:
            pyautogui.press(keys[0] if keys else 'return')

    elif action == 'scroll':
        direction = d.get('direction', 'down')
        amount    = d.get('coordinate', [0, 0, 3])[2] if len(d.get('coordinate', [])) > 2 else 3
        if x and y:
            pyautogui.moveTo(x, y)
        clicks = amount if direction in ('up', 'right') else -amount
        pyautogui.scroll(clicks)

    elif action == 'mouse_move':
        pyautogui.moveTo(x, y, duration=0.2)

    elif action == 'cursor_position':
        pos = pyautogui.position()
        return {'ok': True, 'x': pos.x, 'y': pos.y}

    else:
        return {'ok': False, 'error': f'Acção desconhecida: {action}'}

    return {'ok': True}


# ── Main ─────────────────────────────────────────────────────────────────────
if __name__ == '__main__':
    print()
    print("╔══════════════════════════════════════════════════╗")
    print("║  🖥️  RoboDesk Bridge — ClawYard Mac Controller   ║")
    print("╚══════════════════════════════════════════════════╝")
    print(f"  Secret: {SECRET[:8]}..." if len(SECRET) > 8 else f"  Secret: {SECRET}")
    print(f"  Porta:  {PORT}")
    w, h = pyautogui.size()
    print(f"  Ecrã:   {w}×{h}")
    print()
    print("  Para expor ao Forge:")
    print(f"  → ngrok http {PORT}")
    print("  → Cola o URL em .env: ROBODESK_BRIDGE_URL=https://xyz.ngrok-free.app")
    print()
    print("  ⚠️  Move o rato para o canto superior-esquerdo para parar.")
    print()
    app.run(host='0.0.0.0', port=PORT, debug=False, threaded=True)
