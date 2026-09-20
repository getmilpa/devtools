<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\DevTools\Operations;

/** A native parser finding, without executing the proposal or interpreting process stderr. */
final class SyntaxFinding
{
    /** Location attributes the finding; only the parser and message identify its information.
     * Null is not a compilation verdict: php -l still judges bodies without a ParseError.
     *
     * @return array{parser: string, message: string, line: int, fingerprint: string}|null
     */
    public static function inspect(string $content): ?array
    {
        try {
            $tokens = token_get_all($content, TOKEN_PARSE);
            unset($tokens);
        } catch (\ParseError $error) {
            $parser = 'php-token-parse/v1';
            $message = $error->getMessage();
            // PHP embeds the opening delimiter's line in this specific mismatch message.
            // Retain the full diagnostic, but do not mistake that location for new information.
            $information = preg_replace("/^(Unclosed '(?:\\(|\\{|\\[)') on line [1-9][0-9]*( does not match '(?:\\)|\\}|\\])')$/D", '$1$2', $message);
            return [
                'parser' => $parser,
                'message' => $message,
                'line' => $error->getLine(),
                'fingerprint' => hash('sha256', json_encode([$parser, $information], JSON_THROW_ON_ERROR)),
            ];
        } catch (\CompileError) {
            // A different engine failure must still pass the existing compilation gate.
        }
        return null;
    }
}
