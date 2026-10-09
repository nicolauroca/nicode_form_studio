<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Http;

use Nicode\FormStudio\Actions\ActionFailure;
use Nicode\FormStudio\Contract\HttpTransportInterface;

final readonly class CurlTransport implements HttpTransportInterface
{
    public function __construct(private DestinationPolicy $policy, private int $maximumResponseBytes = 1048576) {}
    public function request(string $url, string $method, array $headers, string $body, int $timeout = 10): HttpResponse
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true) || $timeout < 1 || $timeout > 30 || strlen($body) > 1048576) { throw new \InvalidArgumentException('Invalid HTTP request limits.'); }
        $lines = []; $names = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $name) !== 1 || !is_string($value) || strlen($value) > 8192 || preg_match('/[\x00-\x1f\x7f]/', $value) || in_array(strtolower($name), ['host', 'connection', 'content-length', 'transfer-encoding', 'proxy-authorization', 'cookie'], true)) { throw new \InvalidArgumentException('Unsafe HTTP header.'); }
            if (isset($names[strtolower($name)])) { throw new \InvalidArgumentException('Duplicate HTTP header.'); }
            $names[strtolower($name)] = true;
            $lines[] = $name . ': ' . $value;
        }
        try { $destination = $this->policy->resolve($url); }
        catch (\DomainException) { throw new ActionFailure('http_destination_rejected'); }
        $address = $destination['addresses'][0]; $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $curl = curl_init(); $response = ''; $tooLarge = false;
        curl_setopt_array($curl, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RESOLVE => [$destination['host'] . ':443:' . $pinned],
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > $this->maximumResponseBytes) { $tooLarge = true; return 0; }
                $response .= $chunk; return strlen($chunk);
            }, CURLOPT_USERAGENT => 'NicodeFormStudio/1.0']);
        if ($method !== 'GET') { curl_setopt($curl, CURLOPT_POSTFIELDS, $body); }
        $ok = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // No automatic retry: a timeout can occur after the recipient committed.
        if ($ok === false) { throw new ActionFailure($tooLarge ? 'http_response_limit' : 'http_delivery_unknown', true); }
        return new HttpResponse($status, $response);
    }
}
