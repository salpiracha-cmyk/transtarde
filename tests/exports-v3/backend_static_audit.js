const fs=require('fs');
const assert=require('assert');
const path=require('path');

const root=path.join(__dirname,'..');
const mysql=fs.readFileSync(path.join(root,'main/api/operations.mysql.php'),'utf8');
const modulePhp=fs.readFileSync(path.join(root,'main/module.php'),'utf8');
const schema=fs.readFileSync(path.join(root,'main/api/operations_schema.sql'),'utf8');
const clean=fs.readFileSync(path.join(root,'main/api/operations_clean_start.sql'),'utf8');
const html=fs.readFileSync(path.join(__dirname,'index.html'),'utf8');
const css=fs.readFileSync(path.join(__dirname,'app.css'),'utf8');
const js=fs.readFileSync(path.join(__dirname,'app.js'),'utf8');

assert.match(mysql,/tt_require_login\(\)/);
assert.match(mysql,/tt_verify_csrf/);
assert.match(mysql,/FOR UPDATE/);
assert.match(mysql,/beginTransaction\(\)/);
assert.match(mysql,/baseVersion/);
assert.match(mysql,/409/);
assert.match(mysql,/operations_merge_export/);
assert.match(mysql,/16 \* 1024 \* 1024/);
assert.match(modulePhp,/tt_user_can_open_module/);
assert.match(modulePhp,/'masters'=>\$id==='exports' \? tt_list_masters\(\) : \[\]/);
assert.match(modulePhp,/pending\.set\(key,value\)/,'transient shared-save failures remain pending for retry');
assert.match(modulePhp,/endpoint='api\/operations\.mysql\.php'/);
assert.match(modulePhp,/TG_header\.jpg/);
assert.match(modulePhp,/TG_footer\.jpg/);
assert.match(modulePhp,/TG_sign\.jpg/);
assert.doesNotMatch(modulePhp,/TG_(?:header|footer|sign)\.png/);
assert.match(modulePhp,/data:' \. \$mime \. ';base64,/,'server wrapper must inline each artwork with its real MIME type');
assert.match(schema,/ENGINE=InnoDB/);
assert.match(schema,/tt_operation_history/);
assert.doesNotMatch(clean,/DROP\s+TABLE|TRUNCATE|DELETE\s+FROM\s+(?!tt_operation_|tt_export_documents)/i);
assert.match(clean,/DELETE FROM tt_operation_records/);
assert.match(html,/href="app\.css\?v=/);
assert.match(html,/src="app\.js\?v=/);
assert.match(css,/@page\{size:A4;margin:0\}/);
assert.match(css,/\.docLetterhead\{position:absolute;left:0;right:0/);
assert.match(css,/\.docAutoSign img\{display:block;width:auto;height:20mm/,'signatures must remain with the document body and not create orphan pages');
assert.match(js,/WITH \$\{label\}/);
assert.match(js,/WITHOUT \$\{label\}/);
assert.match(js,/this company has no footer/);
for(const asset of ['TTI_header.png','TTI_sign.png','BRM_header.png','BRM_sign.png','TG_header.jpg','TG_footer.jpg','TG_sign.jpg']){
  assert.ok(js.includes(`assets/${asset}`),asset+' reference missing');
  assert.ok(fs.existsSync(__dirname+'/assets/'+asset),asset+' file missing');
}
console.log('PASS backend/static release audit: auth, CSRF, concurrency, clean-start scope, links and A4 print controls');
