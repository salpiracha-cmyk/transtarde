const fs = require('fs');
const assert = require('assert');
const read = file => fs.readFileSync(`${__dirname}/../../${file}`, 'utf8');

const auth = read('auth_store.php');
const accounts = read('accounts/accounts-accounting-desk.js');
const modules = read('module.php');
const directors = read('directors/index.php');

assert.match(auth, /Super Admin'\) return 'index\.php'/, 'Super Admin must land in the Control Centre');
assert.match(accounts, /access\.super \? document\.createElement\('a'\)/, 'Accounts must add Console only for Super Admin');
assert.match(accounts, /consoleLink\.href = '\/index\.php'/, 'Accounts Console must return to Control Centre');
assert.match(modules, /if\(!c\.super\|\|document\.getElementById\('ttConsoleTop'\)\)return/, 'Exports and Milling must add Console only for Super Admin');
assert.match(modules, /link\.href='\/index\.php'/, 'Exports and Milling Console must return to Control Centre');
assert.match(directors, /class="console" href="\.\.\/index\.php"/, 'Directors must return Super Admin to Control Centre');

console.log('PASS Super Admin lands in and can return to Control Centre from every module');
