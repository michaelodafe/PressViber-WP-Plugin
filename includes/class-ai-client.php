<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles AI API communication for the builder.
 */
class PV_AI_Client {

    const MODEL_GPT5_4    = 'gpt5_4';
    const MODEL_DEEPSEEK  = 'deepseek';

    const PROVIDER_OPENAI   = 'openai';
    const PROVIDER_DEEPSEEK = 'deepseek';

    const OPENAI_API_URL   = 'https://api.openai.com/v1/responses';
    const OPENAI_API_MODEL = 'gpt-5.4';
    const OPENAI_OPTION_KEY = 'pv_openai_api_key';

    const DEEPSEEK_API_URL   = 'https://api.deepseek.com/chat/completions';
    const DEEPSEEK_API_MODEL = 'deepseek-chat';
    const DEEPSEEK_OPTION_KEY = 'pv_deepseek_api_key';

    public function init() {
        add_action( 'wp_ajax_pv_chat', [ $this, 'ajax_chat' ] );
    }

    public function ajax_chat() {
        check_ajax_referer( 'pv_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $prompt  = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
        $model   = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : self::get_default_model_slug();

        if ( '' === $prompt ) {
            wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
        }

        $result = $this->chat( $prompt, $page_id, $model );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Send a prompt to the selected AI model and return the response.
     *
     * @param string $prompt
     * @param int    $page_id  Optional WordPress page ID for context.
     * @param string $model    'deepseek' | 'gpt5_4'
     * @param array  $history  Optional prior message history for multi-turn.
     * @return array|WP_Error  { content, model, usage }
     */
    public function chat( $prompt, $page_id = 0, $model = self::MODEL_DEEPSEEK, $history = [] ) {
        $config = self::resolve_model( $model );
        if ( is_wp_error( $config ) ) {
            return $config;
        }

        if ( self::PROVIDER_DEEPSEEK === $config['provider'] ) {
            return $this->call_deepseek( $prompt, $page_id, $history, $config );
        }

        return $this->call_openai( $prompt, $page_id, $history, $config );
    }

    public static function resolve_model( $model ) {
        $model = sanitize_key( (string) $model );

        switch ( $model ) {
            case '':
            case self::MODEL_DEEPSEEK:
                return [
                    'slug'      => self::MODEL_DEEPSEEK,
                    'provider'  => self::PROVIDER_DEEPSEEK,
                    'api_model' => self::DEEPSEEK_API_MODEL,
                    'label'     => 'DeepSeek v3',
                ];

            case self::MODEL_GPT5_4:
            case 'gpt4':
            case 'openai':
            case 'gpt':
                return [
                    'slug'      => self::MODEL_GPT5_4,
                    'provider'  => self::PROVIDER_OPENAI,
                    'api_model' => self::OPENAI_API_MODEL,
                    'label'     => 'GPT-5.4',
                ];
        }

        return new WP_Error( 'unsupported_model', 'Unsupported model selection.' );
    }

    public static function get_default_model_slug(): string {
        if ( '' !== self::get_api_key( self::PROVIDER_DEEPSEEK ) ) {
            return self::MODEL_DEEPSEEK;
        }

        if ( '' !== self::get_api_key( self::PROVIDER_OPENAI ) ) {
            return self::MODEL_GPT5_4;
        }

        return self::MODEL_DEEPSEEK;
    }

    public static function get_model_label_for_slug( string $slug ): string {
        $config = self::resolve_model( $slug );
        if ( is_wp_error( $config ) ) {
            return $slug;
        }

        return $config['label'];
    }

    public static function get_api_key( string $provider ): string {
        if ( self::PROVIDER_OPENAI === $provider ) {
            if ( defined( 'PV_OPENAI_API_KEY' ) && is_string( PV_OPENAI_API_KEY ) && '' !== trim( PV_OPENAI_API_KEY ) ) {
                return trim( PV_OPENAI_API_KEY );
            }

            return trim( (string) get_option( self::OPENAI_OPTION_KEY, '' ) );
        }

        if ( defined( 'PV_DEEPSEEK_API_KEY' ) && is_string( PV_DEEPSEEK_API_KEY ) && '' !== trim( PV_DEEPSEEK_API_KEY ) ) {
            return trim( PV_DEEPSEEK_API_KEY );
        }

        return trim( (string) get_option( self::DEEPSEEK_OPTION_KEY, '' ) );
    }

    public static function get_api_key_message( string $provider ): string {
        if ( self::PROVIDER_DEEPSEEK === $provider ) {
            return 'DeepSeek API key is not configured. Add it in PressViber settings or define PV_DEEPSEEK_API_KEY in wp-config.php.';
        }

        return 'OpenAI API key is not configured. Add it in PressViber settings or define PV_OPENAI_API_KEY in wp-config.php.';
    }

    public static function mask_api_key( string $api_key ): string {
        $api_key = trim( $api_key );
        if ( '' === $api_key ) {
            return '';
        }

        return str_repeat( '*', max( 12, strlen( $api_key ) - 4 ) ) . substr( $api_key, -4 );
    }

    public static function build_input_messages( array $messages ): array {
        $input = [];

        foreach ( $messages as $message ) {
            if ( empty( $message['role'] ) || ! array_key_exists( 'content', $message ) ) {
                continue;
            }

            $role    = sanitize_key( (string) $message['role'] );
            $content = trim( (string) $message['content'] );

            if ( '' === $content ) {
                continue;
            }

            $input[] = [
                'role'    => $role,
                'content' => $content,
            ];
        }

        return $input;
    }

    public static function normalize_tools_for_responses( array $tools ): array {
        $normalized = [];

        foreach ( $tools as $tool ) {
            if ( empty( $tool['type'] ) || 'function' !== $tool['type'] ) {
                continue;
            }

            if ( ! empty( $tool['function'] ) && is_array( $tool['function'] ) ) {
                $normalized[] = array_merge(
                    [ 'type' => 'function' ],
                    $tool['function']
                );
                continue;
            }

            $normalized[] = $tool;
        }

        return $normalized;
    }

    public static function extract_openai_tool_calls( array $response ): array {
        $tool_calls = [];
        $output     = isset( $response['output'] ) && is_array( $response['output'] ) ? $response['output'] : [];

        foreach ( $output as $item ) {
            if ( empty( $item['type'] ) || 'function_call' !== $item['type'] ) {
                continue;
            }

            $call_id = ! empty( $item['call_id'] ) ? (string) $item['call_id'] : ( ! empty( $item['id'] ) ? (string) $item['id'] : uniqid( 'call_', true ) );

            $tool_calls[] = [
                'id'       => $call_id,
                'call_id'  => $call_id,
                'function' => [
                    'name'      => (string) ( $item['name'] ?? '' ),
                    'arguments' => (string) ( $item['arguments'] ?? '{}' ),
                ],
            ];
        }

        return $tool_calls;
    }

    public static function extract_openai_output_text( array $response ): string {
        if ( ! empty( $response['output_text'] ) && is_string( $response['output_text'] ) ) {
            return trim( $response['output_text'] );
        }

        $chunks = [];
        $output = isset( $response['output'] ) && is_array( $response['output'] ) ? $response['output'] : [];

        foreach ( $output as $item ) {
            if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
                continue;
            }

            foreach ( $item['content'] as $content_item ) {
                if ( empty( $content_item['type'] ) ) {
                    continue;
                }

                if ( in_array( $content_item['type'], [ 'output_text', 'text' ], true ) && ! empty( $content_item['text'] ) ) {
                    $chunks[] = (string) $content_item['text'];
                }
            }
        }

        return trim( implode( "\n\n", $chunks ) );
    }

    public static function extract_openai_usage( array $response ): array {
        $usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [];

        return [
            'prompt_tokens'     => isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : (int) ( $usage['prompt_tokens'] ?? 0 ),
            'completion_tokens' => isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : (int) ( $usage['completion_tokens'] ?? 0 ),
            'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
        ];
    }

    private function call_openai( $prompt, $page_id = 0, $history = [], array $config = [] ) {
        $api_key = self::get_api_key( self::PROVIDER_OPENAI );

        if ( '' === $api_key ) {
            return new WP_Error( 'no_api_key', self::get_api_key_message( self::PROVIDER_OPENAI ) );
        }

        $messages = [
            [ 'role' => 'system', 'content' => $this->build_system_prompt( $page_id ) ],
        ];

        foreach ( $history as $turn ) {
            if ( isset( $turn['role'], $turn['content'] ) ) {
                $messages[] = [
                    'role'    => sanitize_key( (string) $turn['role'] ),
                    'content' => sanitize_textarea_field( (string) $turn['content'] ),
                ];
            }
        }

        $messages[] = [ 'role' => 'user', 'content' => $prompt ];

        $response = wp_remote_post(
            self::OPENAI_API_URL,
            [
                'timeout'   => 90,
                'headers'   => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ],
                'body'      => wp_json_encode(
                    [
                        'model'             => $config['api_model'] ?? self::OPENAI_API_MODEL,
                        'input'             => self::build_input_messages( $messages ),
                        'max_output_tokens' => 4096,
                    ]
                ),
                'sslverify' => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', 'Could not reach OpenAI API: ' . $response->get_error_message() );
        }

        $code         = wp_remote_retrieve_response_code( $response );
        $raw_body     = wp_remote_retrieve_body( $response );
        $decoded_body = json_decode( $raw_body, true );

        if ( 200 !== $code ) {
            $api_msg = $decoded_body['error']['message'] ?? ( 'OpenAI API error (HTTP ' . $code . ')' );
            return new WP_Error( 'api_error', $api_msg );
        }

        return [
            'content' => self::extract_openai_output_text( $decoded_body ),
            'model'   => $decoded_body['model'] ?? ( $config['api_model'] ?? self::OPENAI_API_MODEL ),
            'usage'   => self::extract_openai_usage( $decoded_body ),
        ];
    }

    private function call_deepseek( $prompt, $page_id = 0, $history = [], array $config = [] ) {
        $api_key = self::get_api_key( self::PROVIDER_DEEPSEEK );

        if ( '' === $api_key ) {
            return new WP_Error( 'no_api_key', self::get_api_key_message( self::PROVIDER_DEEPSEEK ) );
        }

        $messages = [
            [ 'role' => 'system', 'content' => $this->build_system_prompt( $page_id ) ],
        ];

        foreach ( $history as $turn ) {
            if ( isset( $turn['role'], $turn['content'] ) ) {
                $messages[] = [
                    'role'    => sanitize_key( (string) $turn['role'] ),
                    'content' => sanitize_textarea_field( (string) $turn['content'] ),
                ];
            }
        }

        $messages[] = [ 'role' => 'user', 'content' => $prompt ];

        $response = wp_remote_post(
            self::DEEPSEEK_API_URL,
            [
                'timeout'   => 90,
                'headers'   => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ],
                'body'      => wp_json_encode(
                    [
                        'model'       => $config['api_model'] ?? self::DEEPSEEK_API_MODEL,
                        'messages'    => $messages,
                        'temperature' => 0.7,
                        'max_tokens'  => 4096,
                    ]
                ),
                'sslverify' => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'http_error', 'Could not reach DeepSeek API: ' . $response->get_error_message() );
        }

        $code         = wp_remote_retrieve_response_code( $response );
        $raw_body     = wp_remote_retrieve_body( $response );
        $decoded_body = json_decode( $raw_body, true );

        if ( 200 !== $code ) {
            $api_msg = $decoded_body['error']['message'] ?? ( 'DeepSeek API error (HTTP ' . $code . ')' );
            return new WP_Error( 'api_error', $api_msg );
        }

        $content = $decoded_body['choices'][0]['message']['content'] ?? '';
        $usage   = $decoded_body['usage'] ?? [];
        $model   = $decoded_body['model'] ?? ( $config['api_model'] ?? self::DEEPSEEK_API_MODEL );

        return [
            'content' => $content,
            'model'   => $model,
            'usage'   => [
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $usage['total_tokens'] ?? 0,
            ],
        ];
    }

