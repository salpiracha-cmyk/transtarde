from pathlib import Path

path = Path('api/document_ai.php')
text = path.read_text(encoding='utf-8')
old = """function ai_env(string $name): string {\n    foreach (['TT_'.$name,$name] as $key) {\n        $value=getenv($key);\n        if($value!==false && trim((string)$value)!=='') return trim((string)$value);\n    }\n    return '';\n}\n"""
new = """function ai_env(string $name): string {\n    foreach (['TT_'.$name,$name] as $key) {\n        $value=getenv($key);\n        if($value!==false && trim((string)$value)!=='') return trim((string)$value);\n    }\n    if($name==='GEMINI_API_KEY' && defined('TT_DATA_DIR')) {\n        $path=rtrim((string)TT_DATA_DIR,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'gemini.key';\n        if(is_file($path) && is_readable($path)) {\n            $value=trim((string)file_get_contents($path));\n            if($value!=='') return $value;\n        }\n    }\n    return '';\n}\n"""
if old not in text:
    raise SystemExit('ai_env block not found')
path.write_text(text.replace(old,new,1),encoding='utf-8')
print('Private Gemini key fallback added.')
