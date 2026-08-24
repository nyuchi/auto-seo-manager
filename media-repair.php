<?php
/**
 * Repair attachments whose file and metadata disagree.
 *
 * An image optimiser that converts a file in place and renames it, without
 * updating the attachment record, leaves WordPress describing a file that is
 * not there. The library shows the wrong type, offload plugins send the wrong
 * bytes to services that reject them, and nothing reports an error because
 * from WordPress's point of view nothing failed.
 *
 * This finds those attachments and repoints them at a replacement file, then
 * rebuilds the attachment metadata so the generated sizes match reality again.
 *
 * Nothing here converts an image. Producing a replacement is a separate job,
 * deliberately: transcoding on a web request is slow, memory-hungry, and on
 * shared hosting is the kind of thing that gets a process killed half way
 * through leaving a truncated file behind. This only runs once a replacement
 * already exists on disk, and refuses to touch an attachment otherwise.
 *
 * @package AutoSEOManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class AutoSEOMediaRepair {

    const CATEGORY = 'nyuchi-optimization';
    const PREFIX   = 'nyuchi-optimization/';

    public function __construct() {
        add_action('wp_abilities_api_init', array($this, 'register_abilities'));
    }

    public function can_manage() {
        return current_user_can('manage_options');
    }

    private function register($name, $args) {
        if (function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }

    public function register_abilities() {
        $this->register(self::PREFIX . 'media-mismatches', array(
            'label'       => 'Attachments whose file type is wrong',
            'description' => 'Find attachments where the stored file extension disagrees with the recorded MIME type, which is what an in-place image conversion leaves behind. Reports whether a replacement file is already present for each. Read-only.',
            'category'    => self::CATEGORY,
            'input_schema' => array('type' => 'object', 'properties' => array(), 'additionalProperties' => false),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function () {
                return $this->find_mismatches();
            },
        ));

        $this->register(self::PREFIX . 'media-repair', array(
            'label'       => 'Repoint attachments at their replacement file',
            'description' => 'For each mismatched attachment that has a replacement file on disk, update the stored path and MIME type and rebuild the attachment metadata so the generated sizes are correct. Defaults to a dry run. Optionally removes the superseded file, but only after the record has been successfully repointed.',
            'category'    => self::CATEGORY,
            'input_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'dry_run' => array(
                        'type'        => 'boolean',
                        'description' => 'Defaults to true. Must be explicitly false to change anything.',
                    ),
                    'delete_old' => array(
                        'type'        => 'boolean',
                        'description' => 'Remove the superseded file after a successful repoint. Defaults to false.',
                    ),
                    'ids' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'integer'),
                        'description' => 'Limit to these attachment IDs. Omit to process every repairable one.',
                    ),
                ),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array($this, 'can_manage'),
            'execute_callback'    => function ($input) {
                $dry = !isset($input['dry_run']) || (bool) $input['dry_run'];
                $del = isset($input['delete_old']) && (bool) $input['delete_old'];
                $ids = isset($input['ids']) ? array_map('intval', (array) $input['ids']) : array();

                return $this->repair($dry, $del, $ids);
            },
        ));
    }

    /**
     * Extension WordPress believes this attachment should have.
     *
     * @param string $mime Recorded MIME type.
     * @return string|null
     */
    protected function expected_ext($mime) {
        $map = array(
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        );

        return isset($map[$mime]) ? $map[$mime] : null;
    }

    /**
     * Attachments whose stored extension contradicts their recorded type.
     *
     * @return array
     */
    public function find_mismatches() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_mime_type, pm.meta_value AS file
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
              WHERE p.post_type = 'attachment'",
            ARRAY_A
        );

        $dir  = wp_get_upload_dir();
        $base = trailingslashit($dir['basedir']);

        $out        = array();
        $repairable = 0;

        foreach ((array) $rows as $r) {
            $file = (string) $r['file'];
            $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $want = $this->expected_ext($r['post_mime_type']);

            if (!$want || $ext === $want) {
                continue;
            }

            // jpg and jpeg are the same format under two spellings, not a fault.
            if ('jpg' === $want && 'jpeg' === $ext) {
                continue;
            }

            $replacement = preg_replace('/\.[^.]+$/', '.' . $want, $file);
            $has         = file_exists($base . $replacement);

            if ($has) {
                $repairable++;
            }

            $out[] = array(
                'id'           => (int) $r['ID'],
                'recorded_as'  => $r['post_mime_type'],
                'stored_file'  => $file,
                'expects'      => $replacement,
                'replacement_present' => $has,
                'old_file_present'    => file_exists($base . $file),
            );
        }

        return array(
            'mismatched'  => count($out),
            'repairable'  => $repairable,
            'blocked'     => count($out) - $repairable,
            'attachments' => $out,
            'note'        => $repairable === count($out)
                ? 'Every mismatch has a replacement file present.'
                : 'Entries with replacement_present false have nothing to point at yet - upload the converted file first, then run this again.',
        );
    }

    /**
     * Repoint mismatched attachments.
     *
     * @param bool  $dry_run    Report only.
     * @param bool  $delete_old Remove the superseded file afterwards.
     * @param int[] $ids        Restrict to these IDs.
     * @return array
     */
    public function repair($dry_run = true, $delete_old = false, $ids = array()) {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $found = $this->find_mismatches();
        $dir   = wp_get_upload_dir();
        $base  = trailingslashit($dir['basedir']);

        $done    = array();
        $changed = 0;

        foreach ($found['attachments'] as $a) {
            if (!empty($ids) && !in_array($a['id'], $ids, true)) {
                continue;
            }

            if (!$a['replacement_present']) {
                $done[] = array(
                    'id'     => $a['id'],
                    'result' => 'skipped - no replacement file on disk',
                    'wanted' => $a['expects'],
                );
                continue;
            }

            if ($dry_run) {
                $done[] = array(
                    'id'        => $a['id'],
                    'result'    => 'would repoint',
                    'from'      => $a['stored_file'],
                    'to'        => $a['expects'],
                    'mime_stays'=> $a['recorded_as'],
                );
                continue;
            }

            update_post_meta($a['id'], '_wp_attached_file', $a['expects']);

            // The recorded MIME type is already what the replacement really is -
            // that is the whole point of choosing the replacement by it - so the
            // record becomes truthful without the type being touched.
            $meta = wp_generate_attachment_metadata($a['id'], $base . $a['expects']);

            if (is_wp_error($meta) || empty($meta)) {
                // Put the pointer back rather than leave the record describing a
                // file whose sizes were never generated.
                update_post_meta($a['id'], '_wp_attached_file', $a['stored_file']);

                $done[] = array(
                    'id'     => $a['id'],
                    'result' => 'failed - could not rebuild metadata, reverted',
                );
                continue;
            }

            wp_update_attachment_metadata($a['id'], $meta);
            $changed++;

            $removed = false;

            if ($delete_old && $a['old_file_present'] && $a['stored_file'] !== $a['expects']) {
                $removed = @unlink($base . $a['stored_file']);
            }

            $done[] = array(
                'id'          => $a['id'],
                'result'      => 'repointed',
                'to'          => $a['expects'],
                'sizes_built' => isset($meta['sizes']) ? count($meta['sizes']) : 0,
                'old_removed' => $removed,
            );
        }

        return array(
            'dry_run'   => (bool) $dry_run,
            'repointed' => $changed,
            'results'   => $done,
            'note'      => $dry_run
                ? 'Nothing was changed. Call again with dry_run false to apply.'
                : 'Attachment metadata was rebuilt. If images are offloaded to a CDN, run that plugin\'s bulk offload again so the new files are pushed.',
        );
    }
}
