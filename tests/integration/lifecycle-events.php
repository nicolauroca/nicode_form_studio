<?php
declare(strict_types=1);
use Joomla\Event\Dispatcher;
use Nicode\FormStudio\Infrastructure\Joomla\{LifecycleEvent, LifecycleEvents};

test('native lifecycle events expose immutable bounded metadata for all eleven phases', function (): void {
    $dispatcher = new Dispatcher(); $seen = [];
    foreach (LifecycleEvent::PHASES as $phase) {
        $dispatcher->addListener('onFormStudio' . $phase, static function (LifecycleEvent $event) use (&$seen): void {
            same($event->context, $event->getArgument('context')); $seen[] = $event->phase;
            raises(LogicException::class, static function () use ($event): void { $event['context'] = []; });
            raises(Error::class, static function () use ($event): void { $event->context['state'] = 'changed'; });
        });
    }
    $events = new LifecycleEvents($dispatcher, static fn () => null, static fn () => null);
    foreach (LifecycleEvent::PHASES as $phase) { $events->emit($phase, ['form_id' => 1]); }
    same(LifecycleEvent::PHASES, $seen);
    raises(InvalidArgumentException::class, fn () => $events->emit('Unknown', []));
    raises(InvalidArgumentException::class, fn () => $events->emit('BeforeValidation', ['answers' => 'private']));
});

test('before lifecycle failures veto and after failures preserve outcomes even if logging fails', function (): void {
    $dispatcher = new Dispatcher(); $logged = 0;
    foreach (['BeforeSubmissionPersist', 'AfterSubmissionPersist'] as $phase) { $dispatcher->addListener('onFormStudio' . $phase, static fn () => throw new RuntimeException('private plugin exception')); }
    $events = new LifecycleEvents($dispatcher, static fn () => null, static function () use (&$logged): void { $logged++; throw new RuntimeException('sink failure'); });
    raises(DomainException::class, fn () => $events->emit('BeforeSubmissionPersist', []));
    $events->emit('AfterSubmissionPersist', []); same(2, $logged);
});

test('source lifecycle veto applies to cache hits without invoking a provider fallback', function (): void {
    $sources = new Nicode\FormStudio\Registry\DataSourceRegistry(); $sources->register(new Nicode\FormStudio\DataSource\StaticDataSource());
    $dispatcher = new Dispatcher(); $hits = [];
    $dispatcher->addListener('onFormStudioResolveDataSource', static function (LifecycleEvent $event) use (&$hits): void { $hits[] = $event->context['cache_hit']; if ($event->context['cache_hit']) { throw new DomainException('Veto cached result'); } });
    $resolver = new Nicode\FormStudio\DataSource\OptionResolver($sources, new Nicode\FormStudio\DataSource\RequestCache(), new LifecycleEvents($dispatcher, static fn () => null, static fn () => null));
    $source = ['type' => 'static', 'ttl' => 60, 'config' => ['options' => [['value' => '1', 'label' => 'One']]]];
    same(1, count($resolver->resolve($source, [])));
    raises(DomainException::class, fn () => $resolver->resolve($source, [])); same([false, true], $hits);
});
