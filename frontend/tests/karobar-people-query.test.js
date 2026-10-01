const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.resolve(__dirname, '..', 'assets/js/karobar-api.js'), 'utf8');
let requestedEndpoint = null;
const context = {
    URLSearchParams,
    window: {},
    api: {
        get: async endpoint => { requestedEndpoint = endpoint; return { success:true }; },
        constructor: { invalidateCache() {} },
    },
};
vm.createContext(context);
vm.runInContext(source, context);

(async () => {
    await context.window.karobarAPI.getPeople({ status:'active', search:undefined, page:1, limit:20 });
    if (requestedEndpoint !== '/people?status=active&page=1&limit=20') {
        throw new Error(`empty People filters leaked into the query: ${requestedEndpoint}`);
    }
    await context.window.karobarAPI.getPeople({ status:'active', search:'Mummy', page:1, limit:20 });
    if (!requestedEndpoint.includes('search=Mummy')) throw new Error('a real People search was omitted');
    console.log('PASS: Karobar People omits empty filters and preserves real searches');
})().catch(error => { console.error(error); process.exit(1); });
