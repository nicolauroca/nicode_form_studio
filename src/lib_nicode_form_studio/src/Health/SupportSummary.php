<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Health;

/** An explicit projection, never a dump of configuration or provider diagnostics. */
final class SupportSummary
{
    public static function encode(array $report): string
    {
        $safe=['format'=>'nicode-formstudio-support-v1'];
        foreach(['php','joomla','formspec'] as $key) { $safe[$key]=self::version($report[$key]??null); }
        foreach(['package:pkg_nicode_form_studio','component:com_nicode_form_studio','module:mod_nicode_form_studio','library:nicode_form_studio','plugin:task:nicode_form_studio','plugin:extension:nicode_form_studio'] as $key) {
            $safe['versions'][$key]=self::version($report['versions'][$key]??null);
        }
        foreach(['storage_path','export_path','database','schema_columns','schema_indexes','schema_types','schema_foreign_keys','schema','scheduled_task','scheduler','jobs_pending','jobs_retryable','jobs_failed','jobs_stalled','index_backlog','failed_actions','uploads_expired','retention-dispatch','export-cleanup','technical-log-cleanup','upload-cleanup','configuration_audit','package','mail_configuration','captcha','search_provider','cache_directory','source_cache'] as $key) {
            $check=$report['checks'][$key]??[];
            if(!is_array($check)) { $check=[]; }
            $status=$check['status']??null;
            $safe['checks'][$key]=['status'=>in_array($status,['ok','warning','unavailable','not_configured','low_space','not_run'],true)?$status:'unavailable'];
            if(is_int($check['count']??null) && $check['count']>=0 && $check['count']<=100 && is_bool($check['more']??null)) {
                $safe['checks'][$key]+=['count'=>$check['count'],'more'=>$check['more']];
            }
        }
        foreach(['post_max_size','upload_max_filesize','max_file_uploads','max_input_vars','max_input_nesting_level','max_multipart_body_parts','memory_limit','max_input_time','max_execution_time','file_uploads'] as $key) {
            $value=$report['php_limits'][$key]??null;
            $safe['php_limits'][$key]=is_string($value) && preg_match('/^-?[0-9]{1,12}[KMG]?$/iD',$value)===1?$value:null;
        }
        return json_encode($safe,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    private static function version(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9][0-9A-Za-z.+-]{0,40}$/D',$value)===1?$value:null;
    }
}
