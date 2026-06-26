'use strict';

function publicAccount(account = {}) {
  const copy = { ...account };
  const hasPassword = Boolean(copy.password || copy.password_encrypted || copy.hasPassword === true);

  delete copy.password;
  delete copy.password_encrypted;

  if (hasPassword) {
    copy.hasPassword = true;
  }

  return copy;
}

async function run(context = {}) {
  const account = context.account || context.lastResult?.account || null;

  if (!account || !account.email) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Mail-Accountdaten zum Speichern vorhanden.',
    };
  }

  return {
    ok: true,
    status: 'success',
    statusMessage: 'Mail-Accountdaten wurden fuer Laravel-Persistierung vorbereitet.',
    account: publicAccount(account),
  };
}

module.exports = { key: 'data.persist_mail_account', run };
