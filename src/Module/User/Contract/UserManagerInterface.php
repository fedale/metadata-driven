<?php

declare(strict_types=1);

namespace App\Module\User\Contract;

use App\Module\User\Contract\Dto\Invitation;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\Dto\PasswordReset;
use App\Module\User\Contract\Dto\Profile;
use App\Module\User\Contract\Exception\UserException;

interface UserManagerInterface
{
    /**
     * Creates an active user with its profile. The email is the login, stored lowercase. Users
     * are global: the new one is a member of the current workspace, if one is selected, and of no
     * other (addToWorkspace()).
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
     * Sets the colour theme of the application, light or dark (Profile::THEMES); null goes back
     * to the system's.
     *
     * @throws UserException unknown user, unknown theme
     */
    public function changeTheme(string $email, ?string $theme): void;

    /**
     * Lets a user sign in to a workspace, whose roles then apply to them there.
     *
     * @return bool false when they were a member already
     *
     * @throws UserException unknown user
     */
    public function addToWorkspace(string $email, string $workspaceId): bool;

    /**
     * Takes a user out of a workspace: they can no longer sign in there, and a session of theirs
     * there ends at its next request. Their roles in the workspace stay, unused.
     *
     * @return bool false when they were no member
     *
     * @throws UserException unknown user
     */
    public function removeFromWorkspace(string $email, string $workspaceId): bool;

    /**
     * @return list<string> the emails of the workspace's members, sorted
     */
    public function membersOf(string $workspaceId): array;

    /**
     * The workspace's members, by name.
     *
     * @return list<Member>
     */
    public function members(string $workspaceId): array;

    /**
     * @throws UserException unknown user
     */
    public function profile(string $email): Profile;

    /**
     * Whether the account's notifications come by email too, in every workspace (accounts are
     * global), not only to the bell.
     */
    public function chooseNotificationsByEmail(string $email, bool $byEmail): void;

    /**
     * @throws UserException unknown user
     */
    public function rename(string $email, ?string $firstName, ?string $lastName): void;

    /**
     * Changes the user's password, once their current one is given: five tries a quarter of an
     * hour. Every session and remember-me cookie of theirs ends (they hold the old hash); the
     * caller signs the current one in again.
     *
     * @throws UserException unknown user, a wrong current password, too many tries, a new one too short
     */
    public function changePassword(string $email, string $currentPassword, string $newPassword): void;

    /**
     * Makes the user a system admin of the installation, or no longer: the console of the
     * installation (part B of docs/internal/tenant-admin-ui-plan.md). From the command line only.
     *
     * @throws UserException unknown user
     */
    /**
     * A reset of the account's password, for its email to carry: the one it had pending replaced,
     * its link working an hour. Null when no active account has the email: say the same to the
     * person asking either way, and limit the requests before calling it.
     */
    public function requestPasswordReset(string $email): ?PasswordReset;

    /**
     * The email of the account a reset's link is for: pending, not expired, the account active.
     * Null otherwise.
     */
    public function openPasswordReset(string $token): ?string;

    /**
     * Gives the account a new password, and uses the link: once. Every session and remember-me
     * cookie of the account ends.
     *
     * @return string the account's email
     *
     * @throws UserException the link is no longer valid, the password is too short
     */
    public function resetPassword(string $token, string $newPassword): string;

    public function setSystemAdmin(string $email, bool $systemAdmin): void;

    /**
     * @return list<string> the emails of the system admins, sorted
     */
    public function systemAdmins(): array;

    /**
     * Invites someone to a workspace: whoever the email belongs to accepts with acceptInvitation().
     * The same whether the email has an account or not: nothing of the account is told.
     *
     * One invitation per email is pending: the inviter's own replaces theirs; another inviter's
     * is refused unless $replaceOthers (the system admin, above every admin).
     *
     * @param bool $keepAssignments false: take the account's assignments in the workspace away on
     *                              acceptance (its inviter doesn't hold them all)
     *
     * @return string the token of the invitation's link, shown once, never stored
     *
     * @throws UserException not an email address, a member, another inviter's pending invitation
     */
    public function invite(string $email, string $workspaceId, string $invitedBy, bool $keepAssignments, bool $replaceOthers = false): string;

    /**
     * @return list<Invitation> the workspace's pending invitations, the newest first
     */
    public function invitations(string $workspaceId): array;

    /**
     * @return bool false when the workspace has no such invitation
     */
    public function revokeInvitation(string $workspaceId, string $id): bool;

    /**
     * A pending invitation of the workspace by its token: not accepted, not expired, not burned by
     * too many wrong passwords. Null otherwise.
     */
    public function openInvitation(string $workspaceId, string $token): ?Invitation;

    /**
     * Accepts an invitation: an existing account proves it with its password (a wrong one counts
     * against the invitation), a new one is created with this password and name. Either way it
     * becomes a member of the workspace.
     *
     * @return Invitation the invitation accepted
     *
     * @throws UserException no such invitation any more, a wrong password, a password too short
     */
    public function acceptInvitation(string $workspaceId, string $token, string $password, ?string $firstName = null, ?string $lastName = null): Invitation;

    /**
     * The email of the account the given one names, as stored (trimmed, lower case), or null
     * when no account has it, in any workspace or none: users are global. Check anything keyed by
     * the email (the RBAC assignments) with this, never with the email as typed.
     */
    public function canonicalEmail(string $email): ?string;
}