    private function build_system_prompt( $page_id = 0 ) {
        $theme   = wp_get_theme();
        $uploads = wp_upload_dir();

        $prompt  = "You are an expert WordPress developer and UI/UX designer embedded directly inside the WordPress admin dashboard.\n\n";
        $prompt .= "## WordPress Environment\n";
        $prompt .= "- WordPress version: " . get_bloginfo( 'version' ) . "\n";
        $prompt .= "- Site URL: " . get_site_url() . "\n";
        $prompt .= "- Home URL: " . get_home_url() . "\n";
        $prompt .= "- Active theme: " . $theme->get( 'Name' ) . " v" . $theme->get( 'Version' ) . "\n";
        $prompt .= "- Theme path: " . get_template_directory() . "\n";
        $prompt .= "- Plugins path: " . WP_PLUGIN_DIR . "\n";
        $prompt .= "- Uploads path: " . $uploads['basedir'] . "\n";

        if ( $page_id ) {
            $page = get_post( $page_id );
            if ( $page ) {
                $prompt .= "\n## Target Page\n";
                $prompt .= "- Title: " . $page->post_title . "\n";
                $prompt .= "- ID: " . $page_id . "\n";
                $prompt .= "- Slug: " . $page->post_name . "\n";
                $prompt .= "- Template: " . ( get_post_meta( $page_id, '_wp_page_template', true ) ?: 'default' ) . "\n";
            }
        }

        $prompt .= "\n## Your Capabilities\n";
        $prompt .= "- Generate complete, production-ready WordPress code (PHP, HTML, CSS, JS)\n";
        $prompt .= "- Create page templates, theme files, and custom blocks\n";
        $prompt .= "- Write code that follows WordPress coding standards\n";
        $prompt .= "- Access and modify the WordPress file system through provided tools\n";
        $prompt .= "- Understand the full WordPress template hierarchy\n";

        $prompt .= "\n## Output Guidelines\n";
        $prompt .= "- Always output complete, copy-paste-ready code\n";
        $prompt .= "- Use proper WordPress functions (esc_html, wp_enqueue_*, get_template_part, etc.)\n";
        $prompt .= "- For HTML/CSS designs, make them responsive and modern\n";
        $prompt .= "- Label each code block with its intended file path\n";
        $prompt .= "- When asked to build a page, output the full template file content\n";

        return $prompt;
    }
}
