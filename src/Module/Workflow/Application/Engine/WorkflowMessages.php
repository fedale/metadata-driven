<?php

declare(strict_types=1);

namespace App\Module\Workflow\Application\Engine;

use App\Module\Access\Contract\WorkspaceRolesInterface;
use App\Module\Metadata\Contract\Dto\KeyType;
use App\Module\Notification\Contract\Dto\NotificationMessage;
use App\Module\Notification\Contract\NotifierInterface;
use App\Module\User\Contract\Dto\Member;
use App\Module\User\Contract\UserManagerInterface;
use App\Module\Workflow\Application\Definition\Step;
use App\Shared\Mail\Mail;
use App\Shared\Mail\Mailer;
use App\Shared\Mail\MailNotSent;
use App\Shared\Message\Message;
use App\Shared\Transaction\TransactionRunner;
use App\Shared\Workspace\CurrentWorkspace;
use App\Shared\Workspace\Workspace;
use App\Shared\Workspace\Workspaces;
use Psr\Log\LoggerInterface;

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
            throw new StepFailed(new Message('workflow.run.subject_empty', 'The subject is empty.'));
        }
        $body = mb_substr($chain->text($step->string('body')), 0, self::MAX_BODY);
        $workspace = $platform ?? $this->current();
        [$recipients, $leftOut] = $this->recipients($step, $chain, $workspace, true);
        $input = ['to' => $step->options['to'] ?? [], 'subject' => $subject, 'body' => $body];
        $output = ['recipients' => \count($recipients), 'left_out' => $leftOut, 'sent' => false];
        if ($dryRun || [] === $recipients) {
            return [$input, $output, true];
        }
        // Taken now, in the run's transaction, not counted once sent: the steps after this one
        // and the runs at the same time would all see the same emails left.
        if (null === $platform && !$this->quota->reserveEmails(\count($recipients))) {
            throw new StepFailed(new Message('workflow.run.emails_over_plan', 'The plan of this workspace allows %left% more emails this month: this step sends %count%.', ['left' => $this->quota->emailsLeft() ?? 0, 'count' => \count($recipients)]));
        }
        $this->transactions->whenDone(function (bool $committed) use ($recipients, $subject, $body, $workspace, $platform): void {
            if (!$committed) {
                // The reservation went with the rolled back transaction.
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
            // Taken ahead: those that could not be sent are given back. The system's are counted
            // nowhere (no workspace).
            if (null === $platform) {
                $this->runs->countEmails($sent - \count($recipients));
            }
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
            throw new StepFailed(new Message('workflow.run.title_empty', 'The title is empty.'));
        }
        $text = null === $step->string('text') ? null : $chain->text($step->string('text'));
        $link = null;
        if (null !== $target = $step->link()) {
            $named = trim($chain->text($target['id']));
            $id = KeyType::canonical($named) ?? throw new StepFailed(new Message('workflow.run.link_not_an_id', 'The link names no record of %object%: "%id%" is no id.', ['object' => $target['object'], 'id' => $named]));
            $link = \sprintf('/o/%s/%s/edit', $target['object'], $id);
        }
        $message = new NotificationMessage($title, $text, $link, \sprintf('Workflow %s', $run->workflow->name));
        [$recipients, $leftOut, $byEmail] = $this->recipients($step, $chain, $this->current(), false);
        $input = ['to' => $step->options['to'] ?? [], 'title' => $message->title, 'text' => $message->text, 'link' => $link];
        $output = ['recipients' => \count($recipients), 'left_out' => $leftOut, 'sent' => false];
        if ($dryRun || [] === $recipients) {
            return [$input, $output, true];
        }
        // The emails of those who chose them, taken now as a send_email's are. Past the plan's,
        // the bell only: a notification is never lost for its email.
        $emails = $this->quota->reserveEmails($byEmail) ? $byEmail : 0;
        $this->transactions->whenDone(function (bool $committed) use ($recipients, $message, $emails): void {
            if (!$committed) {
                return;
            }
            $notified = $this->notifier->notify(array_keys($recipients), $message, $emails > 0);
            $this->runs->countEmails($notified->emailed - $emails);
        });
        $output['sent'] = true;

        return [$input, $output, true];
    }

    /**
     * The addresses a step reaches, each with its language (a member's), and how many of what it
     * names were left out: an address that is no active member, a field holding no address.
     *
     * @return array{array<string, string|null>, int, int} the addresses, those left out, and the
     *                                                     members among them who get their
     *                                                     notifications by email
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
            throw new StepFailed(new Message('workflow.run.too_many_recipients', '%count% recipients: one step reaches %max% at most.', ['count' => \count($recipients), 'max' => self::MAX_RECIPIENTS]));
        }

        $byEmail = \count(array_filter(array_keys($recipients), static fn (string $email): bool => true === ($members[$email] ?? null)?->notifyByEmail));

        return [$recipients, $leftOut, $byEmail];
    }

    private function current(): Workspace
    {
        return $this->workspaces->byId($this->workspace->id()) ?? throw new StepFailed(new Message('workflow.run.workspace_gone', 'The workspace of this run is gone.'));
    }
}
