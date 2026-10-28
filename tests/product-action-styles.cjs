const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '../assets/css/style.css'), 'utf8');
for (const selector of [
    '.woocommerce div.product form.cart button.single_add_to_cart_button',
    '.woocommerce div.product #sdpr_reserve_product'
]) {
    const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const block = css.match(new RegExp(`${escaped}\\s*\\{([^}]+)\\}`));
    assert.ok(block, `Missing product action rule: ${selector}`);
    assert.match(block[1], /vertical-align:\s*top\s*;/, 'Both inline buttons must use the same top edge, not their differing text/SVG baselines.');
    assert.doesNotMatch(block[1], /\b(?:top|transform|height):/, 'Alignment must not depend on offsets or forced button heights.');
}
console.log('PASS: Product actions share top alignment without offsets or forced heights.');
