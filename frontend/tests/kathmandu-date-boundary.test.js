const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const context = { window: {}, console, Intl, Date, Object, String, Number, TypeError };
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'utils.js'), 'utf8'), context);
const DateUtils = context.window.DateUtils;

const cases = [
    ['00:01', '2026-08-24T18:16:00.000Z', '2026-08-25'],
    ['05:44', '2026-08-24T23:59:00.000Z', '2026-08-25'],
    ['05:45', '2026-08-25T00:00:00.000Z', '2026-08-25'],
    ['midday', '2026-08-25T06:15:00.000Z', '2026-08-25'],
    ['month boundary', '2026-08-31T18:30:00.000Z', '2026-09-01'],
    ['year boundary', '2026-12-31T18:30:00.000Z', '2027-01-01']
];

for (const [label, instant, expected] of cases) {
    assert.strictEqual(DateUtils.getKathmanduDateString(new Date(instant)), expected, `${label} resolved to the wrong Kathmandu date`);
}

assert.deepStrictEqual(
    JSON.parse(JSON.stringify(DateUtils.getKathmanduRange('week', new Date('2026-08-25T06:15:00.000Z')))),
    { start: '2026-08-24', end: '2026-08-30' },
    'Monday-Sunday week range is incorrect'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(DateUtils.getKathmanduRange('month', new Date('2026-08-31T18:30:00.000Z')))),
    { start: '2026-09-01', end: '2026-09-30' },
    '30-day month range is incorrect'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(DateUtils.getKathmanduRange('month', new Date('2026-12-31T18:30:00.000Z')))),
    { start: '2027-01-01', end: '2027-01-31' },
    '31-day month range is incorrect'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(DateUtils.getKathmanduRange('month', new Date('2024-02-15T06:15:00.000Z')))),
    { start: '2024-02-01', end: '2024-02-29' },
    'leap February range is incorrect'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(DateUtils.getKathmanduRange('month', new Date('2026-02-15T06:15:00.000Z')))),
    { start: '2026-02-01', end: '2026-02-28' },
    'non-leap February range is incorrect'
);
assert.deepStrictEqual(
    JSON.parse(JSON.stringify(DateUtils.getKathmanduRange('year', new Date('2026-12-31T18:30:00.000Z')))),
    { start: '2027-01-01', end: '2027-12-31' },
    'year range is incorrect'
);

const custom = { start: '2026-08-01', end: '2026-08-25' };
assert(custom.start <= custom.end, 'valid custom range moved backwards');
assert.strictEqual(DateUtils.addCalendarDays('2026-03-01', -1), '2026-02-28', 'previous-day calculation is incorrect');
assert.strictEqual(DateUtils.addCalendarDays('2024-03-01', -1), '2024-02-29', 'leap previous-day calculation is incorrect');

assert.strictEqual(
    DateUtils.parseKathmanduDateTime('2026-08-25 00:15:00').toISOString(),
    '2026-08-24T18:30:00.000Z',
    'timezone-less database timestamp was not interpreted as Kathmandu'
);
assert.strictEqual(
    DateUtils.parseKathmanduDateTime('2026-08-25T00:15:00Z').toISOString(),
    '2026-08-25T00:15:00.000Z',
    'explicit UTC timestamp was not preserved'
);

console.log('PASS: Kathmandu date boundaries, calendar ranges, leap dates, and notification timestamp parsing');
