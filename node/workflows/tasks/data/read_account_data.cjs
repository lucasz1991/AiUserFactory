'use strict';

function workflowContext(context = {}) {
  return context.workflow && typeof context.workflow === 'object' ? context.workflow : {};
}

function accountFromContext(context = {}) {
  const workflow = workflowContext(context);
  const person = workflow.person && typeof workflow.person === 'object' ? workflow.person : null;

  return context.account
    || context.lastResult?.account
    || workflow.account
    || workflow.email_account
    || (person ? person.emailAccount : null)
    || null;
}

function publicAccount(account = {}) {
  const copy = { ...account };

  delete copy.password;
  delete copy.password_encrypted;

  if (account.password || account.password_encrypted || account.hasPassword === true) {
    copy.hasPassword = true;
  }

  return copy;
}

async function run(context = {}) {
  const account = accountFromContext(context);

  if (!account) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Accountdaten im Ergebnis gefunden.',
    };
  }

  context.account = account;

  return {
    ok: true,
    status: 'success',
    statusMessage: 'Accountdaten wurden gelesen.',
    account: publicAccount(account),
  };
}

module.exports = { key: 'data.read_account_data', run };
