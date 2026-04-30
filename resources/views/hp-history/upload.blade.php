{{--
    Drag-drop UI for feeding historical proposals/contracts into the
    hp-history droplet. The form posts multipart/form-data to
    POST /hp-history/upload; the controller proxies to FastAPI.

    Limits (mirrored on the FastAPI side, see services/hp-history/app/main.py):
      • {{ $maxFiles }} files per submission
      • {{ $maxMb }} MB per file
      • .pdf, .txt, .md only

    The "domain" field tags the documents (spares / marine / military)
    so search filters work end-to-end. "year" lets us recall "show me
    the 2018 marine RFQs" later.
--}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('dashboard') }}" class="text-sm text-indigo-600 hover:underline">← Voltar</a>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                hp-history — Upload de propostas históricas
            </h2>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl sm:px-6 lg:px-8 space-y-6">

            @if(session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-md bg-red-50 border border-red-200 p-4 text-sm text-red-800">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @unless($enabled)
                <div class="rounded-md bg-yellow-50 border border-yellow-200 p-4 text-sm text-yellow-800">
                    O cliente hp-history está desligado em <code>HP_HISTORY_ENABLED</code>.
                    O upload só vai funcionar depois de activares a flag no <code>.env</code> e
                    fazeres restart do PHP-FPM.
                </div>
            @endunless

            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-semibold text-gray-800 mb-1">Novo lote</h3>
                <p class="text-xs text-gray-500 mb-4">
                    Os ficheiros vão directamente para o pipeline de chunking + embedding do hp-history.
                    A indexação é idempotente — re-submeter o mesmo PDF não cria duplicados.
                </p>

                <form id="hp-upload-form"
                      method="POST"
                      action="{{ route('hp_history.upload.store') }}"
                      enctype="multipart/form-data"
                      class="space-y-5">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Domínio</label>
                            <select name="domain" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                <option value="">— sem tag —</option>
                                <option value="spares"   @selected(old('domain') === 'spares')>Peças (spares)</option>
                                <option value="marine"   @selected(old('domain') === 'marine')>Marinha</option>
                                <option value="military" @selected(old('domain') === 'military')>Militar</option>
                            </select>
                            <p class="mt-1 text-[11px] text-gray-500">
                                Usado para filtrar pesquisas semânticas mais à frente.
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Ano (opcional)</label>
                            <input type="number" name="year" min="1990" max="2100"
                                   value="{{ old('year') }}"
                                   placeholder="ex.: 2018"
                                   class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                        </div>
                    </div>

                    {{-- Drag-drop zone --}}
                    <div id="hp-dropzone"
                         class="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center cursor-pointer transition hover:bg-gray-100 hover:border-indigo-400">
                        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 7.5h-.75A2.25 2.25 0 0 0 4.5 9.75v7.5a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-7.5a2.25 2.25 0 0 0-2.25-2.25h-.75m-9 4.5 3.75-3.75m0 0 3.75 3.75M12 7.5v9" />
                        </svg>
                        <p class="mt-2 text-sm text-gray-700">
                            <span class="font-semibold text-indigo-600">Arrasta para aqui</span> ou clica para escolher
                        </p>
                        <p class="mt-1 text-[11px] text-gray-500">
                            PDF, TXT ou MD &middot; máx. {{ $maxFiles }} ficheiros &middot; {{ $maxMb }} MB cada
                        </p>
                        <input id="hp-file-input"
                               type="file"
                               name="files[]"
                               accept=".pdf,.txt,.md"
                               multiple
                               class="hidden">
                    </div>

                    <ul id="hp-file-list" class="space-y-1 text-sm"></ul>

                    <div class="flex items-center justify-between gap-3">
                        <p class="text-[11px] text-gray-500">
                            O upload pode demorar — o droplet faz embedding em sequência. PDFs grandes ≈ 30 s cada.
                        </p>
                        <div class="flex gap-2">
                            <button type="button" id="hp-clear-btn"
                                    class="rounded-md border border-gray-300 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 hidden">
                                Limpar
                            </button>
                            <button type="submit" id="hp-submit-btn"
                                    @disabled(!$enabled)
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed">
                                <span id="hp-submit-label">Enviar</span>
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="rounded-lg bg-white shadow-sm border border-gray-100 p-6 text-xs text-gray-600 space-y-2">
                <h4 class="text-sm font-semibold text-gray-800">Como o hp-history usa estes ficheiros</h4>
                <ol class="list-decimal list-inside space-y-1">
                    <li>Cada PDF é dividido em chunks de ~500 tokens com overlap.</li>
                    <li>Cada chunk é embeddado e guardado em pgvector na droplet hp-history.</li>
                    <li>Os agentes (Marco, Lúcia) podem citar passagens via RAG quando respondem a perguntas.</li>
                    <li>O download autenticado faz-se por UUID em <code>/hp-history/doc/{id}</code>.</li>
                </ol>
            </section>

        </div>
    </div>

    {{-- Drag-drop interactivity. Vanilla JS — no extra dependencies. --}}
    <script>
    (function () {
        const form    = document.getElementById('hp-upload-form');
        const drop    = document.getElementById('hp-dropzone');
        const input   = document.getElementById('hp-file-input');
        const list    = document.getElementById('hp-file-list');
        const clear   = document.getElementById('hp-clear-btn');
        const submit  = document.getElementById('hp-submit-btn');
        const label   = document.getElementById('hp-submit-label');

        const MAX_FILES = {{ (int) $maxFiles }};
        const MAX_BYTES = {{ (int) $maxMb }} * 1024 * 1024;
        const ALLOWED   = ['.pdf', '.txt', '.md'];

        // We use a DataTransfer to keep <input>.files in sync with the
        // user's curated picks (drag-drop AND remove-individual-file).
        let bag = new DataTransfer();

        function fmtBytes(n) {
            if (n < 1024) return n + ' B';
            if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
            return (n / 1024 / 1024).toFixed(1) + ' MB';
        }

        function extOk(name) {
            const dot = name.lastIndexOf('.');
            if (dot < 0) return false;
            return ALLOWED.indexOf(name.slice(dot).toLowerCase()) >= 0;
        }

        function render() {
            list.innerHTML = '';
            const files = Array.from(bag.files);
            files.forEach((f, idx) => {
                const li = document.createElement('li');
                li.className = 'flex items-center justify-between gap-3 rounded border border-gray-200 bg-gray-50 px-3 py-2';

                const ok = extOk(f.name) && f.size <= MAX_BYTES;
                const left = document.createElement('div');
                left.className = 'flex items-center gap-2 min-w-0';
                left.innerHTML =
                    '<span class="' + (ok ? 'text-indigo-600' : 'text-red-600') + '">' +
                    (ok ? '✓' : '✗') +
                    '</span>' +
                    '<span class="font-mono truncate" title="' + f.name + '">' + f.name + '</span>' +
                    '<span class="text-[11px] text-gray-500 shrink-0">' + fmtBytes(f.size) + '</span>';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'text-[11px] text-gray-500 hover:text-red-600';
                btn.textContent = 'remover';
                btn.addEventListener('click', () => {
                    const next = new DataTransfer();
                    Array.from(bag.files).forEach((g, j) => { if (j !== idx) next.items.add(g); });
                    bag = next;
                    input.files = bag.files;
                    render();
                });

                li.appendChild(left);
                li.appendChild(btn);
                list.appendChild(li);
            });

            if (files.length) {
                clear.classList.remove('hidden');
                label.textContent = 'Enviar ' + files.length + ' ficheiro' + (files.length === 1 ? '' : 's');
            } else {
                clear.classList.add('hidden');
                label.textContent = 'Enviar';
            }
        }

        function addFiles(fileList) {
            const incoming = Array.from(fileList);
            incoming.forEach((f) => {
                if (bag.files.length >= MAX_FILES) return;
                if (!extOk(f.name))               return;
                if (f.size > MAX_BYTES)           return;
                // Avoid obvious duplicates (same name + size).
                const dup = Array.from(bag.files).some((g) => g.name === f.name && g.size === f.size);
                if (dup) return;
                bag.items.add(f);
            });
            input.files = bag.files;
            render();
        }

        drop.addEventListener('click', () => input.click());
        input.addEventListener('change', (e) => addFiles(e.target.files));

        ['dragenter', 'dragover'].forEach((ev) =>
            drop.addEventListener(ev, (e) => {
                e.preventDefault(); e.stopPropagation();
                drop.classList.add('bg-indigo-50', 'border-indigo-400');
            })
        );
        ['dragleave', 'drop'].forEach((ev) =>
            drop.addEventListener(ev, (e) => {
                e.preventDefault(); e.stopPropagation();
                drop.classList.remove('bg-indigo-50', 'border-indigo-400');
            })
        );
        drop.addEventListener('drop', (e) => {
            if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
        });

        clear.addEventListener('click', () => {
            bag = new DataTransfer();
            input.files = bag.files;
            render();
        });

        // Disable double-submit + show pending state.
        form.addEventListener('submit', (e) => {
            if (!input.files || input.files.length === 0) {
                e.preventDefault();
                alert('Escolhe pelo menos um ficheiro.');
                return;
            }
            submit.disabled = true;
            label.textContent = 'A enviar… (pode demorar 30 s+ por PDF)';
        });
    })();
    </script>
</x-app-layout>
