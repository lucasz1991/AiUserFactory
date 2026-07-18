<div
    wire:key="{{ $prefix }}-workflow-input-definitions-{{ \Illuminate\Support\Str::slug($catalogKey) }}"
    class="mt-1 rounded-xl border border-slate-200 bg-slate-50 p-3"
    x-data="{
        serialized: @entangle($fieldModel).live,
        rows: [],
        init() {
            this.rows = this.decode(this.serialized);
            this.$watch('rows', () => this.sync());
        },
        decode(value) {
            if (Array.isArray(value)) return value.map((row) => this.normalize(row));
            if (! String(value || '').trim()) return [];
            try {
                const parsed = JSON.parse(String(value));
                return Array.isArray(parsed) ? parsed.map((row) => this.normalize(row)) : [];
            } catch (_error) {
                return [];
            }
        },
        normalize(row = {}) {
            return {
                name: String(row.name || row.variable || row.key || ''),
                source: String(row.source || row.path || ''),
                type: String(row.type || row.kind || 'string'),
                required: row.required === true || ['1', 'true', 'required', 'pflicht'].includes(String(row.required || '').toLowerCase()),
                defaultValue: row.default ?? row.default_value ?? row.defaultValue ?? '',
                requireOpen: row.require_open !== false && row.requireOpen !== false,
                targetTask: String(row.target_task || row.targetTask || ''),
                inputId: String(row.input_id || row.inputId || ''),
            };
        },
        addRow() {
            this.rows.push(this.normalize());
        },
        removeRow(index) {
            this.rows.splice(index, 1);
        },
        serializedDefault(row) {
            const value = row.defaultValue;
            if (value === '' || value === null || value === undefined) return undefined;
            if (row.type === 'number' && String(value).trim() !== '' && Number.isFinite(Number(value))) return Number(value);
            if (row.type === 'boolean') return ['1', 'true', 'yes', 'ja', 'on'].includes(String(value).toLowerCase());
            if (row.type === 'json') {
                try { return JSON.parse(String(value)); } catch (_error) { return value; }
            }
            return value;
        },
        sync() {
            const definitions = this.rows.map((row) => {
                const definition = {
                    name: String(row.name || '').trim(),
                    required: Boolean(row.required),
                };
                const source = String(row.source || '').trim();
                const type = String(row.type || 'string').trim();
                const targetTask = String(row.targetTask || '').trim();
                const inputId = String(row.inputId || '').trim();
                const defaultValue = this.serializedDefault(row);

                if (source && source !== definition.name) definition.source = source;
                if (type && type !== 'string') definition.type = type;
                if (defaultValue !== undefined) definition.default = defaultValue;
                if (type === 'browser_window' && row.requireOpen === false) definition.require_open = false;
                if (targetTask) definition.target_task = targetTask;
                if (inputId) definition.input_id = inputId;

                return definition;
            }).filter((definition) => definition.name !== '');

            this.serialized = JSON.stringify(definitions);
        },
    }"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="text-xs font-bold text-slate-800">Eingabevariablen</p>
            <p class="mt-0.5 text-[11px] text-slate-500">Nur fehlende Variablen mit aktivierter Pflichtangabe führen zur Fehlerroute.</p>
        </div>
        <button type="button" x-on:click="addRow()" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-slate-700">
            + Variable
        </button>
    </div>

    <div class="mt-3 space-y-2">
        <template x-for="(row, index) in rows" :key="index">
            <article class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                <div class="grid gap-3 md:grid-cols-[minmax(180px,1.5fr)_minmax(120px,.8fr)_minmax(140px,1fr)_auto_auto] md:items-end">
                    <label class="block">
                        <span class="text-[11px] font-bold text-slate-600">Variablenname</span>
                        <input type="text" x-model="row.name" placeholder="google_search_url" class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-bold text-slate-600">Typ</span>
                        <select x-model="row.type" class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                            <option value="string">Text</option>
                            <option value="number">Zahl</option>
                            <option value="boolean">Ja/Nein</option>
                            <option value="json">JSON</option>
                            <option value="browser_window">Browserfenster</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-[11px] font-bold text-slate-600">Default</span>
                        <input type="text" x-model="row.defaultValue" placeholder="optional" class="mt-1 block w-full rounded-lg border-slate-300 text-sm focus:border-cyan-500 focus:ring-cyan-500">
                    </label>
                    <label class="inline-flex h-10 items-center gap-2 rounded-lg border px-3 text-xs font-bold" :class="row.required ? 'border-rose-300 bg-rose-50 text-rose-800' : 'border-slate-200 bg-slate-50 text-slate-600'">
                        <input type="checkbox" x-model="row.required" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                        Pflicht
                    </label>
                    <button type="button" x-on:click="removeRow(index)" class="h-10 rounded-lg border border-rose-200 px-3 text-xs font-bold text-rose-700 hover:bg-rose-50">Entfernen</button>
                </div>

                <details class="mt-2 rounded-lg bg-slate-50 px-3 py-2">
                    <summary class="cursor-pointer text-[11px] font-bold text-slate-600">Quelle und Task-Übernahme</summary>
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        <label class="block">
                            <span class="text-[11px] font-bold text-slate-600">Quelle</span>
                            <input type="text" x-model="row.source" placeholder="leer = Variablenname" class="mt-1 block w-full rounded-lg border-slate-300 text-xs focus:border-cyan-500 focus:ring-cyan-500">
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-bold text-slate-600">Ziel-Task</span>
                            <input type="text" x-model="row.targetTask" placeholder="Tasktitel" class="mt-1 block w-full rounded-lg border-slate-300 text-xs focus:border-cyan-500 focus:ring-cyan-500">
                        </label>
                        <label class="block">
                            <span class="text-[11px] font-bold text-slate-600">Zielfeld</span>
                            <input type="text" x-model="row.inputId" placeholder="z. B. search_count" class="mt-1 block w-full rounded-lg border-slate-300 text-xs focus:border-cyan-500 focus:ring-cyan-500">
                        </label>
                    </div>
                    <label x-show="row.type === 'browser_window'" class="mt-3 inline-flex items-center gap-2 text-[11px] font-semibold text-slate-600">
                        <input type="checkbox" x-model="row.requireOpen" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">
                        Browserfenster muss bei Pflichtvariablen geöffnet sein
                    </label>
                </details>
            </article>
        </template>

        <div x-show="rows.length === 0" class="rounded-lg border border-dashed border-slate-300 px-4 py-6 text-center text-xs text-slate-500">
            Noch keine Eingabevariable definiert. Ohne Pflichtvariable läuft die Task über die Erfolgsroute.
        </div>
    </div>

</div>
