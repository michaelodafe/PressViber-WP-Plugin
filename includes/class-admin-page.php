<?php
defined( 'ABSPATH' ) || exit;

class PV_Admin_Page {

    const PAGE_SLUG = 'pressviber';
    const EDITOR_MODE = 'editor';
    const STANDALONE_PARAM = 'pv_editor';

    public function init(): void {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_pv_get_pages', [ $this, 'ajax_get_pages' ] );
        add_action( 'admin_post_pv_save_api_keys', [ $this, 'handle_save_api_keys' ] );
        add_action( 'admin_bar_menu', [ $this, 'register_admin_bar_node' ], 90 );
        add_filter( 'admin_body_class', [ $this, 'filter_admin_body_class' ] );
        add_filter( 'show_admin_bar', [ $this, 'filter_show_admin_bar' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render_standalone_editor' ], 0 );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'PressViber', 'pressviber' ),
            __( 'PressViber', 'pressviber' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'dashicons-superhero',
            3
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) return;

        wp_enqueue_style(  'pv-admin', PV_PLUGIN_URL . 'assets/css/admin.css', [], PV_VERSION );
        wp_enqueue_script( 'pv-admin', PV_PLUGIN_URL . 'assets/js/admin.js',  [], PV_VERSION, true );
        wp_localize_script( 'pv-admin', 'pvData', $this->get_script_data() );
    }

    public function register_admin_bar_node( WP_Admin_Bar $admin_bar ): void {
        if ( is_admin() || wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $context = $this->get_frontend_context();
        if ( '' === $context['url'] ) {
            return;
        }

        $admin_bar->add_node( [
            'id'    => 'pv-edit-with-ai',
            'title' => __( 'Edit with AI', 'pressviber' ),
            'href'  => $this->build_standalone_editor_url( $context['url'], $context['title'], $context['page_id'], $context['kind'] ),
            'meta'  => [
                'class' => 'pv-admin-bar-node',
                'title' => __( 'Open PressViber for this live page', 'pressviber' ),
            ],
        ] );
    }

    public function filter_admin_body_class( string $classes ): string {
        if ( $this->is_editor_mode() ) {
            $classes .= ' pv-editor-mode';
        }

        return $classes;
    }

    public function filter_show_admin_bar( bool $show ): bool {
        if ( ! $show ) {
            return false;
        }

        if ( $this->is_preview_request() && current_user_can( 'manage_options' ) ) {
            return false;
        }

        return $show;
    }

    /** Returns published pages with id, title, and permalink. */
    public function ajax_get_pages(): void {
        check_ajax_referer( 'pv_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $pages = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'post_title', 'sort_order' => 'ASC' ] );

        wp_send_json_success( array_map( function ( $p ) {
            return [
                'id'    => $p->ID,
                'title' => $p->post_title,
                'url'   => get_permalink( $p->ID ),
            ];
        }, $pages ) );
    }

    public function handle_save_api_keys(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'pressviber' ) );
        }

        check_admin_referer( 'pv_save_api_keys' );

        $redirect = menu_page_url( self::PAGE_SLUG, false );
        if ( ! is_string( $redirect ) || '' === $redirect ) {
            $redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        }

        $messages = [];

        if ( ! defined( 'PV_OPENAI_API_KEY' ) ) {
            $openai_key = isset( $_POST['pv_openai_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pv_openai_api_key'] ) ) ) : '';
            if ( '' !== $openai_key ) {
                update_option( PV_AI_Client::OPENAI_OPTION_KEY, $openai_key, false );
                $messages[] = 'OpenAI API key saved.';
            }
        } else {
            $messages[] = 'OpenAI API key is managed by PV_OPENAI_API_KEY in wp-config.php.';
        }

