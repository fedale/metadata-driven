<?php

declare(strict_types=1);

namespace App\Module\Admin\UI;

use App\Module\User\Contract\UserManagerInterface;
use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\WorkspaceUrls;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * An invitation's link, by email: from the members page and from the console (a workspace's
 * admin). The email is the same whether the address has an account or not, so that it tells
 * nothing of the account (tenant-admin-ui-plan.md, part A2), in the inviter's language: the
 * invitee's is not known before they accept.
 *
 * The token is never stored: the email is written in the request that made the invitation, the
 * only place it is in hand.
 */
final class InvitationMail
{
    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly Mailer $mailer,
        private readonly WorkspaceUrls $urls,
        // config/packages/rate_limiter.yaml
        private readonly RateLimiterFactoryInterface $invitationEmailLimiter,
    ) {
    }

    /**
     * Takes one of the inviter's invitations by email (invitation_email): false when they sent
     * too many lately, and should invite no one before a while.
     */
    public function allow(string $inviter): bool
    {
        return $this->invitationEmailLimiter->create(mb_strtolower($inviter))->consume()->isAccepted();
    }

    /**
     * The invitation's address, on the workspace's host: what the email links to, and what the
     * page shows once.
     */
    public function link(Workspace $workspace, string $token): string
    {
        return $this->urls->ofWorkspace($workspace, '/invitation/'.$token);
    }

    /**
     * @throws MailNotSent
     */
    public function send(Workspace $workspace, string $email, string $token, string $inviter, string $locale): void
    {
        $invitation = $this->users->openInvitation($workspace->id, $token) ?? throw new \LogicException('The invitation just made is not open.');
        $profile = $this->users->profile($inviter);
        $inviterName = trim(($profile->firstName ?? '').' '.($profile->lastName ?? ''));
        $inviterName = '' === $inviterName ? $profile->email : $inviterName;

        $this->mailer->send(new Mail(
            $email,
            'invitation',
            'invitation.subject',
            ['%inviter%' => $inviterName, '%workspace%' => $workspace->name],
            [
                'inviter' => $inviterName,
                'workspace' => $workspace->name,
                'link' => $this->link($workspace, $token),
                'expires_at' => $invitation->expiresAt,
            ],
            $locale,
            $workspace->name,
        ));
    }
}
