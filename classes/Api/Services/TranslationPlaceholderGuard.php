<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

/**
 * Hides the machine-readable parts of a translation string from a translator.
 *
 * A lang string is not prose. `Showing %1$s of %2$s`, `{count} items` and
 * `<a href="{url}">read more</a>` all carry tokens the runtime substitutes, and
 * a machine-translation engine will happily reorder, translate or mangle them —
 * `%1$s` comes back as `%1 $ s`, `{count}` as `{compte}`, and the string is
 * broken at render time with no error anywhere.
 *
 * The fix is mechanical, not a matter of prompting: the providers behind
 * ai-translate are DeepL and Google, which take text and give back text. So
 * each token is swapped for an opaque sentinel before translation and restored
 * after, and {@see preserved()} refuses any result whose sentinel set changed.
 *
 * ## ICU messages are masked whole, on purpose
 *
 * `{n, plural, one {# item} other {# items}}` is not translatable by machine in
 * any useful sense: the target language decides how many plural categories
 * exist (Russian has three, Arabic six), so a two-form English message cannot
 * be mapped mechanically. Rather than emit something plausible and wrong, the
 * whole block is masked and {@see analyze()} reports `needs_human` so the
 * caller can route it to a person instead.
 */
final class TranslationPlaceholderGuard
{
    /**
     * Sentinel shape. Chosen to survive translation intact: no letters an
     * engine might translate, no punctuation it might re-space, and a digit
     * run that keeps ordering recoverable.
     */
    private const TOKEN = "\u{2062}%d\u{2062}";

    private const TOKEN_PATTERN = '/\x{2062}(\d+)\x{2062}/u';

    /** printf-style: %s %d %1$s %02.2f %% */
    private const PRINTF = '/%(?:%|\d+\$)?[-+ 0#\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/';

    /** HTML/Twig-ish tags: <a href="…">, </strong>, <br /> */
    private const TAG = '/<\/?[a-zA-Z][a-zA-Z0-9-]*(?:\s[^<>]*)?\/?>/';

    /** ICU argument types that make a brace group a message, not a placeholder. */
    private const ICU_TYPES = ['plural', 'select', 'selectordinal', 'number', 'date', 'time', 'ordinal'];

    /**
     * Replace every machine-readable token with a sentinel.
     *
     * @return array{0: string, 1: array<int, string>} [masked text, token map]
     */
    public function mask(string $text): array
    {
        $map = [];

        // Brace groups first: they can contain both printf tokens and tags, and
        // an ICU message must be masked as one unit rather than shredded.
        $text = $this->maskBraceGroups($text, $map);

        foreach ([self::PRINTF, self::TAG] as $pattern) {
            $text = (string) preg_replace_callback(
                $pattern,
                function (array $m) use (&$map): string {
                    $map[] = $m[0];

                    return sprintf(self::TOKEN, count($map) - 1);
                },
                $text
            );
        }

        return [$text, $map];
    }

    /**
     * Put the original tokens back.
     *
     * Runs to a fixed point rather than once, because tokens nest: in
     * `<a href="{url}">` the brace group is masked first, so the tag stored in
     * the map still contains that inner sentinel. A single pass would restore
     * the tag and leave `<a href="⁢1⁢">` in the output. The map is finite and
     * each pass consumes at least one entry, so the loop terminates.
     *
     * @param array<int, string> $map
     */
    public function unmask(string $text, array $map): string
    {
        for ($pass = 0; $pass <= count($map); $pass++) {
            $next = (string) preg_replace_callback(
                self::TOKEN_PATTERN,
                static fn(array $m): string => $map[(int) $m[1]] ?? $m[0],
                $text
            );
            if ($next === $text) {
                break;
            }
            $text = $next;
        }

        return $text;
    }

    /**
     * The sentinel ids present in a masked string, sorted.
     *
     * Order is deliberately ignored: a translation may legitimately move
     * `%1$s` after `%2$s` when the target language's word order demands it.
     * What must not change is *which* tokens are present.
     *
     * @return array<int, int>
     */
    public function tokens(string $masked): array
    {
        preg_match_all(self::TOKEN_PATTERN, $masked, $matches);
        $ids = array_map('intval', $matches[1] ?? []);
        sort($ids);

        return $ids;
    }

    /**
     * True when a translation kept every token the source had, and invented none.
     *
     * This is the check that turns a silent corruption into a visible refusal.
     */
    public function preserved(string $maskedSource, string $maskedTranslation): bool
    {
        return $this->tokens($maskedSource) === $this->tokens($maskedTranslation);
    }

    /**
     * What a caller needs to know before sending a string to a machine.
     *
     * @return array{
     *     masked: string,
     *     map: array<int, string>,
     *     token_count: int,
     *     needs_human: bool,
     *     translatable: bool
     * } `needs_human` marks an ICU message; `translatable` is false when
     *   masking left nothing but sentinels and whitespace, so there are no
     *   words to translate at all.
     */
    public function analyze(string $text): array
    {
        [$masked, $map] = $this->mask($text);

        $needsHuman = false;
        foreach ($map as $token) {
            if ($this->isIcuMessage($token)) {
                $needsHuman = true;
                break;
            }
        }

        $stripped = trim((string) preg_replace(self::TOKEN_PATTERN, '', $masked));

        return [
            'masked' => $masked,
            'map' => $map,
            'token_count' => count($map),
            'needs_human' => $needsHuman,
            'translatable' => $stripped !== '',
        ];
    }

    /**
     * Mask balanced `{…}` groups, nesting included.
     *
     * Written as a scanner rather than a regex because ICU messages nest
     * arbitrarily (`{n, plural, other {# of {total}}}`) and a non-recursive
     * pattern would stop at the first inner brace.
     *
     * @param array<int, string> $map
     */
    private function maskBraceGroups(string $text, array &$map): string
    {
        $out = '';
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            if ($text[$i] !== '{') {
                $out .= $text[$i];
                $i++;
                continue;
            }

            $depth = 0;
            $start = $i;
            $end = null;
            for ($j = $i; $j < $length; $j++) {
                if ($text[$j] === '{') {
                    $depth++;
                } elseif ($text[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $j;
                        break;
                    }
                }
            }

            if ($end === null) {
                // Unbalanced brace: leave it alone rather than swallow the rest
                // of the string.
                $out .= substr($text, $i);
                break;
            }

            $map[] = substr($text, $start, $end - $start + 1);
            $out .= sprintf(self::TOKEN, count($map) - 1);
            $i = $end + 1;
        }

        return $out;
    }

    /** True for `{n, plural, …}`-style messages, false for a bare `{name}`. */
    private function isIcuMessage(string $token): bool
    {
        if ($token === '' || $token[0] !== '{') {
            return false;
        }

        $inner = substr($token, 1, -1);
        $parts = explode(',', $inner, 3);
        if (count($parts) < 2) {
            return false;
        }

        return in_array(strtolower(trim($parts[1])), self::ICU_TYPES, true);
    }
}
