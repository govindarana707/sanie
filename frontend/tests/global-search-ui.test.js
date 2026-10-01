const fs = require('fs');
const assert = require('assert');

const html = fs.readFileSync('frontend/index.html', 'utf8');
const css = fs.readFileSync('frontend/assets/css/styles.css', 'utf8');

assert(html.includes('id="global-search"'), 'Global search input is missing');
assert(/\.top-nav \.search-bar:focus-within[\s\S]*?border-color:\s*var\(--sanie-primary\)/.test(css), 'Search wrapper needs the focus border');
assert(/#global-search:focus-visible[\s\S]*?outline:\s*0[\s\S]*?box-shadow:\s*none/.test(css), 'Input focus must not create a second ring');
assert(/#global-search[\s\S]*?background:\s*transparent/.test(css), 'Search input must keep a transparent background');
assert(/\.top-nav \.search-bar \.(?:search-submit|search-kbd)[\s\S]*?align-self:\s*center/.test(css), 'Search icon and keyboard badge must stay centered');
assert(/\.top-nav \.search-bar \{[\s\S]*?overflow:\s*visible/.test(css), 'Search overlays must not be clipped');

console.log('Global search UI checks passed');
