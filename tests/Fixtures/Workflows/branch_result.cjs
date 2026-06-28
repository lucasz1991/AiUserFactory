'use strict';

async function run() {
  return {
    ok: true,
    status: 'not_found',
    statusMessage: 'Bedingung nicht erfuellt.',
    branchOutcome: 'failed',
  };
}

module.exports = { key: 'test.branch_result', run };
