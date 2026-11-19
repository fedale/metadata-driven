<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * An email of the application to one person: a template of templates/email/ (its HTML and its
 * text twin), the translation key of its subject (domain "email"), in the recipient's language.
 */
final readonly class Mail
{
    /**
     * @param string               $template          the name under templates/email/: "invitation" for invitation.html.twig and invitation.txt.twig
     * @param string               $subject           a translation key of the domain "email"
     * @param array<string, mixed> $subjectParameters
     * @param array<string, mixed> $context           the template's variables
     * @param string|null          $locale            the recipient's language; null for the application's default
     * @param string|null          $senderName        the name the email comes from; null for the current workspace's, or the application's
     */
    public function __construct(
        public string $to,
        public string $template,
        public string $subject,
        public array $subjectParameters = [],
        public array $context = [],
        public ?string $locale = null,
        public ?string $senderName = null,
    ) {
    }
}
