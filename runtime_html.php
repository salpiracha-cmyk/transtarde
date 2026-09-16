<?php
declare(strict_types=1);

/**
 * Replace the first matching HTML tag with literal content.
 *
 * preg_replace() interprets $0, $1 and backslashes in replacement strings.
 * Application JavaScript, CSS and JSON must never pass through that parser.
 */
function tt_replace_html_once(string $pattern, callable $replacement, string $html): string {
    $result = preg_replace_callback($pattern, $replacement, $html, 1);
    if ($result === null) throw new RuntimeException('Application HTML could not be assembled safely.');
    return $result;
}
