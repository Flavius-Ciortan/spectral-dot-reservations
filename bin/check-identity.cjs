#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const slug = 'spectral-dot-reservations';
const main = fs.readFileSync(path.join(root, slug + '.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
const name = 'Spectral Dot - Product Reservations for WooCommerce';
let failures = 0;
function check(condition, message) {
  console.log((condition ? 'PASS: ' : 'FAIL: ') + message);
  if (!condition) failures++;
}
check(main.includes('Plugin Name:       ' + name) && readme.startsWith('=== ' + name + ' ==='), 'Canonical display name.');
check(main.includes('Text Domain:       ' + slug), 'Canonical text domain.');
const version = main.match(/\* Version:\s*(\S+)/)?.[1];
check(version && main.includes("define( 'SDPR_VERSION', '" + version + "' );") && readme.includes('Stable tag: ' + version + '\n'), 'Version fields agree.');
for (const field of ['Requires at least', 'Requires PHP', 'WC requires at least', 'WC tested up to']) {
  const value = main.match(new RegExp('\\* ' + field + ':\\s*(\\S+)'))?.[1];
  check(value && readme.includes(field + ': ' + value + '\n'), field + ' agrees in header and readme.');
}
check(fs.existsSync(path.join(root, 'languages', slug + '.pot')), 'Canonical translation template.');
function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    if (['.git', 'vendor', 'dist', 'node_modules'].includes(entry.name)) return [];
    const full = path.join(dir, entry.name);
    return entry.isDirectory() ? walk(full) : [full];
  });
}
for (const file of walk(root).filter(file => file.endsWith('.php'))) {
  const text = fs.readFileSync(file, 'utf8');
  for (const match of text.matchAll(/^(?:final\s+|abstract\s+)?(class|interface|function)\s+([\w]+)/gm)) {
    check(match[2].startsWith(match[1] === 'function' ? 'sdpr_' : 'SDPR_'), 'Owned symbol prefix: ' + match[2]);
  }
  for (const match of text.matchAll(/SDPR_PLUGIN_PATH\s*\.\s*'([^']+)'/g)) {
    check(fs.existsSync(path.join(root, match[1])), 'Referenced plugin file exists: ' + match[1]);
  }
}
process.exitCode = failures ? 1 : 0;
