'use strict';

function workflowContext(context = {}) {
  return context.workflow && typeof context.workflow === 'object' ? context.workflow : {};
}

async function run(context = {}) {
  const workflow = workflowContext(context);
  const person = workflow.person || null;

  if (!person) {
    return {
      ok: false,
      status: 'failed',
      statusMessage: 'Keine Person fuer den Workflow-Task gefunden. Bitte den Test mit einer Person starten.',
    };
  }

  context.person = person;

  return {
    ok: true,
    status: 'success',
    statusMessage: 'Person-Daten wurden ermittelt.',
    person,
  };
}

module.exports = { key: 'data.resolve_person', run };
