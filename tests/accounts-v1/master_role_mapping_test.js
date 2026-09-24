const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const masters = {
  business_parties: [
    {id:'broker',values:['Shams Broker','','Broker','','','','','','','','Active']},
    {id:'bags',values:['Packing Bags Ltd','','Supplier','','','','','','','','Active']},
    {id:'forwarder',values:['Ocean Line','','Shipping Line / Carrier','','','','','','','','Active']},
    {id:'inactive',values:['Old Transporter','','Transporter','','','','','','','','Inactive']},
  ],
  export_customers:[{id:'buyer',values:['Buyer XYZ','','','Address','','','','','','','Active']}],
};
const context = {window:{TT_ACCOUNT_ACCESS:{masters}},document:{readyState:'loading',addEventListener(){}},MutationObserver:class{}};
vm.runInNewContext(fs.readFileSync('accounts/master-autocomplete.js','utf8'),context);
const names = (...roles) => [...context.window.TT_ACCOUNTS_MASTER_CHOICES.partyNames(...roles)];
assert.deepEqual(names('Supplier'),['Packing Bags Ltd']);
assert.deepEqual(names('Broker'),['Shams Broker']);
assert.deepEqual(names('Shipping Line / Carrier'),['Ocean Line']);
assert.deepEqual(names('Transporter'),[]);
console.log('Accounts selector roles and inactive party filtering passed');
