<?php

namespace Ramon\MailReply\Parser;

use EmailReplyParser\EmailReplyParser as WdEmailReplyParser;

/**
 * Extrai a parte "nova" de um e-mail de resposta (a mensagem real que o
 * usuário escreveu), descartando assinaturas, histórico citado e
 * artefatos MIME que tenham escapado do Rfc822Parser.
 *
 * Estratégia em fases:
 *
 *   1. `cleanupMimeArtifacts` — remove linhas que são claramente
 *      cabeçalho MIME ou boundary (`--xxx`, `Content-Type:`,
 *      `Content-Transfer-Encoding:`, etc.). Decodifica quoted-printable
 *      se for marcado. Necessário quando o parser MIME pré-falhou e
 *      jogou body cru com headers literais.
 *
 *   2. `toPlainText` — se HTML, strip de tags preservando quebras.
 *
 *   3. `truncateAtQuoteMarker` — corta no cabeçalho de quote/forward,
 *      independente do idioma. CRÍTICO rodar ANTES da willdurand, que só
 *      entende inglês e devolveria o histórico inteiro intocado.
 *
 *   4. `parseReply` (willdurand) — remove signatures + quotes. Agressivo.
 *
 *   5. Se 4 zerou tudo, `stripQuotesOnly` (leve).
 *
 *   6. Se 5 também zerou, primeiras N linhas não-vazias do texto cru.
 *
 * Sempre retorna ALGO útil — nunca string vazia se o input tinha
 * conteúdo legível.
 */
class EmailParser
{
    private const RAW_FALLBACK_LINES = 30;

    public function extractReply(string $rawBody, bool $stripSignatures = true): string
    {
        if (trim($rawBody) === '') {
            return '';
        }

        $cleaned = $this->cleanupMimeArtifacts($rawBody);

        $text = $this->toPlainText($cleaned);

        if (trim($text) === '') {
            return '';
        }

        $text = $this->truncateAtQuoteMarker($text);

        if (trim($text) === '') {
            return '';
        }

        if ($stripSignatures) {
            $parsed = trim(WdEmailReplyParser::parseReply($text));
            if ($parsed !== '') {
                return $parsed;
            }
        }

        $parsed = trim($this->stripQuotesOnly($text));
        if ($parsed !== '') {
            return $parsed;
        }

        return $this->firstNonEmptyLines($text, self::RAW_FALLBACK_LINES);
    }

    /**
     * Remove artefatos MIME que possam ter chegado no body bruto quando
     * o Rfc822Parser cai no fallback de "body cru" (multipart sem
     * boundary detectado, parser falhou em isolar a parte text/plain).
     *
     * O que limpa:
     *   - Linhas `--<boundary>` (incluindo `--<boundary>--` de fechamento)
     *   - Headers MIME no início de uma "parte": Content-Type,
     *     Content-Transfer-Encoding, Content-Disposition, MIME-Version,
     *     charset, etc. — apenas quando aparecem isolados no meio do
     *     texto (não como conteúdo legítimo).
     *   - Aplica decode de quoted-printable se detectar marker.
     *
     * Duas passadas porque se na primeira ficou um header MIME isolado
     * (linha em branco intercalada), a segunda pega.
     */
    protected function cleanupMimeArtifacts(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        if (preg_match('/^Content-Transfer-Encoding:\s*quoted-printable/im', $body)) {
            $body = quoted_printable_decode($body);
        }
        if (preg_match('/^Content-Transfer-Encoding:\s*base64/im', $body)) {
            $body = preg_replace_callback(
                '/(?:^[A-Za-z0-9+\/=]{60,}\s*$\n?)+/m',
                static fn ($m) => base64_decode($m[0], false) ?: $m[0],
                $body,
            ) ?? $body;
        }

        $body = $this->stripMimeLines($body);
        $body = $this->stripMimeLines($body);

        return $body;
    }

    /**
     * Uma passada de remoção de linhas MIME (boundary + headers). O
     * regex de boundary `^--\S` é permissivo de propósito — qualquer
     * linha começando com `--` seguido de não-espaço é candidata.
     * Continuações indentadas de header também são puladas.
     */
    protected function stripMimeLines(string $body): string
    {
        $lines = explode("\n", $body);
        $kept = [];
        $skipNextBlank = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^--\S/', $trimmed)) {
                $skipNextBlank = true;
                continue;
            }

