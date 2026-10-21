"""Hostile Office document/container and finite storage admission regressions."""
import base64, io, json, pathlib, subprocess, tempfile, zipfile, os
ROOT=pathlib.Path(__file__).resolve().parents[2]
def pdf_fixture():
 out=bytearray(b'%PDF-1.4\n');offsets=[0]
 for i,body in enumerate([b'<< /Type /Catalog /Pages 2 0 R >>',b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>'],1):
  offsets.append(len(out));out.extend(str(i).encode()+b' 0 obj\n'+body+b'\nendobj\n')
 xref=len(out);out.extend(b'xref\n0 4\n0000000000 65535 f \n'+b''.join(f'{n:010d} 00000 n \n'.encode() for n in offsets[1:]));out.extend(b'trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n'+str(xref).encode()+b'\n%%EOF\n');return bytes(out)
PDF=pdf_fixture()
PNG=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Bxx4AAAAASUVORK5CYII=')
WEBP=base64.b64decode('UklGRi4AAABXRUJQVlA4ICIAAABwAQCdASoBAAEAAUAmJZQCdAFAAAD++3sItRJUvJ+iMAAA')
def package(entries,compression=zipfile.ZIP_STORED):
 out=io.BytesIO()
 with zipfile.ZipFile(out,'w',compression=compression) as z:
  for name,data in entries:z.writestr(name,data)
 return out.getvalue()
# Extract the production Word package template; use its generated document body.
source=(ROOT/'exports/shipment-files.js').read_text();import re
entries=[]
for name,xml in re.findall(r"^ '([^']+)':'(.*)'[,]?$",source,re.M):
 if name in ['[Content_Types].xml','_rels/.rels','word/_rels/document.xml.rels','word/styles.xml','word/document.xml']:
  xml=xml.replace("'+body+'",'<w:p><w:r><w:t>Fixture covering letter</w:t></w:r></w:p>');entries.append((name,xml.encode()))
assert len(entries)==5
DOCX=package(entries);BAGS=package([('Purchase order.pdf',PDF),('Bag marking.png',PNG)])
