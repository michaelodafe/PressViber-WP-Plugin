<?php
defined( 'ABSPATH' ) || exit;

class PV_Site_Inspector {

    const WIDGET_VISIBILITY_RULES_OPTION = 'pv_widget_visibility_rules';

    public function init(): void {
        add_filter( 'sidebars_widgets', [ $this, 'apply_widget_visibility_rules' ], 40 );
    }

    public function inspect_front_page(): array {
        $show_on_front = get_option( 'show_on_front', 'posts' );
        $front_page_id = (int) get_option( 'page_on_front', 0 );
        $posts_page_id = (int) get_option( 'page_for_posts', 0 );

        $front_page = $front_page_id > 0 ? get_post( $front_page_id ) : null;
        $posts_page = $posts_page_id > 0 ? get_post( $posts_page_id ) : null;

        $result = [
            'front_page' => [
                'mode'       => $show_on_front,
                'url'        => home_url( '/' ),
                'page'       => $front_page instanceof WP_Post ? $this->map_post_summary( $front_page ) : null,
                'posts_page' => $posts_page instanceof WP_Post ? $this->map_post_summary( $posts_page ) : null,
            ],
        ];

        if ( $front_page instanceof WP_Post ) {
            $result['front_page']['template'] = get_post_meta( $front_page->ID, '_wp_page_template', true ) ?: 'default';
        } else {
            $result['front_page']['template_candidates'] = [ 'front-page.php', 'home.php', 'index.php' ];
        }

        $render = $this->fetch_same_origin_html( home_url( '/' ) );
        if ( is_wp_error( $render ) ) {
            $result['render_error'] = $render->get_error_message();
            return $result;
        }

        $markers = $this->extract_dom_markers( $render['body'] );

        $result['render'] = [
            'status_code'    => $render['status_code'],
            'title'          => $render['title'],
            'text_excerpt'   => substr( $render['text'], 0, 1200 ),
            'headings'       => $markers['headings'],
            'section_ids'    => $markers['section_ids'],
            'marker_classes' => $markers['marker_classes'],
            'body_classes'   => $markers['body_classes'],
        ];

        return $result;
    }

    /* =========================================================================
       POSTS / CONTENT TOOLS
       ========================================================================= */

