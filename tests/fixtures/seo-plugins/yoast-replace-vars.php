<?php
/**
 * Yoast SEO's variable replacement, reduced to something a test can see:
 * it answers %%sitedesc%% itself and leaves every other variable alone.
 *
 * @package AutoSEOManager\Tests
 */

/**
 * @param string $text Text with %%variables%%.
 * @param mixed  $args Post or array.
 * @return string
 */
function wpseo_replace_vars($text, $args) {
    return str_replace('%%sitedesc%%', 'FROM YOAST', $text);
}
