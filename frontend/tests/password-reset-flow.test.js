const fs = require('fs');

const html = fs.readFileSync('frontend/index.html', 'utf8');
const auth = fs.readFileSync('frontend/assets/js/auth.js', 'utf8');
const api = fs.readFileSync('frontend/assets/js/api.js', 'utf8');

function assert(condition, message) { if (!condition) throw new Error(message); }

assert(/id="forgot-password-link"/.test(html), 'Login does not expose the forgot-password action');
assert(/id="forgot-password-form"/.test(html) && /id="forgot-password-email"/.test(html), 'Request form is missing');
assert(/id="reset-password-form"/.test(html), 'Reset form is missing');
assert(/id="reset-new-password"/.test(html) && /id="reset-confirm-password"/.test(html), 'Reset password fields are incomplete');
assert(/minlength="12" maxlength="72"/.test(html), 'Reset form does not mirror the existing password bounds');
assert(auth.includes('If an eligible account exists'), 'Forgot-password confirmation is not enumeration-neutral');
assert(auth.includes('invalid, expired, or has already been used'), 'Reset form lacks invalid/expired/used handling');
assert(auth.includes('Password confirmation does not match.'), 'Reset form lacks confirmation mismatch handling');
assert(auth.includes("this.switchAuthTab('login')"), 'Successful reset does not return to login');
assert(api.includes("'/auth/forgot-password'"), 'Forgot-password API route is not wired');
assert(api.includes("'/auth/reset-password/validate'"), 'Reset validation API route is not wired');
assert(api.includes("'/auth/reset-password'"), 'Reset API route is not wired');
assert(!auth.includes('console.log(this.resetToken)') && !auth.includes('console.log(token)'), 'Reset token is logged by the UI');

process.stdout.write('PASS: forgot-password and reset-password frontend flow is wired with neutral and failure states\n');
