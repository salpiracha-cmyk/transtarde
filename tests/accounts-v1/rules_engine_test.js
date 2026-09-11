'use strict';
const assert=require('node:assert/strict');
function corn({weight,rate,moisture,damage,other=0}){const per100=Math.max(0,moisture-13)+Math.max(0,damage-2)+other,deduction=weight*per100/100,net=weight-deduction;return{deduction,net,value:net/40*rate,brokerage:weight/100*10}}
function sesame({weight,rate,admixture,raw=false,other=0}){const free=raw?3:1,per100=Math.max(0,admixture-free)+other,deduction=weight*per100/100,net=weight-deduction;return{deduction,net,value:net/40*rate,brokerage:weight/40*(raw?10:15)}}
assert.deepEqual(corn({weight:40000,rate:2500,moisture:13,damage:2}),{deduction:0,net:40000,value:2500000,brokerage:4000});
assert.deepEqual(corn({weight:40000,rate:2500,moisture:15,damage:3,other:.5}),{deduction:1400,net:38600,value:2412500,brokerage:4000});
assert.deepEqual(sesame({weight:40000,rate:12000,admixture:1}),{deduction:0,net:40000,value:12000000,brokerage:15000});
assert.deepEqual(sesame({weight:40000,rate:12000,admixture:5,raw:true,other:.25}),{deduction:900,net:39100,value:11730000,brokerage:10000});
let seed=84191;const random=()=>((seed=seed*48271%2147483647)-1)/2147483646;
for(let i=0;i<1000;i++){const weight=1000+random()*100000,rate=1+random()*20000,moisture=random()*25,damage=random()*12,other=random()*2,c=corn({weight,rate,moisture,damage,other});assert(c.deduction>=0);assert(Math.abs(c.net+c.deduction-weight)<1e-8);assert(c.value>=0);assert(Math.abs(c.brokerage-weight/10)<1e-8);const s=sesame({weight,rate,admixture:random()*15,raw:i%2===0,other});assert(s.deduction>=0);assert(Math.abs(s.net+s.deduction-weight)<1e-8);assert(s.value>=0)}
console.log('Accounts commodity rules: 1,004 deterministic and randomized cases passed.');
