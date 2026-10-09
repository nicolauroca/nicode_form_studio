<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Privacy;

final class RequestMetadata
{
    public static function select(array $privacy, string $mode, array $raw): array
    {
        if ($mode === 'none') { return []; }
        $result = [];
        if (($privacy['store_ip'] ?? false) === true && is_string($raw['ip'] ?? null) && filter_var($raw['ip'], FILTER_VALIDATE_IP)) {
            $result['ip'] = inet_ntop(inet_pton($raw['ip']));
        }
        if (($privacy['store_user_agent'] ?? false) === true && is_string($raw['user_agent'] ?? null) && mb_check_encoding($raw['user_agent'], 'UTF-8')) {
            $agent = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw['user_agent']);
            $agent = mb_strcut($agent, 0, 512, 'UTF-8');
            if ($agent !== '') { $result['user_agent'] = $agent; }
        }
        return $result;
    }
}
