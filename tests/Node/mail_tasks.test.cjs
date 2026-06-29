'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

const extractValueTask = require('../../node/workflows/tasks/mail/extract_value.cjs');
const {
  ageSecondsFromText,
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
