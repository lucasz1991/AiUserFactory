'use strict';

const { RecorderError, inputBinding, point, resolvePublicTarget } = require('./security.cjs');
const { inspectPage } = require('./selectors.cjs');
const { resolveRecordedTarget, recheckRecordedTarget, runRecordedFill } = require('../workflows/tasks/lib/recorded-target.cjs');

class RecorderSession {
  constructor({ page, viewport = { width: 1280, height: 800 }, testingLocalHosts = false, maxEvents = 500, clock = Date.now, resolveTarget = resolvePublicTarget }) {
    this.page = page; this.viewport = viewport; this.testingLocalHosts = testingLocalHosts;
    this.clock = clock; this.resolveTarget = resolveTarget;
    this.maxEvents = Math.max(1, Math.min(500, Number(maxEvents) || 500));
    this.state = 'recording'; this.events = []; this.sequence = 0; this.selected = null;
    this.cursor = { x: 0, y: 0 }; this.pendingMove = null; this.startedAt = clock();
    this.privateSamples = new Set();
  }

  record(type, fields = {}) {
    if (this.state !== 'recording') return;
    if (this.events.length >= this.maxEvents) {
      this.state = 'paused';
      throw new RecorderError('event_limit', 'Die maximal zulaessige Anzahl an Aktionen wurde erreicht. Bitte zuerst speichern.');
    }
    const sequence = ++this.sequence;
    const event = { id: `event-${sequence}`, sequence, type, url: this.publicUrl(),
      recorded_at: new Date(this.clock()).toISOString(), ...fields };
    // State contains capture evidence only; preview samples never enter this structure.
    this.events.push(event);
  }

  publicUrl() {
    const url = this.page.url();
    if (url === 'about:blank') return url;
    try {
      const parsed = new URL(url); parsed.username = ''; parsed.password = '';
      for (const key of parsed.searchParams.keys()) {
        if (/password|passwd|secret|token|api.?key|\botp\b|\bpin\b|security.?code/i.test(key)) parsed.searchParams.set(key, '[geschuetzt]');
      }
      if (/password|passwd|secret|token|api.?key|security.?code/i.test(parsed.hash)) parsed.hash = '[geschuetzt]';
      return this.scrub(parsed.href);
    } catch { return ''; }
  }

  scrub(value) {
    let result = String(value || '');
    for (const sample of this.privateSamples) {
      result = result.split(sample).join('[geschuetzt]').split(encodeURIComponent(sample)).join('[geschuetzt]')
        .split(encodeURIComponent(sample).replace(/%20/g, '+')).join('[geschuetzt]');
    }
    return result;
  }

  checkedTarget(target) {
    if (target.selectors.some((selector) => this.scrub(selector) !== selector)
      || Object.values(target.target_signature || {}).some((value) => this.scrub(value) !== value)) {
      throw new RecorderError('sensitive_selector', 'Dieses Ziel enthaelt sensible Daten im Selektor. Bitte ein anderes stabiles Merkmal verwenden.');
    }
    return { ...target, label: this.scrub(target.label) };
  }

  targetFields(target) {
    return { selector: target.selector, selectors: target.selectors, label: target.label,
      input_type: target.input_type, sensitive: target.sensitive, scope: target.scope,
      target_signature: target.target_signature, stable_selectors: target.stable_selectors };
  }

  flushMove(force = false) {
    const pending = this.pendingMove;
    if (!pending || (!force && this.clock() - pending.lastAt < 200)) return;
    this.pendingMove = null;
    if (!pending.target.interactive || pending.distance < 6 || this.clock() - pending.firstAt < 120) return;
    this.record('hover', { ...this.targetFields(pending.target), mouse_path: pending.path });
  }

