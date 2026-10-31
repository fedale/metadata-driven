<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Switches the request to the language of the signed-in user's profile.
 *
 * Runs after the firewall (priority 8), which knows the user: by then the locale listener has
 * set the browser's language (Accept-Language) and the locale-aware services follow it, so the
 * switch goes through LocaleSwitcher, which updates them all.
 */
final class UserLocale
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly Security $security,
        private readonly LocaleSwitcher $locales,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function onRequest(RequestEvent $event): void
    {
        $user = $this->security->getUser();
        $locale = $user instanceof SecurityUser ? $user->getLocale() : null;
        if (null === $locale || !\in_array($locale, $this->enabledLocales, true)) {
            return;
        }
        $event->getRequest()->setLocale($locale);
        $this->locales->setLocale($locale);
    }
}
