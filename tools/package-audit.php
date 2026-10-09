<?php
declare(strict_types=1);

/** Verify archive membership and bytes without extracting executable content. */
function auditDevelopmentPackage(string $root, string $output): array
{
    $manifestPath=$root.'/src/pkg_nicode_form_studio/pkg_nicode_form_studio.xml';
    $manifest=simplexml_load_file($manifestPath,SimpleXMLElement::class,LIBXML_NONET);
    if(!$manifest || (string)$manifest['type']!=='package') { throw new RuntimeException('Invalid package manifest.'); }
    $audit=static function(string $path,array $expected):array {
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::RDONLY)!==true) { throw new RuntimeException('Unreadable archive: '.basename($path)); }
        try {
            if($zip->numFiles!==count($expected)) { throw new RuntimeException('Archive membership count mismatch: '.basename($path)); }
            $seen=[]; $entries=[];
            for($index=0;$index<$zip->numFiles;$index++) {
                $stat=$zip->statIndex($index); $name=$stat['name']??'';
                if(!isset($expected[$name]) || isset($seen[$name])) { throw new RuntimeException('Unexpected or duplicate archive entry.'); }
                $seen[$name]=true; $source=$expected[$name];
                if($stat['size']!==filesize($source) || $stat['mtime']!==1789776000) { throw new RuntimeException('Archive size or normalized timestamp mismatch: '.$name); }
                $bytes=$zip->getFromIndex($index);
                if(!is_string($bytes) || !hash_equals(hash_file('sha256',$source),hash('sha256',$bytes))) { throw new RuntimeException('Archive content mismatch: '.$name); }
                $entries[$name]=['bytes'=>$stat['size'],'sha256'=>hash('sha256',$bytes)];
            }
            ksort($entries,SORT_STRING);
            return ['sha256'=>hash_file('sha256',$path),'entries'=>$entries];
        } finally { $zip->close(); }
    };
    $packageFiles=['pkg_nicode_form_studio.xml'=>$manifestPath,'script.php'=>$root.'/src/pkg_nicode_form_studio/script.php']; $archives=[];
    foreach($manifest->files->file as $child) {
        $name=(string)$child;
        if(preg_match('/^(?:lib|com|mod|plg_task|plg_extension)_nicode_form_studio\.zip$/D',$name)!==1 || isset($archives[$name])) { throw new RuntimeException('Invalid child archive reference.'); }
        $extension=substr($name,0,-4); $source=$root.'/src/'.$extension; $expected=[];
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS)) as $file) {
            if($file->isLink()) { throw new RuntimeException('Package sources must not contain symbolic links.'); }
            if($file->isFile()) { $expected[str_replace('\\','/',substr($file->getPathname(),strlen($source)+1))]=$file->getPathname(); }
        }
        $childManifest=str_starts_with($extension,'plg_')?'nicode_form_studio.xml':$extension.'.xml';
        $definition=simplexml_load_file($source.'/'.$childManifest,SimpleXMLElement::class,LIBXML_NONET);
        if(!$definition || (string)$definition->version!==(string)$manifest->version) { throw new RuntimeException('Child/package software version mismatch.'); }
        $expected['LICENSE']=$root.'/LICENSE';
        $archives[$name]=$audit($output.'/'.$name,$expected);
        $packageFiles['packages/'.$name]=$output.'/'.$name;
    }
    if(count($archives)!==5) { throw new RuntimeException('Expected all five development extensions.'); }
    $archives['pkg_nicode_form_studio.zip']=$audit($output.'/pkg_nicode_form_studio.zip',$packageFiles);
    return ['schema'=>1,'version'=>(string)$manifest->version,'release'=>false,'archives'=>$archives];
}
