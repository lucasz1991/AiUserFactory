'use strict';

const workflowDataTask = require('./save_workflow_data.cjs');

module.exports = {
  ...workflowDataTask,
  key: 'data.persist_mail_account',
};
