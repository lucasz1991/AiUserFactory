'use strict';

function normalizeText(value) {
  return String(value ?? '').trim();
}

function normalizeMailboxSource(value) {
  const normalized = normalizeText(value).toLowerCase();

  return ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'].includes(normalized)
    ? 'verification'
    : 'person';
}

function personAccountFromContext(context = {}) {
  const workflow = context.workflow || {};
  const person = context.person || workflow.person || {};
  const workflowAccount = workflow.account
    || workflow.email_account
    || person.emailAccount
    || person.email_account
    || {};

  return {
    ...workflowAccount,
    ...(context.account || {}),
    webmailSession: context.account?.webmailSession || workflowAccount.webmailSession || workflowAccount.webmail_session || null,
    webmail_session: context.account?.webmail_session || workflowAccount.webmail_session || workflowAccount.webmailSession || null,
  };
}

function verificationAccountFromContext(context = {}) {
  const workflow = context.workflow || {};
  const verificationAccount = context.verificationMailbox
    || context.verification_mailbox
    || context.veri_account
    || context['veri-account']
    || workflow.verificationMailbox
    || workflow.verification_mailbox
    || workflow.veri_account
    || workflow['veri-account']
    || {};

  return {
    ...verificationAccount,
    webmailSession: verificationAccount.webmailSession || verificationAccount.webmail_session || null,
    webmail_session: verificationAccount.webmail_session || verificationAccount.webmailSession || null,
  };
}

function accountFromContext(context = {}, input = {}) {
  const mailboxSource = normalizeMailboxSource(input.mailbox_source || input.mailboxSource || input.account_source || input.accountSource || input.value || 'person');
  const account = mailboxSource === 'verification'
    ? verificationAccountFromContext(context)
    : personAccountFromContext(context);

  return { account, mailboxSource };
}

module.exports = {
  accountFromContext,
  normalizeMailboxSource,
  normalizeText,
};
