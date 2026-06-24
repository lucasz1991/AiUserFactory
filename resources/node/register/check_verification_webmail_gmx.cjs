process.env.VERIFICATION_WEBMAIL_CHECK_PROVIDER = 'gmx';
process.env.VERIFICATION_WEBMAIL_CHECK_SCRIPT_NAME = 'check_verification_webmail_gmx.cjs';

require('./check_verification_webmail.cjs');
