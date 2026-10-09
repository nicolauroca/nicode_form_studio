<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Infrastructure\Joomla;

use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\Input\Input;
use Nicode\FormStudio\Domain\FormSpec;
use Nicode\FormStudio\Submission\RequestContext;
use Nicode\FormStudio\Submission\SubmitRequest;

/** The only bridge from Joomla request/session objects to trusted runtime context. */
final readonly class RequestAdapter
{
    public function __construct(private CMSWebApplicationInterface $application, private string $key)
    {
        if (strlen($key) < 32) { throw new \InvalidArgumentException('Request context key is too short.'); }
    }

    public function context(string $channel, bool $submission = false): RequestContext
    {
        $app = $this->application; $input = $app->getInput(); $session = $app->getSession(); $user = $app->getIdentity();
        if ($user === null || $session->getId() === '') { throw new \DomainException('Joomla session is unavailable.'); }
        $binding = hash_hmac('sha256', 'session:' . $session->getId(), $this->key);
        // Forwarded headers are deliberately not trusted without a configured proxy policy.
        $address = $input->server->get('REMOTE_ADDR', '', 'raw');
        $packed = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) ? inet_pton($address) : false;
        $scope = hash_hmac('sha256', $packed === false ? 'session:' . $binding : 'ip:' . $packed, $this->key);
        $valid = false;
        if ($submission && strtoupper($input->getMethod()) === 'POST') {
            // Joomla checkToken redirects a new session when the token is missing.
            // Reject missing/malformed tokens first so AJAX remains a JSON response.
            $token = $app->getFormToken();
            $header = $input->server->get('HTTP_X_CSRF_TOKEN', '', 'raw');
            $posted = $input->post->get($token, '', 'raw');
            $present = (is_string($header) && hash_equals($token, $header)) || (is_scalar($posted) && (string) $posted === '1');
            $valid = $present && $app->checkToken('post');
        }
        $query = [];
        if (!$submission) {
            foreach (array_slice(array_keys($input->get->getArray()), 0, 100) as $name) {
                if (!is_string($name) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D', $name) !== 1) { continue; }
                $value = $input->get->get($name, null, 'raw');
                if (is_string($value) && strlen($value) <= 16384 && mb_check_encoding($value, 'UTF-8')) { $query[$name] = $value; }
                elseif (is_array($value) && array_is_list($value) && count($value) <= 100 && count(array_filter($value, static fn ($entry): bool => is_string($entry) && strlen($entry) <= 1024 && mb_check_encoding($entry, 'UTF-8'))) === count($value)) { $query[$name] = $value; }
            }
        }
        // Joomla reloads the session identity, but an existing session can still
        // carry a blocked account. Never grant it public or restricted form access.
        $viewLevels = (int) $user->id > 0 && (int) $user->block === 1 ? [] : array_map('intval', $user->getAuthorisedViewLevels());
        return new RequestContext((int) $user->id, $viewLevels, $app->getLanguage()->getTag(), $binding, $scope, $valid, $channel, userProperties: ['id' => (int) $user->id, 'name' => (string) $user->name, 'username' => (string) $user->username, 'email' => (string) $user->email], prefillQuery: $query, metadataProvider: static fn (array $privacy): array => [
            'ip' => ($privacy['store_ip'] ?? false) === true ? $address : null,
            'user_agent' => ($privacy['store_user_agent'] ?? false) === true ? $input->server->get('HTTP_USER_AGENT', '', 'raw') : null,
        ]);
    }

    public static function identities(Input $input): array
    {
        if (strtoupper($input->getMethod()) !== 'POST') { throw new \InvalidArgumentException('Submission requires POST.'); }
        $result = [];
        foreach (['form_id', 'version_id'] as $key) {
            $raw = $input->post->get($key, null, 'raw');
            if ((!is_string($raw) && !is_int($raw)) || preg_match('/^[1-9][0-9]*$/D', (string) $raw) !== 1 || filter_var($raw, FILTER_VALIDATE_INT) === false) { throw new \InvalidArgumentException('Invalid form identity.'); }
            $result[$key] = (int) $raw;
        }
        return $result;
    }

    /** Detect the definite byte-limit case even when PHP discarded the POST body. */
    public static function exceedsPostSize(Input $input, ?string $configured = null): bool
    {
        if (strtoupper($input->getMethod()) !== 'POST') { return false; }
        $configured ??= (string) ini_get('post_max_size');
        if (preg_match('/^\s*[0-9]+[KMG]?\s*$/iD', $configured) !== 1) { return false; }
        $limit = ini_parse_quantity(trim($configured));
        if ($limit <= 0) { return false; }
        $length = $input->server->get('CONTENT_LENGTH', null, 'raw');
        if ((!is_string($length) && !is_int($length)) || preg_match('/^[0-9]+$/D', (string) $length) !== 1) { return false; }
        // Compare decimal strings so an oversized length cannot overflow an integer.
        $length = ltrim((string) $length, '0'); $maximum = (string) $limit;
        return strlen($length) > strlen($maximum) || (strlen($length) === strlen($maximum) && strcmp($length, $maximum) > 0);
    }

    public static function request(Input $input, FormSpec $spec): SubmitRequest
    {
        return self::parseRequest($input, $spec);
    }

    public static function requestInstances(Input $input, FormSpec $spec, int $budget = 10000): \Nicode\FormStudio\Submission\RepeatedSubmitRequest
    {
        $encoded = $input->post->get('nfs_instances', null, 'raw');
        if (!is_string($encoded) || strlen($encoded) > 2097152) { throw new \InvalidArgumentException('Invalid instance declaration payload.'); }
        try { $decoded = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \InvalidArgumentException('Malformed instance declaration JSON.', 0, $error); }
        if (!$decoded instanceof \stdClass) { throw new \InvalidArgumentException('Instance declarations require an object.'); }
        $instances = new \Nicode\FormStudio\Domain\RepeatedInstances($spec->toArray()['elements'], get_object_vars($decoded), $budget);
        $request = self::parseRequest($input, $spec, $instances, $budget);
        return new \Nicode\FormStudio\Submission\RepeatedSubmitRequest($request, $instances);
    }

    private static function parseRequest(Input $input, FormSpec $spec, ?\Nicode\FormStudio\Domain\RepeatedInstances $instances = null, int $budget = 10000): SubmitRequest
    {
        $ids = self::identities($input); $post = $input->post;
        $attempt = $post->get('attempt', '', 'raw'); $values = $post->get('nfs', [], 'raw');
        $packed = $post->get('nfs_values', null, 'raw');
        if ($packed !== null) {
            if (!is_string($packed) || strlen($packed) > 2097152 || $values !== []) { throw new \InvalidArgumentException('Malformed or ambiguous packed submission.'); }
            try { $decoded = json_decode($packed, false, 64, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new \InvalidArgumentException('Malformed packed submission JSON.', 0, $error); }
            if (!$decoded instanceof \stdClass) { throw new \InvalidArgumentException('Packed submission requires an object.'); }
            $values = get_object_vars($decoded);
        }
        $honeypot = $post->get('nfs_contact', '', 'raw'); $answer = $post->get('formstudio_captcha', null, 'raw');
        if (!is_string($attempt) || strlen($attempt) > 200 || !is_array($values) || !is_string($honeypot) || strlen($honeypot) > 4096 || ($answer !== null && (!is_string($answer) || strlen($answer) > 16384))) { throw new \InvalidArgumentException('Malformed submission.'); }
        $definition = $spec->toArray();
        if ($instances !== null) {
            $instances->bind($values, $budget);
            $fields = array_column($definition['fields'], null, 'uuid'); $expanded = [];
            foreach ($instances->addresses($budget) as $address) {
                $field = $fields[$address->field] ?? throw new \InvalidArgumentException('Missing field definition.');
                $field['uuid'] = $address->key(); $expanded[] = $field;
            }
            $definition['fields'] = $expanded;
        }
        $allowed = array_flip(array_column($definition['fields'], 'uuid'));
        $values = array_intersect_key($values, $allowed); $files = [];
        $uploads = $input->files->get('nfs', []);
        if (!is_array($uploads)) { throw new \InvalidArgumentException('Malformed uploads.'); }
        if ($instances !== null) { $instances->bind($uploads, $budget); }
        foreach ($definition['fields'] as $field) {
            $uuid = $field['uuid'];
            if (!in_array($field['type'], ['file', 'multiple-files'], true) || !isset($uploads[$uuid])) { continue; }
            $entries = $uploads[$uuid];
            if (!is_array($entries)) { throw new \InvalidArgumentException('Malformed upload field.'); }
            if (array_key_exists('error', $entries)) { $entries = [$entries]; }
            if (!array_is_list($entries)) { throw new \InvalidArgumentException('Malformed upload list.'); }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !is_int($entry['error'] ?? null)) { throw new \InvalidArgumentException('Malformed upload status.'); }
                if ($entry['error'] === UPLOAD_ERR_NO_FILE) { continue; }
                if (!is_string($entry['name'] ?? null) || !is_string($entry['tmp_name'] ?? null)) { throw new \InvalidArgumentException('Malformed upload metadata.'); }
                $files[$uuid][] = ['name' => $entry['name'], 'tmp_name' => $entry['tmp_name'], 'error' => $entry['error']];
            }
        }
        return new SubmitRequest($ids['form_id'], $ids['version_id'], $attempt, $values, $files, $honeypot, $answer);
    }
}