  async command(command) {
    if (!command || typeof command !== 'object' || Array.isArray(command)) throw new RecorderError('invalid_command', 'Die Browseraktion ist ungueltig.');
    const type = String(command.type || '');
    if (this.state === 'stopped' && type !== 'stop') throw new RecorderError('session_stopped', 'Die Aufnahme wurde bereits beendet.', 409);
    if (type === 'stop') { this.flushMove(true); this.state = 'stopped'; return this.snapshot(); }
    if (type === 'pause') { this.flushMove(true); this.state = 'paused'; return this.snapshot(); }
    if (type === 'resume') { this.state = 'recording'; return this.snapshot(); }
    if (!['navigate', 'click', 'move', 'scroll', 'key', 'fill', 'inspect'].includes(type)) {
      throw new RecorderError('invalid_command', 'Diese Browseraktion wird nicht unterstuetzt.');
    }
    // Paused viewing permits inspection only; unrecorded mutations cannot corrupt later replay.
    if (this.state !== 'recording' && type !== 'inspect') throw new RecorderError('session_paused', 'Die Aufnahme ist pausiert.', 409);
    if (type !== 'inspect' && this.events.length >= this.maxEvents) {
      this.state = 'paused';
      throw new RecorderError('event_limit', 'Die maximal zulaessige Anzahl an Aktionen wurde erreicht. Bitte zuerst speichern.');
    }
    if (type !== 'move' && type !== 'inspect') this.flushMove(true);
    if (type !== 'inspect' && this.events.length >= this.maxEvents) {
      this.state = 'paused';
      throw new RecorderError('event_limit', 'Die maximal zulaessige Anzahl an Aktionen wurde erreicht. Bitte zuerst speichern.');
    }
    if (type === 'navigate') {
      const target = await this.resolveTarget(command.url, { testingLocalHosts: this.testingLocalHosts });
      await this.page.goto(target.url.href, { waitUntil: 'domcontentloaded', timeout: 30000 });
      this.selected = null; this.pendingMove = null;
      this.record('navigate', { url: this.publicUrl() });
    }
    if (type === 'inspect' || type === 'click' || type === 'move') {
      const position = point(command, this.viewport);
      const target = this.checkedTarget(await inspectPage(this.page, position));
      if (type === 'inspect') this.selected = target;
      if (type === 'click') {
        // Select/OTP/password fields are controlled with explicit bindings, never raw keyboard events.
        const found = await resolveRecordedTarget(this.page, { selector: target.selector,
          recorded_target_signature: target.target_signature, recorded_stable_selectors: target.stable_selectors }, 0);
        try {
          await this.page.mouse.move(position.x, position.y, { steps: 1 });
          await recheckRecordedTarget(this.page, found);
          const reachable = await found.handle.evaluate((element, cursor) => {
            const hit = document.elementFromPoint(cursor.x, cursor.y);
            return hit === element || element.contains(hit);
          }, position);
          if (!reachable) throw new RecorderError('target_covered', 'Das ausgewaehlte Klickziel wird inzwischen von einem anderen Element verdeckt.');
          let pressed = false;
          try { await this.page.mouse.down({ button: 'left' }); pressed = true; }
          finally { if (pressed) await this.page.mouse.up({ button: 'left' }); }
        } finally { await found.handle.dispose(); }
        this.cursor = position; this.selected = target;
        this.record('click', this.targetFields(target));
      }
      if (type === 'move') {
        await this.page.mouse.move(position.x, position.y, { steps: 3 });
        const time = this.clock();
        if (this.pendingMove?.target.selector !== target.selector) this.flushMove(true);
        if (!this.pendingMove) this.pendingMove = { target, firstAt: time, lastAt: time, distance: 0, path: [] };
        const pending = this.pendingMove;
        pending.distance += Math.hypot(position.x - this.cursor.x, position.y - this.cursor.y);
        pending.lastAt = time;
        pending.path.push({ x: Number((position.x / this.viewport.width).toFixed(5)),
          y: Number((position.y / this.viewport.height).toFixed(5)), ms: Math.max(0, Math.min(10000, time - pending.firstAt)) });
        if (pending.path.length > 32) pending.path.splice(1, 1);
        this.cursor = position;
      }
    }
    if (type === 'scroll') {
      const delta = Math.round(Number(command.deltaY));
      if (!Number.isFinite(delta) || Math.abs(delta) < 1 || Math.abs(delta) > 2000) throw new RecorderError('invalid_scroll', 'Die Scrollstrecke ist ungueltig.');
      await this.page.mouse.wheel({ deltaY: delta });
      const direction = delta < 0 ? 'up' : 'down';
      // Every response can already be ingested by PHP. Published sequence entries are immutable.
      this.record('scroll', { direction, pixels: Math.abs(delta) });
    }
    if (type === 'key') {
      if (!['Enter', 'Tab'].includes(command.key)) throw new RecorderError('invalid_key', 'Text bitte ueber das Eingabefeld mit Wertquelle erfassen. Erlaubte Tasten: Enter, Tab.');
      await this.page.keyboard.press(command.key);
      this.record('key', { key: command.key });
    }
    if (type === 'fill') {
      const request = command.selector ? { selector: command.selector } : point(command, this.viewport);
      const target = this.checkedTarget(await inspectPage(this.page, request));
      if (!target.editable) throw new RecorderError('not_editable', 'Dieses Element kann nicht als Eingabefeld verwendet werden.');
      const binding = inputBinding(command, target.sensitive);
      const preview = binding.source === 'literal' ? binding.value : String(command.previewValue ?? '');
      if (preview.length > 8192) throw new RecorderError('invalid_binding', 'Der Beispielwert ist zu lang.');
      if (target.sensitive && preview) this.privateSamples.add(preview);
      // No sample means only the binding is recorded. Never type the variable/path name itself.
      if (binding.source === 'literal' || Object.prototype.hasOwnProperty.call(command, 'previewValue')) {
        const filled = await runRecordedFill({ page: this.page, input: { selector: target.selector,
          recorded_target_signature: target.target_signature, recorded_stable_selectors: target.stable_selectors,
          value_source: binding.source, workflow_variable: binding.workflow_variable } }, preview, 0, { typeDelayMs: 0 });
        if (!filled.ok) {
          throw new RecorderError(filled.recordedTargetError || 'fill_failed', filled.statusMessage);
        }
      }
      this.selected = target;
      this.record('fill', { ...this.targetFields(target), value_source: binding.source,
        ...(binding.source === 'workflow_variable' ? { workflow_variable: binding.workflow_variable } : { value: binding.value }) });
    }
    return this.snapshot();
  }

