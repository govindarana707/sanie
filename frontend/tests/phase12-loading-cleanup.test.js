const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('frontend/assets/js/ajax.js', 'utf8');

const button = {
    dataset: {}, disabled: false, innerHTML: '<span>Save</span>', offsetWidth: 100,
    style: {}, tagName: 'BUTTON', role: 'button'
};
let notifications = 0;
const context = {
    window: {
        Api: {
            post: async () => { throw Object.assign(new Error('Request timed out.'), { category: 'timeout_error', code: 'TIMEOUT_ERROR' }); }
        },
        NotificationService: { error() { notifications++; } }
    },
    NotificationService: { error() { notifications++; } },
    document: { querySelector: () => button, dispatchEvent() {} },
    CustomEvent: class CustomEvent {}, setTimeout, clearTimeout, console: { error() {} }
};
vm.runInNewContext(source, context);

(async () => {
    const error = await context.window.AjaxService.post('/hang', {}, { loading: button }).catch(value => value);
    if (error.category !== 'timeout_error') throw new Error('Timeout was not surfaced to the caller');
    if (button.disabled || button.dataset.loading === 'true' || button.innerHTML !== '<span>Save</span>') throw new Error('Timeout left the submit button loading');
    if (notifications !== 1) throw new Error('Timeout did not surface one safe user-facing error');

    let release;
    context.window.Api.post = () => new Promise(resolve => { release = resolve; });
    const first = context.window.AjaxService.post('/duplicate', { value: 1 }, { loading: button });
    const duplicate = await context.window.AjaxService.post('/duplicate', { value: 1 }, { loading: button });
    if (duplicate !== undefined || !button.disabled) throw new Error('Duplicate submission was not ignored safely');
    release({ success: true });
    await first;
    if (button.disabled || button.dataset.loading === 'true') throw new Error('Successful completion did not clear loading state');

    process.stdout.write('PASS: Phase 12 loading state settles and duplicate submissions do not lock controls\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
