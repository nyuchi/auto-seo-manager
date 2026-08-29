<?php
/**
 * Cloudflare Images sizing.
 *
 * Offloading to Cloudflare Images removes the local sub-sizes and, on this
 * site, the recorded width and height with them. WordPress is then left holding
 * one image of unknown dimensions, so every request for `medium` or `large` or
 * a theme's own size resolves to the same file. Nothing crops, and a page of
 * cards ends up as tall as whichever photograph happened to be portrait.
 *
 * The delivery URL is the place to fix it. Cloudflare's flexible variants take
 * the target shape as parameters, so a size that used to mean "a cropped file
 * on disk" can mean "these parameters" instead, and the crop happens at the
 * edge. No files are regenerated and nothing is re-uploaded; the sizes simply
 * start working again, for third-party widgets as much as for ours.
 *
 * @package Nyuchi_WordPress_Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOImageSizes {

    /**
     * Host that serves the offloaded images. Anything else is left alone - this
     * has no business rewriting URLs it did not recognise.
     */
    const HOST = 'imagedelivery.net';

    public function __construct() {
        // Late, so cf-images has already built its URL and this adjusts the
        // result rather than racing it.
        add_filter('wp_get_attachment_image_src', array($this, 'size_src'), PHP_INT_MAX, 3);
        add_filter('wp_calculate_image_srcset', array($this, 'size_srcset'), PHP_INT_MAX, 5);
        add_filter('wp_get_attachment_image_attributes', array($this, 'restore_dimensions'), PHP_INT_MAX, 3);
    }

    /**
     * Whether the sizing rewrite is switched on. Off leaves delivery exactly as
     * cf-images left it, which is the safe thing to fall back to.
     */
    public static function enabled() {
        $settings = get_option('auto_seo_settings', array());

        return !isset($settings['cf_image_sizes']) || $settings['cf_image_sizes'];
    }

    /**
     * How Cloudflare picks what to keep when it crops.
     *
     * `auto` runs a saliency detector and centres on whatever is most visually
     * interesting. On a safari library that matters more than it sounds: the
     * animal is rarely in the middle of the frame, and a plain centre crop
     * takes the head off it.
     */
    public static function gravity() {
        $settings = get_option('auto_seo_settings', array());
        $gravity  = isset($settings['cf_image_gravity']) ? $settings['cf_image_gravity'] : 'auto';

        return in_array($gravity, array('auto', 'face', 'center'), true) ? $gravity : 'auto';
    }

    /**
     * Turn a requested size into a target width, height and fit.
     *
     * A registered size that crops wants both dimensions and `cover`. One that
     * does not crop is a bounding box, so it gets the width and `scale-down`,
     * which is the same promise WordPress made: no larger than this, and never
     * enlarged.
     *
     * @param string|array $size Registered size name, or a [width, height] pair.
     * @return array|false Target as [width, height|null, fit], or false to skip.
     */
    public static function target($size) {
        if (is_array($size)) {
            $w = isset($size[0]) ? (int) $size[0] : 0;
            $h = isset($size[1]) ? (int) $size[1] : 0;

            if ($w < 1) {
                return false;
            }

            return $h > 0 ? array($w, $h, 'cover') : array($w, null, 'scale-down');
        }

        if (!is_string($size) || 'full' === $size || '' === $size) {
            return false;
        }

        $sizes = self::registered_sizes();

        if (!isset($sizes[$size])) {
            return false;
        }

        $spec = $sizes[$size];
        $w    = (int) $spec['width'];
        $h    = (int) $spec['height'];

        if ($w < 1 && $h < 1) {
            return false;
        }

        if (!empty($spec['crop']) && $w > 0 && $h > 0) {
            return array($w, $h, 'cover');
        }

        // An uncropped size with only a height is unusual but legal; Cloudflare
        // takes either dimension on its own.
        return $w > 0 ? array($w, null, 'scale-down') : array(null, $h, 'scale-down');
    }

    /**
     * Every image size the site knows about, including the ones themes and
     * plugins add. Cached per request because this runs once per image.
     */
    public static function registered_sizes() {
        static $sizes = null;

        if (null !== $sizes) {
            return $sizes;
        }

        $sizes = function_exists('wp_get_registered_image_subsizes')
            ? wp_get_registered_image_subsizes()
            : array();

        return $sizes;
    }

    /**
     * Rewrite one delivery URL to carry the target shape.
     *
     * The last path segment of a Cloudflare Images URL is the variant. A named
     * variant is a fixed preset defined in the dashboard and cannot take
     * parameters, so only a flexible one - recognisable by carrying `key=value`
     * - is safe to rewrite. Anything else is returned untouched.
     *
     * @return string The rewritten URL, or the original if it was not ours.
     */
    public static function apply($url, $width, $height, $fit) {
        if (!is_string($url) || false === strpos($url, self::HOST)) {
            return $url;
        }

        $parts   = explode('/', $url);
        $variant = array_pop($parts);

        if ('' === $variant || false === strpos($variant, '=')) {
            return $url;
        }

        // Preserve anything already set that we are not responsible for, such
        // as a quality or format parameter, and replace only our own keys.
        $keep = array();

        foreach (explode(',', $variant) as $pair) {
            $key = strtolower(trim(strtok($pair, '=')));

            if (!in_array($key, array('w', 'width', 'h', 'height', 'fit', 'g', 'gravity'), true)) {
                $keep[] = $pair;
            }
        }

        $params = array();

        if ($width) {
            $params[] = 'w=' . (int) $width;
        }

        if ($height) {
            $params[] = 'h=' . (int) $height;
        }

        $params[] = 'fit=' . $fit;

        // Gravity only means anything to a crop, and Cloudflare ignores it
        // otherwise, but sending it regardless would be noise in the URL.
        if ('cover' === $fit) {
            $params[] = 'gravity=' . self::gravity();
        }

        $parts[] = implode(',', array_merge($params, $keep));

        return implode('/', $parts);
    }

    /**
     * Give a sized request the shape it asked for.
     *
     * The reported width and height matter as much as the URL. WordPress passes
     * them straight through to the `width` and `height` attributes, and with no
     * recorded dimensions it currently reports nothing, so the browser cannot
     * reserve space and the page shifts as each image arrives.
     */
    public function size_src($image, $attachment_id, $size) {
        if (!self::enabled() || !is_array($image) || empty($image[0])) {
            return $image;
        }

        $target = self::target($size);

        if (false === $target) {
            return $image;
        }

        list($w, $h, $fit) = $target;

        $rewritten = self::apply($image[0], $w, $h, $fit);

        if ($rewritten === $image[0]) {
            return $image;
        }

        $image[0] = $rewritten;

        // A crop delivers exactly what was asked for, so both are known. An
        // uncropped size only fixes one side, and guessing the other would be
        // worse than leaving whatever was already there.
        if ('cover' === $fit) {
            $image[1] = (int) $w;
            $image[2] = (int) $h;
            $image[3] = true;
        }

        return $image;
    }

    /**
     * Keep the srcset consistent with the src.
     *
     * cf-images builds a srcset of widths. Left alone those entries stay
     * uncropped, so a browser picking a wider candidate would swap a cropped
     * image for an uncropped one and the layout would jump mid-load.
     */
    public function size_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!self::enabled() || !is_array($sources) || empty($sources)) {
            return $sources;
        }

        $target = self::target($size_array);

        if (false === $target || 'cover' !== $target[2]) {
            return $sources;
        }

        list($w, $h) = $target;

        if ($w < 1 || $h < 1) {
            return $sources;
        }

        $ratio = $h / $w;

        foreach ($sources as $key => $source) {
            if (empty($source['url']) || !isset($source['value']) || 'w' !== $source['descriptor']) {
                continue;
            }

            // Each candidate keeps the requested shape at its own width, so
            // whichever the browser picks, the box it lands in is the same.
            $candidate_w = (int) $source['value'];
            $candidate_h = (int) round($candidate_w * $ratio);

            $sources[$key]['url'] = self::apply($source['url'], $candidate_w, $candidate_h, 'cover');
        }

        return $sources;
    }

    /**
     * Put width and height back on the tag.
     *
     * Without them the browser has no aspect ratio to reserve, which is the
     * layout shift you can watch happen as a gallery loads.
     */
    public function restore_dimensions($attr, $attachment, $size) {
        if (!self::enabled()) {
            return $attr;
        }

        if (!empty($attr['width']) && !empty($attr['height'])) {
            return $attr;
        }

        $target = self::target($size);

        if (false === $target || 'cover' !== $target[2]) {
            return $attr;
        }

        $attr['width']  = (int) $target[0];
        $attr['height'] = (int) $target[1];

        return $attr;
    }
}
