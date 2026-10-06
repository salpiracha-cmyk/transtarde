<?php
declare(strict_types=1);

/**
 * Replace the first matching HTML tag with literal content.
 *
 * preg_replace() interprets $0, $1 and backslashes in replacement strings.
 * Application JavaScript, CSS and JSON must never pass through that parser.
 */
function tt_replace_html_once(string $pattern, callable $replacement, string $html): string {
    $result = preg_replace_callback($pattern, $replacement, $html, 1);
    if ($result === null) throw new RuntimeException('Application HTML could not be assembled safely.');
    return $result;
}

/** Version local static assets by content, without interpreting inline JavaScript. */
function tt_version_local_assets(string $html, string $basePath='/', ?string $assetRoot=null): string {
    $root=realpath($assetRoot ?? __DIR__);
    if($root===false)throw new RuntimeException('Application asset directory is unavailable.');
    $versions=[];$offset=0;$result='';
    // Scan opening tags only. Large inline modules must not hit PCRE's
    // backtracking limit, and script strings must never be treated as HTML.
    while(preg_match('~<script\b[^>]*>|<link\b[^>]*>~i',$html,$match,PREG_OFFSET_CAPTURE,$offset)){
            $opening=$match[0][0];$start=$match[0][1];
            $after=$start+strlen($opening);
            $script=preg_match('/^<script\b/i',$opening)===1;
            $result.=substr($html,$offset,$start-$offset);
            $attribute=preg_match('/^<script\b/i',$opening)?'src':'href';
            $opening=preg_replace_callback('~\s'.$attribute.'\s*=\s*(["\'])(.*?)\1~i',
                static function(array $attr) use($root,$basePath,&$versions,$attribute):string {
                    $url=html_entity_decode($attr[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
                    $parts=parse_url($url);
                    if($parts===false||isset($parts['scheme'])||isset($parts['host']))return $attr[0];
                    $path=$parts['path']??'';
                    if(!preg_match('/\.(?:js|css)$/i',$path))return $attr[0];
                    $resolved=realpath($root.'/'.ltrim(str_starts_with($path,'/')?$path:rtrim($basePath,'/').'/'.$path,'/'));
                    if($resolved===false||!is_file($resolved)||!str_starts_with($resolved,$root.DIRECTORY_SEPARATOR))return $attr[0];
                    if(!isset($versions[$resolved])){
                        $hash=hash_file('sha256',$resolved);
                        if($hash===false)throw new RuntimeException('Application asset could not be versioned.');
                        $versions[$resolved]=substr($hash,0,16);
                    }
                    $query=implode('&',array_filter(explode('&',$parts['query']??''),static fn(string $part):bool=>$part!==''&&!str_starts_with($part,'v=')));
                    $versioned=$path.'?'.($query!==''?$query.'&':'').'v='.$versions[$resolved].(isset($parts['fragment'])?'#'.$parts['fragment']:'');
                    return ' '.$attribute.'='.$attr[1].htmlspecialchars($versioned,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').$attr[1];
                },$opening);
            if($opening===null)throw new RuntimeException('Application asset tags could not be versioned.');
            $result.=$opening;$offset=$after;
            if($script){
                $close=stripos($html,'</script',$after);
                $end=$close===false?false:strpos($html,'>',$close);
                if($end===false)return $result.substr($html,$after);
                $result.=substr($html,$after,$end+1-$after);$offset=$end+1;
            }
    }
    return $result.substr($html,$offset);
}
