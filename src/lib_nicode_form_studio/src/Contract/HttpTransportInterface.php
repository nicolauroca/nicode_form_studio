<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Contract;
use Nicode\FormStudio\Http\HttpResponse;
interface HttpTransportInterface
{
    public function request(string $url, string $method, array $headers, string $body, int $timeout = 10): HttpResponse;
}
