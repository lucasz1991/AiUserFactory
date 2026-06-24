process.env.WEBMAIL_SESSION_PROVIDER = 'proton';
process.env.WEBMAIL_SESSION_SCRIPT_NAME = 'webmail_session_proton.cjs';

require('./webmail_session.cjs');
