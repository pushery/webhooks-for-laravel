<?php

declare(strict_types=1);

namespace Pushery\Webhooks\Core\Http;

/**
 * Strips the credentials out of a transport error message before it is persisted as a
 * delivery's `error`. The sibling of {@see HeaderRedactor}, and it uses the same marker,
 * because the two defend the same thing at two different exits.
 *
 * A transport failure's message ends in the URL it failed against, and a webhook endpoint
 * URL is a place hosts really do put credentials — `https://TOKEN@receiver.test/hook`, or a
 * `?token=` query. That message is written verbatim into `webhook_deliveries.error`, and
 * that table has no `url` column, so the message is the ONLY place such a credential can
 * come to rest there.
 *
 * ⚠️ THE REASON THIS IS THE PACKAGE'S JOB AND NOT THE LIBRARY'S: guzzle already redacts,
 * and it redacts DIFFERENTLY depending on which psr7 major the host resolved — both of
 * which this package's `^2.7|^3.0` allows. psr7 2 returns early unless the userinfo
 * contains a colon (`Utils::redactUserInfo`), so a username-only credential passes through
 * untouched and the query string is kept; psr7 3 redacts every non-empty userinfo and
 * empties the query. Inheriting that means whether a token is stored in clear text is
 * decided by a dependency resolution, which is not a property anyone can reason about.
 *
 * So the message is rewritten here, on both majors, to one shape. A URL already redacted
 * upstream passes through this unchanged in meaning — its `***` is re-marked, so the two
 * majors produce identical text rather than merely equally safe text.
 *
 * The query string is dropped rather than masked, which is what psr7 3 chose upstream and
 * costs a little diagnostic detail. It is the right trade here: a query is exactly where a
 * token hides, and the host already knows its own endpoint URL.
 *
 * @internal
 */
final class ErrorMessageRedactor
{
    /**
     * The marker written in place of anything credential-bearing. Deliberately the same
     * string {@see HeaderRedactor} uses, so one grep finds every masked value.
     */
    public const string MARKER = '[redacted]';

    /**
     * What a URL is replaced with when it cannot be parsed at all. Naming the URL is not
     * worth the risk: the substring matched as a URL, so whatever is in it may well be a
     * credential, and a message is not a place to gamble.
     */
    public const string UNPARSEABLE = '[unparseable url]';

    /**
     * Rewrite every http(s) URL in the message without its userinfo and without its query.
     * Everything else in the message — the errno, the cURL text, the timings — is untouched,
     * because that is the part an operator reads.
     */
    public static function redact(string $message): string
    {
        $redacted = preg_replace_callback(
            '~\bhttps?://\S+~i',
            static fn (array $match): string => self::rewrite($match[0]),
            $message,
        );

        // preg_replace_callback answers null only on a backtrack or recursion limit. The
        // original message is the wrong answer there — it is the one that may carry the
        // credential — so refuse rather than pass it through.
        return $redacted ?? self::UNPARSEABLE;
    }

    /**
     * Take one matched URL, split off the sentence punctuation the greedy `\S+` swallowed,
     * and rebuild the rest from its parsed components.
     */
    private static function rewrite(string $matched): string
    {
        $trailing = '';

        // A message reads `... for https://host/hook.` or `(https://host/hook)`. That
        // punctuation belongs to the sentence, and carrying it into parse_url() turns a
        // perfectly good URL into an unparseable one.
        while ($matched !== '' && str_contains('.,;:!?)]}\'"', substr($matched, -1))) {
            $trailing = substr($matched, -1).$trailing;
            $matched = substr($matched, 0, -1);
        }

        return self::withoutCredentials($matched).$trailing;
    }

    private static function withoutCredentials(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return self::UNPARSEABLE;
        }

        $rebuilt = $parts['scheme'].'://';

        if (isset($parts['user']) || isset($parts['pass'])) {
            $rebuilt .= self::MARKER.'@';
        }

        $rebuilt .= $parts['host'];

        if (isset($parts['port'])) {
            $rebuilt .= ':'.$parts['port'];
        }

        return $rebuilt.($parts['path'] ?? '');
    }
}
