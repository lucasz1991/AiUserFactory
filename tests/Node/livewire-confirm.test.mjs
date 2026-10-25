import assert from 'node:assert/strict';
import test from 'node:test';

class FakeElement {
    constructor(attributes) {
        this.attributes = Object.entries(attributes).map(([name, value]) => ({ name, value }));
    }
}

globalThis.HTMLElement = FakeElement;
globalThis.window = globalThis;

const { installLivewireConfirmDialog } = await import('../../resources/js/livewire-confirm.js');
installLivewireConfirmDialog();

function dialogAnswering(isConfirmed, seen) {
    window.Swal = { fire: async (options) => { seen.push(options.text); return { isConfirmed }; } };
}

test('wire:confirm uses the in-app dialog and runs the action only when confirmed', async () => {
    const seen = [];
    const calls = [];
    const el = new FakeElement({ 'wire:click': 'stopRun', 'wire:confirm': 'Diesen Lauf wirklich stoppen?' });
    dialogAnswering(true, seen);
    el.__livewire_confirm = () => calls.push('native');

    el.__livewire_confirm(() => calls.push('action'), () => calls.push('instead'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.deepEqual(seen, ['Diesen Lauf wirklich stoppen?']);
    assert.deepEqual(calls, ['action']);
});

test('a cancelled dialog runs the fallback instead of the action', async () => {
    const calls = [];
    const el = new FakeElement({ 'wire:confirm': 'Wirklich?' });
    dialogAnswering(false, []);
    el.__livewire_confirm = () => calls.push('native');

    el.__livewire_confirm(() => calls.push('action'), () => calls.push('instead'));
    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.deepEqual(calls, ['instead']);
});

test('wire:confirm.prompt keeps the native Livewire behaviour', () => {
    const calls = [];
    const el = new FakeElement({ 'wire:confirm.prompt': 'Tippe LOESCHEN|LOESCHEN' });
    dialogAnswering(true, []);
    el.__livewire_confirm = () => calls.push('native');

    el.__livewire_confirm(() => calls.push('action'), () => calls.push('instead'));

    assert.deepEqual(calls, ['native']);
});
