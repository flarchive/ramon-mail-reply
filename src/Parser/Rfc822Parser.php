<?php

namespace Ramon\MailReply\Parser;

/**
 * Parser mínimo de mensagens RFC 822 — extrai To/From/Subject + body
 * (text/plain ou primeira parte multipart). MIME profundo (anexos, etc.)
 * é responsabilidade dos provedores de inbound; cobrimos o suficiente
 * para mensagens reais de notificação/reply que passam por MTAs comuns
 * (Exim, Postfix, Sendmail).
 *
 * Headers priorizados na extração de recipients (ordem importa):
 *   Envelope-To, X-Original-To, Delivered-To, X-Envelope-To,
 *   X-Delivered-To, To, Cc
 * — porque o `To:` visível pode perder o `+TAG` quando o MTA reescreve a
 * entrega, mas headers de envelope preservam o alias original.
 */
class Rfc822Parser
{
    /**
     * @return array{
     *     to: array<int, string>,
     *     from: string,
     *     subject: string,
     *     body_text: string,
     *     body_html: string,
     *     headers: array<string, string>
     * }
     */
    public function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headerBlock, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');

        $headers = $this->parseHeaders($headerBlock);

        $to = [];

        foreach (['envelope-to', 'x-original-to', 'delivered-to', 'x-envelope-to', 'x-delivered-to'] as $name) {
            if (! isset($headers[$name])) continue;
            foreach ($this->splitAddresses($headers[$name]) as $addr) {
                $to[] = $addr;
            }
        }

        foreach (['to', 'cc'] as $name) {
            if (! isset($headers[$name])) continue;
            foreach ($this->splitAddresses($headers[$name]) as $addr) {
                $to[] = $addr;
            }
        }
        $to = array_values(array_unique($to));

        [$bodyText, $bodyHtml] = $this->extractBody($headers, $body);

        if (trim($bodyText) === '' && trim($bodyHtml) === '' && trim($body) !== '') {
            $bodyText = $body;
        }

        return [
            'to'        => $to,
            'from'      => $this->extractFirstAddress($headers['from'] ?? ''),
            'subject'   => $headers['subject'] ?? '',
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
            'headers'   => $headers,
        ];
    }

    /**
     * Continuação de header (linha começa com espaço/tab) é concatenada
     * na chave corrente.
     *
     * @return array<string, string>
     */
    protected function parseHeaders(string $headerBlock): array
    {
        $headers = [];
        $current = null;

        foreach (explode("\n", $headerBlock) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
                $headers[$current] .= ' '.trim($line);
                continue;
            }

            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $current = strtolower(trim($name));
            $headers[$current] = trim($value);
        }

        return $headers;
    }

    /**
     * Divide um valor de To:/Cc:/Envelope-To: em endereços individuais.
     *
     * @return array<int, string>
     */
    protected function splitAddresses(string $value): array
    {
        $parts = preg_split('/,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $out = [];

        foreach ($parts as $part) {
            $addr = $this->extractFirstAddress($part);
            if ($addr !== '') {
                $out[] = $addr;
            }
        }

        return $out;
    }

    protected function extractFirstAddress(string $value): string
    {
        if (preg_match('/<([^>]+@[^>]+)>/', $value, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $value, $m)) {
            return trim($m[0]);
        }
        return '';
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string}
     */
    protected function extractBody(array $headers, string $body): array
    {
        $contentType = strtolower($headers['content-type'] ?? '');

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $this->extractBoundary($contentType);

            if ($boundary === null) {
                $boundary = $this->detectBoundaryFromBody($body);
            }

            if ($boundary !== null) {
                return $this->extractMultipart($body, $boundary);
            }
        }

        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        $body = $this->decode($body, $encoding);

        if (str_starts_with($contentType, 'text/html')) {
            return ['', $body];
        }

        return [$body, ''];
    }

    /**
     * Detecta o boundary no header Content-Type. Suporta as variações:
     *
     *   boundary="X"
     *   boundary='X'
     *   boundary=X            (sem aspas)
     *   boundary =  X         (espaços ao redor do =)
     *
     * RFC 2046: bcharsnospace = DIGIT / ALPHA / "'" / "(" / ")" / "+" /
     * "_" / "," / "-" / "." / "/" / ":" / "=" / "?"  — boundary pode ter
     * vários chars, mas excluímos espaço/`;`/aspas dos termos da regex.
     */
    protected function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary\s*=\s*"([^"]+)"/i', $contentType, $m)) {
            return $m[1];
        }
        if (preg_match("/boundary\\s*=\\s*'([^']+)'/i", $contentType, $m)) {
            return $m[1];
        }
        if (preg_match('/boundary\s*=\s*([^\s;"\']+)/i', $contentType, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Última cartada quando o boundary não veio no header: procura
     * `--<algo>` no início de uma linha do body e usa esse `<algo>` como
     * boundary. Pega o primeiro candidato e confia.
     */
    protected function detectBoundaryFromBody(string $body): ?string
    {
        if (preg_match('/^--([^\s\-][^\r\n]*?)$/m', $body, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Suporta multipart aninhado (multipart/alternative dentro de
     * multipart/mixed) descendo recursivamente até achar text/plain ou
     * text/html.
     *
     * @return array{0: string, 1: string}
     */
    protected function extractMultipart(string $body, string $boundary): array
    {
        $parts = explode('--'.$boundary, $body);
        $text = '';
        $html = '';

        foreach ($parts as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || str_starts_with($part, '--')) continue;

            [$partHeadersBlock, $partBody] = array_pad(explode("\n\n", $part, 2), 2, '');
            $partHeaders = $this->parseHeaders($partHeadersBlock);
            $partType = strtolower($partHeaders['content-type'] ?? '');
            $partEncoding = strtolower($partHeaders['content-transfer-encoding'] ?? '');

            if (str_starts_with($partType, 'multipart/')) {
                $innerBoundary = $this->extractBoundary($partType) ?? $this->detectBoundaryFromBody($partBody);
                if ($innerBoundary !== null) {
                    [$innerText, $innerHtml] = $this->extractMultipart($partBody, $innerBoundary);
                    if ($innerText !== '' && $text === '') $text = $innerText;
                    if ($innerHtml !== '' && $html === '') $html = $innerHtml;
                    continue;
                }
            }

            $partBody = $this->decode($partBody, $partEncoding);

            if (str_starts_with($partType, 'text/plain') && $text === '') {
                $text = $partBody;
            } elseif (str_starts_with($partType, 'text/html') && $html === '') {
                $html = $partBody;
            }
        }

        return [$text, $html];
    }

    protected function decode(string $content, string $encoding): string
    {
        switch ($encoding) {
            case 'base64':
                return (string) base64_decode($content, true);
            case 'quoted-printable':
                return quoted_printable_decode($content);
            default:
                return $content;
        }
    }
}
