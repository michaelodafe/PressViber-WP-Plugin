<?php
defined( 'ABSPATH' ) || exit;

/**
 * Provides secure, sandboxed access to the WordPress file system.
 *
 * PUBLIC API (called directly from PV_Agent):
 *   fm_list($rel_path)                     → array|WP_Error
 *   fm_read($rel_path)                     → array|WP_Error
 *   fm_read_many($paths)                   → array|WP_Error
 *   fm_write($rel_path, $content)          → array|WP_Error
 *   fm_replace_text($rel_path, $old, $new) → array|WP_Error
 *   fm_patch($rel_path, $operations)       → array|WP_Error
 *   fm_mkdir($rel_path)                    → array|WP_Error
 *   fm_move($from, $to)                    → array|WP_Error
 *   fm_delete($rel_path)                   → array|WP_Error
 *   fm_stat($rel_path)                     → array|WP_Error
 *   fm_search($pattern, $rel_dir, $type)   → array|WP_Error
 *
 * AJAX ACTIONS (for browser calls):
 *   pv_fs_list, pv_fs_read, pv_fs_write, pv_fs_stat
 */
class PV_File_Manager {

    private string $root;

    private array $blocked_files = [ 'wp-config.php', '.htpasswd' ];

    private array $binary_extensions = [
        'jpg','jpeg','png','gif','webp','svg','ico',
        'woff','woff2','ttf','eot','otf',
        'zip','gz','tar','rar','7z',
        'pdf','doc','docx','xls','xlsx',
        'mp4','mp3','wav','ogg','webm',
        'exe','dll','so','dylib',
    ];

    const READ_SIZE_LIMIT = 524288; // 512 KB
    const BATCH_READ_LIMIT = 8;
    const SEARCH_MATCH_LIMIT = 8; // Kept low to avoid filling the AI context window

    public function __construct() {
        $this->root = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
    }

    public function init() {
        add_action( 'wp_ajax_pv_fs_list',  [ $this, 'ajax_list' ] );
        add_action( 'wp_ajax_pv_fs_read',  [ $this, 'ajax_read' ] );
        add_action( 'wp_ajax_pv_fs_write', [ $this, 'ajax_write' ] );
        add_action( 'wp_ajax_pv_fs_replace', [ $this, 'ajax_replace' ] );
        add_action( 'wp_ajax_pv_fs_stat',  [ $this, 'ajax_stat' ] );
    }

    /* =========================================================================
       PUBLIC DIRECT-CALL API  (used by PV_Agent, no AJAX overhead)
       ========================================================================= */

    /**
     * List a directory.
     *
     * @param string $rel_path  Relative path from WP root; empty = root.
     * @return array|WP_Error   { path, entries[] }
     */
    public function fm_list( string $rel_path = '' ) {
        $abs = $rel_path !== '' ? $this->resolve( $this->sanitize_path( $rel_path ) ) : $this->root;

        if ( ! $abs || ! is_dir( $abs ) ) {
            return new WP_Error( 'invalid_path', "Not a valid directory: $rel_path" );
        }

        return [
            'path'    => $this->to_relative( $abs ),
            'entries' => $this->scan_directory( $abs ),
        ];
    }

