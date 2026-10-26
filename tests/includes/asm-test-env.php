<?php
/**
 * Paths and set-up shared by the PHPUnit bootstrap and tests/request.php.
 *
 * Nothing here needs WordPress loaded.
 *
 * @package AutoSEOManager\Tests
 */

/** The directory the release zip unpacks to, and so the installed slug. */
define('ASM_TEST_SLUG', 'auto-seo-manager');

/** plugin_basename() of the main file on a real install. */
define('ASM_TEST_PLUGIN', ASM_TEST_SLUG . '/auto-seo-manager.php');

/** The GitHub repository the updater reads releases from. */
define('ASM_TEST_REPO', 'nyuchi/auto-seo-manager');

/** The plugin's source tree (the repository root). */
define('ASM_TEST_ROOT', dirname(__DIR__, 2));

/**
 * WordPress core directory, as tests/bin/install-wp.sh used it.
 *
 * @return string Without a trailing slash.
 */
function asm_test_core_dir() {
    $dir = getenv('WP_CORE_DIR');

    if (!$dir) {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests';
    }

    $dir = rtrim($dir, '/\\');

    // Canonical: WordPress will not create directories under a path that
    // contains "..".
    $real = realpath($dir);

    return $real ? $real : $dir;
}

/**
 * Stop with an instruction rather than a stack of include warnings when
 * WordPress has not been downloaded yet.
 */
function asm_test_env_check() {
    $core = asm_test_core_dir();

    if (!file_exists($core . '/wp-settings.php') || !file_exists($core . '/wp-content/db.php')) {
        fwrite(STDERR, "WordPress is not installed in {$core}.\nRun: bash tests/bin/install-wp.sh (see TESTING.md)\n");
        exit(1);
    }
}

/**
 * Point wp-content/plugins/<slug> at the working tree.
 *
 * @param string $root Repository root.
 */
function asm_test_link_plugin($root) {
    $plugins = asm_test_core_dir() . '/wp-content/plugins';
    $link    = $plugins . '/' . ASM_TEST_SLUG;

    if (is_link($link) && realpath($link) === realpath($root)) {
        return;
    }

    if (is_link($link)) {
        unlink($link);
    } elseif (file_exists($link)) {
        fwrite(STDERR, "{$link} exists and is not a symlink; remove it so the tests use the working tree.\n");
        exit(1);
    }

    if (!is_dir($plugins)) {
        mkdir($plugins, 0777, true);
    }

    symlink($root, $link);
}

/**
 * Copy the plugin's runtime files (what the release zip ships) into $dest.
 *
 * Used to give a child request a disposable install that an automatic update
 * may overwrite, without ever touching the working tree.
 *
 * @param string $dest    Destination directory, created if missing.
 * @param array  $replace Optional search => replace applied to the main file.
 */
function asm_test_copy_plugin($dest, $replace = array()) {
    $skip = array('.git', '.github', 'vendor', 'tests', 'node_modules', 'build', 'tools', '.claude');
    $root = ASM_TEST_ROOT;

    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($file) use ($skip, $root) {
                $rel = substr($file->getPathname(), strlen($root) + 1);

                return !in_array(strtok($rel, '/'), $skip, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }

    foreach ($it as $file) {
        $target = $dest . '/' . substr($file->getPathname(), strlen($root) + 1);

        if ($file->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
            continue;
        }

        copy($file->getPathname(), $target);
    }

    if ($replace) {
        $main = $dest . '/' . basename(ASM_TEST_PLUGIN);
        file_put_contents($main, strtr(file_get_contents($main), $replace));
    }
}

/**
 * Remove a directory tree the tests created.
 *
 * @param string $dir Directory.
 */
function asm_test_rmdir($dir) {
    if (!is_dir($dir) || is_link($dir)) {
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);
        }

        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }

    rmdir($dir);
}
