<?php

declare(strict_types=1);

namespace App\Module\User\Application;

use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Domain\Exception\InvalidUser;
use App\Module\User\Domain\Model\User;
use App\Module\User\Domain\Model\UserProfile;
use App\Module\User\Domain\Repository\MembershipRepository;
use App\Module\User\Domain\Repository\UserRepository;
use App\Shared\Tenant\CurrentTenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

final class UserManager implements UserManagerInterface
{
    public const int MIN_PASSWORD_LENGTH = 12;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly CurrentTenant $tenant,
        private readonly PasswordHasherFactoryInterface $hashers,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales,
    ) {
    }

    public function createUser(string $email, string $plainPassword, ?string $firstName = null, ?string $lastName = null): string
    {
        $email = User::normalizeEmail($email);
        if (null !== $this->users->findByEmail($email)) {
            throw InvalidUser::emailTaken($email);
        }
        if (mb_strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            throw InvalidUser::passwordTooShort(self::MIN_PASSWORD_LENGTH);
        }

        // The hasher configured for every password user (security.yaml), not one per class.
        $hash = $this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class)->hash($plainPassword);
        $user = new User(Uuid::v7(), $email, $hash);
        new UserProfile(Uuid::v7(), $user, $firstName, $lastName);
        $this->users->save($user);
        if ($this->tenant->isSelected()) {
            $this->memberships->add($this->tenant->id(), $user->getId());
        }

        return $user->getId()->toRfc4122();
    }

    public function changeLocale(string $email, ?string $locale): void
    {
        if (null !== $locale && !\in_array($locale, $this->enabledLocales, true)) {
            throw InvalidUser::unknownLocale($locale, $this->enabledLocales);
        }
        $user = $this->users->findByEmail($email) ?? throw InvalidUser::notFound($email);
        $profile = $user->getProfile() ?? new UserProfile(Uuid::v7(), $user);
        $profile->changeLocale($locale);
        $this->users->save($user);
    }

    public function addToTenant(string $email, string $tenantId): bool
    {
        return $this->memberships->add($tenantId, $this->user($email)->getId());
    }

    public function removeFromTenant(string $email, string $tenantId): bool
    {
        return $this->memberships->remove($tenantId, $this->user($email)->getId());
    }

    public function membersOf(string $tenantId): array
    {
        return $this->memberships->membersOf($tenantId);
    }

    public function members(string $tenantId): array
    {
        return array_map(static function (array $row): Member {
            $name = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));

            return new Member(
                $row['email'],
                '' !== $name ? $name : $row['email'],
                null === $row['last_login_at'] ? null : new \DateTimeImmutable($row['last_login_at']),
            );
        }, $this->memberships->membersWithProfiles($tenantId));
    }

    public function canonicalEmail(string $email): ?string
    {
        try {
            return $this->users->findByEmail(User::normalizeEmail($email))?->getEmail();
        } catch (InvalidUser) {
            return null;
        }
    }

    private function user(string $email): User
    {
        return $this->users->findByEmail(User::normalizeEmail($email)) ?? throw InvalidUser::notFound($email);
    }
}
