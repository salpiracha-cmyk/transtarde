'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 const desk=fs.readFileSync('accounts/accounts-accounting-desk.js','utf8');
 const menuSource=desk.slice(desk.indexOf('  const pakistanAreas ='),desk.indexOf('  const currentAreas ='));
 const sandbox={access:{}};vm.runInNewContext(menuSource+';this.menus={pakistanAreas,tgAreas};',sandbox);
 const actions=areas=>areas.flatMap(area=>area.actions);
 assert(!actions(sandbox.menus.pakistanAreas).some(action=>action.special==='tg-remittances'));
 assert(actions(sandbox.menus.tgAreas).some(action=>action.special==='tg-remittances'));
 const planning=fs.readFileSync('accounts/supplier-payment-planning-ui.js','utf8');
 const refresh=planning.slice(planning.indexOf('  async function refresh()'),planning.indexOf('  async function mount()'));
 const calls=[],context={currentEntity:()=> 'TG',load:()=>calls.push('load'),render:()=>calls.push('render'),toast:()=>calls.push('error')};
 vm.runInNewContext(refresh+';this.run=refresh;',context);await context.run();assert.deepEqual(calls,[],'TG must not request or render Pakistan supplier planning');
 let company='TTI',release;context.currentEntity=()=>company;context.load=()=>new Promise(resolve=>{release=resolve});
 const pending=context.run();company='TG';release();await pending;assert.deepEqual(calls,[],'A prior-company response must not repaint TG bills');
 console.log('PASS TG-only remittance navigation, no unsupported planning reads and stale-company render isolation');
})().catch(error=>{console.error(error);process.exitCode=1});
