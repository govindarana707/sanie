const fs=require('fs');const path=require('path');const root=path.resolve(__dirname,'..','assets','js');
const karobar=fs.readFileSync(path.join(root,'karobar.js'),'utf8');const api=fs.readFileSync(path.join(root,'karobar-api.js'),'utf8');const transactions=fs.readFileSync(path.join(root,'transactions.js'),'utf8');

if(karobar.includes('This will also delete all their transactions'))throw new Error('Person removal still warns that financial history will be deleted');
if(!karobar.includes('financial history exists')||!karobar.includes("result.data?.action === 'archived'"))throw new Error('Person removal UI does not communicate archive-versus-delete behavior');
if(!api.includes('invalidatePersonCaches'))throw new Error('Person lifecycle does not invalidate targeted caches');
for(const pattern of ['/people','/karobar','/dashboard','/reports'])if(!api.includes(`'${pattern}'`))throw new Error(`Person cache invalidation is missing ${pattern}`);
if(!transactions.includes("Api.get('/people?status=active&page=1&limit=200')"))throw new Error('Credit purchase selector does not exclude archived people');
if(!karobar.includes('(Archived)')||!transactions.includes("+' (Archived)'"))throw new Error('Historical transaction editors cannot resolve archived people');

console.log('PASS: person UI preserves history messaging, active-only selectors, archived identity display, and cache invalidation');
