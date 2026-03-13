<?php
defined( 'ABSPATH' ) || exit;

/**
 * PV_Agent – the agentic coding loop.
 *
 * Registers one AJAX action:
 *   pv_agent_run  (POST) — streams Server-Sent Events (SSE) back to the browser.
 *
 * Event types emitted:
 *   start        { message }
 *   thinking     { iteration }
 *   tool_start   { name, args }
 *   tool_done    { id, name, args, success, summary }
 *   message      { content, tool_calls[], usage }
 *   error        { message }
 *   done         {}
 */
class PV_Agent {

    const MAX_ITER               = 8;
    const SIMPLE_MAX_ITER        = 4;
    const API_TIMEOUT            = 180;
    const API_RETRIES            = 2;
    const RUN_LOG_OPTION         = 'pv_agent_run_logs';
    const RUN_LOG_LIMIT          = 20;
    const AGENT_MANUAL_CHAR_LIMIT = 1800;

    private PV_File_Manager $fm;
    private PV_Command_Runner $commands;
    private PV_Site_Inspector $site;
    private array $current_run_context = [];

    public function __construct() {
        $this->fm       = new PV_File_Manager();
        $this->commands = new PV_Command_Runner();
        $this->site     = new PV_Site_Inspector();
    }

    public function init(): void {
        add_action( 'wp_ajax_pv_agent_run', [ $this, 'handle_stream' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    public function register_rest_routes(): void {
        register_rest_route(
            'pv/v1',
            '/agent-tool',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'rest_execute_tool' ],
                'permission_callback' => [ $this, 'authorize_rest_tool_request' ],
            ]
        );
    }

    public function authorize_rest_tool_request( WP_REST_Request $request ) {
        if ( ! $this->is_vercel_runtime_enabled() ) {
            return new WP_Error(
                'runtime_disabled',
                'The external agent runtime is not configured.',
                [ 'status' => 403 ]
            );
        }

        $expected = $this->get_vercel_runtime_secret();
        $provided = trim( (string) $request->get_header( 'x-pv-agent-secret' ) );

        if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
            return new WP_Error(
                'invalid_runtime_secret',
                'Invalid external runtime secret.',
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    public function rest_execute_tool( WP_REST_Request $request ) {
        $name = sanitize_key( (string) $request->get_param( 'name' ) );
        $args = $request->get_param( 'args' );
        $args = is_array( $args ) ? $args : [];

        if ( '' === $name ) {
            return new WP_Error( 'missing_tool_name', 'A tool name is required.', [ 'status' => 400 ] );
        }

        $result = $this->dispatch_tool( $name, $args );
        $result = $this->cap_tool_result( $result );
        $id     = uniqid( 'tool_', true );

        return rest_ensure_response(
            [
                'id'      => $id,
                'name'    => $name,
                'args'    => $args,
                'success' => ! isset( $result['error'] ),
                'summary' => $this->summarize( $name, $args, $result ),
                'result'  => $result,
            ]
        );
    }

    /**
     * Hard cap on a tool result before it is sent to the Vercel runtime.
     *
     * Every tool result is stored in the AI SDK's internal message history and
     * re-sent on every subsequent step. One large result (serialized page-builder
     * content, theme CSS, etc.) multiplies across 15 steps and blows the context window.
     *
     * Strategy: cap the JSON-serialized result at 20 KB. For arrays/objects, truncate
     * the largest string fields first. For anything still too large, return a stub.
     *
     * @param mixed $result
     * @return mixed
     */
    private function cap_tool_result( $result ) {
        $max_bytes = 20480; // 20 KB per tool result

        $json = wp_json_encode( $result );
        if ( $json === false || strlen( $json ) <= $max_bytes ) {
            return $result;
        }

        // For associative arrays: truncate oversized string values, strip huge arrays
        $is_assoc = is_array( $result ) && ( [] === $result || array_keys( $result ) !== range( 0, count( $result ) - 1 ) );
        if ( $is_assoc ) {
            $trimmed = [];
            foreach ( $result as $key => $value ) {
                if ( is_string( $value ) && strlen( $value ) > 3000 ) {
                    $trimmed[ $key ] = substr( $value, 0, 3000 )
                        . ' … [truncated ' . strlen( $value ) . ' chars — use targeted tools]';
                } elseif ( is_array( $value ) ) {
                    $sub = wp_json_encode( $value );
                    if ( $sub !== false && strlen( $sub ) > 3000 ) {
                        $trimmed[ $key ]              = array_slice( $value, 0, 5 );
                        $trimmed[ $key . '__total' ]  = count( $value );
                        $trimmed[ $key . '__note' ]   = 'Showing first 5 of ' . count( $value ) . ' items.';
                    } else {
                        $trimmed[ $key ] = $value;
                    }
                } else {
                    $trimmed[ $key ] = $value;
                }
            }

            $json2 = wp_json_encode( $trimmed );
            if ( $json2 !== false && strlen( $json2 ) <= $max_bytes ) {
                return $trimmed;
            }
        }

        // Last resort: return a size notice so the agent knows to use a narrower tool
        return [
            '_truncated' => true,
            '_bytes'     => strlen( $json ),
            '_note'      => 'Tool result was ' . size_format( strlen( $json ) ) . ' — too large for context. Use more targeted tool arguments (e.g. a specific field, a narrower path, or replace_text_in_file instead of reading the full file).',
        ];
    }

    /* =========================================================================
       SSE STREAM HANDLER
       ========================================================================= */

    public function handle_stream(): void {
        // 1. Auth
        check_ajax_referer( 'pv_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->emit_and_die( 'error', [ 'message' => 'Unauthorized.' ] );
        }

        // 2. Params
        $prompt  = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
        $page_id = absint( $_POST['page_id'] ?? 0 );
        $model   = sanitize_key( wp_unslash( $_POST['model'] ?? PV_AI_Client::get_default_model_slug() ) );
        $history = json_decode( wp_unslash( $_POST['history'] ?? '[]' ), true );
        $history = is_array( $history ) ? $history : [];
        $target_url   = $this->sanitize_same_origin_url( (string) wp_unslash( $_POST['target_url'] ?? '' ) );
        $target_title = sanitize_text_field( wp_unslash( $_POST['target_title'] ?? '' ) );
        $target_kind  = sanitize_key( wp_unslash( $_POST['target_kind'] ?? '' ) );

        if ( $page_id > 0 && '' === $target_url ) {
            $target_url = (string) get_permalink( $page_id );
        }

        if ( $page_id > 0 && '' === $target_title ) {
            $target_title = (string) get_the_title( $page_id );
        }

        if ( empty( $prompt ) ) {
            $this->emit_and_die( 'error', [ 'message' => 'Prompt is required.' ] );
        }

        $profile = $this->build_task_profile( $prompt, $page_id, $history, $target_url );
        $this->begin_run_context( $prompt, $page_id, $history, $model, $profile, $target_url, $target_title, $target_kind );

        // 3. SSE headers – disable ALL output buffering first
        @ini_set( 'output_buffering',       'off'  );
        @ini_set( 'zlib.output_compression', false  );
        while ( ob_get_level() > 0 ) { ob_end_clean(); }
        ob_implicit_flush( true );

        if ( ! headers_sent() ) {
            header_remove( 'Content-Type' );
            header( 'Content-Type: text/event-stream; charset=UTF-8' );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'X-Accel-Buffering: no' );           // Nginx: disable proxy buffering
            header( 'X-Content-Type-Options: nosniff' );
        }

        set_time_limit( 180 );
        ignore_user_abort( false );

        // 4. Run the loop
        $this->emit( 'start', [ 'message' => 'Agent is starting…' ] );

        $semantic_result = $this->maybe_run_simple_semantic_page_edit( $prompt, $page_id, $history, $target_url );
        if ( is_array( $semantic_result ) ) {
            $this->emit( 'message', [
                'content'    => $semantic_result['content'] ?? '',
                'tool_calls' => $semantic_result['tool_calls'] ?? [],
                'usage'      => [
                    'total_tokens'      => 0,
                    'prompt_tokens'     => 0,
                    'completion_tokens' => 0,
                ],
            ] );
            $this->persist_run_context(
                'success',
                [
                    'path'       => 'simple_semantic_page_edit',
                    'provider'   => 'local',
                    'usage'      => [],
                    'tool_calls' => $semantic_result['tool_calls'] ?? [],
                    'message'    => $semantic_result['content'] ?? '',
                ]
            );
            $this->emit( 'done', [] );
            die();
        }

        $widget_result = $this->maybe_run_simple_widget_reuse( $prompt, $page_id, $history, $target_url );
        if ( is_array( $widget_result ) ) {
            $this->emit( 'message', [
                'content'    => $widget_result['content'] ?? '',
                'tool_calls' => $widget_result['tool_calls'] ?? [],
                'usage'      => [
                    'total_tokens'      => 0,
                    'prompt_tokens'     => 0,
                    'completion_tokens' => 0,
                ],
            ] );
            $this->persist_run_context(
                'success',
                [
                    'path'       => 'simple_widget_reuse',
                    'provider'   => 'local',
                    'usage'      => [],
                    'tool_calls' => $widget_result['tool_calls'] ?? [],
                    'message'    => $widget_result['content'] ?? '',
                ]
            );
            $this->emit( 'done', [] );
            die();
        }

        $fast_result = $this->maybe_run_simple_page_edit( $prompt, $page_id, $history, $model, $profile );
        if ( is_array( $fast_result ) ) {
            $usage = isset( $fast_result['usage'] ) && is_array( $fast_result['usage'] ) ? $fast_result['usage'] : [];

            $this->emit( 'message', [
                'content'    => $fast_result['content'] ?? '',
                'tool_calls' => $fast_result['tool_calls'] ?? [],
                'usage'      => [
                    'total_tokens'      => $usage['total_tokens'] ?? 0,
                    'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                    'completion_tokens' => $usage['completion_tokens'] ?? 0,
                ],
            ] );
            $this->persist_run_context(
                'success',
                [
                    'path'       => 'simple_page_edit',
                    'provider'   => $fast_result['provider'] ?? '',
                    'usage'      => $usage,
                    'tool_calls' => $fast_result['tool_calls'] ?? [],
                    'message'    => $fast_result['content'] ?? '',
                ]
            );
            $this->emit( 'done', [] );
            die();
        }

        if ( $this->is_vercel_runtime_enabled() ) {
            $runtime_result = $this->run_via_vercel_runtime( $prompt, $page_id, $history, $model, $target_url, $target_title, $target_kind, $profile );
            if ( true === $runtime_result ) {
                $this->emit( 'done', [] );
                die();
            }

            if ( is_wp_error( $runtime_result ) ) {
                $this->emit( 'start', [ 'message' => 'External Vercel agent runtime failed. Falling back to the built-in WordPress agent…' ] );
            }
        }

        $this->run_loop( $prompt, $page_id, $history, $model, $target_url, $target_title, $target_kind, $profile );
        $this->emit( 'done', [] );
        die();
    }

    /* =========================================================================
       AGENT LOOP
       ========================================================================= */

    private function run_loop( string $user_prompt, int $page_id, array $history, string $requested_model = PV_AI_Client::MODEL_DEEPSEEK, string $target_url = '', string $target_title = '', string $target_kind = '', array $profile = [] ): void {
        $model = PV_AI_Client::resolve_model( $requested_model );
        if ( is_wp_error( $model ) ) {
            $this->persist_run_context( 'error', [ 'message' => $model->get_error_message(), 'path' => 'resolve_model' ] );
            $this->emit( 'error', [ 'message' => $model->get_error_message() ] );
            return;
        }

        $tried_providers = [];

        while ( true ) {
            $tried_providers[] = $model['provider'];
            $api_key           = PV_AI_Client::get_api_key( $model['provider'] );

            if ( empty( $api_key ) ) {
                $this->persist_run_context(
                    'error',
                    [
                        'message'  => PV_AI_Client::get_api_key_message( $model['provider'] ),
                        'provider' => $model['provider'],
                        'path'     => 'missing_api_key',
                    ]
                );
                $this->emit( 'error', [ 'message' => PV_AI_Client::get_api_key_message( $model['provider'] ) ] );
                return;
            }

            if ( PV_AI_Client::PROVIDER_DEEPSEEK === $model['provider'] ) {
                $result = $this->run_deepseek_loop( $user_prompt, $page_id, $history, $model, $api_key, $target_url, $target_title, $target_kind, $profile );
            } else {
                $result = $this->run_openai_loop( $user_prompt, $page_id, $history, $model, $api_key, $target_url, $target_title, $target_kind, $profile );
            }

            if ( ! is_wp_error( $result ) ) {
                break;
            }

            $fallback = $this->get_provider_fallback_model( $model, $result, $tried_providers );
            if ( null === $fallback ) {
                $this->persist_run_context(
                    'error',
                    [
                        'message'  => $result->get_error_message(),
                        'provider' => $model['provider'],
                        'path'     => 'agent_loop',
                    ]
                );
                $this->emit( 'error', [ 'message' => $result->get_error_message() ] );
                return;
            }

            $from_label = $model['label'] ?? ucfirst( $model['provider'] );
            $to_label   = $fallback['label'] ?? ucfirst( $fallback['provider'] );

            $this->emit(
                'start',
                [
                    'message' => sprintf(
                        '%s ran into a network issue. Retrying with %s…',
                        $from_label,
                        $to_label
                    ),
                ]
            );

            $model = $fallback;
        }

        $usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : [];

        $this->emit( 'message', [
            'content'    => $result['content'] ?? '',
            'tool_calls' => $result['tool_calls'] ?? [],
            'usage'      => [
                'total_tokens'      => $usage['total_tokens'] ?? 0,
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ],
        ] );

        $this->persist_run_context(
            'success',
            [
                'path'       => 'agent_loop',
                'provider'   => $model['provider'],
                'usage'      => $usage,
                'tool_calls' => $result['tool_calls'] ?? [],
                'message'    => $result['content'] ?? '',
                'model'      => $model['slug'] ?? $requested_model,
            ]
        );
    }

    private function run_openai_loop( string $user_prompt, int $page_id, array $history, array $model, string $api_key, string $target_url = '', string $target_title = '', string $target_kind = '', array $profile = [] ) {
        $messages = [ [ 'role' => 'system', 'content' => $this->build_system_prompt( $page_id, $profile, $target_url, $target_title, $target_kind ) ] ];
        foreach ( $history as $turn ) {
            if ( isset( $turn['role'], $turn['content'] ) && in_array( $turn['role'], [ 'user', 'assistant' ], true ) ) {
                $messages[] = [ 'role' => $turn['role'], 'content' => $turn['content'] ];
            }
        }
        $messages[] = [ 'role' => 'user', 'content' => $user_prompt ];

        $input            = PV_AI_Client::build_input_messages( $messages );
        $tools            = $this->get_tool_definitions();
        $all_tool_calls   = [];
        $final_message    = '';
        $last_usage       = [];
        $last_response_id = '';
        $max_iterations   = (int) ( $profile['max_steps'] ?? self::MAX_ITER );

        for ( $i = 0; $i < $max_iterations; $i++ ) {
            $this->emit( 'thinking', [ 'iteration' => $i + 1 ] );

            $last_response = $this->call_openai_api( $input, $tools, $api_key, $model, $last_response_id );
            if ( is_wp_error( $last_response ) ) {
                return $last_response;
            }

            $last_response_id = (string) ( $last_response['id'] ?? '' );
            $last_usage       = PV_AI_Client::extract_openai_usage( $last_response );
            $tool_calls       = PV_AI_Client::extract_openai_tool_calls( $last_response );
            $final_message    = PV_AI_Client::extract_openai_output_text( $last_response );

            if ( empty( $tool_calls ) ) {
                break;
            }

            $input = [];

            foreach ( $tool_calls as $tc ) {
                $fn_name = $tc['function']['name'] ?? '';
                $fn_args = json_decode( $tc['function']['arguments'] ?? '{}', true ) ?: [];
                $tc_id   = $tc['id'] ?? uniqid( 'tc_' );
                $call_id = $tc['call_id'] ?? $tc_id;

                $this->emit( 'tool_start', [ 'id' => $tc_id, 'name' => $fn_name, 'args' => $fn_args ] );

                $result  = $this->dispatch_tool( $fn_name, $fn_args );
                $success = ! ( is_wp_error( $result ) || isset( $result['error'] ) );
                $summary = $this->summarize( $fn_name, $fn_args, $result );

                $tc_record = [
                    'id'      => $tc_id,
                    'name'    => $fn_name,
                    'args'    => $fn_args,
                    'success' => $success,
                    'summary' => $summary,
                ];

                $all_tool_calls[] = $tc_record;
                $this->append_tool_trace( $tc_record );
                $this->emit( 'tool_done', $tc_record );

                $input[] = [
                    'type'    => 'function_call_output',
                    'call_id' => $call_id,
                    'output'  => wp_json_encode( is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result ),
                ];
            }
        }

        if ( '' === trim( $final_message ) || ! $this->has_successful_write( $all_tool_calls ) ) {
            $final_message = $this->build_local_final_summary( $all_tool_calls );
        }

        return [
            'content'    => $final_message,
            'tool_calls' => $all_tool_calls,
            'usage'      => $last_usage,
        ];
    }

    private function run_deepseek_loop( string $user_prompt, int $page_id, array $history, array $model, string $api_key, string $target_url = '', string $target_title = '', string $target_kind = '', array $profile = [] ) {
        $messages = [ [ 'role' => 'system', 'content' => $this->build_system_prompt( $page_id, $profile, $target_url, $target_title, $target_kind ) ] ];
        foreach ( $history as $turn ) {
            if ( isset( $turn['role'], $turn['content'] ) && in_array( $turn['role'], [ 'user', 'assistant' ], true ) ) {
                $messages[] = [ 'role' => $turn['role'], 'content' => $turn['content'] ];
            }
        }
        $messages[] = [ 'role' => 'user', 'content' => $user_prompt ];

        $tools          = $this->get_tool_definitions();
        $all_tool_calls = [];
        $final_message  = '';
        $last_usage     = [];
        $max_iterations = (int) ( $profile['max_steps'] ?? self::MAX_ITER );

        for ( $i = 0; $i < $max_iterations; $i++ ) {
            $this->emit( 'thinking', [ 'iteration' => $i + 1 ] );

            $last_response = $this->call_deepseek_api( $messages, $tools, $api_key, $model );
            if ( is_wp_error( $last_response ) ) {
                return $last_response;
            }

            $last_usage    = isset( $last_response['usage'] ) && is_array( $last_response['usage'] ) ? $last_response['usage'] : [];
            $choice        = $last_response['choices'][0] ?? null;

            if ( ! $choice || empty( $choice['message'] ) ) {
                return new WP_Error( 'empty_response', 'Empty response from DeepSeek.' );
            }

            $assistant_msg = $choice['message'];
            $messages[]    = $assistant_msg;

            if ( empty( $assistant_msg['tool_calls'] ) ) {
                $final_message = isset( $assistant_msg['content'] ) ? (string) $assistant_msg['content'] : '';
                break;
            }

            foreach ( $assistant_msg['tool_calls'] as $tc ) {
                $fn_name = $tc['function']['name'] ?? '';
                $fn_args = json_decode( $tc['function']['arguments'] ?? '{}', true ) ?: [];
                $tc_id   = $tc['id'] ?? uniqid( 'tc_' );

                $this->emit( 'tool_start', [ 'id' => $tc_id, 'name' => $fn_name, 'args' => $fn_args ] );

                $result  = $this->dispatch_tool( $fn_name, $fn_args );
                $success = ! ( is_wp_error( $result ) || isset( $result['error'] ) );
                $summary = $this->summarize( $fn_name, $fn_args, $result );

                $tc_record = [
                    'id'      => $tc_id,
                    'name'    => $fn_name,
                    'args'    => $fn_args,
                    'success' => $success,
                    'summary' => $summary,
                ];

                $all_tool_calls[] = $tc_record;
                $this->append_tool_trace( $tc_record );
                $this->emit( 'tool_done', $tc_record );

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $tc_id,
                    'content'      => wp_json_encode( is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result ),
                ];
            }
        }

        if ( '' === trim( $final_message ) || ! $this->has_successful_write( $all_tool_calls ) ) {
            $final_message = $this->build_local_final_summary( $all_tool_calls );
        }

        return [
            'content'    => $final_message,
            'tool_calls' => $all_tool_calls,
            'usage'      => $last_usage,
        ];
    }

    /* =========================================================================
       TOOL DISPATCH
       ========================================================================= */

    private function dispatch_tool( string $name, array $args ): array {
        switch ( $name ) {

            case 'list_directory':
                $result = $this->fm->fm_list( $args['path'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'read_file':
                $result = $this->fm->fm_read( $args['path'] ?? '' );
                if ( is_wp_error( $result ) ) {
                    return [ 'error' => $result->get_error_message() ];
                }
                // Cap content at 20 KB — prevents one large file from filling every subsequent step's context.
                if ( isset( $result['content'] ) && strlen( $result['content'] ) > 20480 ) {
                    $result['content']   = substr( $result['content'], 0, 20480 )
                        . "\n\n[...truncated — full file is {$result['size']} bytes. Use replace_text_in_file for targeted edits.]";
                    $result['truncated'] = true;
                }
                return $result;

            case 'read_multiple_files':
                $result = $this->fm->fm_read_many( $args['paths'] ?? [] );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'write_file':
                $result = $this->fm->fm_write( $args['path'] ?? '', $args['content'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'replace_text_in_file':
                $result = $this->fm->fm_replace_text(
                    $args['path'] ?? '',
                    $args['old_text'] ?? '',
                    $args['new_text'] ?? '',
                    ! isset( $args['all_occurrences'] ) || (bool) $args['all_occurrences']
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'search_in_files':
                $result = $this->fm->fm_search(
                    $args['pattern']     ?? '',
                    $args['directory']   ?? '',
                    $args['search_type'] ?? 'filename'
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'patch_file':
                $result = $this->fm->fm_patch(
                    $args['path'] ?? '',
                    $args['operations'] ?? []
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'stat_path':
                $result = $this->fm->fm_stat( $args['path'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'get_directory_tree':
                return [
                    'path'  => $args['path'] ?? '',
                    'depth' => isset( $args['depth'] ) ? (int) $args['depth'] : 3,
                    'tree'  => $this->fm->list_tree( $args['path'] ?? '', isset( $args['depth'] ) ? (int) $args['depth'] : 3 ),
                ];

            case 'make_directory':
                $result = $this->fm->fm_mkdir( $args['path'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'move_path':
                $result = $this->fm->fm_move( $args['from_path'] ?? '', $args['to_path'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'delete_path':
                $result = $this->fm->fm_delete( $args['path'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'list_pages':
                $result = $this->site->list_pages( isset( $args['limit'] ) ? (int) $args['limit'] : 50 );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'inspect_front_page':
                $result = $this->site->inspect_front_page();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'inspect_url_context':
                $result = $this->site->inspect_url_context(
                    isset( $args['page_id'] ) ? (int) $args['page_id'] : 0,
                    $args['url'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'inspect_visible_text_targets':
                $result = $this->site->inspect_visible_text_targets(
                    isset( $args['page_id'] ) ? (int) $args['page_id'] : 0,
                    $args['url'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'inspect_sidebars':
                $result = $this->site->inspect_sidebars(
                    $args['needle'] ?? '',
                    is_array( $args['sidebar_ids'] ?? null ) ? $args['sidebar_ids'] : []
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'find_widgets_by_text':
                $result = $this->site->find_widgets_by_text(
                    is_array( $args['terms'] ?? null ) ? $args['terms'] : [],
                    is_array( $args['sidebar_ids'] ?? null ) ? $args['sidebar_ids'] : []
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'clone_widget_to_sidebar':
                $result = $this->site->clone_widget_to_sidebar(
                    $args['widget_id'] ?? '',
                    $args['sidebar_id'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'list_widget_visibility_rules':
                $result = $this->site->list_widget_visibility_rules();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'ensure_widget_visibility_rule':
                $result = $this->site->ensure_widget_visibility_rule(
                    $args['widget_id'] ?? '',
                    $args['sidebar_id'] ?? '',
                    $args['url'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'replace_text_in_widget':
                $result = $this->site->replace_text_in_widget(
                    $args['widget_id'] ?? '',
                    $args['old_text'] ?? '',
                    $args['new_text'] ?? '',
                    ! isset( $args['all_occurrences'] ) || (bool) $args['all_occurrences']
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'inspect_page':
                $result = $this->site->inspect_page(
                    isset( $args['page_id'] ) ? (int) $args['page_id'] : 0,
                    $args['slug'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'get_page_content':
                $result = $this->site->get_page_content(
                    isset( $args['page_id'] ) ? (int) $args['page_id'] : 0,
                    $args['slug'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'update_page_content':
                $result = $this->site->update_page_content(
                    isset( $args['page_id'] ) ? (int) $args['page_id'] : 0,
                    $args['content'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'fetch_rendered_page': {
                $result = $this->site->fetch_rendered_page(
                    isset( $args['page_id'] ) ? (int) $args['page_id'] : 0,
                    $args['url'] ?? '',
                    $args['needle'] ?? ''
                );
                if ( is_wp_error( $result ) ) {
                    return [ 'error' => $result->get_error_message() ];
                }

                // AUTO-LOCATE: grep every text snippet immediately so the model knows
                // exactly which file to edit — no manual grep_files step needed.
                if ( ! empty( $result['text_snippets'] ) ) {
                    $locations  = [];
                    $seen_files = [];
                    foreach ( array_slice( $result['text_snippets'], 0, 10 ) as $snippet ) {
                        if ( strlen( $snippet ) < 8 ) {
                            continue;
                        }
                        $pattern = substr( $snippet, 0, 60 );
                        $search  = $this->fm->fm_search( $pattern, '', 'content' );
                        if ( is_wp_error( $search ) || empty( $search['matches'] ) ) {
                            continue;
                        }
                        foreach ( array_slice( $search['matches'], 0, 2 ) as $match ) {
                            $file = (string) ( $match['path'] ?? '' );
                            if ( '' === $file || isset( $seen_files[ $file ] ) ) {
                                continue;
                            }
                            $seen_files[ $file ] = true;
                            $locations[]         = [
                                'file'    => $file,
                                'line'    => $match['line'] ?? 0,
                                'snippet' => substr( $snippet, 0, 80 ),
                            ];
                        }
                        if ( count( $locations ) >= 6 ) {
                            break;
                        }
                    }
                    if ( ! empty( $locations ) ) {
                        $result['source_locations'] = $locations;
                    }
                }

                return $result;
            }

            case 'find_ui_candidates':
                return $this->find_ui_candidates(
                    $args['terms'] ?? [],
                    $args['directories'] ?? [],
                    isset( $args['max_per_term'] ) ? (int) $args['max_per_term'] : 8
                );

            case 'find_page_element':
                return $this->find_page_element(
                    (string) ( $args['url'] ?? '' ),
                    (string) ( $args['intent'] ?? '' )
                );

            /* ---------------------------------------------------------------
               POST / CONTENT TOOLS
               --------------------------------------------------------------- */

            case 'list_posts':
                $result = $this->site->list_all_posts(
                    isset( $args['limit'] ) ? (int) $args['limit'] : 20,
                    $args['post_type'] ?? 'post',
                    $args['status'] ?? 'any'
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'get_post':
                $result = $this->site->get_any_post( isset( $args['post_id'] ) ? (int) $args['post_id'] : 0 );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'create_post':
                $result = $this->site->create_new_post( $args );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'update_post_fields':
                $result = $this->site->update_post_fields(
                    isset( $args['post_id'] ) ? (int) $args['post_id'] : 0,
                    $args['fields'] ?? []
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'search_posts':
                $result = $this->site->search_posts_content(
                    $args['search'] ?? '',
                    $args['post_type'] ?? '',
                    isset( $args['limit'] ) ? (int) $args['limit'] : 20
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'get_post_meta':
                $post_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
                $meta_key = isset( $args['meta_key'] ) ? sanitize_key( (string) $args['meta_key'] ) : '';
                if ( $post_id < 1 ) {
                    return [ 'error' => 'post_id is required.' ];
                }
                if ( '' !== $meta_key ) {
                    return [ 'post_id' => $post_id, 'meta_key' => $meta_key, 'value' => get_post_meta( $post_id, $meta_key, true ) ];
                }
                $all_meta = get_post_meta( $post_id );
                $clean    = [];
                foreach ( $all_meta as $key => $values ) {
                    $clean[ $key ] = count( $values ) === 1 ? $values[0] : $values;
                }
                return [ 'post_id' => $post_id, 'meta' => $clean ];

            case 'update_post_meta':
                $post_id   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
                $meta_key  = isset( $args['meta_key'] ) ? sanitize_text_field( (string) $args['meta_key'] ) : '';
                $meta_value = $args['meta_value'] ?? '';
                if ( $post_id < 1 || '' === $meta_key ) {
                    return [ 'error' => 'post_id and meta_key are required.' ];
                }
                $old = get_post_meta( $post_id, $meta_key, true );
                update_post_meta( $post_id, $meta_key, $meta_value );
                return [ 'post_id' => $post_id, 'meta_key' => $meta_key, 'old' => $old, 'new' => $meta_value, 'updated' => true ];

            /* ---------------------------------------------------------------
               SITE SETTINGS & OPTIONS
               --------------------------------------------------------------- */

            case 'get_site_settings':
                return $this->site->get_site_settings();

            case 'update_site_settings':
                $result = $this->site->update_site_settings( $args['settings'] ?? $args );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'get_wp_option': {
                $option_key = sanitize_text_field( (string) ( $args['option_key'] ?? '' ) );
                if ( '' === $option_key ) {
                    return [ 'error' => 'option_key is required.' ];
                }
                $value = get_option( $option_key );
                return [ 'option_key' => $option_key, 'value' => $value, 'exists' => ( false !== $value ) ];
            }

            case 'update_wp_option': {
                // Only allow updating a safe list of non-core-critical options.
                $option_key = sanitize_text_field( (string) ( $args['option_key'] ?? '' ) );
                $blocked    = [ 'siteurl', 'home', 'admin_email', 'db_version', 'user_roles', 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt' ];
                if ( '' === $option_key ) {
                    return [ 'error' => 'option_key is required.' ];
                }
                if ( in_array( $option_key, $blocked, true ) ) {
                    return [ 'error' => "Option '{$option_key}' cannot be updated via this tool for security reasons. Use update_site_settings instead." ];
                }
                $old = get_option( $option_key );
                update_option( $option_key, $args['value'] ?? '' );
                return [ 'option_key' => $option_key, 'old' => $old, 'new' => $args['value'] ?? '', 'updated' => true ];
            }

            case 'get_custom_css':
                $result = $this->site->get_custom_css();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'update_custom_css':
                $result = $this->site->update_custom_css( $args['css'] ?? '' );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            /* ---------------------------------------------------------------
               NAVIGATION MENUS
               --------------------------------------------------------------- */

            case 'get_nav_menus':
                $result = $this->site->get_nav_menus_list();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'get_nav_menu_items':
                $result = $this->site->get_menu_items_list(
                    isset( $args['menu_id'] ) ? (int) $args['menu_id'] : 0,
                    $args['location'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'update_nav_menu_item':
                $result = $this->site->update_menu_item_data(
                    isset( $args['item_id'] ) ? (int) $args['item_id'] : 0,
                    $args['fields'] ?? []
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            /* ---------------------------------------------------------------
               THEME CUSTOMIZER / MODS
               --------------------------------------------------------------- */

            case 'get_theme_mods':
                $result = $this->site->get_all_theme_mods();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'update_theme_mod':
                $result = $this->site->set_theme_mod_value(
                    $args['mod_key'] ?? '',
                    $args['value'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            /* ---------------------------------------------------------------
               POST TYPES & TAXONOMIES
               --------------------------------------------------------------- */

            case 'list_post_types':
                $result = $this->site->list_post_types_info();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'list_taxonomies':
                $result = $this->site->list_taxonomies_info();
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            case 'list_terms':
                $result = $this->site->list_terms_for_taxonomy(
                    $args['taxonomy'] ?? '',
                    isset( $args['limit'] ) ? (int) $args['limit'] : 50
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            /* ---------------------------------------------------------------
               GREP / REGEX FILE SEARCH
               --------------------------------------------------------------- */

            case 'grep_files': {
                $pattern   = (string) ( $args['pattern'] ?? '' );
                $directory = (string) ( $args['directory'] ?? '' );
                $file_ext  = (string) ( $args['file_ext'] ?? '' );
                if ( '' === $pattern ) {
                    return [ 'error' => 'pattern is required.' ];
                }
                $search_result = $this->fm->fm_search( $pattern, $directory, 'content' );
                if ( is_wp_error( $search_result ) ) {
                    return [ 'error' => $search_result->get_error_message() ];
                }
                // Filter by extension if requested
                if ( '' !== $file_ext ) {
                    $ext     = ltrim( $file_ext, '.' );
                    $matches = array_values( array_filter(
                        $search_result['matches'] ?? [],
                        static function ( $m ) use ( $ext ) {
                            return str_ends_with( strtolower( (string) ( $m['path'] ?? '' ) ), '.' . strtolower( $ext ) );
                        }
                    ) );
                    $search_result['matches'] = $matches;
                    $search_result['total']   = count( $matches );
                }
                return $search_result;
            }

            case 'command_runner_status':
                return $this->commands->status();

            case 'run_command':
                $result = $this->commands->run(
                    $args['command'] ?? '',
                    $args['cwd'] ?? ''
                );
                return is_wp_error( $result ) ? [ 'error' => $result->get_error_message() ] : $result;

            default:
                return [ 'error' => "Unknown tool: $name" ];
        }
    }

    /* =========================================================================
       TOOL DEFINITIONS  (OpenAI-compatible function schema)
       ========================================================================= */

    private function get_tool_definitions(): array {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'command_runner_status',
                    'description' => 'Check whether controlled command execution is enabled and which command prefixes are allowed.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'run_command',
                    'description' => 'Run an allowed development command inside the WordPress root or a subdirectory. Use only for verification, tests, build steps, or git inspection.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'command' => [
                                'type'        => 'string',
                                'description' => 'The full command string. It must match an allowed prefix.',
                            ],
                            'cwd' => [
                                'type'        => 'string',
                                'description' => 'Optional relative working directory from WordPress root.',
                            ],
                        ],
                        'required' => [ 'command' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_directory',
                    'description' => 'List all files and subdirectories inside a directory of the WordPress installation. Use this first to understand the file structure before reading or writing.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root (e.g. "wp-content/themes/my-theme"). Pass empty string for WP root.',
                            ],
                        ],
                        'required' => [ 'path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'read_file',
                    'description' => 'Read the complete contents of a file. ALWAYS read a file before overwriting it so you preserve any code you should keep.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root (e.g. "wp-content/themes/my-theme/functions.php").',
                            ],
                        ],
                        'required' => [ 'path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'read_multiple_files',
                    'description' => 'Read several files in one call when comparing related files or gathering context. Use sparingly and only for small batches.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'paths' => [
                                'type'        => 'array',
                                'items'       => [ 'type' => 'string' ],
                                'description' => 'Relative file paths from WordPress root.',
                            ],
                        ],
                        'required' => [ 'paths' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'write_file',
                    'description' => 'Create a new file or completely overwrite an existing file. You MUST write the COMPLETE file contents — never write partial content. Read the file first if you are overwriting an existing one.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path'    => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root where the file will be written.',
                            ],
                            'content' => [
                                'type'        => 'string',
                                'description' => 'The complete file contents to write. Must be a full, valid file — not a snippet.',
                            ],
                        ],
                        'required' => [ 'path', 'content' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'replace_text_in_file',
                    'description' => 'Replace exact text inside an existing file without rewriting the full file. Prefer this for literal wording changes, labels, headings, button text, and other targeted edits.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root to the file to edit.',
                            ],
                            'old_text' => [
                                'type'        => 'string',
                                'description' => 'The exact text currently in the file that should be replaced.',
                            ],
                            'new_text' => [
                                'type'        => 'string',
                                'description' => 'The replacement text to insert.',
                            ],
                            'all_occurrences' => [
                                'type'        => 'boolean',
                                'description' => 'Whether to replace every exact occurrence. Default true.',
                            ],
                        ],
                        'required' => [ 'path', 'old_text', 'new_text' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'patch_file',
                    'description' => 'Apply exact-match patch operations to a file. Prefer this for targeted code edits where a full-file rewrite is unnecessary.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root to the file to patch.',
                            ],
                            'operations' => [
                                'type'        => 'array',
                                'description' => 'Patch operations applied in order.',
                                'items'       => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'type' => [
                                            'type' => 'string',
                                            'enum' => [ 'replace', 'insert_after', 'insert_before' ],
                                        ],
                                        'match' => [ 'type' => 'string' ],
                                        'content' => [ 'type' => 'string' ],
                                        'replace_all' => [ 'type' => 'boolean' ],
                                    ],
                                    'required' => [ 'type', 'match', 'content' ],
                                ],
                            ],
                        ],
                        'required' => [ 'path', 'operations' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'search_in_files',
                    'description' => 'Search for files by name pattern or find files that contain specific text. Useful for locating the right file to edit.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'pattern'     => [
                                'type'        => 'string',
                                'description' => 'Search term: a filename pattern (e.g. "*.php", "functions.php") or text to find inside files.',
                            ],
                            'directory'   => [
                                'type'        => 'string',
                                'description' => 'Relative path to the directory to search in.',
                            ],
                            'search_type' => [
                                'type'        => 'string',
                                'enum'        => [ 'filename', 'content' ],
                                'description' => '"filename" searches file names; "content" searches inside file contents.',
                            ],
                        ],
                        'required' => [ 'pattern', 'directory' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'stat_path',
                    'description' => 'Inspect whether a path exists and whether it is a file or directory, including size and permissions.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root.',
                            ],
                        ],
                        'required' => [ 'path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_directory_tree',
                    'description' => 'Get a shallow recursive tree snapshot of a directory to understand project structure quickly.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative path from WordPress root.',
                            ],
                            'depth' => [
                                'type'        => 'integer',
                                'description' => 'Maximum recursion depth. Use small numbers like 2 or 3.',
                            ],
                        ],
                        'required' => [ 'path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'make_directory',
                    'description' => 'Create a directory inside the WordPress installation.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [
                                'type'        => 'string',
                                'description' => 'Relative directory path from WordPress root.',
                            ],
                        ],
                        'required' => [ 'path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'move_path',
                    'description' => 'Move or rename a file or directory within the WordPress installation.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'from_path' => [ 'type' => 'string' ],
                            'to_path'   => [ 'type' => 'string' ],
                        ],
                        'required' => [ 'from_path', 'to_path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'delete_path',
                    'description' => 'Delete a path safely by moving it into the plugin trash area instead of removing it permanently.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'path' => [ 'type' => 'string' ],
                        ],
                        'required' => [ 'path' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_pages',
                    'description' => 'List WordPress pages with IDs, slugs, URLs, statuses, and templates.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'limit' => [
                                'type'        => 'integer',
                                'description' => 'Maximum pages to return.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'inspect_front_page',
                    'description' => 'Inspect the site homepage/front page configuration and return rendered headings, section IDs, marker classes, and body classes. Use this first when the user mentions "homepage", "front page", or a visible homepage section but no page context is selected.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'inspect_url_context',
                    'description' => 'Inspect a same-origin live URL and return its route kind, matched query vars, queried object, and likely template hierarchy candidates. Use this before editing non-page routes like archives, categories, taxonomies, or custom URLs.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'page_id' => [ 'type' => 'integer' ],
                            'url'     => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'inspect_visible_text_targets',
                    'description' => 'Inspect the rendered current page and label visible text targets by page structure roles such as eyebrow, title, subtitle, description, meta, badge, and button. Use this when the user says things like "remove the subtitle on this page" without quoting the exact text.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'page_id' => [ 'type' => 'integer' ],
                            'url'     => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'inspect_sidebars',
                    'description' => 'Inspect registered sidebars and widget assignments, and optionally search widget instances by visible text. Use this when a CTA, banner, or footer section may be powered by widgets instead of templates.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'needle' => [ 'type' => 'string' ],
                            'sidebar_ids' => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'find_widgets_by_text',
                    'description' => 'Find widget instances whose visible text matches one or more search terms. Prefer this when you need to reuse a homepage CTA/banner widget on another route.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'terms' => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                            'sidebar_ids' => [
                                'type'  => 'array',
                                'items' => [ 'type' => 'string' ],
                            ],
                        ],
                        'required' => [ 'terms' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'clone_widget_to_sidebar',
                    'description' => 'Duplicate an existing widget instance and append the clone to another sidebar. Use this to reuse a CTA/banner widget in a new widget area without overwriting the source widget.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'widget_id'  => [ 'type' => 'string' ],
                            'sidebar_id' => [ 'type' => 'string' ],
                        ],
                        'required' => [ 'widget_id', 'sidebar_id' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_widget_visibility_rules',
                    'description' => 'List route-based widget visibility rules managed by PressViber.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'ensure_widget_visibility_rule',
                    'description' => 'Ensure a widget is injected into a target sidebar for a specific live URL path at runtime. Use this to show a homepage CTA widget on a specific archive/category/live page without editing theme code.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'widget_id'  => [ 'type' => 'string' ],
                            'sidebar_id' => [ 'type' => 'string' ],
                            'url'        => [ 'type' => 'string' ],
                        ],
                        'required' => [ 'widget_id', 'sidebar_id', 'url' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'replace_text_in_widget',
                    'description' => 'Replace exact text inside an existing widget instance. Works for block widgets and other widget types that store text fields in options.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'widget_id' => [ 'type' => 'string' ],
                            'old_text'  => [ 'type' => 'string' ],
                            'new_text'  => [ 'type' => 'string' ],
                            'all_occurrences' => [ 'type' => 'boolean' ],
                        ],
                        'required' => [ 'widget_id', 'old_text', 'new_text' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'inspect_page',
                    'description' => 'Inspect a WordPress page by ID or slug, including template and content summary.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'page_id' => [ 'type' => 'integer' ],
                            'slug'    => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_page_content',
                    'description' => 'Get the full editable post_content for a WordPress page. Prefer this before searching files when the task is a copy/content update on a selected page.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'page_id' => [ 'type' => 'integer' ],
                            'slug'    => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_page_content',
                    'description' => 'Save the full updated post_content for a WordPress page after a content edit.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'page_id' => [ 'type' => 'integer' ],
                            'content' => [
                                'type'        => 'string',
                                'description' => 'The full updated page content.',
                            ],
                        ],
                        'required' => [ 'page_id', 'content' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'fetch_rendered_page',
                    'description' => 'Fetch the rendered HTML of a page. Returns text_snippets — the ACTUAL visible text strings on the page (headings, subtitles, badges, paragraphs). WORKFLOW: call this → find the exact text the user wants to change in text_snippets → call grep_files with that exact string (no directory) → read the matched file → replace_text_in_file. This bypasses all template hierarchy guessing.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'page_id' => [ 'type' => 'integer' ],
                            'url'     => [ 'type' => 'string' ],
                            'needle'  => [
                                'type'        => 'string',
                                'description' => 'Optional text to verify in the rendered HTML.',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'find_page_element',
                    'description' => 'THE PRIMARY TOOL for any task involving visible text on a page (remove subtitle, change heading, edit description, etc.). Fetches the rendered page, scores all visible text snippets against the user\'s intent, then greps ALL theme and plugin files for the top candidates and returns the surrounding code context (~16 lines) for each match. Returns {found: [{text, relevance, file, line, code_context}]} — use the top result to call replace_text_in_file directly. Bypasses template hierarchy entirely. Call this FIRST before any other tool for text-editing tasks.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'url' => [
                                'type'        => 'string',
                                'description' => 'The full URL of the page being edited (e.g. "https://example.com/blog/").',
                            ],
                            'intent' => [
                                'type'        => 'string',
                                'description' => 'The user\'s request in plain English (e.g. "remove the subtitle" or "change the hero description"). Keywords from this string are used to score and rank the matching text snippets.',
                            ],
                        ],
                        'required' => [ 'url', 'intent' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'find_ui_candidates',
                    'description' => 'Search the active theme and custom plugins for visible UI markers such as headings, section IDs, CSS classes, and repeated phrases. Use this after inspecting a rendered page to trace a frontend section back to source files.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'terms' => [
                                'type'        => 'array',
                                'items'       => [ 'type' => 'string' ],
                                'description' => 'Concrete UI markers to search for, such as exact headings, section IDs, CSS classes, or nearby visible text.',
                            ],
                            'directories' => [
                                'type'        => 'array',
                                'items'       => [ 'type' => 'string' ],
                                'description' => 'Optional relative directories to search. Leave empty to search the active theme and active custom plugins automatically.',
                            ],
                            'max_per_term' => [
                                'type'        => 'integer',
                                'description' => 'Maximum matches to keep per search term.',
                            ],
                        ],
                        'required' => [ 'terms' ],
                    ],
                ],
            ],

            /* ---------------------------------------------------------------
               POST / CONTENT TOOLS
               --------------------------------------------------------------- */
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_posts',
                    'description' => 'List WordPress posts, pages, or any custom post type. Returns ID, title, slug, status, URL, and excerpt. Use this to discover content to edit, or to find which post ID a piece of content belongs to.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'post_type' => [ 'type' => 'string', 'description' => 'Post type slug (e.g. "post", "page", "product"). Default: "post".' ],
                            'status'    => [ 'type' => 'string', 'enum' => [ 'any', 'publish', 'draft', 'private', 'pending', 'trash' ], 'description' => 'Filter by status. Default: "any".' ],
                            'limit'     => [ 'type' => 'integer', 'description' => 'Max posts to return (1–100). Default: 20.' ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_post',
                    'description' => 'Get a single post, page, or custom post type record by ID, including its full content, metadata, template, and featured image URL.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'post_id' => [ 'type' => 'integer', 'description' => 'The WordPress post ID.' ],
                        ],
                        'required' => [ 'post_id' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'create_post',
                    'description' => 'Create a new WordPress post, page, or custom post type. Returns the new post ID and URL.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'title'     => [ 'type' => 'string', 'description' => 'Post title (required).' ],
                            'content'   => [ 'type' => 'string', 'description' => 'Post content (HTML or block markup).' ],
                            'post_type' => [ 'type' => 'string', 'description' => 'Post type. Default: "post".' ],
                            'status'    => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'private', 'pending' ], 'description' => 'Initial post status. Default: "draft".' ],
                            'slug'      => [ 'type' => 'string', 'description' => 'URL slug. Defaults to sanitized title.' ],
                            'excerpt'   => [ 'type' => 'string' ],
                            'parent_id' => [ 'type' => 'integer', 'description' => 'Parent page ID for hierarchical types.' ],
                            'template'  => [ 'type' => 'string', 'description' => 'Page template filename (e.g. "page-full.php").' ],
                        ],
                        'required' => [ 'title' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_post_fields',
                    'description' => 'Update one or more fields on an existing post/page (title, content, excerpt, status, slug, template, parent_id, menu_order). Use this for direct content updates to any post type without touching files.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'post_id' => [ 'type' => 'integer', 'description' => 'The post/page ID to update.' ],
                            'fields'  => [
                                'type'        => 'object',
                                'description' => 'Key-value pairs of fields to update. Allowed: post_title, post_content, post_excerpt, post_status, post_name, post_parent, menu_order, template.',
                            ],
                        ],
                        'required' => [ 'post_id', 'fields' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'search_posts',
                    'description' => 'Full-text search across WordPress posts/pages/CPTs using the WordPress search API. Returns matching posts with ID, title, type, status, URL, and excerpt.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'search'    => [ 'type' => 'string', 'description' => 'Text to search for.' ],
                            'post_type' => [ 'type' => 'string', 'description' => 'Restrict to a post type slug. Leave empty to search all.' ],
                            'limit'     => [ 'type' => 'integer', 'description' => 'Max results. Default: 20.' ],
                        ],
                        'required' => [ 'search' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_post_meta',
                    'description' => 'Read custom field (post meta) values for a post. Provide meta_key to get one value, or omit to get all meta for the post.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'post_id'  => [ 'type' => 'integer' ],
                            'meta_key' => [ 'type' => 'string', 'description' => 'Specific meta key to retrieve. Omit to get all meta.' ],
                        ],
                        'required' => [ 'post_id' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_post_meta',
                    'description' => 'Create or update a custom field (post meta) on any post.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'post_id'    => [ 'type' => 'integer' ],
                            'meta_key'   => [ 'type' => 'string' ],
                            'meta_value' => [ 'description' => 'The new value (string, number, or array).' ],
                        ],
                        'required' => [ 'post_id', 'meta_key', 'meta_value' ],
                    ],
                ],
            ],

            /* ---------------------------------------------------------------
               SITE SETTINGS & OPTIONS
               --------------------------------------------------------------- */
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_site_settings',
                    'description' => 'Get core WordPress site settings: site name, tagline, admin email, timezone, posts per page, front page mode, permalink structure, etc.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_site_settings',
                    'description' => 'Update core WordPress site settings such as the site name (blogname), tagline (blogdescription), posts_per_page, timezone_string, show_on_front, page_on_front, and page_for_posts.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'settings' => [
                                'type'        => 'object',
                                'description' => 'Object with setting keys to update. Allowed keys: blogname, blogdescription, admin_email, timezone_string, date_format, time_format, posts_per_page, show_on_front, page_on_front, page_for_posts.',
                            ],
                        ],
                        'required' => [ 'settings' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_wp_option',
                    'description' => 'Read any WordPress option by key using get_option(). Useful for reading plugin settings, custom configurations, and stored data.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'option_key' => [ 'type' => 'string', 'description' => 'The option name to retrieve.' ],
                        ],
                        'required' => [ 'option_key' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_wp_option',
                    'description' => 'Update a WordPress option value. Blocked for security-critical options (siteurl, auth keys, etc.). Use update_site_settings for core site settings.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'option_key' => [ 'type' => 'string' ],
                            'value'      => [ 'description' => 'The new value to store.' ],
                        ],
                        'required' => [ 'option_key', 'value' ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_custom_css',
                    'description' => 'Get the Additional CSS saved in the WordPress Customizer (wp_get_custom_css). Use this to read or inspect global custom styles before editing.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_custom_css',
                    'description' => 'Replace the Additional CSS in the WordPress Customizer. Provide the complete CSS string. This affects the global site stylesheet visible in the front end.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'css' => [ 'type' => 'string', 'description' => 'The complete CSS to save as Additional CSS.' ],
                        ],
                        'required' => [ 'css' ],
                    ],
                ],
            ],

            /* ---------------------------------------------------------------
               NAVIGATION MENUS
               --------------------------------------------------------------- */
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_nav_menus',
                    'description' => 'List all registered navigation menus with their IDs, names, assigned theme locations, and item counts.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_nav_menu_items',
                    'description' => 'Get all items in a navigation menu. Provide either a menu_id or a registered theme location slug.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'menu_id'  => [ 'type' => 'integer', 'description' => 'The numeric menu term ID.' ],
                            'location' => [ 'type' => 'string', 'description' => 'Theme location slug (e.g. "header-menu").' ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_nav_menu_item',
                    'description' => 'Update a navigation menu item\'s title, URL, or other attributes by item post ID.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'item_id' => [ 'type' => 'integer', 'description' => 'The menu item post ID (from get_nav_menu_items).' ],
                            'fields'  => [
                                'type'        => 'object',
                                'description' => 'Fields to update. Allowed: title, url, target, attr_title, classes.',
                            ],
                        ],
                        'required' => [ 'item_id', 'fields' ],
                    ],
                ],
            ],

            /* ---------------------------------------------------------------
               THEME CUSTOMIZER / MODS
               --------------------------------------------------------------- */
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'get_theme_mods',
                    'description' => 'Read all theme customizer settings (theme_mods) for the active theme. Use this to discover what can be changed via the Customizer without file edits.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'update_theme_mod',
                    'description' => 'Set a single theme customizer setting (theme_mod) by key. Use get_theme_mods first to discover valid keys.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'mod_key' => [ 'type' => 'string', 'description' => 'The theme_mod key to update.' ],
                            'value'   => [ 'description' => 'The new value.' ],
                        ],
                        'required' => [ 'mod_key', 'value' ],
                    ],
                ],
            ],

            /* ---------------------------------------------------------------
               POST TYPES & TAXONOMIES
               --------------------------------------------------------------- */
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_post_types',
                    'description' => 'List all registered WordPress post types with their labels, public visibility, archive config, and REST base. Use this when you need to know what content types exist.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_taxonomies',
                    'description' => 'List all registered WordPress taxonomies with labels, associated post types, and term counts.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => new stdClass(),
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'list_terms',
                    'description' => 'List terms (categories, tags, or custom taxonomy terms) for a given taxonomy.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'taxonomy' => [ 'type' => 'string', 'description' => 'Taxonomy slug (e.g. "category", "post_tag").' ],
                            'limit'    => [ 'type' => 'integer', 'description' => 'Max terms to return. Default: 50.' ],
                        ],
                        'required' => [ 'taxonomy' ],
                    ],
                ],
            ],

            /* ---------------------------------------------------------------
               GREP / REGEX FILE SEARCH
               --------------------------------------------------------------- */
            [
                'type' => 'function',
                'function' => [
                    'name'        => 'grep_files',
                    'description' => 'Search file contents using a literal pattern (like grep). CRITICAL: always grep for the ACTUAL visible text shown on the page (e.g. "Fresh perspectives, founder stories") — NEVER grep for a role keyword like "subtitle", "eyebrow", or "description" because that only finds CSS class names, not the content. Leave directory empty to search all of wp-content (theme + all plugins) — content may be in a plugin template, not the theme.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'pattern'   => [ 'type' => 'string', 'description' => 'The EXACT visible text string to search for. Do NOT use role/role keywords like "subtitle" or "description" — use the actual text the user can see on screen.' ],
                            'directory' => [ 'type' => 'string', 'description' => 'Relative directory to search (e.g. "wp-content/themes/my-theme" or "wp-content/plugins/my-plugin"). Leave empty to search all of wp-content — ALWAYS leave empty when you are not sure which plugin or theme owns the text.' ],
                            'file_ext'  => [ 'type' => 'string', 'description' => 'Optional file extension filter (e.g. "php", "css", "js").' ],
                        ],
                        'required' => [ 'pattern' ],
                    ],
                ],
            ],
        ];
    }

