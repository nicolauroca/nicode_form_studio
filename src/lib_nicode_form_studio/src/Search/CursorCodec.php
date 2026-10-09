<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Search;

use Nicode\FormStudio\Domain\CanonicalJson;

final readonly class CursorCodec
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) { throw new \InvalidArgumentException('Cursor signing key must contain at least 32 bytes.'); }
    }
    public function encode(array $payload): string
    {
        $body = rtrim(strtr(base64_encode(CanonicalJson::encode($payload)), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', $body, $this->secret);
    }
    public function decode(string $cursor): array
    {
        if (strlen($cursor) > 4096) { throw new \InvalidArgumentException('Invalid search cursor.'); }
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $this->secret), $parts[1])) { throw new \InvalidArgumentException('Invalid search cursor.'); }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($decoded === false) { throw new \InvalidArgumentException('Invalid search cursor.'); }
        $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new \InvalidArgumentException('Invalid search cursor.'); }
        return $payload;
    }
}
