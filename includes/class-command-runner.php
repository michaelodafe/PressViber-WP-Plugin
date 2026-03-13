<?php
defined( 'ABSPATH' ) || exit;

class PV_Command_Runner {

    private string $root;

    public function __construct() {
        $this->root = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
    }

    public function status(): array {
        return [
            'enabled'          => $this->is_enabled(),
            'allowed_prefixes' => $this->allowed_prefixes(),
        ];
    }

    public function run( string $command, string $cwd = '' ) {
        if ( ! $this->is_enabled() ) {
            return new WP_Error(
                'command_runner_disabled',
                'Command execution is disabled. Define PV_ENABLE_COMMAND_RUNNER as true to enable it.'
            );
        }

        $command = trim( $command );
        if ( '' === $command ) {
            return new WP_Error( 'missing_command', 'A command is required.' );
        }

        if ( ! $this->is_command_allowed( $command ) ) {
            return new WP_Error( 'command_not_allowed', 'That command is not in the allowed command prefix list.' );
        }

        if ( $this->contains_blocked_fragment( $command ) ) {
            return new WP_Error( 'command_blocked', 'That command contains a blocked fragment.' );
        }

        $working_dir = $this->resolve_cwd( $cwd );
        if ( is_wp_error( $working_dir ) ) {
            return $working_dir;
        }

        $shell = stripos( PHP_OS_FAMILY, 'Windows' ) === 0 ? 'cmd /C ' : '/bin/sh -lc ';
        $full  = $shell . escapeshellarg( $command );

        $descriptors = [
            0 => [ 'pipe', 'r' ],
            1 => [ 'pipe', 'w' ],
            2 => [ 'pipe', 'w' ],
        ];

        $process = @proc_open( $full, $descriptors, $pipes, $working_dir );
        if ( ! is_resource( $process ) ) {
            return new WP_Error( 'command_failed', 'Could not start the command process.' );
        }

        fclose( $pipes[0] );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );

        $stdout   = '';
        $stderr   = '';
        $timeout  = 30;
        $started  = time();
        $timed_out = false;

        do {
            $stdout .= stream_get_contents( $pipes[1] );
            $stderr .= stream_get_contents( $pipes[2] );

            $status = proc_get_status( $process );
            if ( ! $status['running'] ) {
                break;
            }

            if ( ( time() - $started ) >= $timeout ) {
                $timed_out = true;
                proc_terminate( $process, 15 );
                break;
            }

            usleep( 100000 );
        } while ( true );

        $stdout .= stream_get_contents( $pipes[1] );
        $stderr .= stream_get_contents( $pipes[2] );

        fclose( $pipes[1] );
        fclose( $pipes[2] );

        $exit_code = proc_close( $process );

        return [
            'command'   => $command,
            'cwd'       => $this->to_relative( $working_dir ),
            'exit_code' => $timed_out ? 124 : $exit_code,
            'timed_out' => $timed_out,
            'stdout'    => substr( $stdout, 0, 12000 ),
            'stderr'    => substr( $stderr, 0, 12000 ),
        ];
    }

    private function is_enabled(): bool {
        return (bool) apply_filters(
            'pv_enable_command_runner',
            defined( 'PV_ENABLE_COMMAND_RUNNER' ) && PV_ENABLE_COMMAND_RUNNER
        );
    }

    private function allowed_prefixes(): array {
        $defaults = [
            'git status',
            'git diff',
            'git ls-files',
            'git show',
            'git log',
            'git branch',
            'git rev-parse',
            'php -l',
            'phpunit',
            'composer install',
            'composer test',
            'composer run',
            'npm install',
            'npm ci',
            'npm test',
            'npm run',
            'pnpm install',
            'pnpm test',
            'pnpm run',
            'yarn install',
            'yarn test',
            'yarn run',
            'wp ',
            'rg ',
            'find ',
            'ls',
            'cat ',
            'sed ',
        ];

        $prefixes = apply_filters( 'pv_allowed_command_prefixes', $defaults );
        return array_values( array_filter( array_map( 'strval', (array) $prefixes ) ) );
    }

    private function is_command_allowed( string $command ): bool {
        foreach ( $this->allowed_prefixes() as $prefix ) {
            if ( 0 === strpos( $command, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    private function contains_blocked_fragment( string $command ): bool {
        $blocked = [
            ' rm ',
            'sudo ',
            'curl ',
            'wget ',
            'ssh ',
            'scp ',
            'ftp ',
            'chmod ',
            'chown ',
            'mkfs',
            'dd ',
            'shutdown',
            'reboot',
            'kill ',
            '&&',
            '||',
            ';',
            '|',
            '$(',
            '>',
            '<',
            '`',
        ];

        $haystack = ' ' . strtolower( $command ) . ' ';
        foreach ( $blocked as $fragment ) {
            if ( false !== strpos( $haystack, strtolower( $fragment ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function resolve_cwd( string $cwd ) {
        if ( '' === $cwd ) {
            return $this->root;
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR . ltrim( str_replace( '\\', '/', $cwd ), '/' );
        $real      = realpath( $candidate );

        if ( false === $real || ! is_dir( $real ) ) {
            return new WP_Error( 'invalid_cwd', 'The working directory does not exist.' );
        }

        if ( 0 !== strpos( $real, $this->root ) ) {
            return new WP_Error( 'invalid_cwd', 'The working directory must stay inside the WordPress root.' );
        }

        return $real;
    }

    private function to_relative( string $abs ): string {
        return ltrim( str_replace( $this->root, '', $abs ), DIRECTORY_SEPARATOR ) ?: '/';
    }
}
