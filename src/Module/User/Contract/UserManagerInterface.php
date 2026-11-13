<?php

declare(strict_types=1);

namespace App\Module\User\Contract;

use App\Module\User\Contract\Dto\Invitation;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\Exception\UserException;

interface UserManagerInterface
{
    /**
     * Creates an active user with its profile. The email is the login, stored lowercase. Users
     * are global: the new one is a member of the current tenant, if one is selected, and of no
     * other (addToTenant()).
     *
     * @return string the id of the new user
     *
     * @throws UserException invalid or taken email, password too short
     */
    public function createUser(string $email, string $plainPassword, ?string $firstName = null, ?string $lastName = null): string;

    /**
     * Sets the language the user reads the application in, one of the enabled locales; null
     * goes back to the browser's language.
     *
     * @throws UserException unknown user, language not enabled
     */
    public function changeLocale(string $email, ?string $locale): void;

    /**
     * Lets a user sign in to a tenant, whose roles then apply to them there.
     *
     * @return bool false when they were a member already
     *
     * @throws UserException unknown user
     */
    public function addToTenant(string $email, string $tenantId): bool;

    /**
     * Takes a user out of a tenant: they can no longer sign in there, and a session of theirs
     * there ends at its next request. Their roles in the tenant stay, unused.
     *
     * @return bool false when they were no member
     *
     * @throws UserException unknown user
     */
    public function removeFromTenant(string $email, string $tenantId): bool;

    /**
     * @return list<string> the emails of the tenant's members, sorted
     */
    public function membersOf(string $tenantId): array;

    /**
     * The tenant's members, by name.
     *
     * @return list<Member>
     */
    public function members(string $tenantId): array;

    /**
     * Makes the user an operator of the installation, or no longer: the console of the
     * installation (part B of docs/internal/tenant-admin-ui-plan.md). From the command line only.
     *
     * @throws UserException unknown user
     */
    public function setOperator(string $email, bool $operator): void;

    /**
     * @return list<string> the emails of the operators, sorted
     */
    public function operators(): array;

    /**
     * Invites someone to a tenant: whoever the email belongs to accepts with acceptInvitation().
     * The same whether the email has an account or not: nothing of the account is told.
     *
     * @param bool $keepAssignments false: take the account's assignments in the tenant away on
     *                              acceptance (its inviter doesn't hold them all)
     *
     * @return string the token of the invitation's link, shown once, never stored
     *
     * @throws UserException not an email address
     */
    public function invite(string $email, string $tenantId, string $invitedBy, bool $keepAssignments): string;

    /**
     * @return list<Invitation> the tenant's pending invitations, the newest first
     */
    public function invitations(string $tenantId): array;

    /**
     * @return bool false when the tenant has no such invitation
     */
    public function revokeInvitation(string $tenantId, string $id): bool;

    /**
     * A pending invitation of the tenant by its token: not accepted, not expired, not burned by
     * too many wrong passwords. Null otherwise.
     */
    public function openInvitation(string $tenantId, string $token): ?Invitation;

    /**
     * Accepts an invitation: an existing account proves it with its password (a wrong one counts
     * against the invitation), a new one is created with this password and name. Either way it
     * becomes a member of the tenant.
     *
     * @return Invitation the invitation accepted
     *
     * @throws UserException no such invitation any more, a wrong password, a password too short
     */
    public function acceptInvitation(string $tenantId, string $token, string $password, ?string $firstName = null, ?string $lastName = null): Invitation;

    /**
     * The email of the account the given one names, as stored (trimmed, lower case), or null
     * when no account has it, in any tenant or none: users are global. Check anything keyed by
     * the email (the RBAC assignments) with this, never with the email as typed.
     */
    public function canonicalEmail(string $email): ?string;
}
