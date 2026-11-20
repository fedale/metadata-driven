<?php

declare(strict_types=1);

namespace App\Module\User\Infrastructure\Security;

use App\Module\User\Contract\ActingUserInterface;
use App\Module\User\Contract\Exception\NotAMember;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Puts a token of the user in the token storage for the work's duration: everything that asks who
 * is signed in (Security, the RBAC checks) gets them, loaded as a request would load them, with
 * their roles of the current workspace.
 */
final class TokenActingUser implements ActingUserInterface
{
    /** The firewall a signed-in user belongs to (config/packages/security.yaml). */
    private const string FIREWALL = 'main';

    public function __construct(
        private readonly UserProvider $users,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    public function actAs(string $email, callable $work): mixed
    {
        try {
            $user = $this->users->loadUserByIdentifier($email);
        } catch (UserNotFoundException $e) {
            throw new NotAMember($email);
        }
        $previous = $this->tokens->getToken();
        $this->tokens->setToken(new UsernamePasswordToken($user, self::FIREWALL, $user->getRoles()));
        try {
            return $work();
        } finally {
            $this->tokens->setToken($previous);
        }
    }
}
