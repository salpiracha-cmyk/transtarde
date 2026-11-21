'use strict';

function applyScopedDocumentChanges(raw, changes, matches) {
 const root = JSON.parse(raw);
 for (const {action, path, value} of changes) {
  if (path[0] !== 'shipments' && path[0] !== 'audits') throw Error('Unexpected document write');
  const target = path.slice(0, -1).reduce((node, field, index) => {
   if (typeof field === 'object') return node.find(row => row.id === field.id);
   return node[field] ??= path[index + 1] === null ? [] : {};
  }, root);
  const field = path.at(-1);
  switch (action) {
   case 'set': target[field] = value; break;
   case 'remove': delete target[field]; break;
   case 'prepend': target.unshift(...value); break;
   case 'append': target.push(...value); break;
   default: throw Error('Unexpected document patch action ' + action);
  }
 }
 const result = JSON.stringify(root);
 if (!matches(result)) throw Error('The test fixture did not acknowledge the document changes');
 return result;
}

module.exports = applyScopedDocumentChanges;
