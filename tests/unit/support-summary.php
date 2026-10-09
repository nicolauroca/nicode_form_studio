<?php
declare(strict_types=1);

test('support summary projects bounded diagnostics without provider details or secrets',function():void {
    $report=['php'=>'8.5.8','joomla'=>'6.0.0','formspec'=>'1.0','password'=>'SECRET','versions'=>['package:pkg_nicode_form_studio'=>'1.0.0-dev','SECRET'=>'SECRET'],'checks'=>['database'=>['status'=>'ok','detail'=>'SECRET','password'=>'SECRET'],'jobs_pending'=>['status'=>'warning','count'=>100,'more'=>true],'SECRET'=>['status'=>'SECRET'],'storage_path'=>['status'=>'unavailable','detail'=>'C:/SECRET']],'php_limits'=>['post_max_size'=>'8M','session.save_path'=>'SECRET']];
    $encoded=Nicode\FormStudio\Health\SupportSummary::encode($report); $safe=json_decode($encoded,true,flags:JSON_THROW_ON_ERROR);
    same(false,str_contains($encoded,'SECRET')); same('8.5.8',$safe['php']); same('1.0.0-dev',$safe['versions']['package:pkg_nicode_form_studio']);
    same(['status'=>'warning','count'=>100,'more'=>true],$safe['checks']['jobs_pending']); same('8M',$safe['php_limits']['post_max_size']);
    $report['php']="8.5\nSECRET"; $report['checks']['database']=['status'=>'SECRET','count'=>999999,'more'=>true]; $report['php_limits']['post_max_size']='8M SECRET';
    $encoded=Nicode\FormStudio\Health\SupportSummary::encode($report); $safe=json_decode($encoded,true,flags:JSON_THROW_ON_ERROR);
    same(false,str_contains($encoded,'SECRET')); same(null,$safe['php']); same(null,$safe['php_limits']['post_max_size']); same(['status'=>'unavailable'],$safe['checks']['database']);
    same(Nicode\FormStudio\Health\SupportSummary::encode([]),Nicode\FormStudio\Health\SupportSummary::encode([]));
});
