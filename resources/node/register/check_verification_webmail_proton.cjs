process.env.VERIFICATION_WEBMAIL_CHECK_PROVIDER = 'proton';
process.env.VERIFICATION_WEBMAIL_CHECK_SCRIPT_NAME = 'check_verification_webmail_proton.cjs';

require('./check_verification_webmail.cjs');