    /* =========================================================================
       PROVIDER API CALLS
       ========================================================================= */

    private function call_openai_api( array $input, array $tools, string $api_key, array $model, string $previous_response_id = '' ) {
        $payload = [
            'model'             => $model['api_model'],
            'input'             => $input,
            'max_output_tokens' => 4096,
        ];

        if ( ! empty( $tools ) ) {
            $payload['tools'] = PV_AI_Client::normalize_tools_for_responses( $tools );
        }

        if ( '' !== $previous_response_id ) {
            $payload['previous_response_id'] = $previous_response_id;
        }

        $body = wp_json_encode( $payload );
        $attempt = 0;

        do {
            $attempt++;

            $response = wp_remote_post( PV_AI_Client::OPENAI_API_URL, [
                'timeout'   => self::API_TIMEOUT,
                'headers'   => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ],
                'body'      => $body,
                'sslverify' => true,
            ] );

            if ( is_wp_error( $response ) ) {
                $error = new WP_Error( 'http_error', 'Network error: ' . $response->get_error_message() );
                if ( $attempt < self::API_RETRIES && $this->is_retryable_agent_error( $error ) ) {
                    usleep( 700000 );
                    continue;
                }

                error_log( '[PressViber] API error: ' . $error->get_error_message() );
                return $error;
            }

            $code    = wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $code ) {
                return $decoded;
            }

