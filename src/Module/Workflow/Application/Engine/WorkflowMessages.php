<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\NotifierInterface;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\Workflow\Application\Definition\Step;
use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Transaction\TransactionRunner;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The steps that tell people: send_email and notify (docs/workflows.md, "Email and
 * notifications").
 *
 * Who they reach is worked out in the step, which fails on what is wrong (too many, past the
 * plan's emails); what they send leaves once the run's transaction committed
 * (TransactionRunner::whenDone()): a run that fails, or a write refused, tells no one. A test on
 * a record sends nothing. The run's log keeps how many were reached, never their addresses: a
 * role's members are not the workflow author's to read.
 */
final class WorkflowMessages
{
    /** The most people one step reaches, once its roles are expanded. */
    public const int MAX_RECIPIENTS = 50;

    private const int MAX_SUBJECT = 200;

    private const int MAX_BODY = 10000;

    public function __construct(
        private readonly UserManagerInterface $users,
        private readonly WorkspaceRolesInterface $roles,
        private readonly NotifierInterface $notifier,
        private readonly Mailer $mailer,
        private readonly WorkflowQuota $quota,
        private readonly RunStore $runs,
        private readonly TransactionRunner $transactions,
        private readonly CurrentWorkspace $workspace,
        private readonly Workspaces $workspaces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Workspace|null $platform the workspace a system workflow runs for; null in a
     *                                 workspace's own
     *
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    public function email(Step $step, DataChain $chain, bool $dryRun, ?Workspace $platform): array
    {
        $subject = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $chain->text($step->string('subject')))), 0, self::MAX_SUBJECT);
        if ('' === $subject) {
            throw new StepFailed('The subject is empty.');
        }
        $body = mb_substr($chain->text($step->string('body')), 0, self::MAX_BODY);
        $workspace = $platform ?? $this->current();
        [$recipients, $leftOut] = $this->recipients($step, $chain, $workspace, true);
        $input = ['to' => $step->options['to'] ?? [], 'subject' => $subject, 'body' => $body];
        $output = ['recipients' => \count($recipients), 'left_out' => $leftOut, 'sent' => false];
        if ($dryRun || [] === $recipients) {
            return [$input, $output, true];
        }
        $left = null === $platform ? $this->quota->emailsLeft() : null;
        if (null !== $left && \count($recipients) > $left) {
            throw new StepFailed(\sprintf('The plan of this workspace allows %d more emails this month: this step sends %d.', $left, \count($recipients)));
        }
        $this->transactions->whenDone(function (bool $committed) use ($recipients, $subject, $body, $workspace): void {
            if (!$committed) {
                return;
            }
            $sent = 0;
            foreach ($recipients as $address => $locale) {
                try {
                    $this->mailer->send(new Mail($address, 'workflow_email', 'workflow_email.subject', ['%subject%' => $subject], ['body' => $body], $locale, $workspace->name));
                    ++$sent;
                } catch (MailNotSent $e) {
                    $this->logger->error('A workflow\'s email could not be sent: {message}', ['message' => $e->getMessage()]);
                }
            }
            $this->runs->countEmails($sent);
        });
        $output['sent'] = true;

        return [$input, $output, true];
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, bool}
     *
     * @throws StepFailed
     */
    public function notify(Step $step, DataChain $chain, RunLog $run, bool $dryRun): array
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $chain->text($step->string('title'))));
        if ('' === $title) {
            throw new StepFailed('The title is empty.');
        }
        $text = null === $step->string('text') ? null : $chain->text($step->string('text'));
        $link = null;
        if (null !== $target = $step->link()) {
            $id = trim($chain->text($target['id']));
            if (!Uuid::isValid($id)) {
                throw new StepFailed(\sprintf('The link names no record of %s: "%s" is no id.', $target['object'], $id));
            }
            $link = \sprintf('/o/%s/%s/edit', $target['object'], strtolower($id));
        }
        $message = new NotificationMessage($title, $text, $link, \sprintf('Workflow %s', $run->workflow->name));
        [$recipients, $leftOut] = $this->recipients($step, $chain, $this->current(), false);
        $input = ['to' => $step->options['to'] ?? [], 'title' => $message->title, 'text' => $message->text, 'link' => $link];
        $output = ['recipients' => \count($recipients), 'left_out' => $leftOut, 'sent' => false];
        if ($dryRun || [] === $recipients) {
            return [$input, $output, true];
        }
        $this->transactions->whenDone(function (bool $committed) use ($recipients, $message): void {
            if (!$committed) {
                return;
            }
            // Past the plan's emails, the bell only: a notification is never lost for its email.
            $notified = $this->notifier->notify(array_keys($recipients), $message, 0 !== $this->quota->emailsLeft());
            $this->runs->countEmails($notified->emailed);
        });
        $output['sent'] = true;

        return [$input, $output, true];
    }

    /**
     * The addresses a step reaches, each with its language (a member's), and how many of what it
     * names were left out: an address that is no active member, a field holding no address.
     *
     * @return array{array<string, string|null>, int}
     *
     * @throws StepFailed more than MAX_RECIPIENTS
     */
    private function recipients(Step $step, DataChain $chain, Workspace $workspace, bool $fields): array
    {
        /** @var array<string, Member> $members */
        $members = [];
        foreach ($this->users->members($workspace->id) as $member) {
            if ($member->active) {
                $members[mb_strtolower($member->email)] = $member;
            }
        }
        $recipients = [];
        $leftOut = 0;
        foreach ($step->recipients() as ['kind' => $kind, 'value' => $value]) {
            if ('role' === $kind) {
                // Its holders, directly or through a role containing it; members only.
                $holders = array_merge(...array_map($this->roles->assigneesOf(...), [$value, ...$this->roles->rolesContaining($value)]));
                foreach (array_unique(array_map(mb_strtolower(...), $holders)) as $email) {
                    if (isset($members[$email])) {
                        $recipients[$email] = $members[$email]->locale;
                    }
                }
                continue;
            }
            $email = mb_strtolower(trim($chain->text($value)));
            if ('member' === $kind) {
                isset($members[$email]) ? $recipients[$email] = $members[$email]->locale : ++$leftOut;
                continue;
            }
            // A field's address: anyone's, the metadata said it holds an email (DefinitionValidator).
            if ($fields && false !== filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                $recipients[$email] = $members[$email]->locale ?? null;
            } else {
                ++$leftOut;
            }
        }
        if (\count($recipients) > self::MAX_RECIPIENTS) {
            throw new StepFailed(\sprintf('%d recipients: one step reaches %d at most.', \count($recipients), self::MAX_RECIPIENTS));
        }

        return [$recipients, $leftOut];
    }

    private function current(): Workspace
    {
        return $this->workspaces->byId($this->workspace->id()) ?? throw new StepFailed('The workspace of this run is gone.');
    }
}
