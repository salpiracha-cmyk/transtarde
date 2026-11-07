'use strict';
const {EventEmitter}=require('node:events'),assert=require('node:assert/strict');
const {startupNetworkProbe}=require('../../scripts/startup-network.cjs');
const secret='DO-NOT-LOG-PRIVATE-ERROR-TEXT';
function fake(mode){return(url,options,callback)=>{
 assert.equal(url,'https://app.transtradeinternational.com/login.php');assert.equal(options.method,'GET');assert(!('Cookie'in options.headers));assert(!('Authorization'in options.headers));
 const request=new EventEmitter();request.destroy=()=>{};request.end=()=>queueMicrotask(()=>{
  const socket=new EventEmitter();request.emit('socket',socket);
  if(mode==='timeout')return;
  if(mode==='dns'){socket.emit('lookup',new Error(secret));return request.emit('error',{code:'ENOTFOUND',message:secret})}
  socket.emit('lookup',null);socket.emit('connect');
  if(mode==='tls')return request.emit('error',{code:'CERT_HAS_EXPIRED',message:secret});
  socket.emit('secureConnect');const response=new EventEmitter();response.statusCode=mode==='blocked'?403:200;response.resume=()=>queueMicrotask(()=>response.emit('end'));callback(response);
 });return request;
};}
(async()=>{
 const ok=await startupNetworkProbe(fake('ok'));assert.equal(ok.httpStatus,200);assert('tlsReadyMs'in ok);
 const denied=await startupNetworkProbe(fake('blocked'));assert.equal(denied.httpStatus,403);assert(!denied.errorCode);
 const dns=await startupNetworkProbe(fake('dns'));assert.equal(dns.errorCode,'ENOTFOUND');assert(dns.dnsFailed);assert(!('tcpReadyMs'in dns));
 const tls=await startupNetworkProbe(fake('tls'));assert.equal(tls.errorCode,'CERT_HAS_EXPIRED');assert('tcpReadyMs'in tls);assert(!('tlsReadyMs'in tls));
 const timeout=await startupNetworkProbe(fake('timeout'),5);assert.equal(timeout.errorCode,'PROBE_TIMEOUT');
 assert(!JSON.stringify([ok,denied,dns,tls,timeout]).includes(secret));
 console.log('PASS distinguish DNS/TCP/TLS/HTTP/timeout without credentials, response content or retrying the failing assertion');
})().catch(error=>{console.error(error);process.exitCode=1});