    /**
     * Read a file.
     *
     * @param string $rel_path
     * @return array|WP_Error  { path, content, size, ext, mtime }
     */
    public function fm_read( string $rel_path ) {
        $rel = $this->sanitize_path( $rel_path );

        if ( in_array( basename( $rel ), $this->blocked_files, true ) ) {
            return new WP_Error( 'blocked', "Reading $rel_path is blocked." );
        }

        $abs = $this->resolve( $rel );

        if ( ! $abs || ! is_file( $abs ) ) {
            return new WP_Error( 'not_found', "File not found: $rel_path" );
        }

        $ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, $this->binary_extensions, true ) ) {
            return new WP_Error( 'binary', "Binary files cannot be read as text: $rel_path" );
        }

        $size = filesize( $abs );
        if ( $size > self::READ_SIZE_LIMIT ) {
            return new WP_Error( 'too_large', "File too large (" . size_format( $size ) . "): $rel_path" );
        }

        $content = file_get_contents( $abs );
        if ( $content === false ) {
            return new WP_Error( 'read_error', "Could not read: $rel_path" );
        }

        return [
            'path'    => $rel,
            'content' => $content,
            'size'    => $size,
            'ext'     => $ext,
            'mtime'   => filemtime( $abs ),
        ];
    }

    /**
     * Read multiple files in one tool call.
     *
     * @param array $paths
     * @return array|WP_Error { files[], errors[], total }
     */
    public function fm_read_many( array $paths ) {
        $paths = array_values( array_unique( array_filter( array_map( 'strval', $paths ) ) ) );
        if ( empty( $paths ) ) {
            return new WP_Error( 'missing_paths', 'At least one file path is required.' );
        }

        if ( count( $paths ) > self::BATCH_READ_LIMIT ) {
            return new WP_Error( 'too_many_paths', 'Too many files requested at once.' );
        }

        $files  = [];
        $errors = [];

        foreach ( $paths as $path ) {
            $result = $this->fm_read( $path );
            if ( is_wp_error( $result ) ) {
                $errors[] = [
                    'path'    => $path,
                    'message' => $result->get_error_message(),
                ];
                continue;
            }

            $files[] = $result;
        }

        return [
            'files'  => $files,
            'errors' => $errors,
            'total'  => count( $files ),
        ];
    }

    /**
     * Write (create or overwrite) a file.
     *
     * @param string $rel_path
     * @param string $content
     * @return array|WP_Error  { path, bytes_written, backup }
     */
    public function fm_write( string $rel_path, string $content ) {
        $rel = $this->sanitize_path( $rel_path );

        if ( in_array( basename( $rel ), $this->blocked_files, true ) ) {
            return new WP_Error( 'blocked', "Writing to $rel_path is blocked." );
        }

        $abs = $this->resolve_for_write( $rel );

        if ( $abs === false ) {
            return new WP_Error( 'invalid_path', "Invalid or unsafe path: $rel_path" );
        }

        $dir = dirname( $abs );
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'mkdir_fail', "Could not create directory for: $rel_path" );
        }

        // Auto-backup existing file
        $backup = false;
        if ( is_file( $abs ) ) {
            copy( $abs, $abs . '.pv_bak_' . time() );
            $backup = true;
        }

        $bytes = file_put_contents( $abs, $content );
        if ( $bytes === false ) {
            return new WP_Error( 'write_fail', "Write failed (check permissions): $rel_path" );
        }

        return [
            'path'          => $rel,
            'bytes_written' => $bytes,
            'backup'        => $backup,
        ];
    }

    /**
     * Replace exact text in an existing file without requiring a full rewrite.
     *
     * @param string $rel_path
     * @param string $old_text
     * @param string $new_text
     * @param bool   $all_occurrences
     * @return array|WP_Error { path, replacements, bytes_written, backup }
     */
    public function fm_replace_text( string $rel_path, string $old_text, string $new_text, bool $all_occurrences = true ) {
        $rel = $this->sanitize_path( $rel_path );

        if ( in_array( basename( $rel ), $this->blocked_files, true ) ) {
            return new WP_Error( 'blocked', "Writing to $rel_path is blocked." );
        }

        if ( $old_text === '' ) {
            return new WP_Error( 'missing_old_text', 'Old text must not be empty.' );
        }

        $abs = $this->resolve( $rel );
        if ( ! $abs || ! is_file( $abs ) ) {
            return new WP_Error( 'not_found', "File not found: $rel_path" );
        }

        $ext = strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, $this->binary_extensions, true ) ) {
            return new WP_Error( 'binary', "Binary files cannot be edited as text: $rel_path" );
        }

        $content = file_get_contents( $abs );
        if ( $content === false ) {
            return new WP_Error( 'read_error', "Could not read: $rel_path" );
        }

        if ( strpos( $content, $old_text ) === false ) {
            return new WP_Error( 'text_not_found', 'Exact text to replace was not found in the file.' );
        }

        $replacement_count = 0;
        if ( $all_occurrences ) {
            $updated = str_replace( $old_text, $new_text, $content, $replacement_count );
        } else {
            $first_pos = strpos( $content, $old_text );
            if ( false !== $first_pos ) {
                $updated = substr_replace( $content, $new_text, $first_pos, strlen( $old_text ) );
                $replacement_count = 1;
            } else {
                $updated = $content;
            }
        }

        if ( $replacement_count < 1 || $updated === null ) {
            return new WP_Error( 'replace_failed', 'The replacement could not be completed.' );
        }

        $backup = copy( $abs, $abs . '.pv_bak_' . time() );
        $bytes  = file_put_contents( $abs, $updated );

        if ( $bytes === false ) {
            return new WP_Error( 'write_fail', "Write failed (check permissions): $rel_path" );
        }

        return [
            'path'          => $rel,
            'replacements'  => $replacement_count,
            'bytes_written' => $bytes,
            'backup'        => (bool) $backup,
        ];
    }

    /**
     * Apply exact-match patch operations to a file.
     *
     * Supported operations:
     * - replace:      replace the exact "match" text with "content"
     * - insert_after: insert "content" immediately after the exact "match"
     * - insert_before:insert "content" immediately before the exact "match"
     *
     * @param string $rel_path
     * @param array  $operations
     * @return array|WP_Error
     */
    public function fm_patch( string $rel_path, array $operations ) {
        $rel = $this->sanitize_path( $rel_path );

        if ( empty( $operations ) ) {
            return new WP_Error( 'missing_operations', 'At least one patch operation is required.' );
        }

        $file = $this->fm_read( $rel );
        if ( is_wp_error( $file ) ) {
            return $file;
        }

        $updated         = $file['content'];
        $applied         = [];
        $operation_index = 0;

        foreach ( $operations as $operation ) {
            $operation_index++;

            $type        = isset( $operation['type'] ) ? sanitize_key( (string) $operation['type'] ) : 'replace';
            $match       = isset( $operation['match'] ) ? (string) $operation['match'] : '';
            $content     = isset( $operation['content'] ) ? (string) $operation['content'] : '';
            $replace_all = ! empty( $operation['replace_all'] );

            if ( '' === $match ) {
                return new WP_Error( 'invalid_operation', 'Each patch operation requires a non-empty match string.' );
            }

            if ( false === strpos( $updated, $match ) ) {
                return new WP_Error( 'patch_miss', "Patch operation {$operation_index} could not find its match text." );
            }

            $count = 0;

            switch ( $type ) {
                case 'replace':
                    $updated = $replace_all
                        ? str_replace( $match, $content, $updated, $count )
                        : $this->replace_first_occurrence( $updated, $match, $content, $count );
                    break;

                case 'insert_after':
                    $updated = $replace_all
                        ? str_replace( $match, $match . $content, $updated, $count )
                        : $this->replace_first_occurrence( $updated, $match, $match . $content, $count );
                    break;

                case 'insert_before':
                    $updated = $replace_all
                        ? str_replace( $match, $content . $match, $updated, $count )
                        : $this->replace_first_occurrence( $updated, $match, $content . $match, $count );
                    break;

                default:
                    return new WP_Error( 'invalid_operation', "Unsupported patch operation type: {$type}" );
            }

            if ( $count < 1 ) {
                return new WP_Error( 'patch_failed', "Patch operation {$operation_index} did not modify the file." );
            }

            $applied[] = [
                'type'   => $type,
                'match'  => substr( $match, 0, 120 ),
                'count'  => $count,
            ];
        }

        $write = $this->fm_write( $rel, $updated );
        if ( is_wp_error( $write ) ) {
            return $write;
        }

        return [
            'path'              => $rel,
            'operations_applied'=> $applied,
            'bytes_written'     => $write['bytes_written'] ?? 0,
            'backup'            => ! empty( $write['backup'] ),
        ];
    }

    public function fm_mkdir( string $rel_path ) {
        $rel = $this->sanitize_path( $rel_path );
        if ( '' === $rel ) {
            return new WP_Error( 'invalid_path', 'Directory path is required.' );
        }

        $abs = $this->resolve_path_for_write( $rel );
        if ( false === $abs ) {
            return new WP_Error( 'invalid_path', "Invalid or unsafe path: $rel_path" );
        }

        if ( is_dir( $abs ) ) {
            return [
                'path'    => $rel,
                'created' => false,
            ];
        }

        if ( ! wp_mkdir_p( $abs ) ) {
            return new WP_Error( 'mkdir_fail', "Could not create directory: $rel_path" );
        }

        return [
            'path'    => $rel,
            'created' => true,
        ];
    }

    public function fm_move( string $from_rel_path, string $to_rel_path ) {
        $from_rel = $this->sanitize_path( $from_rel_path );
        $to_rel   = $this->sanitize_path( $to_rel_path );

        if ( '' === $from_rel || '' === $to_rel ) {
            return new WP_Error( 'invalid_path', 'Both source and destination paths are required.' );
        }

        $from_abs = $this->resolve( $from_rel );
        if ( false === $from_abs || ! file_exists( $from_abs ) ) {
            return new WP_Error( 'not_found', "Source path not found: $from_rel_path" );
        }

        $to_abs = $this->resolve_path_for_write( $to_rel );
        if ( false === $to_abs ) {
            return new WP_Error( 'invalid_path', "Invalid or unsafe destination path: $to_rel_path" );
        }

        if ( file_exists( $to_abs ) ) {
            return new WP_Error( 'destination_exists', "Destination already exists: $to_rel_path" );
        }

        $target_dir = dirname( $to_abs );
        if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'mkdir_fail', "Could not create destination directory: $to_rel_path" );
        }

        if ( ! @rename( $from_abs, $to_abs ) ) {
            return new WP_Error( 'move_failed', "Could not move $from_rel_path to $to_rel_path" );
        }

        return [
            'from' => $from_rel,
            'to'   => $to_rel,
        ];
    }

    public function fm_delete( string $rel_path ) {
        $rel = $this->sanitize_path( $rel_path );
        if ( '' === $rel ) {
            return new WP_Error( 'invalid_path', 'Path is required.' );
        }

        if ( in_array( basename( $rel ), $this->blocked_files, true ) ) {
            return new WP_Error( 'blocked', "Deleting $rel_path is blocked." );
        }

        $abs = $this->resolve( $rel );
        if ( false === $abs || ! file_exists( $abs ) ) {
            return new WP_Error( 'not_found', "Path not found: $rel_path" );
        }

        $trash_rel = '.pv_trash/' . gmdate( 'Ymd_His' ) . '__' . str_replace( '/', '__', $rel );
        $trash_abs = $this->resolve_path_for_write( $trash_rel );
        if ( false === $trash_abs ) {
            return new WP_Error( 'invalid_path', 'Could not prepare trash path.' );
        }

        $trash_dir = dirname( $trash_abs );
        if ( ! is_dir( $trash_dir ) && ! wp_mkdir_p( $trash_dir ) ) {
            return new WP_Error( 'mkdir_fail', 'Could not create trash directory.' );
        }

        if ( ! @rename( $abs, $trash_abs ) ) {
            return new WP_Error( 'delete_failed', "Could not move $rel_path to trash." );
        }

        return [
            'path'       => $rel,
            'trashed_to' => $this->to_relative( $trash_abs ),
        ];
    }

    /**
     * Stat a path.
     *
     * @param string $rel_path
     * @return array|WP_Error
     */
    public function fm_stat( string $rel_path ) {
        $rel = $rel_path !== '' ? $this->sanitize_path( $rel_path ) : '';
        $abs = $rel !== '' ? $this->resolve( $rel ) : $this->root;

        if ( ! $abs || ! file_exists( $abs ) ) {
            return new WP_Error( 'not_found', "Path not found: $rel_path" );
        }

        return [
            'path'     => $rel ?: '/',
            'type'     => is_dir( $abs ) ? 'dir' : 'file',
            'size'     => is_file( $abs ) ? filesize( $abs ) : null,
            'mtime'    => filemtime( $abs ),
            'readable' => is_readable( $abs ),
            'writable' => is_writable( $abs ),
            'ext'      => is_file( $abs ) ? strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ) : null,
        ];
    }

    /**
     * Search for files by name pattern OR text content within a directory.
     *
     * @param string $pattern     Filename glob (e.g. "*.php") or text string.
     * @param string $rel_dir     Directory to search (relative from WP root).
     * @param string $type        'filename' | 'content'
     * @return array|WP_Error     { matches[], total }
     */
    public function fm_search( string $pattern, string $rel_dir = '', string $type = 'filename' ) {
        $rel     = $rel_dir !== '' ? $this->sanitize_path( $rel_dir ) : '';
        $abs_dir = $rel !== '' ? $this->resolve( $rel ) : $this->root;

        if ( ! $abs_dir || ! is_dir( $abs_dir ) ) {
            return new WP_Error( 'invalid_dir', "Not a valid directory: $rel_dir" );
        }

        $matches = [];
        $this->search_recursive( $abs_dir, $pattern, $type, $matches, 0, 5, self::SEARCH_MATCH_LIMIT );

        return [
            'matches' => $matches,
            'total'   => count( $matches ),
        ];
    }

    /**
     * Tree snapshot used for AI context injection.
     *
     * @param string $rel_path
     * @param int    $depth
     * @return string[]  List of relative paths.
     */
    public function list_tree( string $rel_path = '', int $depth = 3 ): array {
        $abs = $rel_path !== '' ? $this->resolve( $this->sanitize_path( $rel_path ) ) : $this->root;
        if ( ! $abs || ! is_dir( $abs ) ) return [];
        return $this->recurse_tree( $abs, $depth, 0 );
    }

    /* =========================================================================
       AJAX WRAPPERS
       ========================================================================= */

    public function ajax_list() {
        $this->verify();
        $result = $this->fm_list( $this->post_path() );
        is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( $result );
    }

    public function ajax_read() {
        $this->verify();
        $rel = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $result = $this->fm_read( $rel );
        is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( $result );
    }

    public function ajax_write() {
        $this->verify();
        $rel     = isset( $_POST['path'] )    ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
        $result  = $this->fm_write( $rel, $content );
        is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( $result );
    }

    public function ajax_replace() {
        $this->verify();
        $rel             = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $old_text        = isset( $_POST['old_text'] ) ? wp_unslash( $_POST['old_text'] ) : '';
        $new_text        = isset( $_POST['new_text'] ) ? wp_unslash( $_POST['new_text'] ) : '';
        $all_occurrences = ! isset( $_POST['all_occurrences'] ) || filter_var( wp_unslash( $_POST['all_occurrences'] ), FILTER_VALIDATE_BOOLEAN );
        $result          = $this->fm_replace_text( $rel, $old_text, $new_text, $all_occurrences );
        is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( $result );
    }

    public function ajax_stat() {
        $this->verify();
        $result = $this->fm_stat( $this->post_path() );
        is_wp_error( $result ) ? wp_send_json_error( [ 'message' => $result->get_error_message() ] ) : wp_send_json_success( $result );
    }

    /* =========================================================================
       INTERNALS
       ========================================================================= */

    private function verify() {
        check_ajax_referer( 'pv_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
    }

    private function post_path(): string {
        return isset( $_POST['path'] ) ? $this->sanitize_path( wp_unslash( $_POST['path'] ) ) : '';
    }

    private function sanitize_path( string $path ): string {
        $path = preg_replace( '/[\x00-\x1F]/', '', $path );
        $path = str_replace( '\\', '/', $path );
        return ltrim( $path, '/' );
    }

    private function resolve( string $rel ) {
        $candidate = $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
        $real      = realpath( $candidate );
        if ( $real === false ) return false;
        if ( strncmp( $real, $this->root, strlen( $this->root ) ) !== 0 ) return false;
        return $real;
    }

    private function resolve_for_write( string $rel ) {
        $candidate   = $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
        $parent      = dirname( $candidate );
        $real_parent = realpath( $parent );

        if ( $real_parent !== false ) {
            if ( strncmp( $real_parent, $this->root, strlen( $this->root ) ) !== 0 ) return false;
            return $real_parent . DIRECTORY_SEPARATOR . basename( $rel );
        }

        if ( strpos( $candidate, '..' ) !== false ) return false;
        if ( strncmp( $candidate, $this->root, strlen( $this->root ) ) !== 0 ) return false;
        return $candidate;
    }

    private function resolve_path_for_write( string $rel ) {
        $candidate = $this->root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
        if ( strpos( $candidate, '..' ) !== false ) {
            return false;
        }
        if ( strncmp( $candidate, $this->root, strlen( $this->root ) ) !== 0 ) {
            return false;
        }
        return $candidate;
    }

    private function to_relative( string $abs ): string {
        return ltrim( str_replace( $this->root, '', $abs ), DIRECTORY_SEPARATOR ) ?: '/';
    }

    private function scan_directory( string $abs ): array {
        $items   = @scandir( $abs );
        $entries = [];
        if ( ! $items ) return $entries;

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $full    = $abs . DIRECTORY_SEPARATOR . $item;
            $is_dir  = is_dir( $full );
            $entries[] = [
                'name'     => $item,
                'type'     => $is_dir ? 'dir' : 'file',
                'path'     => $this->to_relative( $full ),
                'ext'      => $is_dir ? null : strtolower( pathinfo( $item, PATHINFO_EXTENSION ) ),
                'size'     => $is_dir ? null : @filesize( $full ),
                'mtime'    => @filemtime( $full ),
                'readable' => is_readable( $full ),
                'writable' => is_writable( $full ),
            ];
        }

        usort( $entries, function ( $a, $b ) {
            if ( $a['type'] !== $b['type'] ) return $a['type'] === 'dir' ? -1 : 1;
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $entries;
    }

    private function search_recursive( string $dir, string $pattern, string $type, array &$matches, int $depth, int $max_depth, int $max_matches ): void {
        if ( $depth >= $max_depth ) return;
        if ( count( $matches ) >= $max_matches ) return;
        $skip = [ 'node_modules', '.git', 'vendor', '.svn' ];
        $items = @scandir( $dir );
        if ( ! $items ) return;

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            if ( in_array( $item, $skip, true ) ) continue;
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            $rel  = $this->to_relative( $full );

            if ( is_dir( $full ) ) {
                $this->search_recursive( $full, $pattern, $type, $matches, $depth + 1, $max_depth, $max_matches );
            } elseif ( is_file( $full ) ) {
                if ( $type === 'filename' ) {
                    if ( fnmatch( $pattern, $item ) || stripos( $item, $pattern ) !== false ) {
                        $matches[] = [ 'path' => $rel, 'name' => $item ];
                    }
                } elseif ( $type === 'content' ) {
                    $ext = strtolower( pathinfo( $item, PATHINFO_EXTENSION ) );
                    if ( in_array( $ext, $this->binary_extensions, true ) ) continue;
                    $content = @file_get_contents( $full );
                    if ( $content !== false ) {
                        $offset = stripos( $content, $pattern );
                        if ( $offset !== false ) {
                            $match = [ 'path' => $rel, 'name' => $item ];
                            $match = array_merge( $match, $this->build_match_context( $content, $pattern, $offset ) );
                            $matches[] = $match;
                        }
                    }
                }
            }

            if ( count( $matches ) >= $max_matches ) {
                return;
            }
        }
    }

    /**
     * Read N lines around a specific line number from a file.
     * Returns annotated lines with ">>>" marking the matched line.
     * Token-efficient: only the surrounding context, never the whole file.
     *
     * @param string $rel_path  Relative file path.
     * @param int    $line      1-based line number to centre on.
     * @param int    $radius    Lines to include before and after.
     * @return string           Annotated code block, or empty string on failure.
     */
    public function read_lines_context( string $rel_path, int $line, int $radius = 8 ): string {
        $rel = $this->sanitize_path( $rel_path );
        $abs = $this->resolve( $rel );

        if ( ! $abs || ! is_file( $abs ) ) {
            return '';
        }

        $all_lines = @file( $abs );
        if ( ! $all_lines ) {
            return '';
        }

        $total = count( $all_lines );
        $start = max( 0, $line - $radius - 1 );
        $end   = min( $total - 1, $line + $radius - 1 );

        $out = [];
        for ( $i = $start; $i <= $end; $i++ ) {
            $marker = ( $i + 1 === $line ) ? '>>>' : '   ';
            $out[]  = $marker . ' ' . ( $i + 1 ) . ': ' . rtrim( $all_lines[ $i ] );
        }

        return implode( "\n", $out );
    }

    private function build_match_context( string $content, string $pattern, int $offset ): array {
        $line_number = substr_count( substr( $content, 0, $offset ), "\n" ) + 1;
        $line_start  = strrpos( substr( $content, 0, $offset ), "\n" );
        $line_start  = false === $line_start ? 0 : $line_start + 1;
        $line_end    = strpos( $content, "\n", $offset );
        $line_end    = false === $line_end ? strlen( $content ) : $line_end;
        $line        = trim( substr( $content, $line_start, $line_end - $line_start ) );

        $excerpt_start = max( 0, $offset - 80 );
        $excerpt       = substr( $content, $excerpt_start, strlen( $pattern ) + 160 );
        $excerpt       = preg_replace( '/\s+/', ' ', (string) $excerpt );

        return [
            'line'    => $line_number,
            'snippet' => $line,
            'excerpt' => trim( (string) $excerpt ),
        ];
    }

    private function replace_first_occurrence( string $haystack, string $needle, string $replacement, int &$count ): string {
        $count = 0;
        $position = strpos( $haystack, $needle );
        if ( false === $position ) {
            return $haystack;
        }

        $count = 1;
        return substr_replace( $haystack, $replacement, $position, strlen( $needle ) );
    }

    private function recurse_tree( string $abs, int $max, int $cur ): array {
        if ( $cur >= $max ) return [];
        $skip   = [ 'node_modules', '.git', 'vendor', '.svn', 'cache', 'uploads' ];
        $items  = @scandir( $abs );
        $result = [];
        if ( ! $items ) return $result;

        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $full = $abs . DIRECTORY_SEPARATOR . $item;
            $rel  = $this->to_relative( $full );
            if ( is_dir( $full ) ) {
                if ( in_array( $item, $skip, true ) ) continue;
                $result[] = $rel . '/';
                $result   = array_merge( $result, $this->recurse_tree( $full, $max, $cur + 1 ) );
            } else {
                $result[] = $rel;
            }
        }
        return $result;
    }
}
