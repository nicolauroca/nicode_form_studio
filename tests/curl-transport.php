<?php
declare(strict_types=1);

// Process-local cURL boundary: no network or external endpoint receives test data.
namespace Nicode\FormStudio\Http {
    final class CurlBoundaryFixture {
        public array $options = [];
        public int $calls = 0;
        public int $status = 204;
        public string $body = '';
        public bool $failure = false;
        public static ?self $current = null;
    }
    function curl_init(): CurlBoundaryFixture { return CurlBoundaryFixture::$current ?? throw new \RuntimeException('Fixture missing.'); }
    function curl_setopt_array(CurlBoundaryFixture $handle, array $options): bool { $handle->options += $options; return true; }
    function curl_setopt(CurlBoundaryFixture $handle, int $option, mixed $value): bool { $handle->options[$option] = $value; return true; }
    function curl_exec(CurlBoundaryFixture $handle): bool {
        $handle->calls++;
        if ($handle->failure) { return false; }
        return ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $handle->body) === strlen($handle->body);
    }
    function curl_getinfo(CurlBoundaryFixture $handle, int $option): int {
        if ($option !== CURLINFO_RESPONSE_CODE) { throw new \RuntimeException('Unexpected transport query.'); }
        return $handle->status;
    }
}

namespace {
    require dirname(__DIR__) . '/src/lib_nicode_form_studio/autoload.php';
    use Nicode\FormStudio\Http\{CurlBoundaryFixture, CurlTransport, DestinationPolicy};
    use Nicode\FormStudio\Actions\ActionFailure;
    $assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
    $dns = 0;
    $transport = new CurlTransport(new DestinationPolicy(['hooks.example.test'], static function () use (&$dns): array { $dns++; return ['1.1.1.1']; }), 8);
    $probe = CurlBoundaryFixture::$current = new CurlBoundaryFixture(); $probe->body = 'ok';
    $response = $transport->request('https://hooks.example.test/receive', 'POST', ['Content-Type' => 'application/json'], '{"x":1}', 3);
    $assert($response->status === 204 && $response->body === 'ok' && $dns === 1 && $probe->calls === 1, 'Transport result or DNS pin boundary failed.');
    foreach ([CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 3, CURLOPT_RESOLVE => ['hooks.example.test:443:1.1.1.1'], CURLOPT_POSTFIELDS => '{"x":1}'] as $option => $expected) {
        $assert(($probe->options[$option] ?? null) === $expected, 'Unsafe cURL option: ' . $option);
    }
    foreach ([['status' => 302, 'body' => 'redirect'], ['status' => 503, 'body' => 'unready']] as $case) {
        $probe = CurlBoundaryFixture::$current = new CurlBoundaryFixture(); $probe->status = $case['status']; $probe->body = $case['body'];
        $response = $transport->request('https://hooks.example.test/receive', 'POST', [], '{}');
        $assert($response->status === $case['status'] && $probe->calls === 1, 'Transport followed or retried an unconfirmed response.');
    }
    foreach ([false, true] as $failure) {
        $probe = CurlBoundaryFixture::$current = new CurlBoundaryFixture(); $probe->failure = $failure; $probe->body = 'ninebytes';
        try { $transport->request('https://hooks.example.test/receive', 'POST', [], '{}'); throw new RuntimeException('Unknown outcome accepted.'); }
        catch (ActionFailure $error) { $assert($error->unknownOutcome && $error->resultCode === ($failure ? 'http_delivery_unknown' : 'http_response_limit') && $probe->calls === 1, 'Unknown result retried or misclassified.'); }
    }
    $probe = CurlBoundaryFixture::$current = new CurlBoundaryFixture();
    $private = new CurlTransport(new DestinationPolicy(['hooks.example.test'], static fn (): array => ['127.0.0.1']));
    try { $private->request('https://hooks.example.test/receive', 'POST', [], '{}'); throw new RuntimeException('Private address reached transport.'); }
    catch (ActionFailure $error) { $assert(!$error->unknownOutcome && $error->resultCode === 'http_destination_rejected' && $probe->calls === 0, 'Private destination was not rejected before delivery.'); }
    echo "cURL transport boundary passed: HTTPS/TLS, pinned DNS, proxy/redirect isolation, limits, no automatic retries and private-address rejection. No external delivery performed.\n";
}