            $msg   = $decoded['error']['message'] ?? "OpenAI API error (HTTP $code)";
            $error = new WP_Error( 'api_error', $msg );

            if ( $attempt < self::API_RETRIES && $this->is_retryable_status_code( $code ) ) {
                usleep( 700000 );
                continue;
            }

            error_log( '[PressViber] API error: ' . $error->get_error_message() );
            return $error;
        } while ( $attempt < self::API_RETRIES );

        return new WP_Error( 'api_error', 'OpenAI request failed after retry attempts.' );
    }

    private function request_openai_final_summary( array $messages, string $api_key, array $model ): string {
        $response = $this->call_openai_api( PV_AI_Client::build_input_messages( $messages ), [], $api_key, $model );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        return PV_AI_Client::extract_openai_output_text( $response );
    }

    private function call_deepseek_api( array $messages, array $tools, string $api_key, array $model ) {
        $payload = [
            'model'       => $model['api_model'],
            'messages'    => $messages,
            'temperature' => 0.4,
            'max_tokens'  => 4096,
        ];

        if ( ! empty( $tools ) ) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $attempt = 0;

        do {
            $attempt++;

            $response = wp_remote_post( PV_AI_Client::DEEPSEEK_API_URL, [
                'timeout'   => self::API_TIMEOUT,
                'headers'   => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept'        => 'application/json',
                ],
                'body'      => wp_json_encode( $payload ),
                'sslverify' => true,
            ] );

            if ( is_wp_error( $response ) ) {
                $error = new WP_Error( 'http_error', 'Network error: ' . $response->get_error_message() );
                if ( $attempt < self::API_RETRIES && $this->is_retryable_agent_error( $error ) ) {
                    usleep( 700000 );
                    continue;
                }

                error_log( '[PressViber] API error: ' . $error->get_error_message() );
                return $error;
            }

            $code    = wp_remote_retrieve_response_code( $response );
            $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $code ) {
                return $decoded;
            }

            $msg   = $decoded['error']['message'] ?? "DeepSeek API error (HTTP $code)";
            $error = new WP_Error( 'api_error', $msg );

            if ( $attempt < self::API_RETRIES && $this->is_retryable_status_code( $code ) ) {
                usleep( 700000 );
                continue;
            }

            error_log( '[PressViber] API error: ' . $error->get_error_message() );
            return $error;
        } while ( $attempt < self::API_RETRIES );

        return new WP_Error( 'api_error', 'DeepSeek request failed after retry attempts.' );
    }

    private function get_provider_fallback_model( array $model, WP_Error $error, array $tried_providers ): ?array {
        if ( ! $this->is_retryable_agent_error( $error ) ) {
            return null;
        }

        $fallback_slug = PV_AI_Client::PROVIDER_DEEPSEEK === $model['provider']
            ? PV_AI_Client::MODEL_GPT5_4
            : PV_AI_Client::MODEL_DEEPSEEK;

        $fallback = PV_AI_Client::resolve_model( $fallback_slug );
        if ( is_wp_error( $fallback ) ) {
            return null;
        }

        if ( in_array( $fallback['provider'], $tried_providers, true ) ) {
            return null;
        }

        if ( '' === PV_AI_Client::get_api_key( $fallback['provider'] ) ) {
            return null;
        }

        return $fallback;
    }

    private function is_retryable_status_code( int $code ): bool {
        return in_array( $code, [ 408, 409, 425, 429, 500, 502, 503, 504 ], true );
    }

    private function is_retryable_agent_error( WP_Error $error ): bool {
        $message = strtolower( $error->get_error_message() );

        if ( in_array( $error->get_error_code(), [ 'http_error', 'runtime_http_error' ], true ) ) {
            return true;
        }

        foreach ( [
            'timeout',
            'timed out',
            'ssl connection timeout',
            'curl error 28',
            'temporarily unavailable',
            'rate limit',
            'http 408',
            'http 409',
            'http 425',
            'http 429',
            'http 500',
            'http 502',
            'http 503',
            'http 504',
        ] as $fragment ) {
            if ( false !== strpos( $message, $fragment ) ) {
                return true;
            }
        }

        return false;
    }

    private function request_deepseek_final_summary( array $messages, string $api_key, array $model ): string {
        $response = $this->call_deepseek_api( $messages, [], $api_key, $model );
        if ( is_wp_error( $response ) ) {
            return '';
        }

        return trim( (string) ( $response['choices'][0]['message']['content'] ?? '' ) );
    }

    private function is_vercel_runtime_enabled(): bool {
        return '' !== $this->get_vercel_runtime_url() && '' !== $this->get_vercel_runtime_secret();
    }

    private function get_vercel_runtime_url(): string {
        $url = '';

        if ( defined( 'PV_AGENT_RUNTIME_URL' ) && is_string( PV_AGENT_RUNTIME_URL ) ) {
            $url = PV_AGENT_RUNTIME_URL;
        }

        $url = (string) apply_filters( 'pv_agent_runtime_url', $url );
        $url = trim( $url );

        return '' !== $url ? esc_url_raw( $url ) : '';
    }

    private function get_vercel_runtime_secret(): string {
        $secret = '';

        if ( defined( 'PV_AGENT_RUNTIME_SECRET' ) && is_string( PV_AGENT_RUNTIME_SECRET ) ) {
            $secret = PV_AGENT_RUNTIME_SECRET;
        }

        return trim( (string) apply_filters( 'pv_agent_runtime_secret', $secret ) );
    }

    /**
     * Build API credential fields to include in the Vercel runtime payload.
     * The runtime uses these as fallbacks when its own env vars are not set,
     * so users only need to enter keys once (in the WordPress admin).
     */
    private function build_runtime_api_credentials( string $model_slug ): array {
        $slug = strtolower( trim( $model_slug ) );

        // DeepSeek model selected or key available
        $deepseek_key = PV_AI_Client::get_api_key( PV_AI_Client::PROVIDER_DEEPSEEK );
        $openai_key   = PV_AI_Client::get_api_key( PV_AI_Client::PROVIDER_OPENAI );

        $is_deepseek = in_array( $slug, [ 'deepseek', 'deepseek-chat', 'deepseek-reasoner' ], true );
        $is_openai   = in_array( $slug, [ 'gpt', 'gpt4', 'gpt-4o', 'gpt-4', 'openai' ], true );

        // Prefer the key matching the selected model; fall back by availability
        if ( $is_deepseek && '' !== $deepseek_key ) {
            return [ 'api_key' => $deepseek_key, 'api_provider' => 'deepseek' ];
        }

        if ( $is_openai && '' !== $openai_key ) {
            return [ 'api_key' => $openai_key, 'api_provider' => 'openai' ];
        }

        // Auto-select by key availability
        if ( '' !== $deepseek_key ) {
            return [ 'api_key' => $deepseek_key, 'api_provider' => 'deepseek' ];
        }

        if ( '' !== $openai_key ) {
            return [ 'api_key' => $openai_key, 'api_provider' => 'openai' ];
        }

        return [];
    }

    private function run_via_vercel_runtime( string $user_prompt, int $page_id, array $history, string $requested_model, string $target_url = '', string $target_title = '', string $target_kind = '', array $profile = [] ) {
        $url    = $this->get_vercel_runtime_url();
        $secret = $this->get_vercel_runtime_secret();

        if ( '' === $url || '' === $secret ) {
            return new WP_Error( 'runtime_not_configured', 'The external Vercel runtime is not configured.' );
        }

        $payload = [
            'prompt'           => $user_prompt,
            'page_id'          => $page_id,
            'history'          => array_values( $history ),
            'model'            => $requested_model,
            'target_url'       => $target_url,
            'target_title'     => $target_title,
            'target_kind'      => $target_kind,
            'system_prompt'    => $this->build_system_prompt( $page_id, $profile, $target_url, $target_title, $target_kind ),
            'tool_endpoint'    => rest_url( 'pv/v1/agent-tool' ),
            'tool_definitions' => $this->get_tool_definitions(),
            'max_steps'        => (int) ( $profile['max_steps'] ?? self::MAX_ITER ),
            'site_url'         => get_site_url(),
        ] + $this->build_runtime_api_credentials( $requested_model );

        $this->emit( 'thinking', [ 'iteration' => 1 ] );

        $response = wp_remote_post(
            $url,
            [
                'timeout'   => self::API_TIMEOUT,
                'headers'   => [
                    'Content-Type'        => 'application/json',
                    'Accept'              => 'application/json',
                    'X-PV-Agent-Secret' => $secret,
                ],
                'body'      => wp_json_encode( $payload ),
                'sslverify' => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $runtime_error = new WP_Error( 'runtime_http_error', 'External runtime request failed: ' . $response->get_error_message() );
            error_log( '[PressViber] API error: ' . $runtime_error->get_error_message() );
            return $runtime_error;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( 200 !== $code ) {
            $message = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : "External runtime error (HTTP $code)";
            $runtime_error = new WP_Error( 'runtime_error', $message );
            error_log( '[PressViber] API error: ' . $runtime_error->get_error_message() );
            return $runtime_error;
        }

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'runtime_bad_json', 'External runtime returned invalid JSON.' );
        }

        foreach ( (array) ( $data['tool_calls'] ?? [] ) as $tool_call ) {
            if ( ! is_array( $tool_call ) ) {
                continue;
            }

            $record = [
                'id'      => (string) ( $tool_call['id'] ?? uniqid( 'tc_' ) ),
                'name'    => (string) ( $tool_call['name'] ?? '' ),
                'args'    => is_array( $tool_call['args'] ?? null ) ? $tool_call['args'] : [],
                'success' => ! empty( $tool_call['success'] ),
                'summary' => (string) ( $tool_call['summary'] ?? '' ),
            ];

            $this->append_tool_trace( $record );
            $this->emit(
                'tool_start',
                [
                    'id'   => $record['id'],
                    'name' => $record['name'],
                    'args' => $record['args'],
                ]
            );
            $this->emit( 'tool_done', $record );
        }

        $usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : [];

        $this->emit(
            'message',
            [
                'content'    => (string) ( $data['message'] ?? '' ),
                'tool_calls' => array_values( array_filter( (array) ( $data['tool_calls'] ?? [] ), 'is_array' ) ),
                'usage'      => [
                    'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
                    'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
                    'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
                ],
            ]
        );

        $this->persist_run_context(
            'success',
            [
                'path'       => 'vercel_runtime',
                'provider'   => $requested_model,
                'usage'      => $usage,
                'tool_calls' => array_values( array_filter( (array) ( $data['tool_calls'] ?? [] ), 'is_array' ) ),
                'message'    => (string) ( $data['message'] ?? '' ),
            ]
        );

        return true;
    }

    private function build_task_profile( string $prompt, int $page_id, array $history = [], string $target_url = '' ): array {
        $normalized = strtolower( trim( $prompt ) );
        $cross_route_ui_task =
            '' !== $target_url &&
            1 === preg_match( '/\b(homepage|home page|front page|home)\b/i', $normalized ) &&
            1 === preg_match( '/\b(this page|current page|bottom|banner|section|hero|footer)\b/i', $normalized );
        $simple_content_candidate =
            $page_id > 0 &&
            '' !== $target_url &&
            strlen( $normalized ) <= 220 &&
            0 === count( $history ) &&
            1 === preg_match( '/\b(add|change|update|replace|remove|edit|revise|fix|insert)\b/i', $normalized ) &&
            0 === preg_match( '/\b(plugin|theme|template|php|css|scss|js|javascript|shortcode|query|database|sql|schema|endpoint|api|function|hook|block|layout|header|footer|section|component)\b/i', $normalized );

        return [
            'simple_content_candidate' => $simple_content_candidate,
            'compact_prompt'           => ! $cross_route_ui_task && ( $simple_content_candidate || strlen( $normalized ) <= 260 ),
            'max_steps'                => $simple_content_candidate ? self::SIMPLE_MAX_ITER : self::MAX_ITER,
            'mode'                     => $simple_content_candidate ? 'simple_content' : ( $cross_route_ui_task ? 'cross_route_ui' : 'standard' ),
        ];
    }

    private function maybe_run_simple_page_edit( string $prompt, int $page_id, array $history, string $requested_model, array $profile = [] ) {
        if ( empty( $profile['simple_content_candidate'] ) || $page_id < 1 || ! empty( $history ) ) {
            return null;
        }

        $page = $this->site->get_page_content( $page_id, '' );
        if ( is_wp_error( $page ) ) {
            return null;
        }

        $page_meta        = is_array( $page['page'] ?? null ) ? $page['page'] : [];
        $current_content  = (string) ( $page_meta['content'] ?? '' );
        $content_length   = (int) ( $page_meta['content_length'] ?? strlen( $current_content ) );

        if ( '' === trim( $current_content ) || $content_length > 24000 ) {
            return null;
        }

        $model = PV_AI_Client::resolve_model( $requested_model );
        if ( is_wp_error( $model ) ) {
            return null;
        }

        $api_key = PV_AI_Client::get_api_key( $model['provider'] );
        if ( '' === $api_key ) {
            return null;
        }

        $read_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'get_page_content',
            'args'    => [ 'page_id' => $page_id ],
            'success' => true,
            'summary' => 'Loaded current WordPress page content',
        ];

        $revision = $this->request_page_content_revision( $prompt, $page_meta, $model, $api_key );
        if ( is_wp_error( $revision ) ) {
            return null;
        }

        $updated_content = $this->normalize_page_content_revision( (string) ( $revision['content'] ?? '' ) );
        if ( '' === $updated_content || trim( $updated_content ) === trim( $current_content ) ) {
            return null;
        }

        $update = $this->site->update_page_content( $page_id, $updated_content );
        if ( is_wp_error( $update ) ) {
            return null;
        }

        $this->emit( 'start', [ 'message' => 'Using a fast content-edit path for this page…' ] );
        $this->emit( 'tool_start', [ 'id' => $read_record['id'], 'name' => $read_record['name'], 'args' => $read_record['args'] ] );
        $this->append_tool_trace( $read_record );
        $this->emit( 'tool_done', $read_record );

        $write_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'update_page_content',
            'args'    => [ 'page_id' => $page_id ],
            'success' => true,
            'summary' => 'Updated the page content in WordPress',
        ];
        $this->emit( 'tool_start', [ 'id' => $write_record['id'], 'name' => $write_record['name'], 'args' => $write_record['args'] ] );
        $this->append_tool_trace( $write_record );
        $this->emit( 'tool_done', $write_record );

        return [
            'content'    => sprintf(
                'Updated the %s page content directly in WordPress with the requested copy change.',
                $page_meta['title'] ?? 'selected'
            ),
            'tool_calls' => [ $read_record, $write_record ],
            'usage'      => isset( $revision['usage'] ) && is_array( $revision['usage'] ) ? $revision['usage'] : [],
            'provider'   => $model['provider'] ?? '',
        ];
    }

    private function maybe_run_simple_widget_reuse( string $prompt, int $page_id, array $history, string $target_url = '' ) {
        if ( ! empty( $history ) || '' === $target_url || $page_id > 0 ) {
            return null;
        }

        $normalized = strtolower( trim( $prompt ) );
        if (
            1 !== preg_match( '/\b(add|show|place|put|display|insert)\b/i', $normalized ) ||
            1 !== preg_match( '/\b(homepage|home page|front page|home)\b/i', $normalized ) ||
            1 !== preg_match( '/\b(bottom|footer|end)\b/i', $normalized ) ||
            1 !== preg_match( '/\b(this page|current page)\b/i', $normalized )
        ) {
            return null;
        }

        preg_match_all( '/"([^"]{8,})"/', $prompt, $matches );
        $quoted = array_values( array_filter( array_map( 'trim', $matches[1] ?? [] ) ) );
        if ( empty( $quoted ) ) {
            return null;
        }

        $terms = [];
        foreach ( $quoted as $chunk ) {
            $terms[] = $chunk;
            foreach ( preg_split( '/[\r\n.!?]+/', $chunk ) as $part ) {
                $part = trim( $part );
                if ( strlen( $part ) >= 8 ) {
                    $terms[] = $part;
                }
            }
        }
        $terms = array_values( array_unique( array_filter( $terms ) ) );

        $this->emit( 'start', [ 'message' => 'Trying a direct widget reuse path for this request…' ] );

        $search_result = $this->site->find_widgets_by_text( $terms, [ 'landing', 'above-footer' ] );
        $search_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'find_widgets_by_text',
            'args'    => [ 'terms' => $terms, 'sidebar_ids' => [ 'landing', 'above-footer' ] ],
            'success' => ! empty( $search_result['total'] ),
            'summary' => ! empty( $search_result['total'] ) ? 'Found matching homepage widget candidates' : 'find_widgets_by_text failed — No matching widget text found',
        ];
        $this->emit( 'tool_start', [ 'id' => $search_record['id'], 'name' => $search_record['name'], 'args' => $search_record['args'] ] );
        $this->append_tool_trace( $search_record );
        $this->emit( 'tool_done', $search_record );

        if ( empty( $search_result['matches'][0]['widget_id'] ) ) {
            return null;
        }

        $sidebar_result = $this->site->inspect_sidebars( '', [ 'above-footer' ] );
        $sidebar_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'inspect_sidebars',
            'args'    => [ 'sidebar_ids' => [ 'above-footer' ] ],
            'success' => ! empty( $sidebar_result['sidebar_count'] ),
            'summary' => ! empty( $sidebar_result['sidebar_count'] ) ? 'Inspected target footer sidebar' : 'inspect_sidebars failed — above-footer sidebar not available',
        ];
        $this->emit( 'tool_start', [ 'id' => $sidebar_record['id'], 'name' => $sidebar_record['name'], 'args' => $sidebar_record['args'] ] );
        $this->append_tool_trace( $sidebar_record );
        $this->emit( 'tool_done', $sidebar_record );

        if ( empty( $sidebar_result['sidebar_count'] ) ) {
            return null;
        }

        $widget_id = (string) $search_result['matches'][0]['widget_id'];
        $rule      = $this->site->ensure_widget_visibility_rule( $widget_id, 'above-footer', $target_url );
        if ( is_wp_error( $rule ) ) {
            return null;
        }

        $write_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'ensure_widget_visibility_rule',
            'args'    => [
                'widget_id'  => $widget_id,
                'sidebar_id' => 'above-footer',
                'url'        => $target_url,
            ],
            'success' => true,
            'summary' => ! empty( $rule['created'] ) ? 'Created a route widget visibility rule' : 'Widget visibility rule already existed for this route',
        ];
        $this->emit( 'tool_start', [ 'id' => $write_record['id'], 'name' => $write_record['name'], 'args' => $write_record['args'] ] );
        $this->append_tool_trace( $write_record );
        $this->emit( 'tool_done', $write_record );

        return [
            'content'    => 'Added the homepage CTA banner to this page by attaching its widget to the page footer area for this route.',
            'tool_calls' => [ $search_record, $sidebar_record, $write_record ],
        ];
    }

    private function maybe_run_simple_semantic_page_edit( string $prompt, int $page_id, array $history, string $target_url = '' ) {
        unset( $history );

        $normalized   = strtolower( trim( $prompt ) );
        $action_match = [];
        $role_map     = $this->extract_semantic_role_targets( $normalized );

        if (
            ( $page_id < 1 && '' === $target_url ) ||
            empty( $role_map ) ||
            1 !== preg_match( '/\b(remove|hide|delete|clear)\b/i', $normalized, $action_match ) ||
            1 === preg_match( '/\b(file|php|css|scss|js|javascript|plugin|theme|template|shortcode)\b/i', $normalized )
        ) {
            return null;
        }

        $this->emit( 'start', [ 'message' => 'Trying a direct page-structure edit path for this request…' ] );

        $context = $this->site->inspect_url_context( $page_id, $target_url );
        if ( is_wp_error( $context ) ) {
            return null;
        }

        $context_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'inspect_url_context',
            'args'    => [ 'page_id' => $page_id, 'url' => $target_url ],
            'success' => true,
            'summary' => $this->summarize( 'inspect_url_context', [ 'page_id' => $page_id, 'url' => $target_url ], $context ),
        ];
        $this->emit( 'tool_start', [ 'id' => $context_record['id'], 'name' => $context_record['name'], 'args' => $context_record['args'] ] );
        $this->append_tool_trace( $context_record );
        $this->emit( 'tool_done', $context_record );

        $targets = $this->site->inspect_visible_text_targets( $page_id, $target_url );
        if ( is_wp_error( $targets ) ) {
            return null;
        }

        $targets_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'inspect_visible_text_targets',
            'args'    => [ 'page_id' => $page_id, 'url' => $target_url ],
            'success' => ! empty( $targets['total'] ),
            'summary' => ! empty( $targets['total'] ) ? 'Mapped visible page structure targets' : 'inspect_visible_text_targets failed — No visible text targets found',
        ];
        $this->emit( 'tool_start', [ 'id' => $targets_record['id'], 'name' => $targets_record['name'], 'args' => $targets_record['args'] ] );
        $this->append_tool_trace( $targets_record );
        $this->emit( 'tool_done', $targets_record );

        $target = $this->pick_semantic_text_target( (array) ( $targets['targets'] ?? [] ), $role_map, (string) ( $context['title'] ?? '' ) );

        // ── Fast-path fallback: if inspect_visible_text_targets found nothing,
        //    use find_page_element to locate the element via rendered HTML + file grep.
        if ( ( ! is_array( $target ) || empty( $target['text'] ) ) && '' !== $target_url ) {
            $fpe = $this->find_page_element( $target_url, $prompt );
            if ( ! empty( $fpe['found'] ) ) {
                $top        = $fpe['found'][0];
                $fpe_file   = (string) ( $top['file'] ?? '' );
                $fpe_text   = (string) ( $top['text'] ?? '' );     // Visible text
                $fpe_line   = (string) ( $top['line_text'] ?? '' ); // Raw PHP line to remove
                // We need the full file content to use find_semantic_removal_snippet
                $fpe_fc     = $this->fm->fm_read( $fpe_file );
                $fpe_content = is_wp_error( $fpe_fc ) ? '' : (string) ( $fpe_fc['content'] ?? '' );

                // Build a pseudo-target for find_semantic_removal_snippet
                $pseudo_target = [ 'text' => $fpe_text, 'tag' => 'p', 'classes' => [] ];
                $removal = '' !== $fpe_content ? $this->find_semantic_removal_snippet( $fpe_content, $pseudo_target ) : '';
                $old_text = '' !== $removal ? $removal : ( '' !== $fpe_line ? $fpe_line : '' );

                if ( '' !== $old_text && '' !== $fpe_file ) {
                    $fpe_record = [
                        'id'      => uniqid( 'tc_' ),
                        'name'    => 'find_page_element',
                        'args'    => [ 'url' => $target_url, 'intent' => $prompt ],
                        'success' => true,
                        'summary' => "Located page element in $fpe_file",
                    ];
                    $this->emit( 'tool_start', [ 'id' => $fpe_record['id'], 'name' => $fpe_record['name'], 'args' => $fpe_record['args'] ] );
                    $this->append_tool_trace( $fpe_record );
                    $this->emit( 'tool_done', $fpe_record );

                    $replace = $this->fm->fm_replace_text( $fpe_file, $old_text, '', false );
                    if ( ! is_wp_error( $replace ) ) {
                        $write_record = [
                            'id'      => uniqid( 'tc_' ),
                            'name'    => 'replace_text_in_file',
                            'args'    => [ 'path' => $fpe_file, 'old_text' => $old_text, 'new_text' => '' ],
                            'success' => true,
                            'summary' => $this->summarize( 'replace_text_in_file', [ 'path' => $fpe_file ], $replace ),
                        ];
                        $this->emit( 'tool_start', [ 'id' => $write_record['id'], 'name' => $write_record['name'], 'args' => $write_record['args'] ] );
                        $this->append_tool_trace( $write_record );
                        $this->emit( 'tool_done', $write_record );

                        return [
                            'content'    => sprintf( 'Removed the element from this page by updating %s.', $fpe_file ),
                            'tool_calls' => [ $context_record, $targets_record, $fpe_record, $write_record ],
                        ];
                    }
                }
            }
            return null;
        }

        if ( ! is_array( $target ) || empty( $target['text'] ) ) {
            return null;
        }

        $search = $this->find_ui_candidates( [ (string) $target['text'] ], [], 8 );
        if ( ! empty( $search['error'] ) ) {
            return null;
        }

        $search_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'find_ui_candidates',
            'args'    => [ 'terms' => [ $target['text'] ], 'directories' => [] ],
            'success' => ! empty( $search['total'] ),
            'summary' => ! empty( $search['total'] ) ? 'Found source candidates for the visible page text' : 'find_ui_candidates failed — No source match for the visible page text',
        ];
        $this->emit( 'tool_start', [ 'id' => $search_record['id'], 'name' => $search_record['name'], 'args' => $search_record['args'] ] );
        $this->append_tool_trace( $search_record );
        $this->emit( 'tool_done', $search_record );

        $match = $this->pick_semantic_source_match( (array) ( $search['results'] ?? [] ), $context, (string) $target['text'] );
        if ( ! is_array( $match ) || empty( $match['path'] ) ) {
            return null;
        }

        $file = $this->fm->fm_read( (string) $match['path'] );
        if ( is_wp_error( $file ) ) {
            return null;
        }

        $read_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'read_file',
            'args'    => [ 'path' => $match['path'] ],
            'success' => true,
            'summary' => 'Read the best source file candidate',
        ];
        $this->emit( 'tool_start', [ 'id' => $read_record['id'], 'name' => $read_record['name'], 'args' => $read_record['args'] ] );
        $this->append_tool_trace( $read_record );
        $this->emit( 'tool_done', $read_record );

        $removal_snippet = $this->find_semantic_removal_snippet( (string) $file['content'], $target );
        $old_text        = '' !== $removal_snippet ? $removal_snippet : (string) $target['text'];

        $replace = $this->fm->fm_replace_text( (string) $match['path'], $old_text, '', false );
        if ( is_wp_error( $replace ) ) {
            return null;
        }

        $write_record = [
            'id'      => uniqid( 'tc_' ),
            'name'    => 'replace_text_in_file',
            'args'    => [
                'path'            => $match['path'],
                'old_text'        => $old_text,
                'new_text'        => '',
                'all_occurrences' => false,
            ],
            'success' => true,
            'summary' => $this->summarize(
                'replace_text_in_file',
                [
                    'path'            => $match['path'],
                    'old_text'        => $old_text,
                    'new_text'        => '',
                    'all_occurrences' => false,
                ],
                $replace
            ),
        ];
        $this->emit( 'tool_start', [ 'id' => $write_record['id'], 'name' => $write_record['name'], 'args' => $write_record['args'] ] );
        $this->append_tool_trace( $write_record );
        $this->emit( 'tool_done', $write_record );

        return [
            'content'    => sprintf(
                'Removed the %s on this page by updating %s.',
                str_replace( '_', ' ', (string) $target['role'] ),
                (string) $match['path']
            ),
            'tool_calls' => [ $context_record, $targets_record, $search_record, $read_record, $write_record ],
        ];
    }

    private function extract_semantic_role_targets( string $prompt ): array {
        $roles = [];

        $map = [
            'subtitle'      => [ 'subtitle', 'sub title', 'subheading', 'sub heading' ],
            'description'   => [ 'description', 'tagline', 'summary', 'supporting text', 'intro text', 'hero text', 'copy' ],
            'title'         => [ 'title', 'heading', 'headline' ],
            'eyebrow'       => [ 'eyebrow', 'kicker', 'label', 'badge' ],
            'button'        => [ 'button', 'cta', 'call to action' ],
            'meta'          => [ 'meta', 'caption', 'count', 'stat', 'published articles' ],
            'section_title' => [ 'section title', 'section heading', 'card title' ],
        ];

        foreach ( $map as $role => $needles ) {
            foreach ( $needles as $needle ) {
                if ( false !== strpos( $prompt, $needle ) ) {
                    $roles[] = $role;
                    break;
                }
            }
        }

        return array_values( array_unique( $roles ) );
    }

    private function pick_semantic_text_target( array $targets, array $desired_roles, string $page_title = '' ): ?array {
        $best       = null;
        $best_score = -1;

        foreach ( $targets as $target ) {
            if ( empty( $target['text'] ) || empty( $target['role'] ) ) {
                continue;
            }

            $role  = (string) $target['role'];
            $score = in_array( $role, $desired_roles, true ) ? 100 : 0;

            if ( 'description' === $role && in_array( 'subtitle', $desired_roles, true ) ) {
                $score += 80;
            }

            if ( 'subtitle' === $role && in_array( 'description', $desired_roles, true ) ) {
                $score += 80;
            }

            if ( ! empty( $target['nearby_heading'] ) && '' !== $page_title && 0 === strcasecmp( (string) $target['nearby_heading'], $page_title ) ) {
                $score += 35;
            }

            if ( ! empty( $target['classes'] ) ) {
                $score += 10;
            }

            $text_length = strlen( (string) $target['text'] );
            if ( $text_length >= 8 && $text_length <= 180 ) {
                $score += 10;
            }

            if ( $score > $best_score ) {
                $best       = $target;
                $best_score = $score;
            }
        }

        return $best_score > 0 ? $best : null;
    }

    private function pick_semantic_source_match( array $results, array $context, string $needle ): ?array {
        $best       = null;
        $best_score = -1;
        $templates  = array_map( 'basename', (array) ( $context['template_candidates'] ?? [] ) );
        $post_type  = strtolower( (string) ( $context['post_type'] ?? '' ) );
        $kind       = strtolower( (string) ( $context['kind'] ?? '' ) );

        foreach ( $results as $result ) {
            $path  = (string) ( $result['path'] ?? '' );
            $score = 0;

            if ( '' === $path ) {
                continue;
            }

            if ( in_array( basename( $path ), $templates, true ) ) {
                $score += 100;
            }

            if ( '' !== $post_type && false !== strpos( strtolower( $path ), $post_type ) ) {
                $score += 35;
            }

            if ( '' !== $kind && false !== strpos( strtolower( $path ), str_replace( '_', '-', $kind ) ) ) {
                $score += 20;
            }

            if ( false !== strpos( $path, '/templates/' ) ) {
                $score += 12;
            }

            if ( false !== strpos( (string) ( $result['snippet'] ?? '' ), $needle ) || false !== strpos( (string) ( $result['excerpt'] ?? '' ), $needle ) ) {
                $score += 10;
            }

            if ( $score > $best_score ) {
                $best       = $result;
                $best_score = $score;
            }
        }

        return $best_score >= 0 ? $best : null;
    }

    private function find_semantic_removal_snippet( string $content, array $target ): string {
        $text    = (string) ( $target['text'] ?? '' );
        $tag     = strtolower( (string) ( $target['tag'] ?? '' ) );
        $classes = array_values( array_filter( array_map( 'strval', (array) ( $target['classes'] ?? [] ) ) ) );

        if ( '' === $text ) {
            return '';
        }

        $escaped_text = preg_quote( $text, '/' );
        $tags         = array_unique( array_filter( [ $tag, 'p', 'div', 'span', 'a', 'button', 'h1', 'h2', 'h3' ] ) );

        foreach ( $classes as $class_name ) {
            $escaped_class = preg_quote( $class_name, '/' );
            foreach ( $tags as $candidate_tag ) {
                $pattern = '/^[ \t]*<' . preg_quote( $candidate_tag, '/' ) . '\b(?=[^>]*class="[^"]*\b' . $escaped_class . '\b[^"]*")[^>]*>.*?' . $escaped_text . '.*?<\/' . preg_quote( $candidate_tag, '/' ) . '>\R?/ims';
                if ( preg_match( $pattern, $content, $matches ) ) {
                    return (string) $matches[0];
                }
            }
        }

        foreach ( $tags as $candidate_tag ) {
            $pattern = '/^[ \t]*<' . preg_quote( $candidate_tag, '/' ) . '\b[^>]*>.*?' . $escaped_text . '.*?<\/' . preg_quote( $candidate_tag, '/' ) . '>\R?/ims';
            if ( preg_match( $pattern, $content, $matches ) ) {
                return (string) $matches[0];
            }
        }

        return '';
    }

    private function request_page_content_revision( string $prompt, array $page_meta, array $model, string $api_key ) {
        $system = 'You revise existing WordPress page post_content. Return only the full updated page content markup. Do not explain. Do not wrap the answer in code fences. Make the smallest possible change needed to satisfy the instruction and preserve all unrelated content, markup, shortcodes, and block comments.';
        $user   = "Page title: " . ( $page_meta['title'] ?? '' ) . "\n"
            . "Page URL: " . ( $page_meta['url'] ?? '' ) . "\n"
            . "Instruction: " . $prompt . "\n\n"
            . "Current page content:\n"
            . $page_meta['content'];

        if ( PV_AI_Client::PROVIDER_DEEPSEEK === $model['provider'] ) {
            $messages = [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user', 'content' => $user ],
            ];
            $response = $this->call_deepseek_api( $messages, [], $api_key, $model );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            return [
                'content' => trim( (string) ( $response['choices'][0]['message']['content'] ?? '' ) ),
                'usage'   => isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : [],
            ];
        }

        $messages = [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user', 'content' => $user ],
        ];
        $response = $this->call_openai_api( PV_AI_Client::build_input_messages( $messages ), [], $api_key, $model );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return [
            'content' => PV_AI_Client::extract_openai_output_text( $response ),
            'usage'   => PV_AI_Client::extract_openai_usage( $response ),
        ];
    }

    private function normalize_page_content_revision( string $content ): string {
        $content = trim( $content );
        if ( '' === $content ) {
            return '';
        }

        if ( 1 === preg_match( '/^```(?:html|php)?\s*(.*?)```$/is', $content, $matches ) ) {
            $content = trim( (string) $matches[1] );
        }

        return trim( preg_replace( "/^\xEF\xBB\xBF/", '', $content ) );
    }

    private function build_local_final_summary( array $tool_calls ): string {
        if ( empty( $tool_calls ) ) {
            return 'I reviewed the request but could not identify a concrete change to apply.';
        }

        $changed = [];
        $failures = [];
        foreach ( $tool_calls as $tool_call ) {
            if ( empty( $tool_call['success'] ) ) {
                if ( ! empty( $tool_call['summary'] ) ) {
                    $failures[] = (string) $tool_call['summary'];
                }
                continue;
            }

            $name = (string) ( $tool_call['name'] ?? '' );
            $args = is_array( $tool_call['args'] ?? null ) ? $tool_call['args'] : [];

            if ( in_array( $name, [ 'write_file', 'replace_text_in_file', 'patch_file' ], true ) && ! empty( $args['path'] ) ) {
                $changed[] = (string) $args['path'];
            }

            if ( in_array( $name, [ 'update_page_content', 'update_post_fields', 'create_post' ], true ) ) {
                $post_id = (int) ( $args['post_id'] ?? $args['page_id'] ?? 0 );
                $changed[] = $post_id > 0 ? 'WordPress post #' . $post_id : 'WordPress post';
            }

            if ( 'update_post_meta' === $name && ! empty( $args['meta_key'] ) ) {
                $changed[] = 'post meta: ' . (string) $args['meta_key'];
            }

            if ( in_array( $name, [ 'update_site_settings', 'update_wp_option' ], true ) ) {
                $changed[] = 'site settings';
            }

            if ( 'update_custom_css' === $name ) {
                $changed[] = 'custom CSS';
            }

            if ( 'update_theme_mod' === $name && ! empty( $args['mod_key'] ) ) {
                $changed[] = 'theme mod: ' . (string) $args['mod_key'];
            }

            if ( 'update_nav_menu_item' === $name && ! empty( $args['item_id'] ) ) {
                $changed[] = 'menu item #' . (int) $args['item_id'];
            }

            if ( 'clone_widget_to_sidebar' === $name && ! empty( $args['sidebar_id'] ) ) {
                $changed[] = 'sidebar ' . (string) $args['sidebar_id'];
            }

            if ( 'ensure_widget_visibility_rule' === $name && ! empty( $args['sidebar_id'] ) ) {
                $changed[] = 'widget rule for ' . (string) $args['sidebar_id'];
            }

            if ( 'replace_text_in_widget' === $name && ! empty( $args['widget_id'] ) ) {
                $changed[] = 'widget ' . (string) $args['widget_id'];
            }
        }

        $changed = array_values( array_unique( array_filter( $changed ) ) );
        if ( empty( $changed ) ) {
            $message = 'I investigated the request but did not apply any changes.';
            if ( ! empty( $failures ) ) {
                $message .= ' Blocking issue: ' . reset( $failures ) . '.';
            }

            return $message;
        }

        return 'Done. Updated ' . implode( ', ', $changed ) . '.';
    }

    private function has_successful_write( array $tool_calls ): bool {
        foreach ( $tool_calls as $tool_call ) {
            if ( empty( $tool_call['success'] ) ) {
                continue;
            }

            $name = (string) ( $tool_call['name'] ?? '' );
            if ( in_array( $name, [
                'write_file', 'replace_text_in_file', 'patch_file',
                'update_page_content', 'update_post_fields', 'create_post',
                'update_post_meta', 'update_site_settings', 'update_wp_option',
                'update_custom_css', 'update_theme_mod', 'update_nav_menu_item',
                'clone_widget_to_sidebar', 'ensure_widget_visibility_rule', 'replace_text_in_widget',
            ], true ) ) {
                return true;
            }
        }

        return false;
    }

    private function begin_run_context( string $prompt, int $page_id, array $history, string $model, array $profile, string $target_url = '', string $target_title = '', string $target_kind = '' ): void {
        $this->current_run_context = [
            'started_at'    => gmdate( 'c' ),
            'prompt'        => $prompt,
            'page_id'       => $page_id,
            'target_url'    => $target_url,
            'target_title'  => $target_title,
            'target_kind'   => $target_kind,
            'history_count' => count( $history ),
            'requested_model' => $model,
            'profile'       => $profile['mode'] ?? 'standard',
            'tool_calls'    => [],
        ];
    }

    private function append_tool_trace( array $record ): void {
        if ( empty( $this->current_run_context ) ) {
            return;
        }

        $this->current_run_context['tool_calls'][] = [
            'name'    => (string) ( $record['name'] ?? '' ),
            'success' => ! empty( $record['success'] ),
            'summary' => (string) ( $record['summary'] ?? '' ),
        ];
    }

    private function persist_run_context( string $status, array $payload = [] ): void {
        if ( empty( $this->current_run_context ) ) {
            return;
        }

        $existing = get_option( self::RUN_LOG_OPTION, [] );
        $existing = is_array( $existing ) ? $existing : [];

        $usage = isset( $payload['usage'] ) && is_array( $payload['usage'] ) ? $payload['usage'] : [];
        $tool_calls = isset( $payload['tool_calls'] ) && is_array( $payload['tool_calls'] ) ? $payload['tool_calls'] : ( $this->current_run_context['tool_calls'] ?? [] );
        $tool_calls = array_map(
            static function ( $tool_call ) {
                return [
                    'name'    => (string) ( $tool_call['name'] ?? '' ),
                    'success' => ! empty( $tool_call['success'] ),
                    'summary' => (string) ( $tool_call['summary'] ?? '' ),
                ];
            },
            $tool_calls
        );

        $entry = array_merge(
            $this->current_run_context,
            [
                'status'            => $status,
                'finished_at'       => gmdate( 'c' ),
                'duration_seconds'  => isset( $this->current_run_context['started_at'] ) ? max( 0, time() - strtotime( (string) $this->current_run_context['started_at'] ) ) : 0,
                'provider'          => (string) ( $payload['provider'] ?? '' ),
                'path'              => (string) ( $payload['path'] ?? 'agent_loop' ),
                'usage'             => [
                    'total_tokens'      => (int) ( $usage['total_tokens'] ?? 0 ),
                    'prompt_tokens'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
                    'completion_tokens' => (int) ( $usage['completion_tokens'] ?? 0 ),
                ],
                'tool_calls'        => array_values( $tool_calls ),
                'tool_count'        => count( $tool_calls ),
                'message_excerpt'   => wp_trim_words( wp_strip_all_tags( (string) ( $payload['message'] ?? '' ) ), 30 ),
                'error_message'     => 'success' === $status ? '' : (string) ( $payload['message'] ?? '' ),
            ]
        );

        array_unshift( $existing, $entry );
        $existing = array_slice( $existing, 0, 100 ); // Keep the 100 most recent runs

        update_option( self::RUN_LOG_OPTION, $existing, false );

        $this->current_run_context = [];
    }

    /* =========================================================================
       SYSTEM PROMPT
       ========================================================================= */

    private function build_system_prompt( int $page_id = 0, array $profile = [], string $target_url = '', string $target_title = '', string $target_kind = '' ): string {
        $theme        = wp_get_theme();
        $theme_rel    = ltrim( str_replace( ABSPATH, '', get_template_directory() ), '/' );
        $uploads      = wp_upload_dir();
        $agent_manual = $this->load_agent_manual();
        $compact      = ! empty( $profile['compact_prompt'] );

        $p  = "You are an expert WordPress developer and AI coding agent — like \"Claude Code\" but for WordPress.\n";
        $p .= "You have DIRECT access to: the WordPress filesystem, all WordPress APIs (posts, options, menus, customizer, theme mods, taxonomies), page inspection tools, and a gated command runner.\n";
        $p .= "You can read, write, patch, create, inspect, and verify anything on this WordPress site.\n\n";

        // List active plugins for context
        $active_plugins = get_option( 'active_plugins', [] );
        $plugin_slugs   = array_map( function( $p ) { return dirname( $p ) ?: $p; }, $active_plugins );
        $custom_plugins = array_filter( $plugin_slugs, function( $s ) {
            return ! in_array( $s, [ 'akismet', 'litespeed-cache', 'google-analytics-for-wordpress', 'pressviber' ], true );
        } );

        // Detect posts_page for richer context
        $posts_page_id  = (int) get_option( 'page_for_posts', 0 );
        $front_page_id  = (int) get_option( 'page_on_front', 0 );
        $show_on_front  = get_option( 'show_on_front', 'posts' );

        $p .= "## WordPress Environment\n";
        $p .= "- WordPress " . get_bloginfo( 'version' ) . "\n";
        $p .= "- Site URL: " . get_site_url() . "\n";
        $p .= "- Active theme: " . $theme->get( 'Name' ) . " v" . $theme->get( 'Version' ) . "\n";
        $p .= "- Theme path (relative): $theme_rel\n";
        $p .= "- Plugins path: wp-content/plugins\n";
        $p .= "- Custom plugins: " . ( ! empty( $custom_plugins ) ? implode( ', ', array_values( $custom_plugins ) ) : 'none' ) . "\n";
        $p .= "- Uploads path: " . ltrim( str_replace( ABSPATH, '', $uploads['basedir'] ), '/' ) . "\n";
        $p .= "- Front page mode: {$show_on_front}" . ( $front_page_id > 0 ? " (page ID {$front_page_id})" : '' ) . "\n";
        if ( $posts_page_id > 0 ) {
            $posts_page_obj = get_post( $posts_page_id );
            $posts_page_url = get_permalink( $posts_page_id );
            $p .= "- Posts/Blog page: ID {$posts_page_id}";
            if ( $posts_page_obj instanceof WP_Post ) {
                $p .= " «{$posts_page_obj->post_title}»";
            }
            $p .= " — URL: {$posts_page_url}\n";
            $p .= "  ↳ This is a POSTS_PAGE: its content is the page editor's post_content plus the theme's home.php/archive template. The subtitle/description usually lives in post_content (use get_page_content/update_page_content) or the theme template file.\n";
        }
        $p .= "\n";

        if ( $page_id ) {
            $page = get_post( $page_id );
            if ( $page ) {
                $tpl = get_post_meta( $page_id, '_wp_page_template', true ) ?: 'default';
                $p .= "## Current Page Context\n";
                $p .= "- Title: {$page->post_title}  (ID: {$page_id})\n";
                $p .= "- Slug: {$page->post_name}\n";
                $p .= "- Status: {$page->post_status}\n";
                $p .= "- Template: {$tpl}\n";
                $p .= "- URL: " . get_permalink( $page_id ) . "\n";
                if ( $posts_page_id === $page_id ) {
                    $p .= "- ⚠️ This is the POSTS_PAGE (blog listing). Its content is managed via post_content AND the theme template. For text above the post list (title, subtitle, description), check post_content first with get_page_content.\n";
                }
                $p .= "\n";
            }
        }

        if ( '' !== $target_url || '' !== $target_title || '' !== $target_kind ) {
            $p .= "## Current Live URL Context\n";
            if ( '' !== $target_title ) {
                $p .= "- Title: {$target_title}\n";
            }
            if ( '' !== $target_kind ) {
                $p .= "- Kind: {$target_kind}\n";
            }
            if ( '' !== $target_url ) {
                $p .= "- URL: {$target_url}\n";
            }
            $p .= "- Treat this live same-origin URL as the active screen the user opened from the admin bar.\n";

            // Inject smart guidance based on route kind
            $kind_lower = strtolower( $target_kind );
            if ( 'posts_page' === $kind_lower || 'home' === $kind_lower ) {
                $p .= "- ⚠️ POSTS_PAGE / ARCHIVE ROUTE: This is a blog/posts listing page. Visible text (title, subtitle, description) typically comes from a PHP template file in the theme or a plugin.\n";
                $p .= "  → Use find_page_element first. It renders the page and greps ALL theme + plugin files for the exact visible text.\n";
                $p .= "  → Only fall back to get_page_content if find_page_element returns no results.\n";
            } elseif ( str_starts_with( $kind_lower, 'archive' ) || 'category' === $kind_lower || 'tag' === $kind_lower || str_starts_with( $kind_lower, 'taxonomy' ) ) {
                $p .= "- ⚠️ ARCHIVE ROUTE: This is an archive page (e.g. /blog/, /category/news/). The visible header text (title, subtitle, description) ALWAYS lives in a PHP template file — either in the active theme or in a plugin that injects it via template_include hook.\n";
                $p .= "  → Use find_page_element first. It will locate the file for you without guessing the template hierarchy.\n";
                $p .= "  → Do NOT call inspect_url_context for text edits on archive pages — it often misses plugin-injected templates.\n";
            } elseif ( 'front_page' === $kind_lower ) {
                $p .= "- FRONT PAGE ROUTE: Inspect visible sections with inspect_visible_text_targets and inspect_front_page before editing files.\n";
            }
            $p .= "\n";
        }

        if ( $compact ) {
            $p .= "## Cost-Saving Operating Mode\n";
            $p .= "- Simple task. Minimum tool steps.\n";
            $p .= "- For page copy edits: get_page_content first, then update_page_content. Avoid filesystem search if the content is in post_content.\n";
            $p .= "- For posts_page subtitle/description: get_site_settings → find page_for_posts → get_page_content on that page → update_page_content.\n";
            $p .= "- For archive header text: inspect_url_context → read the template → replace_text_in_file.\n";
            $p .= "- For theme customizer values: get_theme_mods → update_theme_mod.\n";
            $p .= "- For site name/tagline: update_site_settings.\n";
            $p .= "- For page structure words (title/subtitle/eyebrow/description): inspect_visible_text_targets first.\n";
            $p .= "- Keep response short and factual.\n\n";
        }

        $p .= "## Agent Protocol — DECISION TREE (follow this order)\n\n";

        $p .= "### Step 0 — Understand what you're looking at\n";
        $p .= "- ALWAYS start by understanding the route/context before touching files.\n";
        $p .= "- If target_kind is known (posts_page, archive_*, category, front_page, page, single_*): use that to pick your strategy.\n";
        $p .= "- If target_kind is unknown and a URL is present: call inspect_url_context first.\n\n";

        $p .= "### Step 1 — Match the task to the right data layer\n";
        $p .= "| Task | Primary tool |\n";
        $p .= "|------|--------------|\n";
        $p .= "| Edit post/page title, content, excerpt, status | get_page_content / update_page_content OR get_post / update_post_fields |\n";
        $p .= "| Edit posts_page subtitle/description/header text | get_site_settings → find page_for_posts ID → get_page_content → update_page_content |\n";
        $p .= "| Edit archive/category header text | find_page_element → replace_text_in_file |\n";
        $p .= "| Edit site name or tagline | update_site_settings |\n";
        $p .= "| Edit menu item labels or URLs | get_nav_menus → get_nav_menu_items → update_nav_menu_item |\n";
        $p .= "| Edit customizer color/font/layout setting | get_theme_mods → update_theme_mod |\n";
        $p .= "| Add/edit global CSS | get_custom_css → update_custom_css |\n";
        $p .= "| Edit template / theme PHP | read_file → replace_text_in_file or patch_file |\n";
        $p .= "| Edit custom field / ACF field | get_post_meta → update_post_meta |\n";
        $p .= "| Create new page/post | create_post |\n";
        $p .= "| Search for text across files | grep_files or search_in_files |\n";
        $p .= "| Find which template renders a route | inspect_url_context |\n";
        $p .= "| Understand visible page elements (subtitle, eyebrow, etc.) | find_page_element |\n\n";

        $p .= "### Step 2 — Locate and edit visible text (THE PRIMARY WORKFLOW)\n";
        $p .= "For ANY task that involves changing, removing, or editing text the user can see on the page:\n";
        $p .= "1. Call **find_page_element** with the current URL and the user's intent in plain English (e.g. \"remove the subtitle\").\n";
        $p .= "2. The response includes **found** — a ranked list of {text, file, line, code_context} entries.\n";
        $p .= "   - code_context shows ~16 annotated lines around the match (the matching line is marked with '>>>')\n";
        $p .= "   - The top result (highest relevance) is almost always the correct element\n";
        $p .= "3. Read code_context to confirm the exact current text, then call **replace_text_in_file** with file + old_text/new_text.\n";
        $p .= "4. Done. Do NOT call inspect_url_context, inspect_visible_text_targets, get_theme_mods, or read theme files first.\n\n";
        $p .= "If find_page_element returns no found results:\n";
        $p .= "  - Check the 'hint' field in the result — it tells you exactly why.\n";
        $p .= "  - If snippets_found = 0: call fetch_rendered_page directly on the URL, read text_snippets, then call grep_files with an exact snippet (no directory arg).\n";
        $p .= "  - If snippets were found but no file matched: call grep_files with one of the snippets listed in the result.\n";
        $p .= "  - If the text is in the database: use get_page_content, update_page_content, or get_wp_option.\n";
        $p .= "If text is a theme customizer setting: get_theme_mods → update_theme_mod.\n\n";

        $p .= "### Step 3 — Edit with the right tool\n";
        $p .= "- Targeted text change in a file → replace_text_in_file\n";
        $p .= "- Surgical code change → patch_file\n";
        $p .= "- Full file rewrite → read_file first, then write_file\n";
        $p .= "- WordPress data (post content, meta, options, menus, mods) → use the WordPress API tools, not file tools\n\n";

        $p .= "### Step 4 — Verify\n";
        $p .= "- After any UI change: fetch_rendered_page with a needle matching the new text.\n";
        $p .= "- Only report success when a write/update tool succeeded AND verification passed.\n\n";

        $p .= "## Hard Rules\n";
        $p .= "1. **find_page_element is the starting point** — for ANY visible text edit (remove subtitle, change hero text, update description, etc.), call find_page_element FIRST. Do not start with inspect_url_context, inspect_visible_text_targets, get_theme_mods, or list_directory.\n";
        $p .= "2. **NEVER grep for role keywords** — 'subtitle', 'eyebrow', 'description', 'hero' are CSS class names, not content. find_page_element handles this automatically by matching real visible text.\n";
        $p .= "3. **posts_page header text** — if find_page_element returns no results, also check page_for_posts post_content via get_page_content.\n";
        $p .= "4. **Read before write** — always read the full file (or use code_context) before overwriting it.\n";
        $p .= "5. **WordPress-correct code** — use esc_html(), wp_enqueue_*(), proper hooks, nonces.\n";
        $p .= "6. **Do not give up** — if find_page_element returns no matches, fall back to fetch_rendered_page + grep_files with an exact text snippet from text_snippets.\n";
        $p .= "7. **Always respond** — after all tool calls, write a summary. Never end silently.\n";
        $p .= "8. **Do not claim success without a write** — only report done when a write/update/patch tool actually succeeded.\n\n";

        $p .= "## Available Tool Categories\n";
        $p .= "- **File ops**: list_directory, get_directory_tree, read_file, read_multiple_files, write_file, replace_text_in_file, patch_file, search_in_files, grep_files, stat_path, make_directory, move_path, delete_path\n";
        $p .= "- **Posts/content**: list_posts, list_pages, get_post, get_page_content, update_page_content, update_post_fields, create_post, search_posts, get_post_meta, update_post_meta\n";
        $p .= "- **Site settings**: get_site_settings, update_site_settings, get_wp_option, update_wp_option\n";
        $p .= "- **Theme**: get_theme_mods, update_theme_mod, get_custom_css, update_custom_css\n";
        $p .= "- **Navigation**: get_nav_menus, get_nav_menu_items, update_nav_menu_item\n";
        $p .= "- **Taxonomies/CPTs**: list_post_types, list_taxonomies, list_terms\n";
        $p .= "- **Inspect/render**: find_page_element, inspect_front_page, inspect_url_context, inspect_visible_text_targets, inspect_page, inspect_sidebars, find_widgets_by_text, fetch_rendered_page, find_ui_candidates\n";
        $p .= "- **Widgets**: clone_widget_to_sidebar, replace_text_in_widget, list_widget_visibility_rules, ensure_widget_visibility_rule\n";
        $p .= "- **Commands**: command_runner_status, run_command\n\n";

        $p .= "## Final Response Format\n";
        $p .= "After all tool calls are done, write a clear summary:\n";
        $p .= "- What was changed (files, post IDs, settings keys)\n";
        $p .= "- What the changes accomplish\n";
        $p .= "- Any manual steps the user needs to take in WordPress admin\n";

        if ( '' !== $agent_manual && ! $compact ) {
            $p .= "\n## Agent Manual\n";
            $p .= $agent_manual . "\n";
        }

        return $p;
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

        if ( $this->normalize_url_port( $home_parts ) !== $this->normalize_url_port( $target_parts ) ) {
            return '';
        }

        return $url;
    }

    private function normalize_url_port( array $parts ): ?int {
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

    private function load_agent_manual(): string {
        $paths = [
            trailingslashit( PV_PLUGIN_DIR ) . 'AGENTS.md',
            trailingslashit( PV_PLUGIN_DIR ) . 'AGENT.md',
        ];

        foreach ( $paths as $path ) {
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                continue;
            }

            $contents = file_get_contents( $path );
            if ( false === $contents ) {
                continue;
            }

            return $this->compress_agent_manual( trim( preg_replace( '/\r\n?/', "\n", $contents ) ) );
        }

        return '';
    }

    private function compress_agent_manual( string $contents ): string {
        $contents = trim( $contents );
        if ( '' === $contents ) {
            return '';
        }

        if ( strlen( $contents ) <= self::AGENT_MANUAL_CHAR_LIMIT ) {
            return $contents;
        }

        $trimmed = substr( $contents, 0, self::AGENT_MANUAL_CHAR_LIMIT );
        $last_break = strrpos( $trimmed, "\n" );
        if ( false !== $last_break && $last_break > 200 ) {
            $trimmed = substr( $trimmed, 0, $last_break );
        }

        return rtrim( $trimmed ) . "\n- Additional manual details omitted for token efficiency.";
    }

    private function find_ui_candidates( array $terms, array $directories = [], int $max_per_term = 8 ): array {
        $terms = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $term ) {
                            return trim( (string) $term );
                        },
                        $terms
                    ),
                    static function ( string $term ) {
                        return strlen( $term ) >= 2;
                    }
                )
            )
        );

        if ( empty( $terms ) ) {
            return [ 'error' => 'At least one non-empty search term is required.' ];
        }

        $directories = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $directory ) {
                            return ltrim( trim( (string) $directory ), '/' );
                        },
                        $directories
                    )
                )
            )
        );

        if ( empty( $directories ) ) {
            $directories = $this->get_default_ui_search_directories();
        }

        $max_per_term = max( 1, min( 20, $max_per_term ) );
        $results      = [];
        $errors       = [];
        $seen         = [];

        foreach ( $directories as $directory ) {
            foreach ( $terms as $term ) {
                $search = $this->fm->fm_search( $term, $directory, 'content' );
                if ( is_wp_error( $search ) ) {
                    $errors[] = [
                        'directory' => $directory,
                        'term'      => $term,
                        'message'   => $search->get_error_message(),
                    ];
                    continue;
                }

                $matches = array_slice( $search['matches'] ?? [], 0, $max_per_term );
                foreach ( $matches as $match ) {
                    $key = implode(
                        '|',
                        [
                            $term,
                            $match['path'] ?? '',
                            (string) ( $match['line'] ?? 0 ),
                        ]
                    );

                    if ( isset( $seen[ $key ] ) ) {
                        continue;
                    }

                    $seen[ $key ] = true;
                    $results[]    = [
                        'term'      => $term,
                        'directory' => $directory,
                        'path'      => $match['path'] ?? '',
                        'line'      => $match['line'] ?? null,
                        'snippet'   => $match['snippet'] ?? '',
                        'excerpt'   => $match['excerpt'] ?? '',
                    ];
                }
            }
        }

        usort(
            $results,
            static function ( array $a, array $b ) {
                return [ $a['path'], (int) $a['line'], $a['term'] ] <=> [ $b['path'], (int) $b['line'], $b['term'] ];
            }
        );

        return [
            'terms'       => $terms,
            'directories' => $directories,
            'results'     => $results,
            'errors'      => $errors,
            'total'       => count( $results ),
        ];
    }

    /**
     * THE CORE PIPELINE — implements the user's "View Source → grep → show context" workflow:
     *
     * 1. Fetch the page HTML (View Source)
     * 2. Parse the HTML to extract candidate text elements (headings, paragraphs,
     *    badges, subtitles) — these are the ACTUAL strings visible on the page
     * 3. Score each element against the user's intent to prioritise the most relevant
     * 4. For each candidate, grep all theme + plugin PHP files for that text
     * 5. For each match found, read ONLY the surrounding lines (not the whole file)
     * 6. Return: {text, file, line, context_lines} — everything the model needs to
     *    call replace_text_in_file immediately, with zero further searching
     *
     * @param string $url     The page URL to inspect (same-origin).
     * @param string $intent  What the user wants to change, e.g. "remove the subtitle".
     */
    private function find_page_element( string $url, string $intent ): array {
        if ( '' === trim( $url ) ) {
            return [ 'error' => 'url is required.' ];
        }

        // ── Step 1: Fetch the rendered HTML ──────────────────────────────────
        // Searches wp-content/ only (themes + plugins) — not wp-admin or wp-includes.
        $search_dir = 'wp-content';

        $render   = $this->site->fetch_rendered_page( 0, $url );
        $snippets = [];
        if ( ! is_wp_error( $render ) && ! empty( $render['text_snippets'] ) ) {
            $snippets = (array) $render['text_snippets'];
        }

        // ── Step 2: Score snippets against the user intent ───────────────────
        $intent_keywords = array_filter( preg_split( '/\W+/', strtolower( $intent ) ) );
        $stop_words      = [ 'the', 'a', 'an', 'this', 'that', 'it', 'is', 'are', 'was',
                             'remove', 'delete', 'change', 'edit', 'update', 'replace',
                             'add', 'page', 'on', 'in', 'to', 'of', 'and', 'or', 'please' ];
        $intent_keywords = array_values( array_diff( $intent_keywords, $stop_words ) );

        $scored = [];
        foreach ( $snippets as $i => $snippet ) {
            $lower = strtolower( (string) $snippet );
            $score = 0;
            foreach ( $intent_keywords as $kw ) {
                if ( stripos( $lower, $kw ) !== false ) {
                    $score += 2;
                }
            }
            $len = strlen( (string) $snippet );
            if ( $len >= 30 && $len <= 200 ) {
                $score += 1; // subtitle/description length gets a bonus
            }
            $score += max( 0, 5 - $i ); // earlier in page = more prominent
            $scored[] = [ 'text' => (string) $snippet, 'score' => $score, 'index' => $i ];
        }
        usort( $scored, static function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        // ── Step 3: Grep wp-content for the top candidates ───────────────────
        // We decode HTML entities in the grep pattern (e.g. &amp; → &) so the
        // raw PHP source file is matched even when the rendered text was entity-encoded.
        $found     = [];
        $seen_file = [];
        $checked   = 0;

        foreach ( $scored as $candidate ) {
            if ( $checked >= 8 ) {
                break;
            }
            $text    = (string) $candidate['text'];
            // Decode HTML entities so the rendered text matches the PHP source string
            $decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $pattern = substr( $decoded, 0, 60 ); // 60 chars is unique enough
            if ( strlen( trim( $pattern ) ) < 8 ) {
                $checked++;
                continue; // Skip trivially short patterns
            }

            $search = $this->fm->fm_search( $pattern, $search_dir, 'content' );
            $checked++;

            if ( is_wp_error( $search ) || empty( $search['matches'] ) ) {
                // Also try the raw (non-decoded) pattern in case the PHP source has entities
                $raw_pattern = substr( $text, 0, 60 );
                if ( $raw_pattern !== $pattern ) {
                    $search2 = $this->fm->fm_search( $raw_pattern, $search_dir, 'content' );
                    if ( ! is_wp_error( $search2 ) && ! empty( $search2['matches'] ) ) {
                        $search = $search2;
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            foreach ( array_slice( $search['matches'], 0, 3 ) as $match ) {
                $file      = (string) ( $match['path'] ?? '' );
                $line      = (int) ( $match['line'] ?? 0 );
                $line_text = (string) ( $match['snippet'] ?? '' ); // The raw PHP line containing the text
                if ( '' === $file || isset( $seen_file[ $file . ':' . $line ] ) ) {
                    continue;
                }
                $seen_file[ $file . ':' . $line ] = true;

                // ── Step 4: Read ONLY the surrounding lines ───────────────────
                $context = $this->fm->read_lines_context( $file, $line, 8 );

                $found[] = [
                    'text'         => $text,
                    'relevance'    => $candidate['score'],
                    'file'         => $file,
                    'line'         => $line,
                    'line_text'    => $line_text, // Raw PHP line — use this as old_text for replace_text_in_file
                    'code_context' => $context,
                ];
            }

            if ( count( $found ) >= 5 ) {
                break;
            }
        }

        if ( empty( $found ) ) {
            return [
                'found'          => [],
                'message'        => 'No matching PHP/template source found. The text may be in the WordPress database (try get_page_content or get_wp_option) or the page failed to render.',
                'page_url'       => $url,
                'snippets_found' => count( $snippets ),
                'snippets'       => array_slice( $snippets, 0, 10 ),
                'hint'           => count( $snippets ) === 0
                    ? 'IMPORTANT: fetch_rendered_page returned no text snippets. The HTTP loopback may have failed. Try calling fetch_rendered_page directly with this URL and check text_snippets. If text_snippets is empty, use grep_files with a quoted phrase from the visible page text.'
                    : 'Snippets were found but did not match any file. Try grep_files with one of the snippets listed above.',
            ];
        }

        return [
            'found'        => $found,
            'total'        => count( $found ),
            'instructions' => 'Use the top result: confirm code_context shows the exact current text, then call replace_text_in_file with that file path.',
        ];
    }

    private function get_default_ui_search_directories(): array {
        $directories = [ $this->get_theme_relative_path() ];

        foreach ( $this->get_custom_plugin_relative_paths() as $plugin_path ) {
            $directories[] = $plugin_path;
        }

        return array_values( array_unique( array_filter( $directories ) ) );
    }

    private function get_theme_relative_path(): string {
        return ltrim( str_replace( ABSPATH, '', get_template_directory() ), '/' );
    }

    private function get_custom_plugin_relative_paths(): array {
        $active_plugins = (array) get_option( 'active_plugins', [] );
        $blocked        = [ 'akismet', 'litespeed-cache', 'google-analytics-for-wordpress', 'pressviber' ];
        $paths          = [];

        foreach ( $active_plugins as $plugin_file ) {
            $plugin_file = ltrim( (string) $plugin_file, '/' );
            if ( '' === $plugin_file ) {
                continue;
            }

            $slug = dirname( $plugin_file );
            if ( '.' === $slug || '' === $slug ) {
                $slug = basename( $plugin_file, '.php' );
            }

            if ( in_array( $slug, $blocked, true ) ) {
                continue;
            }

            $paths[] = 'wp-content/plugins/' . $slug;
        }

        return array_values( array_unique( $paths ) );
    }

    /* =========================================================================
       TOOL SUMMARY  (short human-readable label shown in chat)
       ========================================================================= */

    private function summarize( string $name, array $args, $result ): string {
        $path = $args['path'] ?? $args['directory'] ?? '';

        if ( is_wp_error( $result ) || isset( $result['error'] ) ) {
            $err = is_wp_error( $result ) ? $result->get_error_message() : $result['error'];
            return "$name failed — $err";
        }

        switch ( $name ) {
            case 'command_runner_status':
                return ! empty( $result['enabled'] ) ? 'Checked command runner status (enabled)' : 'Checked command runner status (disabled)';

            case 'run_command':
                $code = $result['exit_code'] ?? 0;
                return "Ran command (exit $code)";

            case 'list_directory':
                $n = count( $result['entries'] ?? [] );
                return "Listed " . ( $path ?: '/' ) . "  ($n items)";

            case 'read_file':
                $size = isset( $result['size'] ) ? size_format( $result['size'] ) : '';
                return "Read $path" . ( $size ? "  ($size)" : '' );

            case 'read_multiple_files':
                $n = count( $result['files'] ?? [] );
                return "Read $n file" . ( 1 === $n ? '' : 's' ) . " in one batch";

            case 'write_file':
                $bytes = $result['bytes_written'] ?? 0;
                $bak   = ! empty( $result['backup'] ) ? '  (backup saved)' : '';
                return "Wrote $path  (" . size_format( $bytes ) . ")$bak";

            case 'replace_text_in_file':
                $count = $result['replacements'] ?? 0;
                $bak   = ! empty( $result['backup'] ) ? '  (backup saved)' : '';
                return "Replaced $count instance" . ( 1 === $count ? '' : 's' ) . " in $path$bak";

            case 'search_in_files':
                $n = $result['total'] ?? 0;
                return "Found $n match" . ( $n === 1 ? '' : 'es' ) . " for \"{$args['pattern']}\"";

            case 'patch_file':
                $n = count( $result['operations_applied'] ?? [] );
                return "Patched $path with $n operation" . ( 1 === $n ? '' : 's' );

            case 'stat_path':
                return "Inspected " . $path;

            case 'get_directory_tree':
                $n = count( $result['tree'] ?? [] );
                return "Mapped " . ( $path ?: '/' ) . " tree  ($n entries)";

            case 'make_directory':
                return ! empty( $result['created'] ) ? "Created directory $path" : "Directory already exists: $path";

            case 'move_path':
                return "Moved {$result['from']} to {$result['to']}";

            case 'delete_path':
                return "Moved $path to trash";

            case 'list_pages':
                $n = $result['total'] ?? 0;
                return "Listed $n WordPress page" . ( 1 === $n ? '' : 's' );

            case 'inspect_front_page':
                $mode = $result['front_page']['mode'] ?? 'unknown';
                return "Inspected homepage context ({$mode})";

            case 'inspect_url_context':
                $kind  = $result['kind'] ?? 'live_url';
                $title = $result['title'] ?? '';
                return '' !== $title ? "Inspected URL context ({$kind}): {$title}" : "Inspected URL context ({$kind})";

            case 'inspect_visible_text_targets':
                $n = $result['total'] ?? 0;
                return "Mapped $n visible text target" . ( 1 === $n ? '' : 's' );

            case 'inspect_sidebars':
                $n = $result['sidebar_count'] ?? 0;
                return "Inspected $n sidebar" . ( 1 === $n ? '' : 's' );

            case 'find_widgets_by_text':
                $n = $result['total'] ?? 0;
                return "Found $n widget text match" . ( 1 === $n ? '' : 'es' );

            case 'clone_widget_to_sidebar':
                return "Cloned {$result['source_widget_id']} to {$result['sidebar_id']}";

            case 'list_widget_visibility_rules':
                $n = $result['total'] ?? 0;
                return "Listed $n widget visibility rule" . ( 1 === $n ? '' : 's' );

            case 'ensure_widget_visibility_rule':
                return ! empty( $result['created'] )
                    ? "Created widget rule for {$result['sidebar_id']} on {$result['path']}"
                    : "Widget rule already exists for {$result['sidebar_id']} on {$result['path']}";

            case 'replace_text_in_widget':
                $count = $result['replacements'] ?? 0;
                return "Replaced $count widget text instance" . ( 1 === $count ? '' : 's' ) . " in {$result['widget_id']}";

            case 'inspect_page':
                $title = $result['page']['title'] ?? 'page';
                return "Inspected page: $title";

            case 'get_page_content':
                $title = $result['page']['title'] ?? 'page';
                return "Loaded page content: $title";

            case 'update_page_content':
                $page_id = isset( $args['page_id'] ) ? (int) $args['page_id'] : 0;
                return "Updated WordPress page content" . ( $page_id > 0 ? " (#$page_id)" : '' );

            case 'fetch_rendered_page': {
                $status = $result['status_code'] ?? 0;
                $locs   = $result['source_locations'] ?? [];
                $suffix = '';
                if ( ! empty( $locs ) ) {
                    $files  = array_unique( array_column( $locs, 'file' ) );
                    $suffix = ' — located in: ' . implode( ', ', array_slice( $files, 0, 3 ) );
                }
                return "Fetched rendered page (HTTP $status){$suffix}";
            }

            case 'find_ui_candidates':
                $n = $result['total'] ?? 0;
                return "Found $n UI source candidate" . ( 1 === $n ? '' : 's' );

            // Post / content tools
            case 'list_posts': {
                $n    = $result['returned'] ?? 0;
                $type = $result['post_type'] ?? 'post';
                return "Listed $n {$type}" . ( 1 === $n ? '' : 's' );
            }

            case 'get_post': {
                $title = $result['title'] ?? '';
                return '' !== $title ? "Loaded post: $title" : 'Loaded post';
            }

            case 'create_post': {
                $title = $result['title'] ?? '';
                $id    = $result['post_id'] ?? '';
                return '' !== $title ? "Created post: $title (ID $id)" : "Created post ID $id";
            }

            case 'update_post_fields': {
                $id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
                return "Updated post fields" . ( $id > 0 ? " (#$id)" : '' );
            }

            case 'search_posts': {
                $n = $result['total'] ?? 0;
                return "Found $n post" . ( 1 === $n ? '' : 's' ) . ' matching search';
            }

            case 'get_post_meta': {
                $key = $result['meta_key'] ?? '';
                return '' !== $key ? "Read post meta: $key" : 'Read all post meta';
            }

            case 'update_post_meta': {
                $key = $result['meta_key'] ?? ( $args['meta_key'] ?? '' );
                return '' !== $key ? "Updated post meta: $key" : 'Updated post meta';
            }

            // Site settings / options
            case 'get_site_settings':
                return 'Read site settings';

            case 'update_site_settings': {
                $n = $result['count'] ?? 0;
                return "Updated $n site setting" . ( 1 === $n ? '' : 's' );
            }

            case 'get_wp_option': {
                $key = $result['option_key'] ?? ( $args['option_key'] ?? '' );
                return "Read option: $key";
            }

            case 'update_wp_option': {
                $key = $result['option_key'] ?? ( $args['option_key'] ?? '' );
                return "Updated option: $key";
            }

            case 'get_custom_css':
                return 'Read custom CSS (' . ( $result['length'] ?? 0 ) . ' chars)';

            case 'update_custom_css':
                return 'Updated custom CSS (' . ( $result['length'] ?? 0 ) . ' chars)';

            // Navigation menus
            case 'get_nav_menus': {
                $n = $result['total'] ?? 0;
                return "Found $n navigation menu" . ( 1 === $n ? '' : 's' );
            }

            case 'get_nav_menu_items': {
                $n = $result['total'] ?? 0;
                return "Loaded $n menu item" . ( 1 === $n ? '' : 's' );
            }

            case 'update_nav_menu_item': {
                $id = isset( $args['item_id'] ) ? (int) $args['item_id'] : ( $result['item_id'] ?? 0 );
                return "Updated menu item #$id";
            }

            // Theme mods / customizer
            case 'get_theme_mods': {
                $n = $result['count'] ?? 0;
                return "Read $n theme customizer setting" . ( 1 === $n ? '' : 's' );
            }

            case 'update_theme_mod': {
                $key = $result['key'] ?? ( $args['mod_key'] ?? '' );
                return "Updated theme mod: $key";
            }

            // CPTs & taxonomies
            case 'list_post_types': {
                $n = $result['total'] ?? 0;
                return "Found $n post type" . ( 1 === $n ? '' : 's' );
            }

            case 'list_taxonomies': {
                $n = $result['total'] ?? 0;
                return "Found $n taxonom" . ( 1 === $n ? 'y' : 'ies' );
            }

            case 'list_terms': {
                $n    = $result['total'] ?? 0;
                $tax  = $result['taxonomy'] ?? '';
                return "Found $n term" . ( 1 === $n ? '' : 's' ) . ( '' !== $tax ? " in $tax" : '' );
            }

            case 'find_page_element': {
                $n = $result['total'] ?? 0;
                if ( $n > 0 ) {
                    $top  = $result['found'][0];
                    $file = $top['file'] ?? '';
                    $line = $top['line'] ?? 0;
                    return "Located page element in $file (line $line) — $n candidate" . ( 1 === $n ? '' : 's' ) . ' found';
                }
                $sc = $result['snippets_found'] ?? '?';
                return "find_page_element — no source match (snippets_found={$sc}); check result.hint for next steps";
            }

            // Grep
            case 'grep_files': {
                $n = $result['total'] ?? 0;
                return "Found $n match" . ( 1 === $n ? '' : 'es' ) . ' in files';
            }

            default:
                return $name;
        }
    }

    /* =========================================================================
       SSE EMIT HELPERS
       ========================================================================= */

    private function emit( string $type, array $data ): void {
        echo 'data: ' . wp_json_encode( [ 'type' => $type, 'data' => $data ] ) . "\n\n";
        if ( ob_get_level() > 0 ) ob_flush();
        flush();
    }

    private function emit_and_die( string $type, array $data ): void {
        // Headers might not be set yet if we fail early
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/event-stream; charset=UTF-8' );
            header( 'Cache-Control: no-cache' );
        }
        $this->emit( $type, $data );
        $this->emit( 'done', [] );
        die();
    }
}
