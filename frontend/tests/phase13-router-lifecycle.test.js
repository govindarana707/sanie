const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('frontend/assets/js/router.js', 'utf8');

function classList() {
    const values = new Set();
    return { add: value => values.add(value), remove: value => values.delete(value), contains: value => values.has(value), toggle: value => values.has(value) ? values.delete(value) : values.add(value) };
}

const elements = new Map(['detail-page', 'other-page'].map(id => [id, { id, classList: classList() }]));
const windowListeners = new Map();
const historyEntries = [];
const errors = [];
const context = {
    window: {
        authManager: { isAuthenticated: () => true },
        addEventListener: (name, handler) => windowListeners.set(name, handler),
        location: { hash: '' }
    },
    document: {
        querySelectorAll: selector => selector === '.page' ? [...elements.values()] : [],
        querySelector: () => null,
        getElementById: id => elements.get(id) || null
    },
    history: { pushState: (state, title, hash) => historyEntries.push({ state, hash }) },
    URLSearchParams, AbortController, setTimeout, clearTimeout,
    console: { error: (...args) => errors.push(args) }
};
vm.runInNewContext(source, context);
const router = new context.window.RouterService();

const pending = new Map();
let visible = null;
let mountCount = 0;
let activeListeners = 0;
let maxListeners = 0;
const detailModule = {
    routeQueryKeys: ['id', 'page'],
    onMount(route) {
        mountCount++;
        activeListeners++;
        maxListeners = Math.max(maxListeners, activeListeners);
        const id = route.query.get('id');
        visible = `loading:${id}`;
        return new Promise((resolve, reject) => {
            const abort = () => reject(Object.assign(new Error('cancelled'), { category: 'aborted_error', code: 'ABORTED_ERROR' }));
            route.signal.addEventListener('abort', abort, { once: true });
            pending.set(id, value => {
                route.signal.removeEventListener('abort', abort);
                if (route.isCurrent()) visible = value;
                resolve();
            });
        });
    },
    onUnmount() { activeListeners = Math.max(0, activeListeners - 1); }
};
router.registerRoute('detail', detailModule);
router.registerRoute('other', { onMount() {}, onUnmount() {} });
router.init();

(async () => {
    await router.navigate('detail?id=1');
    if (visible !== 'loading:1') throw new Error('First detail did not enter loading state');
    await router.navigate('detail?id=2');
    if (visible !== 'loading:2') throw new Error('ID change retained the old detail');
    pending.get('2')('entity:2');
    pending.get('1')?.('entity:1');
    await new Promise(resolve => setTimeout(resolve, 0));
    if (visible !== 'entity:2') throw new Error('Late entity 1 response overwrote entity 2');

    await router.navigate('detail?id=3');
    await router.navigate('detail?id=4');
    await router.navigate('detail?id=5');
    pending.get('5')('entity:5');
    pending.get('4')?.('entity:4');
    pending.get('3')?.('entity:3');
    await new Promise(resolve => setTimeout(resolve, 0));
    if (visible !== 'entity:5' || router.currentRouteIdentity !== 'detail?id=5') throw new Error('Rapid navigation did not finish on the newest ID');

    const beforeIrrelevant = mountCount;
    await router.navigate('detail?foo=ignored&id=5');
    if (mountCount !== beforeIrrelevant) throw new Error('Irrelevant query change reloaded the detail');
    await router.navigate('detail?id=5&page=2');
    if (mountCount !== beforeIrrelevant + 1) throw new Error('Relevant page query did not reload the detail');
    if (historyEntries.at(-1).state.page !== 'detail?id=5&page=2') throw new Error('History did not retain full route parameters');

    context.window.location.hash = '#detail?id=2';
    windowListeners.get('popstate')({ state: { page: 'detail' } });
    await new Promise(resolve => setTimeout(resolve, 0));
    if (router.currentRouteIdentity !== 'detail?id=2') throw new Error('Back/forward navigation did not restore the correct ID');

    await router.navigate('other');
    if (activeListeners !== 0 || maxListeners > 1) throw new Error('Route listeners accumulated across detail remounts');
    if (errors.length !== 0) throw new Error('Intentional route abort produced a router error');

    process.stdout.write('PASS: Phase 13 router identity, rapid navigation, late-response, query, history, abort, and listener invariants\n');
})().catch(error => {
    process.stderr.write(`FAIL: ${error.message}\n`);
    process.exitCode = 1;
});
