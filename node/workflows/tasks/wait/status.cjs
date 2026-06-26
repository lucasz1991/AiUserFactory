'use strict';

async function run(context = {}) {
  const page = context.page;
  const input = context.input || {};
  const rules = Array.isArray(input.rules) ? input.rules : [];

  if (!page || typeof page.evaluate !== 'function') {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer Status-Auswertung vorhanden.' };
  }

  const snapshot = await page.evaluate(() => ({
    url: window.location.href,
    title: document.title,
    text: document.body ? document.body.innerText.slice(0, 20000) : '',
  }));

  for (const rule of rules) {
    const source = rule.source === 'url' ? snapshot.url : (rule.source === 'title' ? snapshot.title : snapshot.text);
    const pattern = String(rule.contains || '').toLowerCase();

    if (pattern !== '' && source.toLowerCase().includes(pattern)) {
      const status = String(rule.status || 'success');

      return {
        ok: status !== 'failed',
        status,
        statusMessage: rule.message || `Status-Regel getroffen: ${pattern}`,
        matchedRule: rule,
      };
    }
  }

  return {
    ok: true,
    status: 'partial',
    statusMessage: 'Keine Statusregel getroffen; Weiterleitung kann ueber partial erfolgen.',
    snapshot,
  };
}

module.exports = { key: 'wait.status', run };
