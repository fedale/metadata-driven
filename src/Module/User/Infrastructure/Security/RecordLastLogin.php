<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Domain\Repository\UserRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener]
final class RecordLastLogin
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $this->users->findByEmail($event->getUser()->getUserIdentifier());
        if (null !== $user) {
            $user->recordLogin(new \DateTimeImmutable());
            $this->users->save($user);
        }
    }
}
