#!/usr/bin/env node
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(process.argv[2] || '.');
const privateIdentifiers = (process.env.SDPR_PRIVATE_IDENTIFIERS || '').split('|').filter(Boolean);
if (!privateIdentifiers.length) {
  console.error('Set SDPR_PRIVATE_IDENTIFIERS to the local-only prohibited identifiers, separated by |. Values are never logged.');
  process.exit(2);
}
const excluded = new Set(['.git', 'vendor', 'node_modules', 'dist']);
let files = 0;
let violations = 0;
function inspect(directory) {
  for (const entry of fs.readdirSync(directory, {withFileTypes:true})) {
    if (excluded.has(entry.name)) continue;
    const file = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) {
      console.error('FAIL: Symlink requires separate inspection: ' + path.relative(root, file));
      violations++;
    } else if (entry.isDirectory()) {
      inspect(file);
    } else if (entry.isFile()) {
      files++;
      const data = fs.readFileSync(file);
      const contents = [data.toString('utf8').toLowerCase(), data.toString('utf16le').toLowerCase()];
      if (privateIdentifiers.some(value => contents.some(text => text.includes(value.toLowerCase())))) {
        console.error('FAIL: Prohibited identity reference in ' + path.relative(root, file));
        violations++;
      }
    }
  }
}
inspect(root);
console.log((violations ? 'FAIL: ' : 'PASS: ') + files + ' files scanned; ' + violations + ' identity-reference violations. Secret values and identifier matches are not printed.');
process.exitCode = violations ? 1 : 0;
