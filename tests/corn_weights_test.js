const assert=require('node:assert/strict'),flow=require('../commodity-flow.js');
assert.equal(flow.isCorn({product:'Yellow Maize'}),true);assert.equal(flow.isLooseBulk({commodity:'Corn',packing:'Loose bulk in containers'}),true);assert.equal(flow.isLooseBulk({commodity:'Corn',packing:'PP Bags'}),false);
assert.equal(flow.weights({basis:'COMMODITY_NET',weighbridgeKg:27500}).netKg,27500);
const packed={basis:'PACKED_CARGO',weighbridgeKg:27500,silicaKg:10,kraftKg:15,bagTareKg:5};assert.equal(flow.weights(packed).netKg,27470);assert.equal(flow.weights({...packed,basis:'VEHICLE_GROSS',weighbridgeKg:31500,containerTareKg:4000}).netKg,27470);
assert.throws(()=>flow.weights({...packed,basis:'COMMODITY_NET'}),/another packing deduction/);assert.throws(()=>flow.weights({...packed,containerTareKg:4000}),/Vehicle tare/);assert.throws(()=>flow.weights({...packed,weighbridgeKg:20}),/greater than zero/);
console.log('PASS corn identity, loose/bagged distinction and physical net calculation');
