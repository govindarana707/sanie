const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const source = fs.readFileSync('frontend/assets/js/goals.js', 'utf8');
const savings = fs.readFileSync('frontend/assets/js/savings.js', 'utf8');
const api = fs.readFileSync('frontend/assets/js/api.js', 'utf8');
const context = { window: {}, console, Set, Date, Math };
vm.runInNewContext(source, context);
const manager = new context.window.GoalsManager();

assert(manager._validMoney('1', false));
assert(manager._validMoney('1.25', false));
assert(manager._validMoney('0', true));
for (const value of ['0', '-1', '1.001', 'NaN', 'Infinity', '1000000000000']) assert(!manager._validMoney(value, false), `invalid target accepted: ${value}`);
assert(source.includes("goal.status === 'active'"), 'goal actions do not depend on active status');
assert(source.includes("goal.status === 'paused'"), 'paused goal actions are missing');
assert(source.includes('showEditGoalModal') && source.includes('changeGoalStatus'), 'edit/pause/resume actions are incomplete');
assert(source.includes("status !== 'active'"), 'contribution modal is not guarded by goal status');
assert(source.includes('base_version: Number(goal.version)'), 'lifecycle updates omit optimistic goal version');
assert(savings.includes("goal.status === 'active'"), 'Savings still offers contributions to paused/completed goals');
assert(api.includes('async update(id, data)'), 'goal definition update API is missing');
assert(source.includes('DateUtils.getKathmanduDateString()'), 'Kathmandu contribution date behavior changed');
console.log('PASS: goal edit, lifecycle actions, strict validation, active-only contribution UI, versions, and Kathmandu dates are wired');
