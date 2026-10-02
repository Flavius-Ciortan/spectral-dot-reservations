const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '../assets/css/admin-style.css'), 'utf8');
const tokens = Object.fromEntries(Array.from(css.matchAll(/(--sdpr-[\w-]+):\s*([^;]+);/g), match => [match[1], match[2].trim()]));
function rgb(hex) {
    assert.match(hex, /^#[0-9a-f]{6}$/i);
    return [1, 3, 5].map(offset => parseInt(hex.slice(offset, offset + 2), 16));
}
function luminance(channels) {
    return channels.map(channel => {
        const value = channel / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
}
function check(label, foreground, background, minimum = 4.5) {
    const values = [luminance(rgb(foreground)), luminance(rgb(background))].sort((a, b) => a - b);
    const ratio = (values[1] + 0.05) / (values[0] + 0.05);
    assert.ok(ratio >= minimum, `${label}: ${ratio.toFixed(2)}:1 is below ${minimum}:1`);
    console.log(`PASS: ${label} (${ratio.toFixed(2)}:1)`);
}
for (const role of ['primary', 'primary-dark', 'accent', 'success', 'success-dark', 'error', 'error-dark']) {
    check(`${role} button text`, tokens['--sdpr-white'], tokens[`--sdpr-${role}`]);
}
for (const role of ['primary', 'success', 'warning', 'error', 'text-dark', 'text-light']) {
    check(`${role} text on white`, tokens[`--sdpr-${role}`], tokens['--sdpr-white']);
}
check('Hovered tab text', tokens['--sdpr-primary'], tokens['--sdpr-secondary']);
check('Warning time remaining', tokens['--sdpr-warning'], '#fffbeb');
check('Critical time remaining', tokens['--sdpr-error'], '#fef2f2');
const stops = tokens['--sdpr-header-gradient'].match(/#[0-9a-f]{6}/gi);
assert.ok(stops && stops.length >= 2, 'Header gradient must have color stops.');
for (const [index, stop] of stops.entries()) {
    // Include the brightest point of the decorative white dot overlay (0.12 * 0.3).
    const composite = rgb(stop).map(channel => Math.round(channel * 0.964 + 255 * 0.036));
    const background = `#${composite.map(channel => channel.toString(16).padStart(2, '0')).join('')}`;
    check(`Header text at stop ${index + 1}`, tokens['--sdpr-white'], background);
}
check('Keyboard focus outline', tokens['--sdpr-primary-dark'], tokens['--sdpr-white'], 3);
check('Unchecked toggle track and thumb', tokens['--sdpr-white'], tokens['--sdpr-border-ui'], 3);
check('Input boundary on white', tokens['--sdpr-border-ui'], tokens['--sdpr-white'], 3);
check('Input boundary on page background', tokens['--sdpr-border-ui'], '#e2e8f0', 3);

const frontendCss = fs.readFileSync(path.join(__dirname, '../assets/css/style.css'), 'utf8');
const modalTokens = Object.fromEntries(Array.from(frontendCss.matchAll(/(--sdpr-modal-[\w-]+):\s*([^;]+);/g), match => [match[1], match[2].trim()]));
check('Modal submit text', '#ffffff', modalTokens['--sdpr-modal-primary']);
check('Modal hovered submit text', '#ffffff', modalTokens['--sdpr-modal-primary-dark']);
for (const background of ['#ffffff', '#f8fafc', '#e2e8f0']) {
    check(`Modal focus outline on ${background}`, modalTokens['--sdpr-modal-primary-dark'], background, 3);
}
assert.match(frontendCss, /\.sdpr-button-primary\.submit-btn\s*\{\s*background-color:\s*var\(--sdpr-modal-primary\)/);
assert.match(frontendCss, /\.sdpr-button-primary\.submit-btn:hover\s*\{\s*background-color:\s*var\(--sdpr-modal-primary-dark\)/);
for (const selector of ['.modal-close:focus-visible', '.sdpr-button-primary.submit-btn:focus-visible']) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const rule = frontendCss.match(new RegExp(`${escaped}\\s*\\{([^}]+)\\}`));
    assert.ok(rule, `${selector} must have a focus rule.`);
    assert.match(rule[1], /outline:\s*2px solid var\(--sdpr-modal-primary-dark\)/);
}
