const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const view = fs.readFileSync(path.join(__dirname, '../../resources/views/components/ui/dropdown.blade.php'), 'utf8');
const expression = view.match(/x-data="([\s\S]*?)"/)[1];

function dropdown(height = 600) {
    const document = { activeElement: null };
    const state = vm.runInNewContext(`(${expression})`, { window: { innerHeight: height }, document });
    state.$refs = {};
    state.$nextTick = (callback) => callback();
    return { state, document };
}

function item(document, top, bottom) {
    return {
        getBoundingClientRect: () => ({ top, bottom }),
        focus(options) {
            assert.equal(options.preventScroll, true);
            document.activeElement = this;
        },
    };
}

test('dropdown uses available room below the trigger in a short viewport', () => {
    const { state } = dropdown();
    state.open = true;
    state.$refs.trigger = { getBoundingClientRect: () => ({ top: 95, bottom: 139 }) };
    assert.equal(state.panelMaxHeight(), 445);
    state.viewportHeight = 400;
    assert.equal(state.panelMaxHeight(), 245);
});

test('dropdown can flip above a low trigger and never exceeds the viewport', () => {
    const { state } = dropdown();
    state.open = true;
    state.$refs.trigger = { getBoundingClientRect: () => ({ top: 510, bottom: 554 }) };
    assert.equal(state.panelMaxHeight(), 494);
    state.$refs.trigger.getBoundingClientRect = () => ({ top: -200, bottom: -100 });
    assert.equal(state.panelMaxHeight(), 584);
});

test('keyboard focus scrolls only the dropdown panel, in both directions', () => {
    const { state, document } = dropdown();
    const panel = { scrollTop: 0, getBoundingClientRect: () => ({ top: 147, bottom: 592 }) };
    state.$refs.panel = panel;
    state.focusItem(item(document, 790, 830));
    assert.equal(panel.scrollTop, 238);
    state.focusItem(item(document, 100, 140));
    assert.equal(panel.scrollTop, 191);
    state.focusItem(item(document, 300, 340));
    assert.equal(panel.scrollTop, 191);
});

test('opening, arrow navigation and focus restoration reuse the visible trigger', () => {
    const { state, document } = dropdown();
    state.$refs.panel = { scrollTop: 0, getBoundingClientRect: () => ({ top: 100, bottom: 500 }) };
    const items = [item(document, 110, 150), item(document, 160, 200), item(document, 650, 690)];
    const trigger = item(document, 50, 90);
    state.$refs.trigger = { querySelector: () => trigger };
    state.menuItems = () => items;
    state.show('last');
    assert.equal(state.open, true);
    assert.equal(document.activeElement, items[2]);
    assert.equal(state.$refs.panel.scrollTop, 190);
    state.moveFocus(1);
    assert.equal(document.activeElement, items[0]);
    state.hide(true);
    assert.equal(state.open, false);
    assert.equal(document.activeElement, trigger);
});