            if (preg_match('/^(Content-Type|Content-Transfer-Encoding|Content-Disposition|Content-ID|Content-Description|MIME-Version)\s*[:=]/i', $line)) {
                $skipNextBlank = true;
                continue;
            }

            if ($skipNextBlank && preg_match('/^\s+\S/', $line) && trim($line) !== '') {
                continue;
            }

            if ($skipNextBlank && $trimmed === '') {
                $skipNextBlank = false;
                continue;
            }

            $skipNextBlank = false;
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    /**
     * Se o payload parece HTML, descarta tags mas preserva quebras de
     * parágrafo (parser de quote olha linhas em branco pra detectar).
     */
    protected function toPlainText(string $body): string
    {
        if (preg_match('/<(html|body|div|p|br)[\s>]/i', $body)) {
            $body = preg_replace('/<br\s*\/?>/i', "\n", $body);
            $body = preg_replace('/<\/p>/i', "\n\n", $body);
            $body = strip_tags($body);
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return str_replace(["\r\n", "\r"], "\n", $body);
    }

    /**
     * Trunca o body no primeiro marcador de quote/forward,
     * independente do idioma do cliente de e-mail.
     *
     * Estratégia: sinais estruturais que valem em qualquer idioma —
     * não uma lista de palavras-chave por locale.
     *
     *   - Marcador "Original Message" / "Forwarded message" (qualquer
     *     idioma, detectado pelas linhas com dashes).
     *   - Apple Mail / Gmail localizado: a linha do cabeçalho de quote
     *     SEMPRE termina com `:` (ASCII ou full-width `：`), SEMPRE
     *     contém um ano de 4 dígitos, e quase sempre tem vírgula. Vale
     *     em PT, EN, ES, FR, DE, IT, JA, ZH, RU, etc.
     *   - Variante sem vírgula (DE, ZH): ano + HH:MM + termina com `:`.
     *   - Linha com `<email@host>` + termina com `:` — Outlook/Apple
     *     "From: Foo <foo@bar> wrote:" e parentes.
     *   - Cabeçalho Outlook colado: linha com `<word>: <stuff <email>>`
     *     (campo nomeado seguido de endereço — From/De/Von/差出人/Da/...).
     */
    protected function truncateAtQuoteMarker(string $text): string
    {
        $lines = explode("\n", $text);

        $patterns = [
            '/^\s*-{2,}\s*\S[^\n]*\S\s*-{2,}\s*$/u',
            '/^\s*>?\s*(?=.*[\d\p{N}]{4})(?=.*[,，、]).+[:：]\s*$/u',
            '/^\s*>?\s*(?=.*[\d\p{N}]{4})(?=.*[\d\p{N}]{1,2}:[\d\p{N}]{2}).+[:：]\s*$/u',
            '/^\s*>?\s*.*<[^@\s<>]+@[^@\s<>]+>.*[:：]\s*$/u',
            '/^\s*\S{1,30}\s*[:：]\s+.*<[^@\s<>]+@[^@\s<>]+>/u',
        ];

        foreach ($lines as $i => $line) {
            foreach ($patterns as $p) {
                if (@preg_match($p, $line) === 1) {
                    return implode("\n", array_slice($lines, 0, $i));
                }
            }
        }

        return $text;
    }

    /**
     * Strip leve: para no primeiro "On … wrote:" / "Em … escreveu:" /
     * "-----Original Message-----" e pula linhas com `>` no começo.
     */
    protected function stripQuotesOnly(string $text): string
    {
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(On .+ wrote:|Em .+ escreveu:|-----\s?Original Message\s?-----|Forwarded message)/i', $line)) {
                break;
            }

            if (preg_match('/^\s*>/', $line)) {
                continue;
            }

            $kept[] = $line;
        }

        return rtrim(implode("\n", $kept));
    }

    /**
     * Pega as primeiras N linhas não-vazias do texto. Usado como último
     * fallback quando o parser/strip zera tudo.
     */
    protected function firstNonEmptyLines(string $text, int $maxLines): string
    {
        $lines = explode("\n", $text);
        $kept = [];
        $count = 0;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                if ($kept !== [] && end($kept) !== '') {
                    $kept[] = '';
                }
                continue;
            }

            $kept[] = $line;
            $count++;

            if ($count >= $maxLines) {
                break;
            }
        }

        return rtrim(implode("\n", $kept));
    }
}
