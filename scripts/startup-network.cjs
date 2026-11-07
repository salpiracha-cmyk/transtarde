'use strict';
const https=require('node:https');
// Anonymous fixed-origin probe only. Never send cookies, credentials or bodies,
// and never print provider/error messages, IP addresses or response content.
function startupNetworkProbe(request=https.request,timeoutMs=12000){
 return new Promise(resolve=>{
  const start=Date.now(),result={probe:'anonymous login connectivity',path:'/login.php'},elapsed=()=>Date.now()-start;
  let requestHandle,timer,finished=false;
  const finish=()=>{if(finished)return;finished=true;clearTimeout(timer);resolve({...result,totalMs:elapsed()})};
  timer=setTimeout(()=>{result.errorCode='PROBE_TIMEOUT';requestHandle?.destroy();finish()},timeoutMs);
  try{
   requestHandle=request('https://app.transtradeinternational.com/login.php',{method:'GET',headers:{'User-Agent':'Transtrade-ReadOnly-Startup-Diagnostic','Accept':'text/html'}},response=>{
    result.httpStatus=response.statusCode;result.firstByteMs=elapsed();response.resume();response.once('end',finish);response.once('error',()=>{result.errorCode='RESPONSE_ERROR';finish()});
   });
   requestHandle.once('socket',socket=>{
    socket.once('lookup',error=>{result.dnsReadyMs=elapsed();if(error)result.dnsFailed=true});
    socket.once('connect',()=>{result.tcpReadyMs=elapsed()});socket.once('secureConnect',()=>{result.tlsReadyMs=elapsed()});
   });
   requestHandle.once('error',error=>{result.errorCode=/^[A-Z0-9_]{1,60}$/.test(String(error.code||''))?error.code:'NETWORK_ERROR';finish()});requestHandle.end();
  }catch{result.errorCode='PROBE_INITIALIZATION_FAILED';finish()}
 });
}
module.exports={startupNetworkProbe};