    public function list_all_posts( int $limit = 20, string $post_type = 'post', string $status = 'any' ): array {
        $limit     = max( 1, min( 100, $limit ) );
        $post_type = '' !== $post_type ? sanitize_key( $post_type ) : 'post';
        $status    = in_array( $status, [ 'publish', 'draft', 'private', 'pending', 'trash', 'any' ], true ) ? $status : 'any';

        $query = new WP_Query( [
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => false,
        ] );

        $posts = [];
        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof WP_Post ) {
                continue;
            }
            $posts[] = [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'status'   => $post->post_status,
                'type'     => $post->post_type,
                'url'      => get_permalink( $post->ID ) ?: '',
                'modified' => $post->post_modified,
                'excerpt'  => wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 ),
            ];
        }

        return [
            'posts'      => $posts,
            'total'      => (int) $query->found_posts,
            'returned'   => count( $posts ),
            'post_type'  => $post_type,
            'status'     => $status,
        ];
    }

    public function get_any_post( int $post_id ) {
        if ( $post_id < 1 ) {
            return new WP_Error( 'missing_id', 'A post ID is required.' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'post_not_found', "Post {$post_id} not found." );
        }

        $template = get_post_meta( $post_id, '_wp_page_template', true ) ?: '';

        return [
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'status'          => $post->post_status,
            'type'            => $post->post_type,
            'url'             => get_permalink( $post->ID ) ?: '',
            'template'        => $template,
            'content'         => $post->post_content,
            'excerpt'         => $post->post_excerpt,
            'featured_image'  => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: '',
            'modified'        => $post->post_modified,
            'author'          => get_the_author_meta( 'display_name', (int) $post->post_author ),
            'content_length'  => strlen( $post->post_content ),
        ];
    }

    public function create_new_post( array $data ) {
        $title     = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
        $content   = wp_kses_post( (string) ( $data['content'] ?? '' ) );
        $post_type = sanitize_key( (string) ( $data['post_type'] ?? 'post' ) );
        $status    = in_array( $data['status'] ?? 'draft', [ 'publish', 'draft', 'private', 'pending' ], true )
            ? (string) $data['status']
            : 'draft';
        $slug      = sanitize_title( (string) ( $data['slug'] ?? $title ) );
        $excerpt   = sanitize_textarea_field( (string) ( $data['excerpt'] ?? '' ) );
        $parent_id = isset( $data['parent_id'] ) ? (int) $data['parent_id'] : 0;
        $menu_order = isset( $data['menu_order'] ) ? (int) $data['menu_order'] : 0;

        if ( '' === $title ) {
            return new WP_Error( 'missing_title', 'A post title is required.' );
        }

        $post_arr = [
            'post_title'   => $title,
            'post_content' => wp_slash( $content ),
            'post_type'    => $post_type,
            'post_status'  => $status,
            'post_name'    => $slug,
            'post_excerpt' => $excerpt,
            'post_parent'  => $parent_id,
            'menu_order'   => $menu_order,
        ];

        $post_id = wp_insert_post( $post_arr, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Optionally set page template
        if ( ! empty( $data['template'] ) ) {
            update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( (string) $data['template'] ) );
        }

        return [
            'post_id' => $post_id,
            'title'   => $title,
            'status'  => $status,
            'type'    => $post_type,
            'url'     => get_permalink( $post_id ) ?: '',
            'created' => true,
        ];
    }

    public function update_post_fields( int $post_id, array $fields ) {
        if ( $post_id < 1 ) {
            return new WP_Error( 'missing_id', 'A post ID is required.' );
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return new WP_Error( 'post_not_found', "Post {$post_id} not found." );
        }

        $allowed_fields = [ 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_date', 'comment_status', 'ping_status' ];
        $update_args    = [ 'ID' => $post_id ];

        foreach ( $allowed_fields as $field ) {
            if ( ! array_key_exists( $field, $fields ) ) {
                continue;
            }

            if ( in_array( $field, [ 'post_content', 'post_excerpt' ], true ) ) {
                $update_args[ $field ] = wp_slash( (string) $fields[ $field ] );
            } elseif ( 'post_status' === $field ) {
                $status = (string) $fields[ $field ];
                if ( in_array( $status, [ 'publish', 'draft', 'private', 'pending', 'trash' ], true ) ) {
                    $update_args[ $field ] = $status;
                }
            } else {
                $update_args[ $field ] = sanitize_text_field( (string) $fields[ $field ] );
            }
        }

        if ( count( $update_args ) < 2 ) {
            return new WP_Error( 'no_valid_fields', 'No valid fields were provided for update.' );
        }

        $result = wp_update_post( $update_args, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Handle template separately (stored in post meta)
        if ( array_key_exists( 'template', $fields ) ) {
            update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( (string) $fields['template'] ) );
        }

        clean_post_cache( $post_id );

        return [
            'post_id' => $post_id,
            'title'   => get_the_title( $post_id ),
            'status'  => get_post_status( $post_id ),
            'url'     => get_permalink( $post_id ) ?: '',
            'updated' => true,
        ];
    }

    public function search_posts_content( string $search, string $post_type = '', int $limit = 20 ): array {
        $limit  = max( 1, min( 50, $limit ) );
        $search = trim( $search );

        if ( '' === $search ) {
            return new WP_Error( 'missing_search', 'A search term is required.' );
        }

        $args = [
            's'              => $search,
            'posts_per_page' => $limit,
            'post_status'    => 'any',
            'no_found_rows'  => false,
        ];

        if ( '' !== $post_type ) {
            $args['post_type'] = sanitize_key( $post_type );
        } else {
            $args['post_type'] = 'any';
        }

        $query = new WP_Query( $args );
        $posts = [];

        foreach ( $query->posts as $post ) {
            if ( ! $post instanceof WP_Post ) {
                continue;
            }
            $posts[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'type'    => $post->post_type,
                'status'  => $post->post_status,
                'url'     => get_permalink( $post->ID ) ?: '',
                'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 ),
            ];
        }

        return [
            'search'   => $search,
            'posts'    => $posts,
            'total'    => (int) $query->found_posts,
            'returned' => count( $posts ),
        ];
    }

    /* =========================================================================
       SITE SETTINGS & OPTIONS TOOLS
       ========================================================================= */

    public function get_site_settings(): array {
        return [
            'blogname'        => get_option( 'blogname', '' ),
            'blogdescription' => get_option( 'blogdescription', '' ),
            'siteurl'         => get_option( 'siteurl', '' ),
            'home'            => get_option( 'home', '' ),
            'admin_email'     => get_option( 'admin_email', '' ),
            'timezone_string' => get_option( 'timezone_string', '' ),
            'gmt_offset'      => get_option( 'gmt_offset', 0 ),
            'date_format'     => get_option( 'date_format', '' ),
            'time_format'     => get_option( 'time_format', '' ),
            'posts_per_page'  => (int) get_option( 'posts_per_page', 10 ),
            'show_on_front'   => get_option( 'show_on_front', 'posts' ),
            'page_on_front'   => (int) get_option( 'page_on_front', 0 ),
            'page_for_posts'  => (int) get_option( 'page_for_posts', 0 ),
            'default_category'=> (int) get_option( 'default_category', 1 ),
            'permalink_structure' => get_option( 'permalink_structure', '' ),
        ];
    }

    public function update_site_settings( array $settings ) {
        $allowed = [
            'blogname'        => 'sanitize_text_field',
            'blogdescription' => 'sanitize_text_field',
            'admin_email'     => 'sanitize_email',
            'timezone_string' => 'sanitize_text_field',
            'date_format'     => 'sanitize_text_field',
            'time_format'     => 'sanitize_text_field',
            'posts_per_page'  => 'intval',
            'show_on_front'   => null,
            'page_on_front'   => 'intval',
            'page_for_posts'  => 'intval',
        ];

        $updated = [];
        $errors  = [];

        foreach ( $allowed as $key => $sanitizer ) {
            if ( ! array_key_exists( $key, $settings ) ) {
                continue;
            }

            $value = $settings[ $key ];

            if ( 'show_on_front' === $key ) {
                $value = in_array( $value, [ 'posts', 'page' ], true ) ? $value : null;
            } elseif ( null !== $sanitizer ) {
                $value = $sanitizer( $value );
            }

            if ( null === $value ) {
                $errors[] = "Invalid value for {$key}.";
                continue;
            }

            $old = get_option( $key );
            update_option( $key, $value );
            $updated[ $key ] = [ 'old' => $old, 'new' => $value ];
        }

        return [
            'updated' => $updated,
            'errors'  => $errors,
            'count'   => count( $updated ),
        ];
    }

    public function get_custom_css(): array {
        $custom_css = wp_get_custom_css();

        return [
            'css'    => $custom_css,
            'length' => strlen( $custom_css ),
        ];
    }

    public function update_custom_css( string $css ) {
        $result = wp_update_custom_css_post( $css );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return [
            'updated' => true,
            'length'  => strlen( $css ),
            'post_id' => is_object( $result ) ? $result->ID : 0,
        ];
    }

    /* =========================================================================
       NAVIGATION MENU TOOLS
       ========================================================================= */

    public function get_nav_menus_list(): array {
        $menus     = wp_get_nav_menus();
        $locations = get_nav_menu_locations();
        $loc_names = get_registered_nav_menus();

        $result = [];
        foreach ( $menus as $menu ) {
            if ( ! $menu instanceof WP_Term ) {
                continue;
            }

            $assigned_locations = [];
            foreach ( $locations as $location => $menu_id ) {
                if ( $menu_id === $menu->term_id ) {
                    $assigned_locations[] = $location . ( isset( $loc_names[ $location ] ) ? ' (' . $loc_names[ $location ] . ')' : '' );
                }
            }

            $result[] = [
                'id'          => $menu->term_id,
                'name'        => $menu->name,
                'slug'        => $menu->slug,
                'count'       => $menu->count,
                'locations'   => $assigned_locations,
            ];
        }

        return [
            'menus'      => $result,
            'total'      => count( $result ),
            'locations'  => array_map( static function( $key, $name ) { return [ 'id' => $key, 'name' => $name ]; }, array_keys( $loc_names ), $loc_names ),
        ];
    }

    public function get_menu_items_list( int $menu_id = 0, string $location = '' ) {
        if ( 0 === $menu_id && '' !== $location ) {
            $locations = get_nav_menu_locations();
            $menu_id   = (int) ( $locations[ $location ] ?? 0 );
        }

        if ( $menu_id < 1 ) {
            return new WP_Error( 'missing_menu', 'Provide a menu_id or a valid location slug.' );
        }

        $items = wp_get_nav_menu_items( $menu_id );
        if ( ! is_array( $items ) ) {
            return new WP_Error( 'menu_not_found', "No menu found with ID {$menu_id}." );
        }

        $result = [];
        foreach ( $items as $item ) {
            if ( ! $item instanceof WP_Post ) {
                continue;
            }
            $result[] = [
                'id'        => $item->ID,
                'title'     => $item->title,
                'url'       => $item->url,
                'target'    => $item->target,
                'parent_id' => (int) $item->menu_item_parent,
                'order'     => (int) $item->menu_order,
                'type'      => $item->type,
                'object'    => $item->object,
                'object_id' => (int) $item->object_id,
                'classes'   => implode( ' ', (array) $item->classes ),
                'status'    => $item->post_status,
            ];
        }

        return [
            'menu_id' => $menu_id,
            'items'   => $result,
            'total'   => count( $result ),
        ];
    }

    public function update_menu_item_data( int $item_id, array $fields ) {
        if ( $item_id < 1 ) {
            return new WP_Error( 'missing_id', 'A menu item ID is required.' );
        }

        $item = get_post( $item_id );
        if ( ! $item instanceof WP_Post || 'nav_menu_item' !== $item->post_type ) {
            return new WP_Error( 'item_not_found', "Menu item {$item_id} not found." );
        }

        $allowed_meta = [
            '_menu_item_url'        => 'esc_url_raw',
            '_menu_item_target'     => 'sanitize_key',
            '_menu_item_attr_title' => 'sanitize_text_field',
            '_menu_item_classes'    => null,
        ];

        $updated = [];

        if ( isset( $fields['title'] ) ) {
            wp_update_post( [ 'ID' => $item_id, 'post_title' => sanitize_text_field( (string) $fields['title'] ) ], true );
            $updated['title'] = $fields['title'];
        }

        foreach ( $allowed_meta as $meta_key => $sanitizer ) {
            $field_key = ltrim( str_replace( '_menu_item_', '', $meta_key ), '_' );

            if ( ! array_key_exists( $field_key, $fields ) ) {
                continue;
            }

            $value = null !== $sanitizer ? $sanitizer( $fields[ $field_key ] ) : $fields[ $field_key ];
            update_post_meta( $item_id, $meta_key, $value );
            $updated[ $field_key ] = $value;
        }

        clean_post_cache( $item_id );

        return [
            'item_id' => $item_id,
            'updated' => $updated,
            'count'   => count( $updated ),
        ];
    }

    /* =========================================================================
       THEME MODS / CUSTOMIZER TOOLS
       ========================================================================= */

    public function get_all_theme_mods(): array {
        $mods = get_theme_mods();
        $mods = is_array( $mods ) ? $mods : [];

        // Keep only simple scalar/short values — skip serialized CSS, page-builder blobs,
        // widget data, or anything > 300 chars that would balloon the AI context window.
        $clean   = [];
        $skipped = [];
        foreach ( $mods as $key => $value ) {
            if ( is_bool( $value ) || is_numeric( $value ) ) {
                $clean[ $key ] = $value;
            } elseif ( is_string( $value ) ) {
                if ( strlen( $value ) <= 300 ) {
                    $clean[ $key ] = $value;
                } else {
                    $skipped[] = $key . ' (' . strlen( $value ) . ' chars)';
                }
            } elseif ( is_array( $value ) ) {
                // Include small arrays (e.g. color palettes) but skip large ones
                $encoded = wp_json_encode( $value );
                if ( $encoded !== false && strlen( $encoded ) <= 300 ) {
                    $clean[ $key ] = $value;
                } else {
                    $skipped[] = $key . ' (array)';
                }
            }
        }

        return [
            'theme'         => get_template(),
            'mods'          => $clean,
            'count'         => count( $clean ),
            'skipped_large' => $skipped,
        ];
    }

    public function set_theme_mod_value( string $mod_key, $value ) {
        $mod_key = sanitize_key( $mod_key );
        if ( '' === $mod_key ) {
            return new WP_Error( 'missing_key', 'A theme mod key is required.' );
        }

        $old = get_theme_mod( $mod_key );
        set_theme_mod( $mod_key, $value );

        return [
            'key'     => $mod_key,
            'old'     => $old,
            'new'     => $value,
            'updated' => true,
        ];
    }

    /* =========================================================================
       POST TYPE / TAXONOMY DISCOVERY
       ========================================================================= */

    public function list_post_types_info(): array {
        $types = get_post_types( [], 'objects' );
        $result = [];

        foreach ( $types as $type ) {
            $result[] = [
                'name'         => $type->name,
                'label'        => $type->label,
                'public'       => (bool) $type->public,
                'has_archive'  => $type->has_archive,
                'rewrite_slug' => is_array( $type->rewrite ) ? ( $type->rewrite['slug'] ?? '' ) : '',
                'rest_base'    => $type->rest_base ?: $type->name,
            ];
        }

        return [ 'post_types' => $result, 'total' => count( $result ) ];
    }

    public function list_taxonomies_info(): array {
        $taxonomies = get_taxonomies( [], 'objects' );
        $result     = [];

        foreach ( $taxonomies as $tax ) {
            $term_count = wp_count_terms( [ 'taxonomy' => $tax->name, 'hide_empty' => false ] );
            $result[] = [
                'name'        => $tax->name,
                'label'       => $tax->label,
                'public'      => (bool) $tax->public,
                'hierarchical'=> (bool) $tax->hierarchical,
                'post_types'  => $tax->object_type,
                'term_count'  => is_wp_error( $term_count ) ? 0 : (int) $term_count,
            ];
        }

        return [ 'taxonomies' => $result, 'total' => count( $result ) ];
    }

    public function list_terms_for_taxonomy( string $taxonomy, int $limit = 50 ): array {
        $taxonomy = sanitize_key( $taxonomy );
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'invalid_taxonomy', "Taxonomy '{$taxonomy}' does not exist." );
        }

        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => min( 100, max( 1, $limit ) ),
        ] );

        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $result = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $result[] = [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => $term->count,
                'parent'      => $term->parent,
                'url'         => get_term_link( $term ),
            ];
        }

        return [ 'taxonomy' => $taxonomy, 'terms' => $result, 'total' => count( $result ) ];
    }

    public function list_pages( int $limit = 50 ): array {
        $limit = max( 1, min( 200, $limit ) );

        $pages = get_pages( [
            'post_status' => [ 'publish', 'draft', 'private' ],
            'sort_column' => 'post_title',
            'sort_order'  => 'ASC',
            'number'      => $limit,
        ] );

        return [
            'pages' => array_map( [ $this, 'map_page_summary' ], $pages ),
            'total' => count( $pages ),
        ];
    }

    public function inspect_page( int $page_id = 0, string $slug = '' ) {
        $page = $this->resolve_page( $page_id, $slug );
        if ( ! $page ) {
            return new WP_Error( 'page_not_found', 'The requested page could not be found.' );
        }

        $template = get_post_meta( $page->ID, '_wp_page_template', true ) ?: 'default';
        $content  = (string) $page->post_content;

        return [
            'page' => [
                'id'                 => $page->ID,
                'title'              => $page->post_title,
                'slug'               => $page->post_name,
                'status'             => $page->post_status,
                'url'                => get_permalink( $page->ID ),
                'template'           => $template,
                'featured_image_url' => get_the_post_thumbnail_url( $page->ID, 'full' ) ?: '',
                'content_length'     => strlen( $content ),
                'content_excerpt'    => wp_trim_words( wp_strip_all_tags( $content ), 80 ),
            ],
        ];
    }

    public function get_page_content( int $page_id = 0, string $slug = '' ) {
        $page = $this->resolve_page( $page_id, $slug );
        if ( ! $page ) {
            return new WP_Error( 'page_not_found', 'The requested page could not be found.' );
        }

        $template = get_post_meta( $page->ID, '_wp_page_template', true ) ?: 'default';
        $content  = (string) $page->post_content;

        // Cap raw content to 15 KB — a Gutenberg/Elementor/WPBakery page can have
        // megabytes of serialized JSON that would consume the entire context window.
        $content_length = strlen( $content );
        $content_cap    = 15360; // 15 KB
        if ( $content_length > $content_cap ) {
            $content = substr( $content, 0, $content_cap )
                . "\n\n<!-- [content truncated — {$content_length} bytes total."
                . ' Use replace_text_in_file or patch_file for targeted edits.] -->';
        }

        return [
            'page' => [
                'id'              => $page->ID,
                'title'           => $page->post_title,
                'slug'            => $page->post_name,
                'status'          => $page->post_status,
                'url'             => get_permalink( $page->ID ),
                'template'        => $template,
                'content'         => $content,
                'content_length'  => $content_length,
                'content_excerpt' => wp_trim_words( wp_strip_all_tags( $content ), 100 ),
            ],
        ];
    }

    public function update_page_content( int $page_id, string $content ) {
        $page = $page_id > 0 ? get_post( $page_id ) : null;
        if ( ! ( $page instanceof WP_Post ) || 'page' !== $page->post_type ) {
            return new WP_Error( 'page_not_found', 'The requested page could not be found.' );
        }

        $update = wp_update_post(
            [
                'ID'           => $page_id,
                'post_content' => wp_slash( $content ),
            ],
            true
        );

        if ( is_wp_error( $update ) ) {
            return $update;
        }

        clean_post_cache( $page_id );

        return [
            'page_id'        => $page_id,
            'title'          => get_the_title( $page_id ),
            'url'            => get_permalink( $page_id ),
            'content_length' => strlen( $content ),
            'updated'        => true,
        ];
    }

    public function fetch_rendered_page( int $page_id = 0, string $url = '', string $needle = '' ) {
        $target_url = $this->resolve_same_origin_url( $page_id, $url );
        if ( is_wp_error( $target_url ) ) {
            return $target_url;
        }

        $render = $this->fetch_same_origin_html( $target_url );
        if ( is_wp_error( $render ) ) {
            return $render;
        }

        // Extract actual visible text strings from the page HTML.
        // These are returned as `text_snippets` so the agent can immediately
        // grep for them instead of guessing at role keywords.
        $text_snippets = $this->extract_page_text_snippets( $render['body'] );

        return [
            'url'              => $target_url,
            'status_code'      => $render['status_code'],
            'title'            => $render['title'],
            'body_length'      => strlen( $render['body'] ),
            'text_excerpt'     => substr( $render['text'], 0, 1200 ),
            'html_excerpt'     => substr( $render['body'], 0, 2000 ),
            'text_snippets'    => $text_snippets,
            'contains_needle'  => '' !== $needle ? false !== stripos( $render['body'], $needle ) : null,
            'needle'           => $needle,
        ];
    }

    /**
     * Extract distinct visible text strings from rendered HTML.
     *
     * Returns up to 25 text snippets (headings, paragraphs, badges, etc.) so the
     * agent has the EXACT strings it needs to pass to grep_files — eliminating
     * the need to guess at role keywords or CSS class names.
     *
     * The agent should take any unfamiliar subtitle/eyebrow text from this list,
     * grep_files for it (no directory), and edit the file that contains it.
     *
     * @param string $html Raw page HTML.
     * @return string[]    Distinct visible text strings, longest/most useful first.
     */
    private function extract_page_text_snippets( string $html ): array {
        if ( '' === trim( $html ) ) {
            return [];
        }

        $snippets = [];
        $seen     = [];

        // Priority 1: regex-extracted role-tagged elements (eyebrow, subtitle, description, button…)
        $regex_targets = $this->extract_visible_text_targets_from_html_regex( $html );
        foreach ( $regex_targets as $target ) {
            $t = (string) ( $target['text'] ?? '' );
            if ( strlen( $t ) >= 4 && ! isset( $seen[ $t ] ) ) {
                $seen[ $t ]  = true;
                $snippets[]  = $t;
            }
        }

        // Priority 2: all headings (h1–h4) from the raw HTML
        preg_match_all( '/<h[1-4][^>]*>(.*?)<\/h[1-4]>/is', $html, $m );
        foreach ( $m[1] as $raw ) {
            $t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
            if ( strlen( $t ) >= 2 && strlen( $t ) <= 200 && ! isset( $seen[ $t ] ) ) {
                $seen[ $t ] = true;
                $snippets[] = $t;
            }
        }

        // Priority 3: first 10 <p> and <div class="…"> text snippets of 10–180 chars
        preg_match_all( '/<(?:p|div)[^>]*>(.*?)<\/(?:p|div)>/is', $html, $m2 );
        $p_count = 0;
        foreach ( $m2[1] as $raw ) {
            $t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
            if ( strlen( $t ) >= 10 && strlen( $t ) <= 180 && ! isset( $seen[ $t ] ) ) {
                $seen[ $t ] = true;
                $snippets[] = $t;
                $p_count++;
                if ( $p_count >= 10 ) break;
            }
        }

        return array_slice( $snippets, 0, 25 );
    }

    public function inspect_url_context( int $page_id = 0, string $url = '' ) {
        $target_url = $this->resolve_same_origin_url( $page_id, $url );
        if ( is_wp_error( $target_url ) ) {
            return $target_url;
        }

        return $this->build_local_url_context( $target_url );
    }

    public function inspect_visible_text_targets( int $page_id = 0, string $url = '' ) {
        $target_url = $this->resolve_same_origin_url( $page_id, $url );
        if ( is_wp_error( $target_url ) ) {
            return $target_url;
        }

        $render = $this->fetch_same_origin_html( $target_url );
        if ( is_wp_error( $render ) ) {
            return $render;
        }

        $context = $this->build_local_url_context( $target_url );
        $context = is_wp_error( $context ) ? [] : $context;
        $targets = $this->extract_visible_text_targets( $render['body'] );

        return [
            'url'     => $target_url,
            'title'   => (string) ( $context['title'] ?? $render['title'] ?? '' ),
            'kind'    => (string) ( $context['kind'] ?? 'live_url' ),
            'targets' => $targets,
            'total'   => count( $targets ),
        ];
    }

    public function inspect_sidebars( string $needle = '', array $sidebar_ids = [] ): array {
        global $wp_registered_sidebars;

        $registered       = is_array( $wp_registered_sidebars ) ? $wp_registered_sidebars : [];
        $configured       = get_option( 'sidebars_widgets', [] );
        $configured       = is_array( $configured ) ? $configured : [];
        $sidebar_ids      = array_values( array_filter( array_map( 'sanitize_key', $sidebar_ids ) ) );
        $filtered_ids     = ! empty( $sidebar_ids ) ? array_flip( $sidebar_ids ) : [];
        $sidebars         = [];
        $seen_widget_ids  = [];
        $matching_widgets = [];

        foreach ( $registered as $sidebar_id => $sidebar ) {
            if ( ! empty( $filtered_ids ) && ! isset( $filtered_ids[ $sidebar_id ] ) ) {
                continue;
            }

            $widgets = array_values( array_filter( (array) ( $configured[ $sidebar_id ] ?? [] ), 'is_string' ) );
            foreach ( $widgets as $widget_id ) {
                $seen_widget_ids[ $widget_id ] = true;
            }

            $sidebars[] = [
                'id'          => $sidebar_id,
                'name'        => (string) ( $sidebar['name'] ?? $sidebar_id ),
                'description' => (string) ( $sidebar['description'] ?? '' ),
                'widget_ids'  => $widgets,
                'widget_count'=> count( $widgets ),
            ];
        }

        foreach ( $configured as $sidebar_id => $widgets ) {
            if ( isset( $registered[ $sidebar_id ] ) || 'array_version' === $sidebar_id || ! is_array( $widgets ) ) {
                continue;
            }

            if ( ! empty( $filtered_ids ) && ! isset( $filtered_ids[ $sidebar_id ] ) ) {
                continue;
            }

            $widgets = array_values( array_filter( $widgets, 'is_string' ) );
            foreach ( $widgets as $widget_id ) {
                $seen_widget_ids[ $widget_id ] = true;
            }

            $sidebars[] = [
                'id'          => $sidebar_id,
                'name'        => $sidebar_id,
                'description' => '',
                'widget_ids'  => $widgets,
                'widget_count'=> count( $widgets ),
            ];
        }

        if ( '' !== trim( $needle ) ) {
            $needle = trim( $needle );
            foreach ( array_keys( $seen_widget_ids ) as $widget_id ) {
                $widget = $this->get_widget_record( $widget_id, $configured );
                if ( is_wp_error( $widget ) ) {
                    continue;
                }

                $haystack = $this->flatten_widget_text( $widget['instance'] );
                if ( '' === $haystack || false === stripos( $haystack, $needle ) ) {
                    continue;
                }

                $matching_widgets[] = [
                    'widget_id'      => $widget['widget_id'],
                    'base'           => $widget['base'],
                    'option_name'    => $widget['option_name'],
                    'sidebars'       => $widget['sidebars'],
                    'content_excerpt'=> wp_trim_words( $haystack, 40 ),
                ];
            }
        }

        return [
            'sidebars'         => $sidebars,
            'matching_widgets' => $matching_widgets,
            'sidebar_count'    => count( $sidebars ),
        ];
    }

    public function find_widgets_by_text( array $terms, array $sidebar_ids = [] ): array {
        $terms       = array_values( array_filter( array_map( 'trim', $terms ) ) );
        $configured  = get_option( 'sidebars_widgets', [] );
        $configured  = is_array( $configured ) ? $configured : [];
        $sidebar_ids = array_values( array_filter( array_map( 'sanitize_key', $sidebar_ids ) ) );
        $allowed     = ! empty( $sidebar_ids ) ? array_flip( $sidebar_ids ) : [];
        $widget_ids  = [];
        $matches     = [];

        foreach ( $configured as $sidebar_id => $widgets ) {
            if ( 'array_version' === $sidebar_id || ! is_array( $widgets ) ) {
                continue;
            }

            if ( ! empty( $allowed ) && ! isset( $allowed[ $sidebar_id ] ) ) {
                continue;
            }

            foreach ( $widgets as $widget_id ) {
                if ( is_string( $widget_id ) ) {
                    $widget_ids[ $widget_id ] = true;
                }
            }
        }

        foreach ( array_keys( $widget_ids ) as $widget_id ) {
            $record = $this->get_widget_record( $widget_id, $configured );
            if ( is_wp_error( $record ) ) {
                continue;
            }

            $haystack      = $this->flatten_widget_text( $record['instance'] );
            $matched_terms = [];
            if ( '' === $haystack ) {
                continue;
            }

            foreach ( $terms as $term ) {
                if ( '' !== $term && false !== stripos( $haystack, $term ) ) {
                    $matched_terms[] = $term;
                }
            }

            if ( empty( $matched_terms ) ) {
                continue;
            }

            $matches[] = [
                'widget_id'       => $record['widget_id'],
                'base'            => $record['base'],
                'option_name'     => $record['option_name'],
                'sidebars'        => $record['sidebars'],
                'matched_terms'   => array_values( array_unique( $matched_terms ) ),
                'match_count'     => count( array_unique( $matched_terms ) ),
                'content_excerpt' => wp_trim_words( $haystack, 40 ),
            ];
        }

        usort(
            $matches,
            static function ( array $a, array $b ) {
                return [ -1 * (int) $a['match_count'], $a['widget_id'] ] <=> [ -1 * (int) $b['match_count'], $b['widget_id'] ];
            }
        );

        return [
            'terms'   => $terms,
            'matches' => $matches,
            'total'   => count( $matches ),
        ];
    }

    public function list_widget_visibility_rules(): array {
        $rules = get_option( self::WIDGET_VISIBILITY_RULES_OPTION, [] );
        $rules = is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : [];

        return [
            'rules' => $rules,
            'total' => count( $rules ),
        ];
    }

    public function ensure_widget_visibility_rule( string $widget_id, string $sidebar_id, string $url ) {
        $widget_id  = trim( $widget_id );
        $sidebar_id = sanitize_key( $sidebar_id );
        $path       = $this->normalize_url_path( $url );

        if ( '' === $widget_id || '' === $sidebar_id || '' === $path ) {
            return new WP_Error( 'missing_widget_rule_fields', 'Widget ID, sidebar ID, and URL are required.' );
        }

        $configured = get_option( 'sidebars_widgets', [] );
        $configured = is_array( $configured ) ? $configured : [];
        $widget     = $this->get_widget_record( $widget_id, $configured );
        if ( is_wp_error( $widget ) ) {
            return $widget;
        }

        $rules = get_option( self::WIDGET_VISIBILITY_RULES_OPTION, [] );
        $rules = is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : [];

        foreach ( $rules as $rule ) {
            if ( ( $rule['widget_id'] ?? '' ) === $widget_id && ( $rule['sidebar_id'] ?? '' ) === $sidebar_id && ( $rule['path'] ?? '' ) === $path ) {
                return [
                    'rule_id'    => (string) ( $rule['id'] ?? '' ),
                    'widget_id'  => $widget_id,
                    'sidebar_id' => $sidebar_id,
                    'path'       => $path,
                    'created'    => false,
                ];
            }
        }

        $rule = [
            'id'         => uniqid( 'pv_rule_', true ),
            'widget_id'  => $widget_id,
            'sidebar_id' => $sidebar_id,
            'path'       => $path,
            'enabled'    => true,
            'created_at' => gmdate( 'c' ),
        ];

        array_unshift( $rules, $rule );
        $rules = array_slice( $rules, 0, 50 );
        update_option( self::WIDGET_VISIBILITY_RULES_OPTION, $rules, false );

        return [
            'rule_id'    => $rule['id'],
            'widget_id'  => $widget_id,
            'sidebar_id' => $sidebar_id,
            'path'       => $path,
            'created'    => true,
        ];
    }

    public function clone_widget_to_sidebar( string $widget_id, string $sidebar_id ) {
        $widget_id  = trim( $widget_id );
        $sidebar_id = sanitize_key( $sidebar_id );

        if ( '' === $widget_id || '' === $sidebar_id ) {
            return new WP_Error( 'missing_widget_target', 'A widget ID and sidebar ID are required.' );
        }

        $configured = get_option( 'sidebars_widgets', [] );
        $configured = is_array( $configured ) ? $configured : [];
        $widget     = $this->get_widget_record( $widget_id, $configured );
        if ( is_wp_error( $widget ) ) {
            return $widget;
        }

        $option_name = $widget['option_name'];
        $option      = get_option( $option_name, [] );
        if ( ! is_array( $option ) ) {
            return new WP_Error( 'invalid_widget_option', 'The widget option payload is not editable.' );
        }

        $this->backup_option_snapshot( $option_name, $option );
        $this->backup_option_snapshot( 'sidebars_widgets', $configured );

        $new_number          = $this->next_widget_instance_number( $option );
        $option[ $new_number ] = $widget['instance'];
        if ( array_key_exists( '_multiwidget', $option ) ) {
            $option['_multiwidget'] = 1;
        }

        update_option( $option_name, $option, false );

        if ( empty( $configured[ $sidebar_id ] ) || ! is_array( $configured[ $sidebar_id ] ) ) {
            $configured[ $sidebar_id ] = [];
        }

        $cloned_widget_id        = $widget['base'] . '-' . $new_number;
        $configured[ $sidebar_id ][] = $cloned_widget_id;
        update_option( 'sidebars_widgets', $configured, false );

        return [
            'source_widget_id' => $widget_id,
            'cloned_widget_id' => $cloned_widget_id,
            'sidebar_id'       => $sidebar_id,
            'option_name'      => $option_name,
        ];
    }

    public function replace_text_in_widget( string $widget_id, string $old_text, string $new_text, bool $all_occurrences = true ) {
        $widget_id = trim( $widget_id );
        if ( '' === $widget_id || '' === $old_text ) {
            return new WP_Error( 'missing_widget_text', 'A widget ID and old text are required.' );
        }

        $configured = get_option( 'sidebars_widgets', [] );
        $configured = is_array( $configured ) ? $configured : [];
        $widget     = $this->get_widget_record( $widget_id, $configured );
        if ( is_wp_error( $widget ) ) {
            return $widget;
        }

        $option_name = $widget['option_name'];
        $option      = get_option( $option_name, [] );
        if ( ! is_array( $option ) ) {
            return new WP_Error( 'invalid_widget_option', 'The widget option payload is not editable.' );
        }

        $replacements = 0;
        $updated      = $this->replace_text_in_value( $widget['instance'], $old_text, $new_text, $all_occurrences, $replacements );
        if ( $replacements < 1 ) {
            return new WP_Error( 'widget_text_not_found', 'The requested text was not found in that widget.' );
        }

        $this->backup_option_snapshot( $option_name, $option );

        $option[ $widget['number'] ] = $updated;
        if ( array_key_exists( '_multiwidget', $option ) ) {
            $option['_multiwidget'] = 1;
        }

        update_option( $option_name, $option, false );

        return [
            'widget_id'     => $widget_id,
            'option_name'   => $option_name,
            'replacements'  => $replacements,
            'sidebar_ids'   => $widget['sidebars'],
        ];
    }

    private function map_page_summary( WP_Post $page ): array {
        return $this->map_post_summary( $page );
    }

    private function map_post_summary( WP_Post $page ): array {
        return [
            'id'       => $page->ID,
            'title'    => $page->post_title,
            'slug'     => $page->post_name,
            'status'   => $page->post_status,
            'url'      => get_permalink( $page->ID ),
            'template' => get_post_meta( $page->ID, '_wp_page_template', true ) ?: 'default',
        ];
    }

    private function resolve_page( int $page_id = 0, string $slug = '' ): ?WP_Post {
        if ( $page_id > 0 ) {
            $page = get_post( $page_id );
            return ( $page instanceof WP_Post && 'page' === $page->post_type ) ? $page : null;
        }

        if ( '' !== $slug ) {
            $page = get_page_by_path( sanitize_title( $slug ), OBJECT, 'page' );
            return $page instanceof WP_Post ? $page : null;
        }

        return null;
    }

    private function resolve_same_origin_url( int $page_id = 0, string $url = '' ) {
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
        }

        if ( '' === $url ) {
            return new WP_Error( 'missing_url', 'A page ID or same-origin URL is required.' );
        }

        $url = esc_url_raw( $url );
        if ( ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'invalid_url', 'The URL is not valid.' );
        }

        $home_parts   = wp_parse_url( home_url() );
        $target_parts = wp_parse_url( $url );

        if ( empty( $home_parts['host'] ) || empty( $target_parts['host'] ) ) {
            return new WP_Error( 'invalid_url', 'Could not determine the URL host.' );
        }

        if ( strtolower( $home_parts['host'] ) !== strtolower( $target_parts['host'] ) ) {
            return new WP_Error( 'forbidden_url', 'Only same-origin URLs can be fetched.' );
        }

        $home_port   = $this->normalize_port( $home_parts );
        $target_port = $this->normalize_port( $target_parts );

        if ( $home_port !== $target_port ) {
            return new WP_Error( 'forbidden_url', 'Only same-origin URLs can be fetched.' );
        }

        return $url;
    }

    private function normalize_port( array $parts ): ?int {
        if ( isset( $parts['port'] ) ) {
            return (int) $parts['port'];
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        if ( 'https' === $scheme ) {
            return 443;
        }

        if ( 'http' === $scheme ) {
            return 80;
        }

        return null;
    }

    private function fetch_same_origin_html( string $target_url ) {
        $target_parts = wp_parse_url( $target_url );
        $host         = strtolower( $target_parts['host'] ?? '' );
        $is_local     = in_array( $host, [ 'localhost', '127.0.0.1' ], true );

        $response = wp_remote_get( $target_url, [
            'timeout'     => 20,
            'redirection' => 3,
            'headers'     => [
                'Accept'     => 'text/html,application/xhtml+xml',
                'User-Agent' => 'PressViber/1.0.0',
            ],
            'sslverify'   => ! $is_local,
        ] );

        if ( is_wp_error( $response ) ) {
            $fallback = $this->build_local_render_fallback( $target_url );
            if ( ! is_wp_error( $fallback ) ) {
                return $fallback;
            }

            return new WP_Error( 'render_fetch_failed', 'Could not fetch rendered page: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = (string) wp_remote_retrieve_body( $response );
        $title       = '';

        if ( preg_match( '/<title>(.*?)<\/title>/is', $body, $matches ) ) {
            $title = trim( wp_strip_all_tags( $matches[1] ) );
        }

        return [
            'status_code' => $status_code,
            'title'       => $title,
            'body'        => $body,
            'text'        => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ),
        ];
    }

    private function build_local_render_fallback( string $target_url ) {
        $context = $this->build_local_url_context( $target_url );
        if ( is_wp_error( $context ) ) {
            return $context;
        }

        $segments = [];

        if ( ! empty( $context['title'] ) ) {
            $segments[] = $context['title'];
        }

        if ( ! empty( $context['content_excerpt'] ) ) {
            $segments[] = $context['content_excerpt'];
        }

        if ( ! empty( $context['sample_titles'] ) && is_array( $context['sample_titles'] ) ) {
            $segments[] = 'Items: ' . implode( ' | ', array_slice( $context['sample_titles'], 0, 8 ) );
        }

        if ( ! empty( $context['widget_snippets'] ) && is_array( $context['widget_snippets'] ) ) {
            $segments[] = implode( "\n", array_slice( $context['widget_snippets'], 0, 8 ) );
        }

        if ( ! empty( $context['template_candidates'] ) && is_array( $context['template_candidates'] ) ) {
            $segments[] = 'Template candidates: ' . implode( ', ', array_slice( $context['template_candidates'], 0, 8 ) );
        }

        $text = trim( implode( "\n\n", array_filter( $segments ) ) );
        if ( '' === $text ) {
            return new WP_Error( 'render_fetch_failed', 'Could not build a local render fallback for this URL.' );
        }

        $title = $context['title'] ?? '';
        // Wrap each segment in <p> tags so extract_page_text_snippets can
        // extract them via the heading / paragraph regex patterns.
        $p_segments = '';
        foreach ( array_filter( $segments ) as $seg ) {
            $p_segments .= '<p>' . esc_html( $seg ) . "</p>\n";
        }
        $body = '<html><head><title>' . esc_html( $title ) . '</title></head><body class="pv-local-render-fallback">'
            . '<h1>' . esc_html( $title ) . '</h1>'
            . $p_segments
            . '</body></html>';

        return [
            'status_code' => 200,
            'title'       => $title,
            'body'        => $body,
            'text'        => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) ),
            'fallback'    => true,
        ];
    }

    private function build_local_url_context( string $target_url ) {
        $target_parts = wp_parse_url( $target_url );
        if ( empty( $target_parts['host'] ) ) {
            return new WP_Error( 'invalid_url', 'Could not determine the URL host.' );
        }

        $query_args = [];
        if ( ! empty( $target_parts['query'] ) ) {
            parse_str( (string) $target_parts['query'], $query_args );
        }

        $request_uri = (string) ( $target_parts['path'] ?? '/' );
        if ( '' !== (string) ( $target_parts['query'] ?? '' ) ) {
            $request_uri .= '?' . (string) $target_parts['query'];
        }

        $original_get          = $_GET;
        $original_request      = $_REQUEST;
        $original_server_state = [
            'REQUEST_URI'  => $_SERVER['REQUEST_URI'] ?? null,
            'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null,
            'HTTP_HOST'    => $_SERVER['HTTP_HOST'] ?? null,
            'SERVER_PORT'  => $_SERVER['SERVER_PORT'] ?? null,
            'HTTPS'        => $_SERVER['HTTPS'] ?? null,
        ];
        $original_globals      = [
            'wp'           => $GLOBALS['wp'] ?? null,
            'wp_query'     => $GLOBALS['wp_query'] ?? null,
            'wp_the_query' => $GLOBALS['wp_the_query'] ?? null,
            'post'         => $GLOBALS['post'] ?? null,
        ];

        try {
            $_GET     = $query_args;
            $_REQUEST = $query_args;

            $_SERVER['REQUEST_URI']  = $request_uri;
            $_SERVER['QUERY_STRING'] = (string) ( $target_parts['query'] ?? '' );
            $_SERVER['HTTP_HOST']    = (string) $target_parts['host'];
            $_SERVER['SERVER_PORT']  = (string) ( $this->normalize_port( $target_parts ) ?? 80 );

            if ( 'https' === strtolower( (string) ( $target_parts['scheme'] ?? '' ) ) ) {
                $_SERVER['HTTPS'] = 'on';
            } else {
                unset( $_SERVER['HTTPS'] );
            }

            $wp = new WP();
            $wp->parse_request();

            $query = new WP_Query();
            $query->query( $wp->query_vars );

            $GLOBALS['wp']           = $wp;
            $GLOBALS['wp_query']     = $query;
            $GLOBALS['wp_the_query'] = $query;
            $GLOBALS['post']         = $query->post instanceof WP_Post ? $query->post : null;

            $queried_object      = get_queried_object();
            $resolved_kind       = $this->detect_query_kind( $target_url, $query, $queried_object );
            $template_candidates = $this->build_template_candidates( $query, $queried_object, $resolved_kind );
            $existing_templates  = $this->resolve_existing_theme_templates( $template_candidates );
            $plugin_templates    = $this->resolve_plugin_templates( $template_candidates );
            $title               = $this->resolve_context_title( $query, $queried_object, $target_url );
            $content_excerpt     = '';

            if ( $queried_object instanceof WP_Post ) {
                $content_excerpt = wp_trim_words( wp_strip_all_tags( (string) $queried_object->post_content ), 120 );
            } elseif ( is_home() ) {
                $posts_page_id = (int) get_option( 'page_for_posts', 0 );
                if ( $posts_page_id > 0 ) {
                    $posts_page = get_post( $posts_page_id );
                    if ( $posts_page instanceof WP_Post ) {
                        $content_excerpt = wp_trim_words( wp_strip_all_tags( (string) $posts_page->post_content ), 120 );
                    }
                }
            }

            $sample_titles = [];
            foreach ( array_slice( (array) $query->posts, 0, 8 ) as $post_obj ) {
                if ( $post_obj instanceof WP_Post && '' !== trim( $post_obj->post_title ) ) {
                    $sample_titles[] = $post_obj->post_title;
                }
            }

            $result = [
                'url'                      => $target_url,
                'title'                    => $title,
                'kind'                     => $resolved_kind,
                'matched_rule'             => (string) ( $wp->matched_rule ?? '' ),
                'matched_query'            => (string) ( $wp->matched_query ?? '' ),
                'query_vars'               => $this->filter_context_query_vars( (array) $wp->query_vars ),
                'template_candidates'      => $template_candidates,
                'existing_theme_templates' => $existing_templates,
                'plugin_templates'         => $plugin_templates,
                'content_excerpt'          => $content_excerpt,
                'sample_titles'            => $sample_titles,
            ];

            if ( $queried_object instanceof WP_Post ) {
                $result['post'] = $this->map_post_summary( $queried_object );
                $result['post']['post_type'] = $queried_object->post_type;
            } elseif ( $queried_object instanceof WP_Term ) {
                $result['term'] = [
                    'id'       => $queried_object->term_id,
                    'name'     => $queried_object->name,
                    'slug'     => $queried_object->slug,
                    'taxonomy' => $queried_object->taxonomy,
                ];
            }

            $post_type = '';
            if ( $query->is_post_type_archive() ) {
                $post_type = (string) $query->get( 'post_type' );
            } elseif ( $queried_object instanceof WP_Post ) {
                $post_type = (string) $queried_object->post_type;
            }

            if ( '' !== $post_type ) {
                $result['post_type'] = $post_type;
            }

            if ( is_front_page() ) {
                $result['widget_snippets'] = $this->collect_widget_block_text_snippets();
            }

            return $result;
        } finally {
            $_GET     = $original_get;
            $_REQUEST = $original_request;

            foreach ( $original_server_state as $key => $value ) {
                if ( null === $value ) {
                    unset( $_SERVER[ $key ] );
                } else {
                    $_SERVER[ $key ] = $value;
                }
            }

            foreach ( $original_globals as $key => $value ) {
                if ( null === $value ) {
                    unset( $GLOBALS[ $key ] );
                } else {
                    $GLOBALS[ $key ] = $value;
                }
            }
        }
    }

    private function detect_query_kind( string $target_url, WP_Query $query, $queried_object ): string {
        $normalized_target = trailingslashit( strtok( $target_url, '?' ) ?: $target_url );
        $normalized_home   = trailingslashit( home_url( '/' ) );

        if ( $normalized_target === $normalized_home && is_front_page() ) {
            return 'front_page';
        }

        if ( is_home() ) {
            return (int) get_option( 'page_for_posts', 0 ) > 0 ? 'posts_page' : 'home';
        }

        if ( is_page() && $queried_object instanceof WP_Post ) {
            return 'page';
        }

        if ( is_single() && $queried_object instanceof WP_Post ) {
            return 'single_' . $queried_object->post_type;
        }

        if ( is_post_type_archive() ) {
            $post_type = (string) $query->get( 'post_type' );
            return 'archive_' . ( $post_type ?: 'post' );
        }

        if ( is_category() ) {
            return 'category';
        }

        if ( is_tag() ) {
            return 'tag';
        }

        if ( is_tax() && $queried_object instanceof WP_Term ) {
            return 'taxonomy_' . $queried_object->taxonomy;
        }

        if ( is_search() ) {
            return 'search';
        }

        if ( is_404() ) {
            return '404';
        }

        if ( is_archive() ) {
            return 'archive';
        }

        return 'live_url';
    }

    private function build_template_candidates( WP_Query $query, $queried_object, string $resolved_kind ): array {
        $candidates = [];

        if ( 'front_page' === $resolved_kind ) {
            $candidates[] = 'front-page.php';
        }

        if ( 'posts_page' === $resolved_kind || 'home' === $resolved_kind ) {
            $candidates[] = 'home.php';
        }

        if ( $queried_object instanceof WP_Post && 'page' === $queried_object->post_type ) {
            $candidates[] = 'page-' . $queried_object->post_name . '.php';
            $candidates[] = 'page-' . $queried_object->ID . '.php';
            $candidates[] = 'page.php';
        } elseif ( $queried_object instanceof WP_Post ) {
            $candidates[] = 'single-' . $queried_object->post_type . '.php';
            $candidates[] = 'single.php';
        }

        if ( $query->is_post_type_archive() ) {
            $post_type = (string) $query->get( 'post_type' );
            if ( '' !== $post_type ) {
                $candidates[] = 'archive-' . $post_type . '.php';
            }
            $candidates[] = 'archive.php';
        }

        if ( is_category() && $queried_object instanceof WP_Term ) {
            $candidates[] = 'category-' . $queried_object->slug . '.php';
            $candidates[] = 'category-' . $queried_object->term_id . '.php';
            $candidates[] = 'category.php';
            $candidates[] = 'archive.php';
        }

        if ( is_tag() && $queried_object instanceof WP_Term ) {
            $candidates[] = 'tag-' . $queried_object->slug . '.php';
            $candidates[] = 'tag-' . $queried_object->term_id . '.php';
            $candidates[] = 'tag.php';
            $candidates[] = 'archive.php';
        }

        if ( is_tax() && $queried_object instanceof WP_Term ) {
            $candidates[] = 'taxonomy-' . $queried_object->taxonomy . '-' . $queried_object->slug . '.php';
            $candidates[] = 'taxonomy-' . $queried_object->taxonomy . '.php';
            $candidates[] = 'taxonomy.php';
            $candidates[] = 'archive.php';
        }

        if ( is_search() ) {
            $candidates[] = 'search.php';
        }

        if ( is_404() ) {
            $candidates[] = '404.php';
        }

        if ( empty( $candidates ) && is_archive() ) {
            $candidates[] = 'archive.php';
        }

        $candidates[] = 'index.php';

        return array_values( array_unique( array_filter( $candidates ) ) );
    }

    private function resolve_existing_theme_templates( array $candidates ): array {
        $matches         = [];
        $stylesheet_dir  = trailingslashit( get_stylesheet_directory() );
        $template_dir    = trailingslashit( get_template_directory() );

        foreach ( $candidates as $candidate ) {
            $stylesheet_path = $stylesheet_dir . $candidate;
            if ( file_exists( $stylesheet_path ) ) {
                $matches[] = ltrim( str_replace( ABSPATH, '', $stylesheet_path ), '/' );
                continue;
            }

            $template_path = $template_dir . $candidate;
            if ( $template_dir !== $stylesheet_dir && file_exists( $template_path ) ) {
                $matches[] = ltrim( str_replace( ABSPATH, '', $template_path ), '/' );
            }
        }

        return array_values( array_unique( $matches ) );
    }

    /**
     * Search active plugin directories for template files matching the given candidates.
     * Plugins often inject templates via the `template_include` filter; this catches those
     * by scanning common sub-directories (templates/, template/, inc/, parts/).
     *
     * @param string[] $candidates Template filenames to look for.
     * @return string[]            Relative paths from ABSPATH, sorted by plugin name.
     */
    private function resolve_plugin_templates( array $candidates ): array {
        if ( empty( $candidates ) ) {
            return [];
        }

        $plugins_dir = trailingslashit( WP_PLUGIN_DIR );
        $subdirs     = [ 'templates', 'template', 'inc', 'parts', '' ];
        $matches     = [];

        // Only scan active plugins to keep it fast
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $active_dirs    = array_unique( array_map(
            static function ( string $plugin_file ) use ( $plugins_dir ): string {
                return $plugins_dir . dirname( $plugin_file );
            },
            $active_plugins
        ) );

        foreach ( $active_dirs as $plugin_dir ) {
            if ( ! is_dir( $plugin_dir ) ) {
                continue;
            }

            foreach ( $subdirs as $sub ) {
                $search_dir = $sub !== '' ? trailingslashit( $plugin_dir ) . trailingslashit( $sub ) : trailingslashit( $plugin_dir );

                foreach ( $candidates as $candidate ) {
                    $full_path = $search_dir . $candidate;
                    if ( file_exists( $full_path ) ) {
                        $matches[] = ltrim( str_replace( ABSPATH, '', $full_path ), '/' );
                    }
                }
            }
        }

        return array_values( array_unique( $matches ) );
    }

    private function resolve_context_title( WP_Query $query, $queried_object, string $target_url ): string {
        if ( $queried_object instanceof WP_Post && '' !== trim( $queried_object->post_title ) ) {
            return $queried_object->post_title;
        }

        if ( $queried_object instanceof WP_Term && '' !== trim( $queried_object->name ) ) {
            return $queried_object->name;
        }

        if ( is_post_type_archive() ) {
            $post_type = (string) $query->get( 'post_type' );
            if ( '' !== $post_type ) {
                $obj = get_post_type_object( $post_type );
                if ( $obj && ! empty( $obj->labels->name ) ) {
                    return (string) $obj->labels->name;
                }
            }
        }

        if ( is_home() ) {
            $posts_page_id = (int) get_option( 'page_for_posts', 0 );
            if ( $posts_page_id > 0 ) {
                return get_the_title( $posts_page_id ) ?: 'Blog';
            }

            return 'Blog';
        }

        return $target_url;
    }

    private function filter_context_query_vars( array $query_vars ): array {
        $result = [];

        foreach ( $query_vars as $key => $value ) {
            if ( is_scalar( $value ) && '' !== (string) $value ) {
                $result[ $key ] = (string) $value;
            }

            if ( count( $result ) >= 12 ) {
                break;
            }
        }

        return $result;
    }

    private function collect_widget_block_text_snippets( int $limit = 12 ): array {
        $widgets = get_option( 'widget_block' );
        if ( empty( $widgets ) || ! is_array( $widgets ) ) {
            return [];
        }

        $snippets = [];
        foreach ( $widgets as $widget_id => $widget ) {
            if ( ! is_numeric( $widget_id ) || empty( $widget['content'] ) ) {
                continue;
            }

            $text = trim( wp_strip_all_tags( (string) $widget['content'] ) );
            $text = preg_replace( '/\s+/', ' ', $text );

            if ( '' === $text ) {
                continue;
            }

            $snippets[] = wp_trim_words( $text, 40 );
            if ( count( $snippets ) >= $limit ) {
                break;
            }
        }

        return $snippets;
    }

    public function apply_widget_visibility_rules( $sidebars ) {
        if ( is_admin() || wp_doing_ajax() || ! is_array( $sidebars ) ) {
            return $sidebars;
        }

        $rules = get_option( self::WIDGET_VISIBILITY_RULES_OPTION, [] );
        $rules = is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : [];
        if ( empty( $rules ) ) {
            return $sidebars;
        }

        $current_path = $this->normalize_url_path( home_url( strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' ) ?: '/' ) );
        if ( '' === $current_path ) {
            return $sidebars;
        }

        foreach ( $rules as $rule ) {
            if ( empty( $rule['enabled'] ) ) {
                continue;
            }

            $rule_path  = isset( $rule['path'] ) ? (string) $rule['path'] : '';
            $sidebar_id = isset( $rule['sidebar_id'] ) ? sanitize_key( (string) $rule['sidebar_id'] ) : '';
            $widget_id  = isset( $rule['widget_id'] ) ? (string) $rule['widget_id'] : '';

            if ( '' === $rule_path || '' === $sidebar_id || '' === $widget_id || $rule_path !== $current_path ) {
                continue;
            }

            if ( empty( $sidebars[ $sidebar_id ] ) || ! is_array( $sidebars[ $sidebar_id ] ) ) {
                $sidebars[ $sidebar_id ] = [];
            }

            if ( ! in_array( $widget_id, $sidebars[ $sidebar_id ], true ) ) {
                $sidebars[ $sidebar_id ][] = $widget_id;
            }
        }

        return $sidebars;
    }

    private function get_widget_record( string $widget_id, array $sidebars_widgets ) {
        if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
            return new WP_Error( 'invalid_widget_id', 'The widget ID format is not supported.' );
        }

        $base       = sanitize_key( (string) $matches[1] );
        $number     = (int) $matches[2];
        $option_name = 'widget_' . $base;
        $option     = get_option( $option_name, [] );

        if ( ! is_array( $option ) || ! array_key_exists( $number, $option ) ) {
            return new WP_Error( 'widget_not_found', 'The requested widget instance could not be found.' );
        }

        return [
            'widget_id'   => $widget_id,
            'base'        => $base,
            'number'      => $number,
            'option_name' => $option_name,
            'instance'    => $option[ $number ],
            'sidebars'    => $this->find_widget_sidebars( $widget_id, $sidebars_widgets ),
        ];
    }

    private function find_widget_sidebars( string $widget_id, array $sidebars_widgets ): array {
        $locations = [];

        foreach ( $sidebars_widgets as $sidebar_id => $widgets ) {
            if ( 'array_version' === $sidebar_id || ! is_array( $widgets ) ) {
                continue;
            }

            if ( in_array( $widget_id, $widgets, true ) ) {
                $locations[] = $sidebar_id;
            }
        }

        return $locations;
    }

    private function flatten_widget_text( $value ): string {
        if ( is_string( $value ) ) {
            return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
        }

        if ( ! is_array( $value ) ) {
            return '';
        }

        $parts = [];
        foreach ( $value as $child ) {
            $text = $this->flatten_widget_text( $child );
            if ( '' !== $text ) {
                $parts[] = $text;
            }
        }

        return trim( implode( ' ', $parts ) );
    }

    private function next_widget_instance_number( array $option ): int {
        $max = 1;

        foreach ( array_keys( $option ) as $key ) {
            if ( is_int( $key ) || ctype_digit( (string) $key ) ) {
                $max = max( $max, (int) $key );
            }
        }

        return $max + 1;
    }

    private function replace_text_in_value( $value, string $old_text, string $new_text, bool $all_occurrences, int &$replacements ) {
        if ( is_string( $value ) ) {
            if ( false === strpos( $value, $old_text ) ) {
                return $value;
            }

            if ( $all_occurrences ) {
                $count = 0;
                $value = str_replace( $old_text, $new_text, $value, $count );
                $replacements += $count;
                return $value;
            }

            $value = preg_replace( '/' . preg_quote( $old_text, '/' ) . '/', $new_text, $value, 1, $count );
            $replacements += (int) $count;
            return is_string( $value ) ? $value : '';
        }

        if ( ! is_array( $value ) ) {
            return $value;
        }

        foreach ( $value as $key => $child ) {
            $value[ $key ] = $this->replace_text_in_value( $child, $old_text, $new_text, $all_occurrences, $replacements );
        }

        return $value;
    }

    private function backup_option_snapshot( string $option_name, $value ): void {
        $backups = get_option( 'pv_widget_option_backups', [] );
        $backups = is_array( $backups ) ? $backups : [];

        array_unshift(
            $backups,
            [
                'captured_at' => gmdate( 'c' ),
                'option_name' => $option_name,
                'value'       => $value,
            ]
        );

        $backups = array_slice( $backups, 0, 10 );
        update_option( 'pv_widget_option_backups', $backups, false );
    }

    private function normalize_url_path( string $url ): string {
        $parts = wp_parse_url( $url );
        $path  = (string) ( $parts['path'] ?? '/' );

        if ( '' === $path ) {
            $path = '/';
        }

        return trailingslashit( $path );
    }

    private function extract_dom_markers( string $html ): array {
        $result = [
            'headings'       => [],
            'section_ids'    => [],
            'marker_classes' => [],
            'body_classes'   => [],
        ];

        if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
            return $result;
        }

        $internal_errors = libxml_use_internal_errors( true );
        $dom             = new DOMDocument();
        $loaded          = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $internal_errors );

        if ( ! $loaded ) {
            return $result;
        }

        $xpath = new DOMXPath( $dom );

        foreach ( $xpath->query( '//body' ) as $body ) {
            if ( ! $body instanceof DOMElement ) {
                continue;
            }

            $result['body_classes'] = array_values( array_filter( preg_split( '/\s+/', trim( (string) $body->getAttribute( 'class' ) ) ) ) );
            break;
        }

        foreach ( $xpath->query( '//h1|//h2|//h3' ) as $heading ) {
            if ( ! $heading instanceof DOMElement ) {
                continue;
            }

            $text = trim( preg_replace( '/\s+/', ' ', (string) $heading->textContent ) );
            if ( '' === $text ) {
                continue;
            }

            $result['headings'][] = [
                'tag'     => strtolower( $heading->tagName ),
                'text'    => $text,
                'id'      => $heading->getAttribute( 'id' ) ?: '',
                'classes' => array_values( array_filter( preg_split( '/\s+/', trim( (string) $heading->getAttribute( 'class' ) ) ) ) ),
            ];

            if ( count( $result['headings'] ) >= 20 ) {
                break;
            }
        }

        $seen_ids     = [];
        $seen_classes = [];

        foreach ( $xpath->query( '//*[@id or @class]' ) as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }

            $id = trim( (string) $node->getAttribute( 'id' ) );
            if ( '' !== $id && $this->is_useful_marker_id( $id ) && ! isset( $seen_ids[ $id ] ) ) {
                $seen_ids[ $id ]         = true;
                $result['section_ids'][] = $id;
            }

            $classes = array_values( array_filter( preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ) ) );
            foreach ( $classes as $class_name ) {
                if ( ! $this->is_useful_marker_class( $class_name ) || isset( $seen_classes[ $class_name ] ) ) {
                    continue;
                }

                $seen_classes[ $class_name ]     = true;
                $result['marker_classes'][] = $class_name;
            }

            if ( count( $result['section_ids'] ) >= 30 && count( $result['marker_classes'] ) >= 30 ) {
                break;
            }
        }

        return $result;
    }

    private function extract_visible_text_targets( string $html ): array {
        $regex_targets = $this->extract_visible_text_targets_from_html_regex( $html );
        if ( '' === trim( $html ) ) {
            return [];
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return $regex_targets;
        }

        $internal_errors = libxml_use_internal_errors( true );
        $dom             = new DOMDocument();
        $loaded          = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $internal_errors );

        if ( ! $loaded ) {
            return $regex_targets;
        }

        $xpath   = new DOMXPath( $dom );
        $targets = [];
        $seen    = [];

        // Exclude WP admin bar and navigation elements so nav links don't fill the 60-item
        // limit before we reach actual page content. For <a> and bare <span>/<div> we only
        // include elements that carry a class attribute (CTAs/eyebrows always do); plain
        // heading/paragraph/button elements are always included from non-nav context.
        $nodes = $xpath->query(
            '(//*['
            . 'self::h1 or self::h2 or self::h3 or self::p or self::button'
            . ' or (self::a and @class)'
            . ' or (self::span and @class)'
            . ' or (self::div and @class)'
            . '][not(ancestor-or-self::*[@id="wpadminbar"])]'
            . '[not(ancestor-or-self::nav)]'
            . '[not(ancestor-or-self::*[contains(@class,"navbar") or contains(@class,"site-nav") or contains(@class,"main-nav") or contains(@class,"primary-nav")])]'
            . ')'
        );

        if ( ! $nodes ) {
            return $regex_targets;
        }

        foreach ( $nodes as $node ) {
            if ( ! $node instanceof DOMElement ) {
                continue;
            }

            $text = $this->normalize_visible_text( $node->textContent );
            if ( strlen( $text ) < 2 || strlen( $text ) > 300 ) {
                continue;
            }

            $classes = array_values( array_filter( preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ) ) );
            $role    = $this->classify_visible_text_role( $node, $classes );
            if ( 'ignore' === $role ) {
                continue;
            }

            $key = $role . '|' . $text;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $targets[] = [
                'tag'            => strtolower( $node->tagName ),
                'role'           => $role,
                'text'           => $text,
                'classes'        => $classes,
                'id'             => (string) $node->getAttribute( 'id' ),
                'nearby_heading' => $this->find_nearby_heading_text( $node ),
            ];

            if ( count( $targets ) >= 60 ) {
                break;
            }
        }

        return $this->merge_visible_text_targets( $targets, $regex_targets );
    }

    private function extract_visible_text_targets_from_html_regex( string $html ): array {
        if ( '' === trim( $html ) ) {
            return [];
        }

        $targets = [];
        $patterns = [
            'eyebrow' => '/<(?:div|span|p)[^>]*class="([^"]*(?:eyebrow|kicker|badge|label)[^"]*)"[^>]*>(.*?)<\/(?:div|span|p)>/is',
            'title' => '/<h1[^>]*class="([^"]*)"[^>]*>(.*?)<\/h1>/is',
            'subtitle' => '/<(?:p|div|span)[^>]*class="([^"]*(?:subtitle|sub-title|subheading)[^"]*)"[^>]*>(.*?)<\/(?:p|div|span)>/is',
            'description' => '/<(?:p|div|span)[^>]*class="([^"]*(?:description|tagline|summary|lead|excerpt|dek)[^"]*)"[^>]*>(.*?)<\/(?:p|div|span)>/is',
            'meta' => '/<(?:div|span|p)[^>]*class="([^"]*(?:meta|count|caption|stats)[^"]*)"[^>]*>(.*?)<\/(?:div|span|p)>/is',
            'button' => '/<(?:a|button)[^>]*class="([^"]*)"[^>]*>(.*?)<\/(?:a|button)>/is',
        ];

        foreach ( $patterns as $role => $pattern ) {
            if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
                continue;
            }

            foreach ( $matches as $match ) {
                $text = $this->normalize_visible_text( (string) ( $match[2] ?? '' ) );
                if ( strlen( $text ) < 2 || strlen( $text ) > 260 ) {
                    continue;
                }

                $targets[] = [
                    'tag'            => $this->detect_html_fragment_tag( (string) $match[0] ),
                    'role'           => $role,
                    'text'           => $text,
                    'classes'        => array_values( array_filter( preg_split( '/\s+/', trim( (string) ( $match[1] ?? '' ) ) ) ) ),
                    'id'             => '',
                    'nearby_heading' => '',
                ];
            }
        }

        if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>.*?<p[^>]*>(.*?)<\/p>/is', $html, $hero_match ) ) {
            $heading_text = $this->normalize_visible_text( (string) ( $hero_match[1] ?? '' ) );
            $body_text    = $this->normalize_visible_text( (string) ( $hero_match[2] ?? '' ) );

            if ( '' !== $heading_text ) {
                $targets[] = [
                    'tag'            => 'h1',
                    'role'           => 'title',
                    'text'           => $heading_text,
                    'classes'        => [],
                    'id'             => '',
                    'nearby_heading' => '',
                ];
            }

            if ( strlen( $body_text ) >= 8 && strlen( $body_text ) <= 260 ) {
                $targets[] = [
                    'tag'            => 'p',
                    'role'           => 'subtitle',
                    'text'           => $body_text,
                    'classes'        => [],
                    'id'             => '',
                    'nearby_heading' => $heading_text,
                ];
            }
        }

        return $this->merge_visible_text_targets( $targets );
    }

    private function detect_html_fragment_tag( string $html ): string {
        if ( preg_match( '/<([a-z0-9]+)/i', $html, $matches ) ) {
            return strtolower( (string) $matches[1] );
        }

        return 'div';
    }

    private function merge_visible_text_targets( array ...$groups ): array {
        $merged = [];
        $seen   = [];

        foreach ( $groups as $group ) {
            foreach ( $group as $target ) {
                $role = (string) ( $target['role'] ?? '' );
                $text = (string) ( $target['text'] ?? '' );
                if ( '' === $role || '' === $text ) {
                    continue;
                }

                $key = $role . '|' . $text;
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }

                $seen[ $key ] = true;
                $merged[]     = $target;
            }
        }

        usort(
            $merged,
            function ( array $a, array $b ) {
                return $this->visible_text_role_priority( $b['role'] ?? '' ) <=> $this->visible_text_role_priority( $a['role'] ?? '' );
            }
        );

        return array_slice( $merged, 0, 40 );
    }

    private function classify_visible_text_role( DOMElement $node, array $classes ): string {
        $tag        = strtolower( $node->tagName );
        $class_blob = strtolower( implode( ' ', $classes ) );

        if ( 'h1' === $tag ) {
            return 'title';
        }

        if ( false !== strpos( $class_blob, 'eyebrow' ) || false !== strpos( $class_blob, 'kicker' ) || false !== strpos( $class_blob, 'badge' ) || false !== strpos( $class_blob, 'label' ) ) {
            return 'eyebrow';
        }

        if ( false !== strpos( $class_blob, 'subtitle' ) || false !== strpos( $class_blob, 'sub-title' ) || false !== strpos( $class_blob, 'subheading' ) ) {
            return 'subtitle';
        }

        if ( false !== strpos( $class_blob, 'tagline' ) || false !== strpos( $class_blob, 'description' ) || false !== strpos( $class_blob, 'summary' ) || false !== strpos( $class_blob, 'excerpt' ) || false !== strpos( $class_blob, 'lead' ) || false !== strpos( $class_blob, 'dek' ) ) {
            return 'description';
        }

        if ( false !== strpos( $class_blob, 'meta' ) || false !== strpos( $class_blob, 'count' ) || false !== strpos( $class_blob, 'caption' ) ) {
            return 'meta';
        }

        if ( in_array( $tag, [ 'button', 'a' ], true ) ) {
            return 'button';
        }

        if ( 'h2' === $tag || 'h3' === $tag ) {
            return 'section_title';
        }

        if ( 'p' === $tag ) {
            $previous = $this->get_previous_element_sibling( $node );
            if ( $previous instanceof DOMElement && in_array( strtolower( $previous->tagName ), [ 'h1', 'h2', 'h3' ], true ) ) {
                return 'subtitle';
            }

            return 'description';
        }

        if ( 'span' === $tag && false !== strpos( $class_blob, 'chip' ) ) {
            return 'badge';
        }

        if ( 'div' === $tag && ( false !== strpos( $class_blob, 'meta' ) || false !== strpos( $class_blob, 'stats' ) ) ) {
            return 'meta';
        }

        return 'ignore';
    }

    private function get_previous_element_sibling( DOMElement $node ) {
        $previous = $node->previousSibling;
        while ( $previous ) {
            if ( $previous instanceof DOMElement ) {
                return $previous;
            }
            $previous = $previous->previousSibling;
        }

        return null;
    }

    private function find_nearby_heading_text( DOMElement $node ): string {
        $current = $node;
        while ( $current instanceof DOMElement ) {
            foreach ( [ './/h1[1]', './/h2[1]', './/h3[1]' ] as $query ) {
                $headings = ( new DOMXPath( $node->ownerDocument ) )->query( $query, $current );
                if ( ! $headings || 0 === $headings->length ) {
                    continue;
                }

                $heading = $headings->item( 0 );
                if ( $heading instanceof DOMElement && $heading !== $node ) {
                    $text = $this->normalize_visible_text( $heading->textContent );
                    if ( '' !== $text ) {
                        return $text;
                    }
                }
            }

            $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null;
        }

        $previous = $this->get_previous_element_sibling( $node );
        while ( $previous instanceof DOMElement ) {
            if ( in_array( strtolower( $previous->tagName ), [ 'h1', 'h2', 'h3' ], true ) ) {
                return $this->normalize_visible_text( $previous->textContent );
            }
            $previous = $this->get_previous_element_sibling( $previous );
        }

        return '';
    }

    private function normalize_visible_text( string $text ): string {
        return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
    }

    private function visible_text_role_priority( string $role ): int {
        switch ( $role ) {
            case 'title':
                return 100;
            case 'subtitle':
                return 90;
            case 'description':
                return 80;
            case 'eyebrow':
                return 70;
            case 'button':
                return 60;
            case 'meta':
                return 50;
            case 'section_title':
                return 40;
            case 'badge':
                return 30;
            default:
                return 0;
        }
    }

    private function is_useful_marker_id( string $id ): bool {
        return 1 === preg_match( '/(?:a2a|home|hero|product|ranking|explore|directory|section|feature|card|grid)/i', $id );
    }

    private function is_useful_marker_class( string $class_name ): bool {
        if ( '' === $class_name ) {
            return false;
        }

        return 1 === preg_match( '/^(?:a2a-|home|hero|product|ranking|explore|directory|section|feature|card|grid|page-id-|page-template-|wp-block-cover)/i', $class_name );
    }
}
