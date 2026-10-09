<?php
declare(strict_types=1);

use Joomla\CMS\Captcha\CaptchaProviderInterface;
use Joomla\CMS\Captcha\CaptchaRegistry;
use Joomla\CMS\Form\FormField;
use Joomla\Event\Dispatcher;
use Nicode\FormStudio\Infrastructure\Joomla\CaptchaAdapter;
use Nicode\FormStudio\Security\CaptchaException;
use Nicode\FormStudio\Security\CaptchaPolicy;

/** Test double for the installed third-party plugin boundary, never shipped. */
final class TestCaptchaProvider implements CaptchaProviderInterface
{
    public array $instances = [];
    public bool $unavailable = false;
    public array $answers = [];
    public string $displayMode = 'normal';
    public function __construct(private string $name = 'test-provider') {}
    public function getName(): string { return $this->name; }
    public function display(string $name = '', array $attributes = []): string {
        $this->instances[] = $attributes['id'];
        if ($this->displayMode === 'empty') { return '  '; }
        if ($this->displayMode === 'throw') { throw new RuntimeException('secret-provider-config'); }
        return '<div id="' . htmlspecialchars($attributes['id'], ENT_QUOTES, 'UTF-8') . '"></div>';
    }
    public function checkAnswer(?string $code = null): bool {
        $this->answers[] = $code;
        if ($this->unavailable) { throw new RuntimeException('secret=must-not-leak'); }
        return $code === 'test-valid-response';
    }
    public function setupField(FormField $field, SimpleXMLElement $element): void {}
}

test('Joomla real registry dispatches setup and adapter enumerates provider', function (): void {
    $dispatcher = new Dispatcher(); $registry = new CaptchaRegistry(); $provider = new TestCaptchaProvider();
    $dispatcher->addListener('onCaptchaSetup', static function ($event) use ($provider): void { $event->getArgument('subject')->add($provider); });
    $registry->setDispatcher($dispatcher); $registry->initRegistry();
    $adapter = new CaptchaAdapter($registry, new CaptchaPolicy('joomla'), 'test-provider');
    same(['test-provider'], $adapter->available());
    $adapter->render(new CaptchaPolicy(), 'instance-a'); $adapter->render(new CaptchaPolicy(), 'instance-b');
    same(['instance-a-captcha', 'instance-b-captcha'], $provider->instances);
    $adapter->validate(new CaptchaPolicy(), 'test-valid-response');
    raises(CaptchaException::class, fn () => $adapter->validate(new CaptchaPolicy(), 'wrong'));
    $provider->unavailable = true;
    try { $adapter->validate(new CaptchaPolicy(), 'secret'); }
    catch (CaptchaException $error) { same('captcha_unavailable', $error->getMessage()); same(null, $error->getPrevious()); }
});

test('CAPTCHA adapter selects Joomla providers and delegates opaque answers without an internal solver', function (): void {
    $registry = new CaptchaRegistry(); $first = new TestCaptchaProvider('arbitrary-alpha'); $second = new TestCaptchaProvider('arbitrary-beta');
    $registry->add($second); $registry->add($first);
    $adapter = new CaptchaAdapter($registry, new CaptchaPolicy('provider', 'arbitrary-alpha'), 'arbitrary-beta');
    same(['arbitrary-alpha', 'arbitrary-beta'], $adapter->available());
    foreach ([null, '', '  opaque vendor token  ', 'test-valid-response'] as $answer) {
        if ($answer === 'test-valid-response') { $adapter->validate(new CaptchaPolicy(), $answer); continue; }
        try { $adapter->validate(new CaptchaPolicy(), $answer); throw new LogicException('Provider rejection was ignored.'); }
        catch (CaptchaException $error) { same('captcha_error', $error->getMessage()); }
    }
    same([null, '', '  opaque vendor token  ', 'test-valid-response'], $first->answers); same([], $second->answers);
    $adapter->validate(new CaptchaPolicy('joomla'), 'test-valid-response');
    same(['test-valid-response'], $second->answers);
    $adapter->validate(new CaptchaPolicy('none'), 'unused');
    same(4, count($first->answers)); same(1, count($second->answers));
    foreach (['empty', 'throw'] as $mode) {
        $first->displayMode = $mode;
        try { $adapter->render(new CaptchaPolicy('provider', 'arbitrary-alpha'), 'independent-form'); throw new LogicException('Unavailable CAPTCHA rendered silently.'); }
        catch (CaptchaException $error) { same('captcha_unavailable', $error->getMessage()); same(null, $error->getPrevious()); }
    }
});
test('required missing Joomla CAPTCHA fails closed for render and validation', function (): void {
    $adapter = new CaptchaAdapter(new CaptchaRegistry(), new CaptchaPolicy('provider', 'missing'), null, false);
    raises(CaptchaException::class, fn () => $adapter->assertAvailable(new CaptchaPolicy()));
    raises(CaptchaException::class, fn () => $adapter->render(new CaptchaPolicy(), 'instance-a'));
    raises(CaptchaException::class, fn () => $adapter->validate(new CaptchaPolicy(), null));
    raises(CaptchaException::class, fn () => $adapter->validate(new CaptchaPolicy('none'), null));
});
test('none requires policy permission and Joomla no-provider resolves explicitly', function (): void {
    $adapter = new CaptchaAdapter(new CaptchaRegistry(), new CaptchaPolicy('joomla'), '0', true);
    same('', $adapter->render(new CaptchaPolicy(), 'instance-a'));
    $adapter->validate(new CaptchaPolicy('none'), null);
    raises(InvalidArgumentException::class, fn () => new CaptchaAdapter(new CaptchaRegistry(), new CaptchaPolicy(), null));
});
