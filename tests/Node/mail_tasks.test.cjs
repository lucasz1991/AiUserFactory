'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const extractValueTask = require('../../node/workflows/tasks/mail/extract_value.cjs');
const {
  ageSecondsFromText,
  mailReceivedTimeFromText,
  scanMailList,
  extractValueFromText,
  mailMatches,
} = require('../../node/workflows/tasks/lib/mail_list.cjs');

test('mail helper extracts a verification code from text', () => {
  const result = extractValueFromText('Dein Sicherheitscode lautet 123 456 und ist zehn Minuten gueltig.');

  assert.equal(result.value, '123456');
});

test('mail helper parses relative mail ages', () => {
  assert.equal(ageSecondsFromText('vor 7 Minuten'), 420);
  assert.equal(ageSecondsFromText('just now'), 0);
});

test('mail helper converts configured GMT mail time to the same absolute browser instant', () => {
  const now = new Date('2026-07-02T06:10:00.000Z');
  const utcMail = mailReceivedTimeFromText('02.07.2026 06:00', now, { sourceGmtOffsetHours: 0 });
  const berlinMail = mailReceivedTimeFromText('02.07.2026 08:00', now, { sourceGmtOffsetHours: 2 });

  assert.equal(utcMail.receivedAt, '2026-07-02T06:00:00.000Z');
  assert.equal(berlinMail.receivedAt, '2026-07-02T06:00:00.000Z');
  assert.equal(utcMail.ageSeconds, 600);
  assert.equal(berlinMail.ageSeconds, 600);
});

test('mail helper applies GMT offset to time-only inbox values', () => {
  const now = new Date('2026-07-02T06:10:00.000Z');
  const received = mailReceivedTimeFromText('06:00', now, { sourceGmtOffsetHours: 0 });

  assert.equal(received.receivedAt, '2026-07-02T06:00:00.000Z');
  assert.equal(received.ageSeconds, 600);
});

test('mail list scan exposes UTC and browser-local received times', async () => {
  const frame = {
    async evaluate(_callback, payload) {
      if (!payload) {
        return {
          nowMs: Date.parse('2026-07-02T06:10:00.000Z'),
          timezone: 'Europe/Berlin',
          offsetMinutes: 120,
        };
      }

      return [{
        token: 'mail-1',
        selector: 'li',
        selectorIndex: 0,
        subject: 'Testmail',
        title: 'Testmail',
        sender: 'sender@example.test',
        dateText: '02.07.2026 06:00',
        preview: '',
        text: 'Testmail 02.07.2026 06:00',
      }];
    },
    url: () => 'https://mail.example.test',
    name: () => 'inbox',
  };
  const [mail] = await scanMailList({ frames: () => [frame] }, {
    mail_time_gmt_offset_hours: 0,
    max_items: 1,
  });

  assert.equal(mail.receivedAt, '2026-07-02T06:00:00.000Z');
  assert.match(mail.receivedAtBrowser, /08:00:00/);
  assert.equal(mail.receivedAtBrowserTimezone, 'Europe/Berlin');
  assert.equal(mail.browserGmtOffsetHours, 2);
  assert.equal(mail.ageSeconds, 600);
});

test('mail helper matches configured fields', () => {
  assert.equal(mailMatches({ subject: 'Instagram code', sender: 'noreply@example.test' }, 'instagram', ['subject']), true);
  assert.equal(mailMatches({ subject: 'Newsletter' }, 'instagram', ['subject']), false);
});

test('mail.extract_value stores configurable output variable from workflow source', async () => {
  const context = {
    input: {
      value: '{"source":"workflow_variables.matched_mail.body","output_value_name":"verification_code","extract_mode":"verification_code"}',
    },
    workflow_variables: {
      matched_mail: {
        body: 'Use verification code 654321 to continue.',
      },
    },
  };

  const result = await extractValueTask.run(context);

  assert.equal(result.ok, true);
  assert.equal(result.extracted_value, '654321');
  assert.equal(context.workflow_variables.verification_code, '654321');
});
