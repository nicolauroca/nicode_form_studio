<?php
declare(strict_types=1);
$root=dirname(__DIR__); require $root.'/tools/package-audit.php';
$build=static function()use($root):array {
    $process=proc_open([PHP_BINARY,$root.'/tools/build-development.php'],[0=>['pipe','r'],1=>['file',$root.'/build/package-test.log','w'],2=>['file',$root.'/build/package-test-errors.log','w']],$pipes,$root);
    if(!is_resource($process)) { throw new RuntimeException('Cannot start build.'); }
    fclose($pipes[0]); if(proc_close($process)!==0) { throw new RuntimeException('Build failed; inspect package-test-errors.log.'); }
    return auditDevelopmentPackage($root,$root.'/build/development-package');
};
$first=$build(); $second=$build();
if($first!==$second) { throw new RuntimeException('Repeated builds changed archive bytes or membership.'); }
// Corrupt isolated copies only; retain the installable development output.
$fixture=$root.'/build/package-audit-'.bin2hex(random_bytes(5)); mkdir($fixture);
foreach(array_keys($second['archives']) as $name) { copy($root.'/build/development-package/'.$name,$fixture.'/'.$name); }
foreach(['extra','changed','missing'] as $case) {
    $path=$fixture.'/com_nicode_form_studio.zip'; copy($root.'/build/development-package/com_nicode_form_studio.zip',$path);
    $zip=new ZipArchive(); $zip->open($path);
    if($case==='extra') { $zip->addFromString('unexpected.php','unexpected'); }
    elseif($case==='changed') { $zip->addFromString('LICENSE',str_repeat('x',filesize($root.'/LICENSE'))); $zip->setMtimeName('LICENSE',1789776000); }
    else { $zip->deleteName('LICENSE'); }
    $zip->close(); $rejected=false;
    try { auditDevelopmentPackage($root,$fixture); } catch(RuntimeException) { $rejected=true; }
    if(!$rejected) { throw new RuntimeException('Audit accepted '.$case.' archive contents.'); }
}
file_put_contents($root.'/build/package-reproducibility-results.json',json_encode(['passed'=>true,'timestamp'=>gmdate(DATE_ATOM),'version'=>$second['version'],'archives'=>array_map(static fn($archive)=>$archive['sha256'],$second['archives']),'tampering_rejected'=>['extra','changed','missing'],'release'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "Package audit: two identical builds, all five child archives and package bytes verified; extra, altered and missing entries rejected.\n";
