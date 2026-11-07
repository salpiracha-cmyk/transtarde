(()=>{'use strict';
const host=document.getElementById('directorDailyRates');if(!host)return;
window.TT_DIRECTOR_RATES?.mount(host,{date:new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Karachi'})});
})();
