<?php

declare(strict_types=1);

namespace App\TemplateFormat;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

/**
 * Checks the spec of a document against the JSON Schema of its kind (Resources/schema/v1/).
 * The same schemas give editors autocompletion on a template's files.
 */
final class SchemaValidator
{
    private const string DIRECTORY = __DIR__.'/Resources/schema/v1';

    private ?Validator $validator = null;

    /**
     * @return list<Problem>
     */
    public function validate(Document $document): array
    {
        $file = self::DIRECTORY.'/'.strtolower($document->kind->value).'.json';
        if (!is_file($file)) {
            return [new Problem($document->path, 'kind', \sprintf('Documents of kind %s are not supported yet.', $document->kind->value))];
        }

        $result = $this->validator()->validate(Helper::toJSON($document->spec), self::schemaId($document->kind));
        $error = $result->error();
        if (null === $error) {
            return [];
        }

        $problems = [];
        foreach ((new ErrorFormatter())->format($error) as $pointer => $messages) {
            // "/fields/0/name" becomes "spec.fields.0.name".
            $at = 'spec'.str_replace('/', '.', (string) $pointer);
            foreach (\is_array($messages) ? $messages : [] as $message) {
                $problems[] = new Problem($document->path, rtrim($at, '.'), \is_string($message) ? $message : 'Invalid.');
            }
        }

        return $problems;
    }

    private function validator(): Validator
    {
        if (null === $this->validator) {
            $this->validator = new Validator();
            // Every problem of a document at once, as the app reports a form's errors.
            $this->validator->setMaxErrors(100);
            foreach (DocumentKind::cases() as $kind) {
                $file = self::DIRECTORY.'/'.strtolower($kind->value).'.json';
                if (is_file($file)) {
                    $this->validator->resolver()?->registerFile(self::schemaId($kind), $file);
                }
            }
        }

        return $this->validator;
    }

    private static function schemaId(DocumentKind $kind): string
    {
        return 'urn:metadata-driven:schema:v1:'.strtolower($kind->value);
    }
}
