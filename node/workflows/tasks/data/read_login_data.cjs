'use strict';

function workflowContext(context = {}) {
  return context.workflow && typeof context.workflow === 'object' ? context.workflow : {};
}

function accountFromWorkflow(workflow = {}) {
  const person = workflow.person && typeof workflow.person === 'object' ? workflow.person : null;

  return (person && person.emailAccount)
    || workflow.account
    || workflow.email_account
    || null;
}

async function run(context = {}) {
  const workflow = workflowContext(context);
  const person = workflow.person || null;
  const account = accountFromWorkflow(workflow);
  const password = String(account?.password || '').trim();
  const hasPassword = account?.hasPassword === true || password !== '';

  if (!person) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Person fuer Login-Daten gefunden. Bitte den Test mit einer Person starten.',
    };
  }

  const email = String(account?.email || person.email || '').trim();
  const username = String(account?.username || email).trim();
  const webmailUrl = String(account?.webmailUrl || account?.webmail_url || '').trim();

  if (!email || !username || !hasPassword) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Login-Daten sind unvollstaendig.',
      account: {
        provider: account?.provider || 'proton',
        email,
        username,
        webmailUrl,
        hasPassword,
      },
    };
  }

  context.person = person;
  context.account = {
    provider: account?.provider || 'proton',
    email,
    username,
    password,
    webmailUrl,
  };

  return {
    ok: true,
    status: 'success',
    statusMessage: 'Login-Daten wurden vorbereitet.',
    person,
    account: {
      provider: context.account.provider,
      email: context.account.email,
      username: context.account.username,
      webmailUrl: context.account.webmailUrl,
      hasPassword: true,
    },
  };
}

module.exports = { key: 'data.read_login_data', run };
