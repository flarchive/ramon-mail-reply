<?php

namespace Ramon\MailReply\Parser;

use EmailReplyParser\EmailReplyParser as WdEmailReplyParser;

/**
 * Thin wrapper around willdurand/email-reply-parser that additionally strips
 * HTML and common signature delimiters. The goal is to reduce an inbound
 * message body to just the user's new reply content.
 */
class EmailParser
{
    public function extractReply(string $rawBody, bool $stripSignatures = true): string
    {
        $text = $this->toPlainText($rawBody);

        $reply = $stripSignatures
            ? WdEmailReplyParser::parseReply($text)
            : $this->stripQuotesOnly($text);

        return trim($reply);
    }

    protected function toPlainText(string $body): string
    {
        // If the payload looks like HTML, strip tags but preserve paragraph
        // breaks so quote detection (parser looks at blank lines) still works.
        if (preg_match('/<(html|body|div|p|br)[\s>]/i', $body)) {
            $body = preg_replace('/<br\s*\/?>/i', "\n", $body);
            $body = preg_replace('/<\/p>/i', "\n\n", $body);
            $body = strip_tags($body);
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Normalise CRLF → LF so regexes stay portable.
        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    protected function stripQuotesOnly(string $text): string
    {
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            // Stop at common "On … wrote:" headers or forwarded markers.
            if (preg_match('/^\s*(On .+ wrote:|Em .+ escreveu:|-----\s?Original Message\s?-----|Forwarded message)/i', $line)) {
                break;
            }

            // Drop quoted lines that start with ">".
            if (preg_match('/^\s*>/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        return rtrim(implode("\n", $kept));
    }
}