  async snapshot() {
    this.flushMove();
    return { state: this.state, url: this.publicUrl(), title: this.scrub(await this.page.title()).slice(0, 240),
      viewport: this.viewport, events: this.events, selected: this.selected, cursor: this.cursor };
  }

  async frame() {
    // Cover sensitive controls before capture; never change their values or put samples in HTML attributes.
    const token = `ff-recorder-mask-${Math.random().toString(36).slice(2)}`;
    await this.page.evaluate((maskToken) => {
      const fields = document.querySelectorAll('input,textarea,[contenteditable="true"]');
      const root = document.createElement('div'); root.id = maskToken;
      root.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483647;';
      for (const element of fields) {
        const label = element.getAttribute('aria-label') || element.labels?.[0]?.textContent || element.getAttribute('placeholder') || '';
        const sensitive = element.type === 'password' || /password|passwd|secret|token|api.?key|one-time-code|\botp\b|\bpin\b|security.?code/i
          .test([element.name, element.id, element.getAttribute('autocomplete'), label].join(' '));
        if (!sensitive) continue;
        const rect = element.getBoundingClientRect();
        const mask = document.createElement('div');
        mask.style.cssText = `position:absolute;left:${rect.left}px;top:${rect.top}px;width:${rect.width}px;height:${rect.height}px;background:#e2e8f0;border:1px solid #94a3b8;color:#475569;display:grid;place-items:center;overflow:hidden;font:12px sans-serif;`;
        mask.textContent = 'Geschuetzte Eingabe'; root.appendChild(mask);
      }
      document.documentElement.appendChild(root);
    }, token);
    try { return await this.page.screenshot({ type: 'jpeg', quality: 65, fullPage: false }); }
    finally { await this.page.evaluate((maskToken) => document.getElementById(maskToken)?.remove(), token).catch(() => {}); }
  }
}

module.exports = { RecorderSession };