        if ( ! defined( 'PV_DEEPSEEK_API_KEY' ) ) {
            $deepseek_key = isset( $_POST['pv_deepseek_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['pv_deepseek_api_key'] ) ) ) : '';
            if ( '' !== $deepseek_key ) {
                update_option( PV_AI_Client::DEEPSEEK_OPTION_KEY, $deepseek_key, false );
                $messages[] = 'DeepSeek API key saved.';
            }
        } else {
            $messages[] = 'DeepSeek API key is managed by PV_DEEPSEEK_API_KEY in wp-config.php.';
        }

        if ( empty( $messages ) ) {
            $messages[] = 'No API key changes were saved.';
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'pv_notice'  => 'success',
                    'pv_message' => implode( ' ', $messages ),
                ],
                $redirect
            )
        );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'pressviber' ) );
        }

        $editor_mode        = $this->is_editor_mode();
        $initial_context    = $this->get_initial_context();
        $preview_url        = $this->build_preview_frame_url( $initial_context['url'] ?? '' );
        $notice_type        = isset( $_GET['pv_notice'] ) ? sanitize_key( wp_unslash( $_GET['pv_notice'] ) ) : '';
        $notice_message     = isset( $_GET['pv_message'] ) ? sanitize_text_field( wp_unslash( $_GET['pv_message'] ) ) : '';
        $openai_api_key     = defined( 'PV_OPENAI_API_KEY' ) ? '' : PV_AI_Client::get_api_key( PV_AI_Client::PROVIDER_OPENAI );
        $deepseek_api_key   = defined( 'PV_DEEPSEEK_API_KEY' ) ? '' : PV_AI_Client::get_api_key( PV_AI_Client::PROVIDER_DEEPSEEK );
        $openai_api_mask    = PV_AI_Client::mask_api_key( $openai_api_key );
        $deepseek_api_mask  = PV_AI_Client::mask_api_key( $deepseek_api_key );
        $default_model_slug = PV_AI_Client::get_default_model_slug();
        $default_model_name = PV_AI_Client::get_model_label_for_slug( $default_model_slug );
        ?>
        <div class="wrap pv-admin-page<?php echo $editor_mode ? ' pv-admin-page--editor' : ''; ?>" id="pv-admin-page">
            <?php if ( $editor_mode ) : ?>
                <style id="pv-editor-mode-reset">
                    #adminmenumain,
                    #wpadminbar,
                    #wpfooter,
                    #screen-meta,
                    #screen-meta-links {
                        display: none !important;
                    }
                    #wpcontent,
                    #wpbody,
                    #wpbody-content,
                    .wrap {
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                    #wpcontent {
                        margin-left: 0 !important;
                    }
                </style>
            <?php endif; ?>
            <?php if ( ! $editor_mode ) : ?>
                <h1 class="wp-heading-inline"><?php esc_html_e( 'PressViber', 'pressviber' ); ?></h1>
                <p class="description"><?php esc_html_e( 'Use the builder inside the normal WordPress admin workspace without hiding the admin navigation.', 'pressviber' ); ?></p>
                <?php if ( $notice_type && $notice_message ) : ?>
                    <div class="notice notice-<?php echo esc_attr( 'success' === $notice_type ? 'success' : 'error' ); ?>"><p><?php echo esc_html( $notice_message ); ?></p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:16px 0 24px;max-width:760px;">
                    <?php wp_nonce_field( 'pv_save_api_keys' ); ?>
                    <input type="hidden" name="action" value="pv_save_api_keys" />
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="pv-openai-api-key"><?php esc_html_e( 'OpenAI API key', 'pressviber' ); ?></label></th>
                                <td>
                                    <input
                                        type="password"
                                        id="pv-openai-api-key"
                                        name="pv_openai_api_key"
                                        class="regular-text code"
                                        value=""
                                        placeholder="<?php echo esc_attr( $openai_api_mask ?: 'sk-proj-...' ); ?>"
                                        <?php disabled( defined( 'PV_OPENAI_API_KEY' ) ); ?>
                                    />
                                    <p class="description"><?php esc_html_e( 'Used for GPT-5.4. For better security, define PV_OPENAI_API_KEY in wp-config.php instead of storing it in the database.', 'pressviber' ); ?></p>
                                    <?php if ( defined( 'PV_OPENAI_API_KEY' ) ) : ?>
                                        <p class="description"><?php esc_html_e( 'PV_OPENAI_API_KEY is currently defined in wp-config.php and overrides the saved option.', 'pressviber' ); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="pv-deepseek-api-key"><?php esc_html_e( 'DeepSeek API key', 'pressviber' ); ?></label></th>
                                <td>
                                    <input
                                        type="password"
                                        id="pv-deepseek-api-key"
                                        name="pv_deepseek_api_key"
                                        class="regular-text code"
                                        value=""
                                        placeholder="<?php echo esc_attr( $deepseek_api_mask ?: 'sk-...' ); ?>"
                                        <?php disabled( defined( 'PV_DEEPSEEK_API_KEY' ) ); ?>
                                    />
                                    <p class="description"><?php esc_html_e( 'Used for DeepSeek v3 comparisons. For better security, define PV_DEEPSEEK_API_KEY in wp-config.php instead of storing it in the database.', 'pressviber' ); ?></p>
                                    <?php if ( defined( 'PV_DEEPSEEK_API_KEY' ) ) : ?>
                                        <p class="description"><?php esc_html_e( 'PV_DEEPSEEK_API_KEY is currently defined in wp-config.php and overrides the saved option.', 'pressviber' ); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button( __( 'Save API Keys', 'pressviber' ) ); ?>
                </form>
            <?php endif; ?>
            <div id="pv-root">

                <!-- ═══════════════════════════════════════════════════════════
                     LANDING VIEW
                ═══════════════════════════════════════════════════════════ -->
                <div id="pv-view-landing" class="pv-view<?php echo $editor_mode ? '' : ' pv-view--active'; ?>">

                <header class="pv-nav">
                    <div class="pv-nav__brand">
                        <svg class="pv-nav__star" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2c.3 3.8 2.2 5.7 6 6-3.8.3-5.7 2.2-6 6-.3-3.8-2.2-5.7-6-6 3.8-.3 5.7-2.2 6-6z"/>
                        </svg>
                        <?php esc_html_e( 'PressViber', 'pressviber' ); ?>
                    </div>
                    <span class="pv-nav__badge">v<?php echo esc_html( PV_VERSION ); ?> &nbsp;·&nbsp; Beta</span>
                </header>

                <main class="pv-hero">

                    <h1 class="pv-hero__title"><?php esc_html_e( 'What do you want to build?', 'pressviber' ); ?></h1>

                    <!-- Glass input card -->
                    <div class="pv-card" id="pv-card">

                        <textarea id="pv-prompt" class="pv-card__textarea" rows="3"
                            placeholder="<?php esc_attr_e( 'Where do you start from?', 'pressviber' ); ?>"
                            aria-label="<?php esc_attr_e( 'Describe what you want to build', 'pressviber' ); ?>"></textarea>

                        <div class="pv-card__footer">
                            <div class="pv-card__pills">

                                <!-- Page selector -->
                                <div class="pv-select" id="pv-page-select"
                                     data-placeholder="<?php esc_attr_e( 'Start from a page…', 'pressviber' ); ?>">
                                    <button class="pv-select__trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        <span class="pv-select__label"><?php esc_html_e( 'Start from a page…', 'pressviber' ); ?></span>
                                        <svg class="pv-select__caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                    </button>
                                    <ul class="pv-select__menu" role="listbox" aria-label="<?php esc_attr_e( 'Select a page', 'pressviber' ); ?>">
                                        <li class="pv-select__loading" role="option" aria-disabled="true"><?php esc_html_e( 'Loading pages…', 'pressviber' ); ?></li>
                                    </ul>
                                    <input type="hidden" id="pv-page-value" value="">
                                    <input type="hidden" id="pv-page-url" value="">
                                    <input type="hidden" id="pv-page-title" value="">
                                </div>

                                <!-- Model selector -->
                                <div class="pv-select pv-select--model" id="pv-model-select">
                                    <button class="pv-select__trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>
                                        <span class="pv-select__label"><?php echo esc_html( $default_model_name ); ?></span>
                                        <svg class="pv-select__caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                    </button>
                                    <ul class="pv-select__menu" role="listbox" aria-label="<?php esc_attr_e( 'Select AI model', 'pressviber' ); ?>">
                                        <li class="pv-select__option<?php echo PV_AI_Client::MODEL_GPT5_4 === $default_model_slug ? ' pv-select__option--active' : ''; ?>" data-value="gpt5_4" role="option" aria-selected="<?php echo esc_attr( PV_AI_Client::MODEL_GPT5_4 === $default_model_slug ? 'true' : 'false' ); ?>">
                                            <span>GPT-5.4</span>
                                            <svg class="pv-select__check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"<?php echo PV_AI_Client::MODEL_GPT5_4 === $default_model_slug ? '' : ' hidden'; ?>><polyline points="20 6 9 17 4 12"/></svg>
                                        </li>
                                        <li class="pv-select__option<?php echo PV_AI_Client::MODEL_DEEPSEEK === $default_model_slug ? ' pv-select__option--active' : ''; ?>" data-value="deepseek" role="option" aria-selected="<?php echo esc_attr( PV_AI_Client::MODEL_DEEPSEEK === $default_model_slug ? 'true' : 'false' ); ?>">
                                            <span>DeepSeek v3</span>
                                            <svg class="pv-select__check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"<?php echo PV_AI_Client::MODEL_DEEPSEEK === $default_model_slug ? '' : ' hidden'; ?>><polyline points="20 6 9 17 4 12"/></svg>
                                        </li>
                                        <li class="pv-select__option pv-select__option--disabled" data-value="claude" role="option" aria-disabled="true"><span>Claude</span><span class="pv-select__soon">Soon</span></li>
                                    </ul>
                                    <input type="hidden" id="pv-model-value" value="<?php echo esc_attr( $default_model_slug ); ?>">
                                </div>

                            </div>

                            <button id="pv-submit" class="pv-send" type="button" aria-label="<?php esc_attr_e( 'Build', 'pressviber' ); ?>">
                                <svg class="pv-send__icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2L15 22l-4-9-9-4 20-7z"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Template chips -->
                    <div class="pv-chips" id="pv-templates-grid" role="list">
                        <button class="pv-chip" data-template="saas-landing" type="button" role="listitem">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                            <?php esc_html_e( 'SaaS Landing Page', 'pressviber' ); ?>
                        </button>
                        <button class="pv-chip" data-template="product-landing" type="button" role="listitem">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                            <?php esc_html_e( 'Product Landing Page', 'pressviber' ); ?>
                        </button>
                        <button class="pv-chip" data-template="agency-landing" type="button" role="listitem">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                            <?php esc_html_e( 'Agency Landing Page', 'pressviber' ); ?>
                        </button>
                        <button class="pv-chip" data-template="personal-portfolio" type="button" role="listitem">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?php esc_html_e( 'Personal Portfolio', 'pressviber' ); ?>
                        </button>
                        <button class="pv-chip" data-template="startup-waitlist" type="button" role="listitem">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            <?php esc_html_e( 'Startup Waitlist', 'pressviber' ); ?>
                        </button>
                    </div>

                    <!-- Landscape illustration -->
                    <div class="pv-hero__landscape" aria-hidden="true">
                        <svg viewBox="0 0 1200 280" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMax slice">
                            <!-- Sky gradient -->
                            <defs>
                                <linearGradient id="pv-sky" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#fefbe9" stop-opacity="0"/>
                                    <stop offset="100%" stop-color="#e1eedd" stop-opacity="0.6"/>
                                </linearGradient>
                                <linearGradient id="pv-hill-far" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#c8ddc2"/>
                                    <stop offset="100%" stop-color="#a8c9a0"/>
                                </linearGradient>
                                <linearGradient id="pv-hill-mid" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#3d8b46"/>
                                    <stop offset="100%" stop-color="#2d6a36"/>
                                </linearGradient>
                                <linearGradient id="pv-hill-near" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stop-color="#183a1d"/>
                                    <stop offset="100%" stop-color="#0f2512"/>
                                </linearGradient>
                            </defs>

                            <!-- Sky wash -->
                            <rect width="1200" height="280" fill="url(#pv-sky)"/>

                            <!-- Far mountains – stippled ridgeline -->
                            <path d="M0,200 C80,170 140,140 220,155 C280,165 320,130 400,120 C460,112 500,135 560,125 C620,115 660,90 720,100 C780,110 820,135 880,128 C940,121 980,105 1040,115 C1100,125 1150,145 1200,140 L1200,280 L0,280 Z" fill="url(#pv-hill-far)" opacity="0.55"/>

                            <!-- Stipple dots on far hills -->
                            <g fill="#b0cba8" opacity="0.45">
                                <circle cx="105" cy="168" r="1.2"/><circle cx="120" cy="162" r="0.9"/><circle cx="135" cy="170" r="1.1"/>
                                <circle cx="230" cy="152" r="1.3"/><circle cx="248" cy="158" r="0.8"/><circle cx="260" cy="149" r="1.0"/>
                                <circle cx="415" cy="122" r="1.2"/><circle cx="432" cy="128" r="0.9"/><circle cx="448" cy="120" r="1.1"/>
                                <circle cx="580" cy="127" r="1.0"/><circle cx="596" cy="120" r="1.3"/><circle cx="614" cy="129" r="0.8"/>
                                <circle cx="742" cy="103" r="1.1"/><circle cx="758" cy="110" r="0.9"/><circle cx="775" cy="102" r="1.2"/>
                                <circle cx="900" cy="130" r="1.0"/><circle cx="916" cy="124" r="1.3"/><circle cx="934" cy="132" r="0.8"/>
                                <circle cx="1060" cy="117" r="1.1"/><circle cx="1075" cy="111" r="0.9"/><circle cx="1090" cy="119" r="1.2"/>
                            </g>

                            <!-- Mid valley hills -->
                            <path d="M0,230 C60,215 120,195 200,200 C260,204 300,185 380,178 C440,172 490,192 560,188 C630,184 680,165 750,170 C820,175 870,195 940,190 C1000,186 1060,172 1120,178 C1160,182 1185,198 1200,205 L1200,280 L0,280 Z" fill="url(#pv-hill-mid)" opacity="0.75"/>

                            <!-- Mid hill stippling -->
                            <g fill="#e1eedd" opacity="0.20">
                                <circle cx="80" cy="218" r="1.5"/><circle cx="100" cy="210" r="1.0"/><circle cx="118" cy="220" r="1.3"/>
                                <circle cx="310" cy="188" r="1.4"/><circle cx="330" cy="182" r="1.0"/><circle cx="348" cy="190" r="1.2"/>
                                <circle cx="510" cy="192" r="1.3"/><circle cx="528" cy="185" r="1.1"/><circle cx="546" cy="194" r="0.9"/>
                                <circle cx="700" cy="172" r="1.4"/><circle cx="718" cy="166" r="1.0"/><circle cx="736" cy="174" r="1.2"/>
                                <circle cx="890" cy="193" r="1.3"/><circle cx="908" cy="186" r="1.1"/><circle cx="926" cy="195" r="0.9"/>
                                <circle cx="1080" cy="175" r="1.4"/><circle cx="1098" cy="168" r="1.0"/><circle cx="1116" cy="177" r="1.2"/>
                            </g>

                            <!-- Foreground rolling hills -->
                            <path d="M0,260 C50,248 100,240 180,245 C240,249 290,238 360,235 C420,232 470,248 540,244 C610,240 660,228 730,232 C800,236 850,250 920,246 C980,242 1040,232 1110,238 C1155,242 1180,252 1200,258 L1200,280 L0,280 Z" fill="url(#pv-hill-near)"/>

                            <!-- Foreground stippling (warm dots) -->
                            <g fill="#f6c453" opacity="0.18">
                                <circle cx="60" cy="252" r="1.6"/><circle cx="82" cy="246" r="1.1"/><circle cx="100" cy="254" r="1.4"/>
                                <circle cx="200" cy="248" r="1.5"/><circle cx="220" cy="242" r="1.0"/><circle cx="240" cy="250" r="1.3"/>
                                <circle cx="380" cy="238" r="1.4"/><circle cx="400" cy="232" r="1.1"/><circle cx="420" cy="240" r="0.9"/>
                                <circle cx="560" cy="246" r="1.5"/><circle cx="580" cy="240" r="1.0"/><circle cx="600" cy="248" r="1.3"/>
                                <circle cx="750" cy="234" r="1.4"/><circle cx="770" cy="228" r="1.1"/><circle cx="790" cy="236" r="0.9"/>
                                <circle cx="940" cy="248" r="1.5"/><circle cx="960" cy="242" r="1.0"/><circle cx="980" cy="250" r="1.3"/>
                                <circle cx="1130" cy="240" r="1.4"/><circle cx="1150" cy="234" r="1.1"/><circle cx="1170" cy="242" r="0.9"/>
                            </g>

                            <!-- Ground strip -->
                            <rect y="268" width="1200" height="12" fill="#0f2512"/>
                        </svg>
                    </div>

                </main>

                <span class="pv-sparkle" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c.3 3.8 2.2 5.7 6 6-3.8.3-5.7 2.2-6 6-.3-3.8-2.2-5.7-6-6 3.8-.3 5.7-2.2 6-6z"/></svg>
                </span>

                </div><!-- /#pv-view-landing -->


            <!-- ═══════════════════════════════════════════════════════════
                 CHAT VIEW  (hidden until first Build)
            ═══════════════════════════════════════════════════════════ -->
                <div id="pv-view-chat" class="pv-view<?php echo $editor_mode ? ' pv-view--active' : ''; ?>">

                <!-- Chat nav bar -->
                <header class="pv-chat-nav">
                    <div class="pv-chat-nav__actions">
                        <a
                            id="pv-exit-editor"
                            class="pv-chat-nav__new pv-chat-nav__new--ghost"
                            href="<?php echo esc_url( $initial_context['returnUrl'] ?? home_url( '/' ) ); ?>"
                            <?php echo $editor_mode ? '' : 'hidden'; ?>
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <?php esc_html_e( 'Exit Editor', 'pressviber' ); ?>
                        </a>
                        <button id="pv-new-chat" class="pv-chat-nav__new" type="button">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                            <?php esc_html_e( 'New Chat', 'pressviber' ); ?>
                        </button>
                    </div>

                    <div class="pv-chat-nav__center">
                        <span class="pv-nav__brand">
                            <svg class="pv-nav__star" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2c.3 3.8 2.2 5.7 6 6-3.8.3-5.7 2.2-6 6-.3-3.8-2.2-5.7-6-6 3.8-.3 5.7-2.2 6-6z"/></svg>
                            <?php esc_html_e( 'PressViber', 'pressviber' ); ?>
                        </span>
                        <span class="pv-chat-nav__page-name" id="pv-chat-page-name"></span>
                    </div>

                    <div class="pv-chat-nav__right">
                        <div class="pv-select pv-select--model pv-select--compact" id="pv-chat-model-select">
                            <button class="pv-select__trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>
                                <span class="pv-select__label"><?php echo esc_html( $default_model_name ); ?></span>
                                <svg class="pv-select__caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            </button>
                            <ul class="pv-select__menu" role="listbox" aria-label="<?php esc_attr_e( 'Select AI model', 'pressviber' ); ?>">
                                <li class="pv-select__option<?php echo PV_AI_Client::MODEL_GPT5_4 === $default_model_slug ? ' pv-select__option--active' : ''; ?>" data-value="gpt5_4" role="option" aria-selected="<?php echo esc_attr( PV_AI_Client::MODEL_GPT5_4 === $default_model_slug ? 'true' : 'false' ); ?>">
                                    <span>GPT-5.4</span>
                                    <svg class="pv-select__check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"<?php echo PV_AI_Client::MODEL_GPT5_4 === $default_model_slug ? '' : ' hidden'; ?>><polyline points="20 6 9 17 4 12"/></svg>
                                </li>
                                <li class="pv-select__option<?php echo PV_AI_Client::MODEL_DEEPSEEK === $default_model_slug ? ' pv-select__option--active' : ''; ?>" data-value="deepseek" role="option" aria-selected="<?php echo esc_attr( PV_AI_Client::MODEL_DEEPSEEK === $default_model_slug ? 'true' : 'false' ); ?>">
                                    <span>DeepSeek v3</span>
                                    <svg class="pv-select__check" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"<?php echo PV_AI_Client::MODEL_DEEPSEEK === $default_model_slug ? '' : ' hidden'; ?>><polyline points="20 6 9 17 4 12"/></svg>
                                </li>
                                <li class="pv-select__option pv-select__option--disabled" data-value="claude" role="option" aria-disabled="true"><span>Claude</span><span class="pv-select__soon">Soon</span></li>
                            </ul>
                        </div>
                    </div>
                </header>

                <!-- Split body: chat | preview -->
                <div class="pv-chat-body">

                    <!-- ── LEFT: Chat panel ── -->
                    <div class="pv-chat-panel">

                        <div class="pv-chat-messages" id="pv-chat-messages" aria-live="polite" aria-label="<?php esc_attr_e( 'Chat messages', 'pressviber' ); ?>">
                            <!-- Messages injected by JS -->
                        </div>

                        <div class="pv-chat-landscape" aria-hidden="true">
                            <svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
                                <defs>
                                    <linearGradient id="pv-chat-wave-bg" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#ffffff" stop-opacity="0"/>
                                        <stop offset="100%" stop-color="#eef5e9" stop-opacity="1"/>
                                    </linearGradient>
                                    <linearGradient id="pv-chat-wave-mid" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#cfe2c8"/>
                                        <stop offset="100%" stop-color="#aac89d"/>
                                    </linearGradient>
                                    <linearGradient id="pv-chat-wave-near" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#3d8b46"/>
                                        <stop offset="100%" stop-color="#183a1d"/>
                                    </linearGradient>
                                </defs>
                                <rect width="1200" height="120" fill="url(#pv-chat-wave-bg)"/>
                                <path d="M0,82 C80,70 140,54 220,58 C300,62 352,42 430,39 C510,36 566,60 642,56 C732,51 790,32 870,35 C950,39 1010,60 1090,56 C1148,53 1186,61 1200,66 L1200,120 L0,120 Z" fill="url(#pv-chat-wave-mid)" opacity="0.72"/>
                                <path d="M0,96 C70,86 138,80 220,84 C300,88 362,74 444,72 C534,70 596,88 682,85 C772,81 834,64 914,66 C996,68 1062,86 1144,84 C1168,83 1188,86 1200,89 L1200,120 L0,120 Z" fill="url(#pv-chat-wave-near)"/>
                                <g fill="#f6c453" opacity="0.16">
                                    <circle cx="96" cy="84" r="1.6"/><circle cx="122" cy="78" r="1.1"/><circle cx="148" cy="86" r="1.3"/>
                                    <circle cx="332" cy="76" r="1.5"/><circle cx="358" cy="69" r="1.0"/><circle cx="384" cy="78" r="1.3"/>
                                    <circle cx="566" cy="86" r="1.4"/><circle cx="592" cy="80" r="1.0"/><circle cx="618" cy="88" r="1.2"/>
                                    <circle cx="812" cy="70" r="1.5"/><circle cx="838" cy="64" r="1.1"/><circle cx="864" cy="72" r="1.3"/>
                                    <circle cx="1032" cy="86" r="1.4"/><circle cx="1058" cy="80" r="1.0"/><circle cx="1084" cy="88" r="1.2"/>
                                </g>
                            </svg>
                        </div>

                        <div class="pv-chat-inputbox">
                            <textarea
                                id="pv-chat-input"
                                class="pv-chat-textarea"
                                rows="1"
                                placeholder="<?php esc_attr_e( 'Continue the conversation… (Ctrl+Enter to send)', 'pressviber' ); ?>"
                                aria-label="<?php esc_attr_e( 'Chat input', 'pressviber' ); ?>"
                            ></textarea>
                            <button id="pv-chat-send" class="pv-chat-send" type="button" aria-label="<?php esc_attr_e( 'Send message', 'pressviber' ); ?>">
                                <svg class="pv-send__icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13M22 2L15 22l-4-9-9-4 20-7z"/></svg>
                                <svg class="pv-send__spinner" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" hidden><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2a10 10 0 0110 10"/></svg>
                            </button>
                        </div>

                    </div><!-- /.pv-chat-panel -->

                    <div
                        id="pv-chat-resizer"
                        class="pv-chat-resizer"
                        role="separator"
                        aria-orientation="vertical"
                        aria-label="<?php esc_attr_e( 'Resize chat panel', 'pressviber' ); ?>"
                        tabindex="0"
                    ><span></span></div>

                    <!-- ── RIGHT: Preview panel ── -->
                    <div class="pv-preview-panel">

                        <div class="pv-preview-toolbar">
                            <div class="pv-preview-toolbar__url">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>
                                <span id="pv-preview-url-text" class="pv-preview-toolbar__url-text"><?php echo esc_html( $initial_context['url'] ?? '' ); ?></span>
                            </div>
                            <div class="pv-preview-toolbar__actions">
                                <div class="pv-preview-devices" role="group" aria-label="<?php esc_attr_e( 'Preview device', 'pressviber' ); ?>">
                                    <button id="pv-preview-desktop" class="pv-preview-btn pv-preview-btn--icon is-active" type="button" title="<?php esc_attr_e( 'Desktop preview', 'pressviber' ); ?>" aria-pressed="true">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/></svg>
                                    </button>
                                    <button id="pv-preview-mobile" class="pv-preview-btn pv-preview-btn--icon" type="button" title="<?php esc_attr_e( 'Mobile preview', 'pressviber' ); ?>" aria-pressed="false">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11.5 18h1"/></svg>
                                    </button>
                                </div>
                                <button id="pv-preview-refresh" class="pv-preview-btn" type="button" title="<?php esc_attr_e( 'Refresh preview', 'pressviber' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>
                                    <?php esc_html_e( 'Refresh', 'pressviber' ); ?>
                                </button>
                                <a id="pv-preview-open" class="pv-preview-btn" href="<?php echo esc_url( $initial_context['url'] ?? '#' ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open in new tab', 'pressviber' ); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                    <?php esc_html_e( 'Open', 'pressviber' ); ?>
                                </a>
                            </div>
                        </div>

                        <div class="pv-preview-frame-wrap">
                            <div id="pv-preview-viewport" class="pv-preview-viewport pv-preview-viewport--desktop">
                                <iframe
                                    id="pv-preview-iframe"
                                    class="pv-preview-iframe"
                                    src="<?php echo esc_url( $preview_url ?: 'about:blank' ); ?>"
                                    title="<?php esc_attr_e( 'Page preview', 'pressviber' ); ?>"
                                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
                                ></iframe>
                            </div>
                            <div class="pv-preview-loading" id="pv-preview-loading" hidden>
                                <div class="pv-preview-loading__inner">
                                    <div class="pv-preview-loading__dots">
                                        <span class="pv-dot"></span><span class="pv-dot"></span><span class="pv-dot"></span>
                                    </div>
                                    <span class="pv-preview-loading__label" id="pv-preview-loading-label"><?php esc_html_e( 'Loading preview…', 'pressviber' ); ?></span>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.pv-preview-panel -->

                </div><!-- /.pv-chat-body -->

                </div><!-- /#pv-view-chat -->


                <!-- Toast -->
                <div id="pv-toast" class="pv-toast" role="status" aria-live="polite"></div>

            </div><!-- /#pv-root -->
        </div><!-- /#pv-admin-page -->
        <?php
    }

    private function is_editor_mode(): bool {
        if ( isset( $_GET[ self::STANDALONE_PARAM ] ) && '1' === sanitize_text_field( wp_unslash( $_GET[ self::STANDALONE_PARAM ] ) ) ) {
            return true;
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $mode = isset( $_GET['pv_mode'] ) ? sanitize_key( wp_unslash( $_GET['pv_mode'] ) ) : '';
        $has_target = isset( $_GET['target_url'] ) && '' !== $this->sanitize_same_origin_url( (string) wp_unslash( $_GET['target_url'] ) );

        if ( self::PAGE_SLUG !== $page ) {
            return false;
        }

        return self::EDITOR_MODE === $mode || $has_target;
    }

    public function maybe_render_standalone_editor(): void {
        if ( is_admin() || ! isset( $_GET[ self::STANDALONE_PARAM ] ) ) {
            return;
        }

        if ( '1' !== sanitize_text_field( wp_unslash( $_GET[ self::STANDALONE_PARAM ] ) ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            auth_redirect();
        }

        status_header( 200 );
        nocache_headers();

        $script_url = PV_PLUGIN_URL . 'assets/js/admin.js?ver=' . rawurlencode( PV_VERSION );
        $style_url  = PV_PLUGIN_URL . 'assets/css/admin.css?ver=' . rawurlencode( PV_VERSION );
        $data       = wp_json_encode( $this->get_script_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'PressViber Editor', 'pressviber' ); ?></title>
            <link rel="stylesheet" href="<?php echo esc_url( $style_url ); ?>">
            <style>
                html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; background: #fff; }
                body { min-height: 100vh; }
            </style>
            <script>window.pvData = <?php echo $data ? $data : '{}'; ?>;</script>
            <script src="<?php echo esc_url( $script_url ); ?>" defer></script>
        </head>
        <body class="pv-standalone-editor">
            <?php $this->render_page(); ?>
        </body>
        </html><?php
        exit;
    }

    private function is_preview_request(): bool {
        return ! is_admin() && isset( $_GET['pv_preview'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['pv_preview'] ) );
    }

    private function get_initial_context(): array {
        $editor_mode = $this->is_editor_mode();
        $page_id     = isset( $_GET['target_id'] ) ? absint( $_GET['target_id'] ) : 0;
        $target_url  = isset( $_GET['target_url'] ) ? $this->sanitize_same_origin_url( wp_unslash( $_GET['target_url'] ) ) : '';
        $target_kind = isset( $_GET['target_kind'] ) ? sanitize_key( wp_unslash( $_GET['target_kind'] ) ) : '';
        $title       = isset( $_GET['target_title'] ) ? sanitize_text_field( wp_unslash( $_GET['target_title'] ) ) : '';

        if ( $page_id > 0 ) {
            $page = get_post( $page_id );
            if ( ! ( $page instanceof WP_Post ) || 'page' !== $page->post_type ) {
                $page_id = 0;
            } else {
                if ( '' === $target_url ) {
                    $target_url = get_permalink( $page_id ) ?: '';
                }
                if ( '' === $title ) {
                    $title = get_the_title( $page_id );
                }
                if ( '' === $target_kind ) {
                    $target_kind = 'page';
                }
            }
        }

        if ( '' === $target_url ) {
            $target_url = home_url( '/' );
        }

        if ( '' === $title ) {
            $title = $this->derive_title_from_url( $target_url );
        }

        if ( '' === $target_kind ) {
            $target_kind = 'live_url';
        }

        return [
            'startInEditor' => $editor_mode,
            'pageId'        => $page_id,
            'url'           => $target_url,
            'title'         => $title,
            'returnUrl'     => $target_url,
            'targetKind'    => $target_kind,
        ];
    }

    private function get_frontend_context(): array {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $request_uri = is_string( $request_uri ) ? $request_uri : '/';

        $url   = $this->sanitize_same_origin_url( home_url( $request_uri ) );
        $title = wp_get_document_title();
        $kind  = 'live_url';
        $page_id = 0;

        if ( is_front_page() ) {
            $kind = 'front_page';
        } elseif ( is_home() ) {
            $kind = 'posts_index';
        } elseif ( is_page() ) {
            $kind    = 'page';
            $page_id = (int) get_queried_object_id();
        } elseif ( is_singular() ) {
            $kind = 'singular';
        } elseif ( is_category() ) {
            $kind = 'category';
        } elseif ( is_tag() ) {
            $kind = 'tag';
        } elseif ( is_tax() ) {
            $kind = 'taxonomy';
        } elseif ( is_post_type_archive() ) {
            $kind = 'post_type_archive';
        } elseif ( is_archive() ) {
            $kind = 'archive';
        } elseif ( is_search() ) {
            $kind = 'search';
        }

        if ( '' === $title ) {
            $title = $this->derive_title_from_url( $url );
        }

        return [
            'url'     => $url,
            'title'   => $title,
            'page_id' => $page_id,
            'kind'    => $kind,
        ];
    }

    private function build_editor_url( string $target_url, string $target_title = '', int $page_id = 0, string $target_kind = 'live_url' ): string {
        $query = [
            'page'       => self::PAGE_SLUG,
            'pv_mode'  => self::EDITOR_MODE,
            'target_url' => $target_url,
        ];

        if ( '' !== $target_title ) {
            $query['target_title'] = $target_title;
        }

        if ( $page_id > 0 ) {
            $query['target_id'] = $page_id;
        }

        if ( '' !== $target_kind ) {
            $query['target_kind'] = $target_kind;
        }

        return add_query_arg( $query, admin_url( 'admin.php' ) );
    }

    private function build_standalone_editor_url( string $target_url, string $target_title = '', int $page_id = 0, string $target_kind = 'live_url' ): string {
        $query = [
            self::STANDALONE_PARAM => '1',
            'target_url'           => $target_url,
        ];

        if ( '' !== $target_title ) {
            $query['target_title'] = $target_title;
        }

        if ( $page_id > 0 ) {
            $query['target_id'] = $page_id;
        }

        if ( '' !== $target_kind ) {
            $query['target_kind'] = $target_kind;
        }

        return add_query_arg( $query, home_url( '/' ) );
    }

    private function get_script_data(): array {
        return [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'pv_nonce' ),
            'siteUrl'       => get_site_url(),
            'homeUrl'       => get_home_url(),
            'wpVersion'     => get_bloginfo( 'version' ),
            'activeTheme'   => wp_get_theme()->get( 'Name' ),
            'themeUrl'      => get_template_directory_uri(),
            'editorMode'    => $this->is_editor_mode(),
            'initialContext'=> $this->get_initial_context(),
        ];
    }

    private function build_preview_frame_url( string $url ): string {
        $url = $this->sanitize_same_origin_url( $url );
        if ( '' === $url ) {
            return '';
        }

        return (string) add_query_arg( 'pv_preview', '1', $url );
    }

    private function sanitize_same_origin_url( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }

        $url = esc_url_raw( $url );
        if ( ! wp_http_validate_url( $url ) ) {
            return '';
        }

        $home_parts   = wp_parse_url( home_url() );
        $target_parts = wp_parse_url( $url );

        if ( empty( $home_parts['host'] ) || empty( $target_parts['host'] ) ) {
            return '';
        }

        if ( strtolower( (string) $home_parts['host'] ) !== strtolower( (string) $target_parts['host'] ) ) {
            return '';
        }

        if ( $this->normalize_port( $home_parts ) !== $this->normalize_port( $target_parts ) ) {
            return '';
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

    private function derive_title_from_url( string $url ): string {
        $parts = wp_parse_url( $url );
        $path  = trim( (string) ( $parts['path'] ?? '' ), '/' );

        if ( '' === $path ) {
            return __( 'Homepage', 'pressviber' );
        }

        $segments = array_values( array_filter( explode( '/', $path ) ) );
        $slug     = end( $segments );
        $slug     = is_string( $slug ) ? $slug : '';
        $title    = ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );

        return '' !== $title ? $title : __( 'Current Page', 'pressviber' );
    }
}
