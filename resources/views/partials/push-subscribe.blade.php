{{--
    Web Push subscribe — corre em qualquer página autenticada do app.
    Auto-subscreve se:
       1. Browser suporta Notification + PushManager + ServiceWorker
       2. VAPID_PUBLIC_KEY está configurada
       3. User ainda não recusou explicitamente (Notification.permission ≠ 'denied')

    Em vez de pedir permissão à força no load (anti-pattern), só dispara
    o prompt depois de o user interagir com o botão "🔔 Activar".
    Botão fica num corner discreto, dispensável.
--}}
@auth
    @php
        $vapidPublic = (string) config('services.push.vapid.public', '');
    @endphp
    @if($vapidPublic !== '')
        <div id="cy-push-cta" class="hidden fixed bottom-4 right-4 z-40 bg-white border border-indigo-200 shadow-lg rounded-lg px-4 py-3 max-w-xs text-sm">
            <div class="flex items-start gap-2">
                <div class="text-2xl">🔔</div>
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-gray-800">Notificações de deadlines</div>
                    <div class="text-xs text-gray-600 mt-0.5">
                        Recebe alerta no telemóvel ~24h antes do deadline dos teus concursos.
                    </div>
                    <div class="mt-2 flex gap-2">
                        <button type="button" id="cy-push-enable"
                                class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500">
                            Activar
                        </button>
                        <button type="button" id="cy-push-dismiss"
                                class="rounded-md border border-gray-300 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
                            Mais tarde
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            'use strict';

            const VAPID_PUBLIC = @json($vapidPublic);
            const DISMISS_KEY  = 'cy-push-dismissed-until';

            // Skip se browser não suporta tudo.
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;
            if (!window.isSecureContext) return;
            if (Notification.permission === 'denied') return;

            // Skip se user dispensou nas últimas 24h.
            const dismissedUntil = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            if (dismissedUntil > Date.now()) return;

            const cta = document.getElementById('cy-push-cta');
            const btn = document.getElementById('cy-push-enable');
            const dis = document.getElementById('cy-push-dismiss');
            if (!cta || !btn || !dis) return;

            // Show CTA after 2s de page-load só se permission===default
            // (não chateamos quem já aceitou ou recusou).
            setTimeout(async () => {
                if (Notification.permission !== 'default') {
                    // Se já aceitou, tenta subscribe silencioso (caso o
                    // device tenha perdido a subscription mas mantém perm)
                    if (Notification.permission === 'granted') trySubscribe();
                    return;
                }
                cta.classList.remove('hidden');
            }, 2000);

            dis.addEventListener('click', () => {
                cta.classList.add('hidden');
                localStorage.setItem(DISMISS_KEY, String(Date.now() + 24 * 3600 * 1000));
            });

            btn.addEventListener('click', async () => {
                btn.disabled = true;
                btn.textContent = '⏳ A activar…';
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') {
                    cta.classList.add('hidden');
                    return;
                }
                await trySubscribe();
                cta.classList.add('hidden');
                if (window.cyToast) window.cyToast({ title: '✓ Notificações activas', body: 'Vais receber alertas ~24h antes dos deadlines.', tone: 'success', duration: 3000 });
            });

            async function trySubscribe() {
                try {
                    const reg = await navigator.serviceWorker.ready;
                    let sub = await reg.pushManager.getSubscription();
                    if (!sub) {
                        sub = await reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC),
                        });
                    }
                    const raw  = sub.toJSON();
                    const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
                    await fetch("{{ route('push.subscribe') }}", {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            endpoint:  raw.endpoint,
                            keys:      raw.keys,
                        }),
                    });
                } catch (e) {
                    console.warn('Push subscribe failed:', e);
                }
            }

            // VAPID public é base64url; PushManager precisa de Uint8Array
            function urlBase64ToUint8Array(b64) {
                const pad  = '='.repeat((4 - b64.length % 4) % 4);
                const norm = (b64 + pad).replace(/-/g, '+').replace(/_/g, '/');
                const raw  = atob(norm);
                const out  = new Uint8Array(raw.length);
                for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
                return out;
            }
        })();
        </script>
    @endif
@endauth
