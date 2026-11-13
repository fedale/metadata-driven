<?php

declare(strict_types=1);

namespace App\Module\User\Application;

use App\Module\User\Contract\Dto\Invitation;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Domain\Exception\InvalidUser;
use App\Module\User\Domain\Model\User;
use App\Module\User\Domain\Model\UserProfile;
use App\Module\User\Domain\Repository\InvitationRepository;
use App\Module\User\Domain\Repository\MembershipRepository;
use App\Module\User\Domain\Repository\UserRepository;
use App\Shared\Tenant\CurrentTenant;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

final class UserManager implements UserManagerInterface
{
    public const int MIN_PASSWORD_LENGTH = 12;

    /** How long an invitation lasts. */
    private const string INVITATION_LIFETIME = '+7 days';

    /** Wrong passwords that burn an invitation: guessing, or probing for an account, is costly. */
    public const int INVITATION_MAX_FAILURES = 5;

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly InvitationRepository $invitations,
        private readonly CurrentTenant $tenant,
        private readonly PasswordHasherFactoryInterface $hashers,
        // config/packages/rate_limiter.yaml
        private readonly RateLimiterFactoryInterface $invitationPasswordLimiter,
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

    public function invite(string $email, string $tenantId, string $invitedBy, bool $keepAssignments): string
    {
        $email = User::normalizeEmail($email);
        // Inviting a member would only be a way to touch what they have here; their membership
        // is no secret to the tenant's admins.
        $user = $this->users->findByEmail($email);
        if (null !== $user && $this->memberships->isMember($tenantId, $user->getId())) {
            throw InvalidUser::alreadyMember($email);
        }
        // One pending invitation per email: a new one replaces it.
        $this->invitations->removePendingOf($tenantId, $email);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = new \DateTimeImmutable();
        $this->invitations->add(
            new Invitation(Uuid::v7()->toRfc4122(), $email, $invitedBy, $now, $now->modify(self::INVITATION_LIFETIME), $keepAssignments),
            $tenantId,
            hash('sha256', $token),
        );

        return $token;
    }

    public function invitations(string $tenantId): array
    {
        return $this->invitations->pending($tenantId, new \DateTimeImmutable(), self::INVITATION_MAX_FAILURES);
    }

    public function revokeInvitation(string $tenantId, string $id): bool
    {
        return Uuid::isValid($id) && $this->invitations->remove($tenantId, $id);
    }

    public function openInvitation(string $tenantId, string $token): ?Invitation
    {
        return $this->invitations->findPending($tenantId, hash('sha256', $token), new \DateTimeImmutable(), self::INVITATION_MAX_FAILURES);
    }

    public function acceptInvitation(string $tenantId, string $token, string $password, ?string $firstName = null, ?string $lastName = null): Invitation
    {
        $invitation = $this->openInvitation($tenantId, $token) ?? throw InvalidUser::invitationNotFound();
        $user = $this->users->findByEmail($invitation->email);
        if (null === $user) {
            $this->createUser($invitation->email, $password, $firstName, $lastName);
            $user = $this->users->findByEmail($invitation->email) ?? throw InvalidUser::notFound($invitation->email);
        } else {
            // Counted per account, whatever the invitation: inviting again gives no more
            // guesses. Always checked, a member's included: the next step signs the account in.
            $limiter = $this->invitationPasswordLimiter->create($user->getEmail());
            // consume(0) asks for nothing, and is always accepted: what is left is the answer.
            if (0 === $limiter->consume(0)->getRemainingTokens()) {
                throw InvalidUser::tooManyTries();
            }
            if (!$this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class)->verify($user->getPasswordHash(), $password)) {
                $limiter->consume();
                $this->invitations->recordFailure($invitation->id);

                throw InvalidUser::wrongPassword();
            }
            // A member already (added meanwhile): nothing of theirs is taken away.
            if ($this->memberships->isMember($tenantId, $user->getId())) {
                $invitation = new Invitation($invitation->id, $invitation->email, $invitation->invitedBy, $invitation->createdAt, $invitation->expiresAt, true);
            }
        }
        $this->memberships->add($tenantId, $user->getId());
        $this->invitations->markAccepted($invitation->id, new \DateTimeImmutable());

        return $invitation;
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
