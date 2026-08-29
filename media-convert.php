<?php
/**
 * Convert mismatched attachments back to a format the pipeline accepts.
 *
 * An image optimiser rewrote files to AVIF and WebP in place without touching
 * the attachment record, so the database says image/jpeg while the bytes on
 * disk are something else entirely. Cloudflare Images refuses to ingest AVIF,
 * so every one of those attachments quietly fails to offload and never gets the
 * delivery-layer crop parameters image-sizes.php adds. Nothing errors, because
 * from WordPress's point of view nothing happened.
 *
 * media-repair.php can repoint an attachment at a replacement file, but only
 * once a replacement exists on disk - which until now meant somebody converting
 * the files somewhere else and putting them back over SFTP. That dependency is
 * the only reason this is a two-step job, and it is not a real one: the plugin
 * is already running on the machine that holds the files.
 *
 * So this does the transcode itself, and then hands the result through the same
 * repoint-and-rebuild sequence media-repair uses, including its revert on
 * failure. What it will not do is guess: it verifies the file it wrote is a
 * real image of the expected type before a single row is updated, and it
 * refuses outright on a zero-byte source, because there is no conversion that
 * recovers data that is already gone.
 *
 * @package Nyuchi_WordPress_Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOMediaConvert {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    /**
     * Batch sizing.
     *
     * Transcoding is the most expensive thing this plugin does per item, and a
     * run that is killed half way through is worse than a run that was too
     * small, because the caller cannot tell which of the two happened. Small
     * default, hard ceiling, and the response always says what remains.
     */
    const DEFAULT_LIMIT = 10;
    const MAX_LIMIT     = 50;

    /** JPEG quality when the caller does not say. Visually indistinguishable from 90 at roughly two thirds the bytes. */
    const DEFAULT_QUALITY = 82;

    /** How many rows a grouped scan returns per problem type. */
    const SAMPLE = 10;

    /** Ceilings on the read-only scan, so it cannot become the slow query on a large library. */
    const SCAN_ATTACHMENT_CAP = 2000;
    const SCAN_FILE_CAP       = 5000;

    public function __construct() {
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    public function can_manage() {
        return current_user_can('manage_options');
    }

    private function register($name, $args) {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability($name, $args);
    }

    public function register_abilities() {
        $this->register_status();
        $this->register_convert();
        $this->register_scan();
    }

    /* ---------------------------------------------------------------------
     * What this server can actually do
     * ------------------------------------------------------------------ */

    /**
     * Formats Imagick reports it can handle.
     *
     * queryFormats() returns the full delegate list, which runs to a couple of
     * hundred entries and is noise to a caller. Only the four that decide
     * whether this job is possible are reported, plus the total so an empty
     * list is distinguishable from a missing one.
     *
     * @return array|null Null when Imagick is not installed.
     */
    public function imagick_formats() {
        if (!class_exists('Imagick') || !method_exists('Imagick', 'queryFormats')) {
            return null;
        }

        try {
            $all = (array) Imagick::queryFormats();
        } catch (Exception $e) {
            return null;
        }

        $up = array_map('strtoupper', $all);

        $version = '';

        if (method_exists('Imagick', 'getVersion')) {
            try {
                $v       = Imagick::getVersion();
                $version = isset($v['versionString']) ? $v['versionString'] : '';
            } catch (Exception $e) {
                $version = '';
            }
        }

        return array(
            'loaded'        => true,
            'version'       => $version,
            'format_count'  => count($up),
            'avif'          => in_array('AVIF', $up, true),
            'webp'          => in_array('WEBP', $up, true),
            'jpeg'          => in_array('JPEG', $up, true),
            'png'           => in_array('PNG', $up, true),
        );
    }

    /**
     * GD's per-format entry points.
     *
     * imagecreatefromavif needs PHP 8.1 or newer *and* a libgd that was
     * compiled against libavif. Plenty of 8.1+ hosts have the first and not the
     * second, so the PHP version says nothing useful here and function_exists
     * is the only test that means anything.
     *
     * @return array
     */
    public function gd_functions() {
        $names = array(
            'imagecreatefromavif',
            'imagecreatefromwebp',
            'imagecreatefromjpeg',
            'imagecreatefrompng',
            'imagejpeg',
            'imagepng',
        );

        $out = array('loaded' => extension_loaded('gd'));

        foreach ($names as $n) {
            $out[$n] = function_exists($n);
        }

        return $out;
    }

    /**
     * Which engine would handle this pair, and why that one.
     *
     * Imagick is preferred wherever it can do the job. It handles ICC profiles
     * and orientation without being told, and its pixel buffers live outside
     * PHP's memory_limit, so a large image is far less likely to end the
     * request. GD is the fallback, not the equal.
     *
     * @param string $src    Source format: avif, webp, jpeg, png, gif.
     * @param string $target Target format: jpeg or png.
     * @return array engine (imagick|gd|null) and reason.
     */
    public function engine_for($src, $target) {
        $im = $this->imagick_formats();

        if (is_array($im) && !empty($im[$src]) && !empty($im[$target])) {
            return array('engine' => 'imagick', 'reason' => 'Imagick reports both ' . strtoupper($src) . ' and ' . strtoupper($target) . '.');
        }

        $gd   = $this->gd_functions();
        $read = array(
            'avif' => 'imagecreatefromavif',
            'webp' => 'imagecreatefromwebp',
            'jpeg' => 'imagecreatefromjpeg',
            'png'  => 'imagecreatefrompng',
            'gif'  => 'imagecreatefromgif',
        );
        $write = array('jpeg' => 'imagejpeg', 'png' => 'imagepng');

        $can_read  = isset($read[$src]) && function_exists($read[$src]);
        $can_write = isset($write[$target]) && function_exists($write[$target]);

        if ($gd['loaded'] && $can_read && $can_write) {
            return array('engine' => 'gd', 'reason' => 'Imagick cannot do this pair; GD has ' . $read[$src] . ' and ' . $write[$target] . '.');
        }

        if (is_array($im) && empty($im[$src])) {
            $why = 'Imagick is installed but does not list ' . strtoupper($src) . ', and GD has no ' . (isset($read[$src]) ? $read[$src] : 'reader') . '.';
        } elseif (!$gd['loaded'] && !is_array($im)) {
            $why = 'Neither Imagick nor GD is available.';
        } else {
            $why = 'No installed engine can read ' . strtoupper($src) . ' and write ' . strtoupper($target) . '.';
        }

        return array('engine' => null, 'reason' => $why);
    }

    /**
     * Everything a caller needs before planning a batch.
     *
     * @return array
     */
    public function capabilities() {
        $im  = $this->imagick_formats();
        $gd  = $this->gd_functions();
        $dir = wp_get_upload_dir();

        $avif = $this->engine_for('avif', 'jpeg');
        $webp = $this->engine_for('webp', 'jpeg');

        $free = null;

        if (function_exists('disk_free_space')) {
            $bytes = @disk_free_space($dir['basedir']);
            $free  = is_numeric($bytes) ? (int) $bytes : null;
        }

        $can_avif = null !== $avif['engine'];
        $can_webp = null !== $webp['engine'];

        if ($can_avif && $can_webp) {
            $verdict = 'This server can convert both AVIF and WebP.';
        } elseif ($can_webp) {
            $verdict = 'This server can convert WebP but not AVIF.';
        } elseif ($can_avif) {
            $verdict = 'This server can convert AVIF but not WebP, which is unusual - check the Imagick and GD builds.';
        } else {
            $verdict = 'This server cannot decode either AVIF or WebP. Nothing here can run.';
        }

        $advice = $can_avif
            ? 'Run media-convert with dry_run true first to see what it would produce.'
            : 'No AVIF decoder is present, so the conversion cannot be done on this machine. The alternatives are: ask the host to enable AVIF support in Imagick or in libgd; or convert the files elsewhere, upload them beside the originals, and use media-repair to repoint the records - it only needs the replacement to exist on disk.';

        return array(
            'imagick' => is_array($im) ? $im : array('loaded' => false),
            'gd'      => $gd,
            'engines' => array(
                'avif_source' => $avif,
                'webp_source' => $webp,
            ),
            'uploads' => array(
                'basedir'    => $dir['basedir'],
                'writable'   => is_writable($dir['basedir']),
                'free_bytes' => $free,
                'free_mb'    => null === $free ? null : round($free / 1048576, 1),
            ),
            'limits' => array(
                'memory_limit'        => ini_get('memory_limit'),
                'memory_headroom_mb'  => null === $this->memory_headroom() ? null : round($this->memory_headroom() / 1048576, 1),
                'max_execution_time'  => (int) ini_get('max_execution_time'),
            ),
            'can_convert_avif' => $can_avif,
            'can_convert_webp' => $can_webp,
            'verdict'          => $verdict,
            'note'             => $advice,
        );
    }

    /* ---------------------------------------------------------------------
     * Resource guards
     * ------------------------------------------------------------------ */

    /**
     * Turn a php.ini shorthand size into bytes.
     *
     * @param string $v Value such as 256M, 1G, -1.
     * @return int Bytes, or -1 for unlimited.
     */
    protected function bytes_from_ini($v) {
        $v = trim((string) $v);

        if ('' === $v) {
            return -1;
        }

        $unit = strtolower(substr($v, -1));
        $n    = (int) $v;

        if ($n < 0) {
            return -1;
        }

        if ('g' === $unit) {
            return $n * 1073741824;
        }

        if ('m' === $unit) {
            return $n * 1048576;
        }

        if ('k' === $unit) {
            return $n * 1024;
        }

        return $n;
    }

    /**
     * Bytes still available under memory_limit, or null when it is unlimited.
     *
     * @return int|null
     */
    protected function memory_headroom() {
        $limit = $this->bytes_from_ini(ini_get('memory_limit'));

        if ($limit < 0) {
            return null;
        }

        return max(0, $limit - memory_get_usage(true));
    }

    /**
     * Worst-case memory for decoding an image of these dimensions.
     *
     * GD stores a truecolor image as one 32-bit integer per pixel, so the
     * bitmap alone is width x height x 4 - a 2048 x 1536 photograph is about
     * 12.5 MB before anything else happens. The estimate multiplies that
     * because a single conversion can hold several of them at once: the decoded
     * source, the white canvas a transparent image gets composited onto, and
     * the encoder's own output buffer. On top of that,
     * wp_generate_attachment_metadata decodes the finished file all over again
     * to build the sub-sizes, while this run is still holding everything else.
     *
     * It is deliberately pessimistic because the failure it prevents is not
     * catchable. Exhausting memory_limit inside a GD call is a fatal error, not
     * an exception - on some hosts a blank page and nothing in the log - so
     * there is no recovering afterwards and no reporting what went wrong. Being
     * wrong in the cautious direction costs a skipped image and a clear reason;
     * being wrong the other way costs the whole batch.
     *
     * @param int $w Width.
     * @param int $h Height.
     * @return int Bytes.
     */
    protected function estimate_decode_bytes($w, $h) {
        $w = max(1, (int) $w);
        $h = max(1, (int) $h);

        return (int) ($w * $h * 4 * 3) + 2097152;
    }

    /* ---------------------------------------------------------------------
     * Reading what a file really is
     * ------------------------------------------------------------------ */

    /**
     * Format and dimensions of a file, taken from its contents.
     *
     * The extension is exactly what lied in this incident, so it is never the
     * source of truth. getimagesize reads the header, but it only learned AVIF
     * in PHP 8.1, so Imagick's ping - which reads the header without decoding
     * the pixels - is the fallback rather than trusting the name.
     *
     * @param string $path Absolute path.
     * @return array|false format, width, height, via.
     */
    public function probe($path) {
        $info = @getimagesize($path);

        if (is_array($info) && !empty($info[2])) {
            $map = array(
                IMAGETYPE_JPEG => 'jpeg',
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_GIF  => 'gif',
            );

            if (defined('IMAGETYPE_WEBP')) {
                $map[IMAGETYPE_WEBP] = 'webp';
            }

            if (defined('IMAGETYPE_AVIF')) {
                $map[IMAGETYPE_AVIF] = 'avif';
            }

            if (isset($map[$info[2]])) {
                return array(
                    'format' => $map[$info[2]],
                    'width'  => (int) $info[0],
                    'height' => (int) $info[1],
                    'via'    => 'getimagesize',
                );
            }
        }

        if (class_exists('Imagick')) {
            try {
                $ping = new Imagick();
                $ping->pingImage($path);

                $fmt = strtolower($ping->getImageFormat());
                $w   = (int) $ping->getImageWidth();
                $h   = (int) $ping->getImageHeight();

                $ping->clear();
                $ping->destroy();

                return array(
                    'format' => $fmt,
                    'width'  => $w,
                    'height' => $h,
                    'via'    => 'imagick-ping',
                );
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     * Mismatch detection
     * ------------------------------------------------------------------ */

    /**
     * The mismatched attachments, as media-repair defines them.
     *
     * Deliberately delegated rather than reimplemented. Two copies of this rule
     * would drift - one would start counting a jpeg/jpg spelling as a fault, or
     * stop - and the visible symptom would be this converting files that
     * media-repair then declines to recognise, which is a horrible thing to
     * debug. If AutoSEOMediaRepair::find_mismatches ever changes shape, this
     * must change with it; the two are one rule with two callers.
     *
     * Constructing the repair class only adds a hook that has already fired by
     * the time an ability executes, so there is no double registration.
     *
     * @return array
     */
    public function find_mismatches() {
        if (class_exists('AutoSEOMediaRepair') && method_exists('AutoSEOMediaRepair', 'find_mismatches')) {
            $repair = new AutoSEOMediaRepair();

            return $repair->find_mismatches();
        }

        return array(
            'mismatched'  => 0,
            'repairable'  => 0,
            'blocked'     => 0,
            'attachments' => array(),
            'note'        => 'media-repair.php is not loaded, so there is no mismatch definition to work from. Nothing was examined - this is not a report that the library is clean.',
        );
    }

    /* ---------------------------------------------------------------------
     * Conversion
     * ------------------------------------------------------------------ */

    /**
     * Whether a filename stem says the original was a PNG.
     *
     * The optimiser that caused this kept the old extension as part of the
     * name, so IMG_0382-png.avif and Iconic-Logo-png.avif were PNGs before it
     * touched them. Flattening one of those to JPEG turns whatever was
     * transparent black, which on a logo is not a subtle defect.
     *
     * This is a hint, not the decision - the pixels get the final say, see
     * has_alpha handling in convert_one().
     *
     * @param string $stem Filename without extension.
     * @return bool
     */
    protected function stem_says_png($stem) {
        return (bool) preg_match('/-png$/i', $stem);
    }

    /**
     * Whether a decoded GD image carries real transparency.
     *
     * GD has no "does this have alpha" call, so the channel has to be looked
     * at. Scanning every pixel of a 4000px image to answer a yes/no question is
     * not worth the time, so this samples a grid of at most 64 x 64 points -
     * bounded regardless of image size. That can miss transparency confined to
     * a handful of pixels, which is why the -png stem rule runs first and
     * independently: between them the cases that matter here are covered.
     *
     * @param resource|GdImage $im Decoded image.
     * @return bool
     */
    protected function gd_has_alpha($im) {
        if (!function_exists('imageistruecolor') || !imageistruecolor($im)) {
            // A palette image expresses transparency as one reserved index
            // rather than a per-pixel channel.
            return function_exists('imagecolortransparent') && imagecolortransparent($im) >= 0;
        }

        $w = imagesx($im);
        $h = imagesy($im);

        $sx = max(1, (int) floor($w / 64));
        $sy = max(1, (int) floor($h / 64));

        for ($y = 0; $y < $h; $y += $sy) {
            for ($x = 0; $x < $w; $x += $sx) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Decode with Imagick and write the target.
     *
     * @param string $src     Source path.
     * @param string $tmp     Where to write.
     * @param string $target  jpeg or png.
     * @param int    $quality JPEG quality.
     * @param bool   $force   Caller insisted on the target format.
     * @return array ok, target, alpha, error.
     */
    protected function imagick_convert($src, $tmp, $target, $quality, $force) {
        $img = null;
        $out = null;

        try {
            $img = new Imagick();
            $img->readImage($src);

            $alpha = method_exists($img, 'getImageAlphaChannel') ? (bool) $img->getImageAlphaChannel() : false;

            // Transparency upgrades the target unless the caller named one.
            if ($alpha && !$force) {
                $target = 'png';
            }

            if ('png' === $target) {
                $img->setImageFormat('png');
                $img->writeImage($tmp);
            } else {
                // A JPEG has no alpha channel, so something has to be behind
                // the transparent pixels. Left to itself the flatten produces
                // black; white is what the image was almost certainly designed
                // against.
                $out = new Imagick();
                $out->newImage($img->getImageWidth(), $img->getImageHeight(), new ImagickPixel('white'));
                $out->compositeImage($img, Imagick::COMPOSITE_OVER, 0, 0);

                if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    $out->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                }

                $out->setImageFormat('jpeg');
                $out->setImageCompressionQuality($quality);
                $out->writeImage($tmp);
            }

            $img->clear();
            $img->destroy();

            if ($out) {
                $out->clear();
                $out->destroy();
            }

            return array('ok' => true, 'target' => $target, 'alpha' => $alpha, 'error' => '');
        } catch (Exception $e) {
            if ($img) {
                @$img->clear();
                @$img->destroy();
            }

            if ($out) {
                @$out->clear();
                @$out->destroy();
            }

            return array('ok' => false, 'target' => $target, 'alpha' => false, 'error' => 'Imagick: ' . $e->getMessage());
        }
    }

    /**
     * Decode with GD and write the target.
     *
     * @param string $src     Source path.
     * @param string $tmp     Where to write.
     * @param string $src_fmt Source format.
     * @param string $target  jpeg or png.
     * @param int    $quality JPEG quality.
     * @param bool   $force   Caller insisted on the target format.
     * @return array ok, target, alpha, error.
     */
    protected function gd_convert($src, $tmp, $src_fmt, $target, $quality, $force) {
        $readers = array(
            'avif' => 'imagecreatefromavif',
            'webp' => 'imagecreatefromwebp',
            'jpeg' => 'imagecreatefromjpeg',
            'png'  => 'imagecreatefrompng',
            'gif'  => 'imagecreatefromgif',
        );

        if (!isset($readers[$src_fmt]) || !function_exists($readers[$src_fmt])) {
            return array('ok' => false, 'target' => $target, 'alpha' => false, 'error' => 'GD has no reader for ' . $src_fmt . '.');
        }

        $im = @call_user_func($readers[$src_fmt], $src);

        if (!$im) {
            return array('ok' => false, 'target' => $target, 'alpha' => false, 'error' => 'GD could not decode the source - the file may be truncated.');
        }

        $alpha = $this->gd_has_alpha($im);

        if ($alpha && !$force) {
            $target = 'png';
        }

        $ok = false;

        if ('png' === $target) {
            imagealphablending($im, false);
            imagesavealpha($im, true);
            // Quality is a JPEG idea. PNG is lossless, so this is the zlib
            // level and 6 is the usual balance of time against size.
            $ok = @imagepng($im, $tmp, 6);
        } elseif ($alpha) {
            $w   = imagesx($im);
            $h   = imagesy($im);
            $can = imagecreatetruecolor($w, $h);

            if ($can) {
                imagefill($can, 0, 0, imagecolorallocate($can, 255, 255, 255));
                imagecopy($can, $im, 0, 0, 0, 0, $w, $h);
                $ok = @imagejpeg($can, $tmp, $quality);
                imagedestroy($can);
            }
        } else {
            $ok = @imagejpeg($im, $tmp, $quality);
        }

        imagedestroy($im);

        return array(
            'ok'     => (bool) $ok,
            'target' => $target,
            'alpha'  => $alpha,
            'error'  => $ok ? '' : 'GD wrote nothing - check the directory is writable and there is free disk space.',
        );
    }

    /**
     * Convert one mismatched attachment.
     *
     * @param array  $a    A row from find_mismatches().
     * @param string $base Uploads basedir with trailing slash.
     * @param array  $opt  dry_run, quality, delete_source, target_format.
     * @return array
     */
    protected function convert_one($a, $base, $opt) {
        $src_rel = $a['stored_file'];
        $src     = $base . $src_rel;

        $row = array(
            'id'             => $a['id'],
            'source_path'    => $src_rel,
            'source_format'  => null,
            'source_bytes'   => null,
            'target_path'    => null,
            'target_format'  => null,
            'target_bytes'   => null,
            'dimensions'     => null,
            'engine'         => null,
            'record_updated' => false,
            'status'         => 'skipped',
            'reason'         => '',
        );

        if (!file_exists($src)) {
            $row['reason'] = 'Source file is not on disk. There is nothing to convert - this attachment needs the file restored, or the record removed.';

            return $row;
        }

        $bytes = (int) filesize($src);
        $row['source_bytes'] = $bytes;

        if (0 === $bytes) {
            // Not a conversion problem. A zero-byte file is the original data
            // already lost, and any output produced from it would be a
            // plausible-looking placeholder standing where a photograph was.
            $row['status'] = 'skipped';
            $row['reason'] = 'Source file is zero bytes. The image data is already gone - this is data loss, not a format problem, and no conversion can recover it. Restore this file from backup.';

            return $row;
        }

        $probe = $this->probe($src);

        if (!$probe) {
            $row['status'] = 'failed';
            $row['reason'] = 'Nothing on this server could read the file header, so its real format is unknown. Run media-convert-status to see which decoders are present.';

            return $row;
        }

        $row['source_format'] = $probe['format'];
        $row['dimensions']    = $probe['width'] . 'x' . $probe['height'];

        $stem = pathinfo($src_rel, PATHINFO_FILENAME);
        $dir  = trim(pathinfo($src_rel, PATHINFO_DIRNAME), '.');
        $dir  = '' === $dir ? '' : trailingslashit($dir);

        // The caller's override wins outright; otherwise the -png stem is the
        // first-pass answer and the decoded pixels may upgrade it below.
        $force  = !empty($opt['target_format']);
        $target = 'jpeg';

        if ($force) {
            $target = 'png' === $opt['target_format'] ? 'png' : 'jpeg';
        } elseif ($this->stem_says_png($stem)) {
            $target = 'png';
        }

        $engine = $this->engine_for($probe['format'], $target);
        $row['engine'] = $engine['engine'];

        if (null === $engine['engine']) {
            $row['status'] = 'failed';
            $row['reason'] = $engine['reason'];

            return $row;
        }

        $ext         = 'png' === $target ? 'png' : 'jpg';
        $target_rel  = $dir . $stem . '.' . $ext;
        $row['target_path']   = $target_rel;
        $row['target_format'] = $target;

        if ($target_rel === $src_rel) {
            $row['reason'] = 'The target path is the source path, so converting would overwrite the file being read. Only the recorded MIME type is wrong here - use media-repair instead.';

            return $row;
        }

        // Memory is checked against the source dimensions before anything is
        // decoded, because after the decode it is too late to decline.
        $need = $this->estimate_decode_bytes($probe['width'], $probe['height']);
        $free = $this->memory_headroom();

        if ('gd' === $engine['engine'] && null !== $free && $need > $free) {
            $row['reason'] = sprintf(
                'Skipped on memory: decoding %s with GD needs roughly %d MB and only %d MB remains under memory_limit (%s). Raise memory_limit, or run a smaller limit so less is held at once.',
                $row['dimensions'],
                (int) round($need / 1048576),
                (int) round($free / 1048576),
                ini_get('memory_limit')
            );

            return $row;
        }

        if (!empty($opt['dry_run'])) {
            $row['status'] = 'would convert';
            $row['reason'] = 'Dry run. Target format shown is from the filename and the recorded type; transparency can still upgrade a JPEG target to PNG, which is only knowable once the file is decoded.';

            return $row;
        }

        // Written beside the target and renamed into place, so a decoder that
        // dies half way through leaves a stray temporary file rather than a
        // truncated image sitting exactly where the record is about to point.
        $tmp = $base . $target_rel . '.nyuchi-tmp';

        if ('imagick' === $engine['engine']) {
            $res = $this->imagick_convert($src, $tmp, $target, $opt['quality'], $force);
        } else {
            $res = $this->gd_convert($src, $tmp, $probe['format'], $target, $opt['quality'], $force);
        }

        if (!$res['ok']) {
            @unlink($tmp);
            $row['status'] = 'failed';
            $row['reason'] = $res['error'];

            return $row;
        }

        // Transparency may have changed the target after the decode, so the
        // path is settled here rather than above.
        if ($res['target'] !== $target) {
            $target     = $res['target'];
            $ext        = 'png' === $target ? 'png' : 'jpg';
            $new_rel    = $dir . $stem . '.' . $ext;
            $new_tmp    = $base . $new_rel . '.nyuchi-tmp';

            @rename($tmp, $new_tmp);

            $tmp                  = $new_tmp;
            $target_rel           = $new_rel;
            $row['target_path']   = $target_rel;
            $row['target_format'] = $target;
        }

        $row['alpha_detected'] = (bool) $res['alpha'];

        // Verify before the database is touched. An encoder can return success
        // and still leave nothing useful behind when the disk is full.
        $check = @getimagesize($tmp);
        $want  = 'png' === $target ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if (!is_array($check) || empty($check[2]) || $check[2] !== $want || $check[0] < 1 || $check[1] < 1) {
            @unlink($tmp);
            $row['status'] = 'failed';
            $row['reason'] = 'The written file is not a valid ' . strtoupper($target) . ', so the record was left alone and the file was removed.';

            return $row;
        }

        if (!@rename($tmp, $base . $target_rel)) {
            @unlink($tmp);
            $row['status'] = 'failed';
            $row['reason'] = 'Could not move the converted file into place - check directory permissions.';

            return $row;
        }

        $row['target_bytes'] = (int) filesize($base . $target_rel);
        $row['dimensions']   = $check[0] . 'x' . $check[1];

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $old_mime = $a['recorded_as'];
        $new_mime = 'png' === $target ? 'image/png' : 'image/jpeg';

        update_post_meta($a['id'], '_wp_attached_file', $target_rel);

        if ($new_mime !== $old_mime) {
            wp_update_post(array('ID' => $a['id'], 'post_mime_type' => $new_mime));
        }

        $meta = wp_generate_attachment_metadata($a['id'], $base . $target_rel);

        if (is_wp_error($meta) || empty($meta)) {
            // Same choice media-repair makes, and for the same reason: a record
            // pointing at a file whose sizes were never built is worse than the
            // mismatch it started with. The converted file is left on disk -
            // it is a valid replacement, so media-repair can pick it up later.
            update_post_meta($a['id'], '_wp_attached_file', $src_rel);

            if ($new_mime !== $old_mime) {
                wp_update_post(array('ID' => $a['id'], 'post_mime_type' => $old_mime));
            }

            $row['status'] = 'failed';
            $row['reason'] = 'Converted successfully but the attachment metadata could not be rebuilt, so the record was reverted. The new file is still on disk at ' . $target_rel . '.';

            return $row;
        }

        wp_update_attachment_metadata($a['id'], $meta);

        $row['record_updated'] = true;
        $row['status']         = 'converted';
        $row['sizes_built']    = isset($meta['sizes']) ? count($meta['sizes']) : 0;
        $row['source_removed'] = false;

        if (!empty($opt['delete_source']) && $src_rel !== $target_rel && file_exists($src)) {
            $row['source_removed'] = (bool) @unlink($src);
        }

        return $row;
    }

    /**
     * Convert a batch.
     *
     * @param array $input Raw ability input.
     * @return array
     */
    public function convert($input = array()) {
        $ids = isset($input['attachment_ids']) ? array_map('intval', (array) $input['attachment_ids']) : array();

        // Absent means dry run. A malformed call has to fail safe, because the
        // destructive version of this rewrites files.
        $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];

        $quality = isset($input['quality']) ? (int) $input['quality'] : self::DEFAULT_QUALITY;
        $quality = max(40, min(100, $quality));

        $limit = isset($input['limit']) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $target_format = '';

        if (!empty($input['target_format'])) {
            $tf = strtolower((string) $input['target_format']);

            if (!in_array($tf, array('jpg', 'jpeg', 'png'), true)) {
                return new WP_Error('bad_target', 'target_format must be jpg or png.');
            }

            $target_format = 'png' === $tf ? 'png' : 'jpeg';
        }

        // A transcode can take several seconds each. If the request will be cut
        // off before the batch finishes, the half that ran leaves no report at
        // all, so the batch is shortened to fit rather than gambling on it.
        $max_time    = (int) ini_get('max_execution_time');
        $time_note   = '';
        $asked_limit = $limit;

        if ($max_time > 0 && $max_time <= 30 && $limit > 5) {
            $limit     = 5;
            $time_note = sprintf(
                'Limit reduced from %d to %d because max_execution_time is %d seconds. A run that is cut off mid-batch returns nothing at all, so the batch was sized to finish. Call again to continue.',
                $asked_limit,
                $limit,
                $max_time
            );
        }

        $found = $this->find_mismatches();
        $dir   = wp_get_upload_dir();
        $base  = trailingslashit($dir['basedir']);

        $opt = array(
            'dry_run'       => $dry,
            'quality'       => $quality,
            'delete_source' => isset($input['delete_source']) && (bool) $input['delete_source'],
            'target_format' => $target_format,
        );

        $rows      = array();
        $attempted = 0;
        $converted = 0;
        $skipped   = 0;
        $failed    = 0;
        $before    = 0;
        $after     = 0;

        foreach ($found['attachments'] as $a) {
            if (!empty($ids) && !in_array($a['id'], $ids, true)) {
                continue;
            }

            if ($attempted >= $limit) {
                break;
            }

            $attempted++;
            $row    = $this->convert_one($a, $base, $opt);
            $rows[] = $row;

            $before += (int) $row['source_bytes'];
            $after  += (int) $row['target_bytes'];

            if ('converted' === $row['status']) {
                $converted++;
            } elseif ('failed' === $row['status']) {
                $failed++;
            } else {
                $skipped++;
            }
        }

        $remaining = $dry ? $found['mismatched'] : $this->find_mismatches();
        $remaining = is_array($remaining) ? $remaining['mismatched'] : $remaining;

        return array(
            'dry_run' => $dry,
            'summary' => array(
                'attempted'         => $attempted,
                'converted'         => $converted,
                'skipped'           => $skipped,
                'failed'            => $failed,
                'bytes_before'      => $before,
                'bytes_after'       => $after,
                'bytes_delta'       => $after - $before,
                'mismatched_total'  => $found['mismatched'],
                'mismatched_left'   => $remaining,
                'limit_applied'     => $limit,
                'limit_requested'   => $asked_limit,
                'quality'           => $quality,
            ),
            'attachments' => $rows,
            'time_note'   => $time_note,
            'note'        => $dry
                ? 'Nothing was changed and no file was written. Call again with dry_run false to convert.'
                : 'Converted files are on disk and the records point at them. If images are offloaded to Cloudflare, run that plugin\'s bulk offload again so the new files are pushed - the old AVIF was never accepted.',
        );
    }

    /* ---------------------------------------------------------------------
     * Scan
     * ------------------------------------------------------------------ */

    /**
     * Every image problem this can see, grouped.
     *
     * Broader than the mismatch case on purpose. The mismatch was found because
     * somebody went looking for it; the point of this is that the next
     * unrelated fault - a missing file, a truncated upload, an attachment whose
     * sub-sizes were never built - shows up without anyone having to suspect it
     * first.
     *
     * @param int  $large_bytes  Size above which a file is listed as heavy.
     * @param int  $sample       Rows returned per problem type.
     * @param bool $find_orphans Walk the uploads directory for unreferenced files.
     * @return array
     */
    public function scan($large_bytes = 2097152, $sample = self::SAMPLE, $find_orphans = true) {
        global $wpdb;

        $large_bytes = max(65536, (int) $large_bytes);
        $sample      = max(1, min(50, (int) $sample));

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, p.post_title, pm.meta_value AS file,
                    (SELECT meta_value FROM {$wpdb->postmeta} m2
                      WHERE m2.post_id = p.ID AND m2.meta_key = '_wp_attachment_metadata' LIMIT 1) AS meta
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
              WHERE p.post_type = 'attachment'
              ORDER BY p.ID DESC",
            ARRAY_A
        );

        $dir  = wp_get_upload_dir();
        $base = trailingslashit($dir['basedir']);

        $problems = array(
            'file_missing'     => array('label' => 'Attachment file is not on disk', 'count' => 0, 'sample' => array()),
            'zero_bytes'       => array('label' => 'Attachment file is zero bytes', 'count' => 0, 'sample' => array()),
            'mime_disagrees'   => array('label' => 'Recorded MIME type disagrees with the file contents', 'count' => 0, 'sample' => array()),
            'no_metadata'      => array('label' => 'No attachment metadata stored', 'count' => 0, 'sample' => array()),
            'no_sizes'         => array('label' => 'Metadata reports no sub-sizes', 'count' => 0, 'sample' => array()),
            'large_files'      => array('label' => 'Files above the size threshold', 'count' => 0, 'sample' => array()),
        );

        $known    = array();
        $examined = 0;
        $capped   = false;
        $total    = count((array) $rows);

        foreach ((array) $rows as $r) {
            if ($examined >= self::SCAN_ATTACHMENT_CAP) {
                $capped = true;
                break;
            }

            $examined++;

            $rel  = (string) $r['file'];
            $path = $base . $rel;
            $id   = (int) $r['ID'];

            $known[$rel] = true;

            $meta = empty($r['meta']) ? false : @unserialize($r['meta']);

            if (is_array($meta) && !empty($meta['sizes']) && is_array($meta['sizes'])) {
                $sub = trim(pathinfo($rel, PATHINFO_DIRNAME), '.');
                $sub = '' === $sub ? '' : trailingslashit($sub);

                foreach ($meta['sizes'] as $s) {
                    if (!empty($s['file'])) {
                        $known[$sub . $s['file']] = true;
                    }
                }
            }

            if (!file_exists($path)) {
                $problems['file_missing']['count']++;

                if (count($problems['file_missing']['sample']) < $sample) {
                    $problems['file_missing']['sample'][] = array('id' => $id, 'file' => $rel, 'title' => $r['post_title']);
                }

                continue;
            }

            $bytes = (int) filesize($path);

            if (0 === $bytes) {
                $problems['zero_bytes']['count']++;

                if (count($problems['zero_bytes']['sample']) < $sample) {
                    $problems['zero_bytes']['sample'][] = array('id' => $id, 'file' => $rel, 'title' => $r['post_title']);
                }

                continue;
            }

            if ($bytes > $large_bytes) {
                $problems['large_files']['count']++;

                if (count($problems['large_files']['sample']) < $sample) {
                    $problems['large_files']['sample'][] = array('id' => $id, 'file' => $rel, 'mb' => round($bytes / 1048576, 2));
                }
            }

            if (0 === strpos((string) $r['post_mime_type'], 'image/')) {
                $probe = $this->probe($path);

                if ($probe) {
                    $actual = 'jpeg' === $probe['format'] ? 'image/jpeg' : 'image/' . $probe['format'];

                    if ($actual !== $r['post_mime_type']) {
                        $problems['mime_disagrees']['count']++;

                        if (count($problems['mime_disagrees']['sample']) < $sample) {
                            $problems['mime_disagrees']['sample'][] = array(
                                'id'        => $id,
                                'file'      => $rel,
                                'recorded'  => $r['post_mime_type'],
                                'actually'  => $actual,
                                'read_via'  => $probe['via'],
                            );
                        }
                    }
                }

                if (!is_array($meta)) {
                    $problems['no_metadata']['count']++;

                    if (count($problems['no_metadata']['sample']) < $sample) {
                        $problems['no_metadata']['sample'][] = array('id' => $id, 'file' => $rel);
                    }
                } elseif (empty($meta['sizes'])) {
                    $problems['no_sizes']['count']++;

                    if (count($problems['no_sizes']['sample']) < $sample) {
                        $problems['no_sizes']['sample'][] = array('id' => $id, 'file' => $rel);
                    }
                }
            }
        }

        $orphans = $find_orphans
            ? $this->find_orphans($base, $known, $sample)
            : array('count' => 0, 'sample' => array(), 'files_examined' => 0, 'cap_hit' => false, 'note' => 'Orphan walk was not requested.');

        return array(
            'attachments_total'    => $total,
            'attachments_examined' => $examined,
            'attachment_cap_hit'   => $capped,
            'large_file_threshold_mb' => round($large_bytes / 1048576, 2),
            'problems'             => $problems,
            'orphaned_files'       => $orphans,
            'note'                 => $capped
                ? 'Read-only. The attachment cap of ' . self::SCAN_ATTACHMENT_CAP . ' was reached, so the counts are of what was examined and not of the whole library.'
                : 'Read-only. MIME disagreement is judged from the file header, never the extension - the extension is exactly what was wrong in the AVIF incident.',
        );
    }

    /**
     * Image files in uploads that no attachment record accounts for.
     *
     * Sub-sizes are not attachments and must not be reported as orphans, so
     * anything named in an attachment's metadata is treated as known, and a
     * file whose name ends in a WxH suffix is matched back to its parent stem
     * as well. That second rule is a heuristic: it will forgive a genuinely
     * abandoned thumbnail whose parent still exists. Over-reporting a file
     * somebody might then delete is the worse mistake of the two.
     *
     * @param string $base   Uploads basedir with trailing slash.
     * @param array  $known  Relative paths already accounted for.
     * @param int    $sample Rows to return.
     * @return array
     */
    protected function find_orphans($base, $known, $sample) {
        $stems = array();

        foreach (array_keys($known) as $rel) {
            $d = trim(pathinfo($rel, PATHINFO_DIRNAME), '.');
            $d = '' === $d ? '' : trailingslashit($d);
            $stems[$d . pathinfo($rel, PATHINFO_FILENAME)] = true;
        }

        $exts = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');

        $found    = array();
        $count    = 0;
        $examined = 0;
        $cap_hit  = false;

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $e) {
            return array(
                'count'          => 0,
                'sample'         => array(),
                'files_examined' => 0,
                'cap_hit'        => false,
                'note'           => 'The uploads directory could not be walked: ' . $e->getMessage(),
            );
        }

        foreach ($it as $file) {
            if ($examined >= self::SCAN_FILE_CAP) {
                $cap_hit = true;
                break;
            }

            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());

            if (!in_array($ext, $exts, true)) {
                continue;
            }

            $examined++;

            $rel = ltrim(str_replace($base, '', $file->getPathname()), '/');

            if (isset($known[$rel])) {
                continue;
            }

            $d    = trim(pathinfo($rel, PATHINFO_DIRNAME), '.');
            $d    = '' === $d ? '' : trailingslashit($d);
            $stem = pathinfo($rel, PATHINFO_FILENAME);

            if (isset($stems[$d . $stem])) {
                continue;
            }

            // A generated size, a -scaled original or a -rotated copy all carry
            // the parent's stem with a suffix.
            $parent = preg_replace('/-(?:\d+x\d+|scaled|rotated|e\d+)$/', '', $stem);

            if ($parent !== $stem && isset($stems[$d . $parent])) {
                continue;
            }

            $count++;

            if (count($found) < $sample) {
                $found[] = array(
                    'file'  => $rel,
                    'bytes' => (int) $file->getSize(),
                    'mb'    => round($file->getSize() / 1048576, 2),
                );
            }
        }

        return array(
            'count'          => $count,
            'sample'         => $found,
            'files_examined' => $examined,
            'cap_hit'        => $cap_hit,
            'note'           => $cap_hit
                ? 'The walk stopped at ' . self::SCAN_FILE_CAP . ' image files, so this count is a floor and not a total. An uploads directory can hold tens of thousands of files and walking all of them is not free.'
                : 'Matching is by name, so a file an unusual plugin stores under its own scheme can appear here without being abandoned. Check before deleting anything.',
        );
    }

    /* ---------------------------------------------------------------------
     * Abilities
     * ------------------------------------------------------------------ */

    private function register_status() {
        $this->register(self::PREFIX . 'media-convert-status', array(
            'label'       => 'What this server can convert',
            'description' => 'Report whether Imagick and GD are present, which image formats each can actually read and write, which engine media-convert would choose and why, and whether the uploads directory is writable with space free. Read-only. Call this before planning a conversion batch - a host with no AVIF decoder cannot run one at all, and this says so plainly.',
            'category'    => self::CATEGORY,
            'input_schema' => array('type' => 'object', 'properties' => array(), 'additionalProperties' => false),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->capabilities();
            },
        ));
    }

    private function register_convert() {
        $this->register(self::PREFIX . 'media-convert', array(
            'label'       => 'Convert mismatched attachments on the server',
            'description' => 'Transcode attachments whose file on disk is not the format their record claims - AVIF and WebP left behind by an in-place optimiser - back to JPEG or PNG, then repoint the record and rebuild its sizes. Defaults to a dry run. Targets PNG when the original was one or when the image has transparency, so a logo does not come back with a black background. Verifies every written file before any row is updated, and reverts the record if the metadata rebuild fails.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'attachment_ids' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                        'description' => 'Limit to these attachment IDs. Omit to work through everything mismatched.',
                    ),
                    'limit' => array(
                        'type'        => 'integer',
                        'description' => 'How many to attempt in this call. Default 10, maximum 50. Reduced automatically when max_execution_time is short.',
                    ),
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false before any file is written or any record changed.',
                    ),
                    'quality' => array(
                        'type'        => 'integer',
                        'description' => 'JPEG quality, 40 to 100. Default 82. Ignored for PNG output, which is lossless.',
                    ),
                    'delete_source' => array(
                        'type'        => 'boolean',
                        'description' => 'Remove the original AVIF or WebP after a fully successful conversion. Defaults to false.',
                    ),
                    'target_format' => array(
                        'type'        => 'string',
                        'enum'        => array('jpg', 'png'),
                        'description' => 'Force the output format instead of deciding per image. Forcing jpg on a transparent image flattens it onto white.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                return $this->convert(is_array($input) ? $input : array());
            },
        ));
    }

    private function register_scan() {
        $this->register(self::PREFIX . 'media-scan', array(
            'label'       => 'Find image problems in the library',
            'description' => 'Group every fault the media library can be checked for without changing anything: files missing from disk, zero-byte files, records whose MIME type disagrees with the actual file contents, attachments with no metadata or no generated sizes, files over a size threshold, and image files in uploads that no attachment claims. Returns counts per problem with a bounded sample of each. Read-only.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'large_bytes' => array(
                        'type'        => 'integer',
                        'description' => 'Byte size above which a file is listed as an optimisation candidate. Default 2097152 (2 MB).',
                    ),
                    'sample' => array(
                        'type'        => 'integer',
                        'description' => 'Rows returned per problem type. Default 10, maximum 50.',
                    ),
                    'find_orphans' => array(
                        'type'        => 'boolean',
                        'description' => 'Walk the uploads directory for unreferenced image files. Defaults to true. The walk is capped and the response says if the cap was reached.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $large   = isset($input['large_bytes']) ? (int) $input['large_bytes'] : 2097152;
                $sample  = isset($input['sample']) ? (int) $input['sample'] : self::SAMPLE;
                $orphans = !isset($input['find_orphans']) || (bool) $input['find_orphans'];

                return $this->scan($large, $sample, $orphans);
            },
        ));
    }
}
