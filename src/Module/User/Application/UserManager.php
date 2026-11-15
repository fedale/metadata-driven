<?php

declare(strict_types=1);

namespace App\Module\User\Application;

use App\Module\User\Contract\Dto\Invitation;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\Dto\Profile;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\User\Domain\Exception\InvalidUser;
use App\Module\User\Domain\Model\User;
use App\Module\User\Domain\Model\UserProfile;
use App\Module\User\Domain\Repository\InvitationRepository;
use App\Module\User\Domain\Repository\MembershipRepository;
use App\Module\User\Domain\Repository\UserRepository;
use App\Shared\Workspace\CurrentWorkspace;
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
        private readonly CurrentWorkspace $workspace,
        private readonly PasswordHasherFactoryInterface $hashers,
        // config/packages/rate_limiter.yaml
        private readonly RateLimiterFactoryInterface $invitationPasswordLimiter,
        private readonly RateLimiterFactoryInterface $passwordChangeLimiter,
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
        if ($this->workspace->isSelected()) {
            $this->memberships->add($this->workspace->id(), $user->getId());
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

    public function addToWorkspace(string $email, string $workspaceId): bool
    {
        return $this->memberships->add($workspaceId, $this->user($email)->getId());
    }

    public function removeFromWorkspace(string $email, string $workspaceId): bool
    {
        return $this->memberships->remove($workspaceId, $this->user($email)->getId());
    }

    public function membersOf(string $workspaceId): array
    {
        return $this->memberships->membersOf($workspaceId);
    }

    public function members(string $workspaceId): array
    {
        return array_map(static function (array $row): Member {
            $name = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));

            return new Member(
                $row['email'],
                '' !== $name ? $name : $row['email'],
                null === $row['last_login_at'] ? null : new \DateTimeImmutable($row['last_login_at']),
            );
        }, $this->memberships->membersWithProfiles($workspaceId));
    }

    public function profile(string $email): Profile
    {
        $user = $this->user($email);

        return new Profile($user->getEmail(), $user->getProfile()?->getFirstName(), $user->getProfile()?->getLastName());
    }

    public function rename(string $email, ?string $firstName, ?string $lastName): void
    {
        $user = $this->user($email);
        ($user->getProfile() ?? new UserProfile(Uuid::v7(), $user))->rename($firstName, $lastName);
        $this->users->save($user);
    }

    public function changePassword(string $email, string $currentPassword, string $newPassword): void
    {
        $user = $this->user($email);
        // The try taken before the check: concurrent requests can't make more.
        if (!$this->passwordChangeLimiter->create($user->getEmail())->consume()->isAccepted()) {
            throw InvalidUser::tooManyTries();
        }
        $hasher = $this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class);
        if (!$hasher->verify($user->getPasswordHash(), $currentPassword)) {
            throw InvalidUser::wrongPassword();
        }
        if (mb_strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
            throw InvalidUser::passwordTooShort(self::MIN_PASSWORD_LENGTH);
        }
        $user->changePasswordHash($hasher->hash($newPassword));
        $this->users->save($user);
    }

    public function setSystemAdmin(string $email, bool $systemAdmin): void
    {
        $user = $this->user($email);
        $systemAdmin ? $user->grantSystemAdmin() : $user->revokeSystemAdmin();
        $this->users->save($user);
    }

    public function systemAdmins(): array
    {
        return array_map(static fn (User $user): string => $user->getEmail(), $this->users->systemAdmins());
    }

    public function invite(string $email, string $workspaceId, string $invitedBy, bool $keepAssignments, bool $replaceOthers = false): string
    {
        $email = User::normalizeEmail($email);
        // Inviting a member would only be a way to touch what they have here; their membership
        // is no secret to the workspace's admins.
        $user = $this->users->findByEmail($email);
        if (null !== $user && $this->memberships->isMember($workspaceId, $user->getId())) {
            throw InvalidUser::alreadyMember($email);
        }
        // One pending invitation per email: a new one replaces the inviter's own. Another
        // inviter's stands until revoked (in the open, on the members page): replacing it would
        // be a way to change what it carries, or to take it over.
        $pending = $this->invitations->pendingOf($workspaceId, $email, new \DateTimeImmutable(), self::INVITATION_MAX_FAILURES);
        if (null !== $pending && $pending->invitedBy !== $invitedBy && !$replaceOthers) {
            throw InvalidUser::invitedByAnother($email, $pending->invitedBy);
        }
        $this->invitations->removePendingOf($workspaceId, $email);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = new \DateTimeImmutable();
        $this->invitations->add(
            new Invitation(Uuid::v7()->toRfc4122(), $email, $invitedBy, $now, $now->modify(self::INVITATION_LIFETIME), $keepAssignments),
            $workspaceId,
            hash('sha256', $token),
        );

        return $token;
    }

    public function invitations(string $workspaceId): array
    {
        return $this->invitations->pending($workspaceId, new \DateTimeImmutable(), self::INVITATION_MAX_FAILURES);
    }

    public function revokeInvitation(string $workspaceId, string $id): bool
    {
        return Uuid::isValid($id) && $this->invitations->remove($workspaceId, $id);
    }

    public function openInvitation(string $workspaceId, string $token): ?Invitation
    {
        return $this->invitations->findPending($workspaceId, hash('sha256', $token), new \DateTimeImmutable(), self::INVITATION_MAX_FAILURES);
    }

    public function acceptInvitation(string $workspaceId, string $token, string $password, ?string $firstName = null, ?string $lastName = null): Invitation
    {
        $invitation = $this->openInvitation($workspaceId, $token) ?? throw InvalidUser::invitationNotFound();
        $user = $this->users->findByEmail($invitation->email);
        if (null === $user) {
            $this->createUser($invitation->email, $password, $firstName, $lastName);
            $user = $this->users->findByEmail($invitation->email) ?? throw InvalidUser::notFound($invitation->email);
        } else {
            // Every try is taken before the password is checked, so concurrent requests can't
            // try more than are left: the account's (whatever the invitation: inviting again
            // gives no more guesses), then the invitation's (burned at the fifth). A right
            // password uses one too; the invitation is used up then anyway. Always checked, a
            // member's included: the next step signs the account in.
            if (!$this->invitationPasswordLimiter->create($user->getEmail())->consume()->isAccepted()) {
                throw InvalidUser::tooManyTries();
            }
            if (!$this->invitations->takeAttempt($invitation->id, self::INVITATION_MAX_FAILURES)) {
                throw InvalidUser::invitationNotFound();
            }
            if (!$this->hashers->getPasswordHasher(PasswordAuthenticatedUserInterface::class)->verify($user->getPasswordHash(), $password)) {
                throw InvalidUser::wrongPassword();
            }
            // A member already (added meanwhile): nothing of theirs is taken away.
            if ($this->memberships->isMember($workspaceId, $user->getId())) {
                $invitation = new Invitation($invitation->id, $invitation->email, $invitation->invitedBy, $invitation->createdAt, $invitation->expiresAt, true);
            }
        }
        $this->memberships->add($workspaceId, $user->getId());
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
