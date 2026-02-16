<?php

namespace WP_CLI\Shell;

use WP_CLI;

class REPL {

	private $prompt;

	private $history_file;

	public function __construct( $prompt ) {
		$this->prompt = $prompt;

		$this->set_history_file();
	}

	public function start() {
		// @phpstan-ignore while.alwaysTrue
		while ( true ) {
			$__repl_input_line = $this->prompt();

			if ( '' === $__repl_input_line ) {
				continue;
			}

			$__repl_input_line = rtrim( $__repl_input_line, ';' ) . ';';

			if ( self::starts_with( self::non_expressions(), $__repl_input_line ) ) {
				ob_start();
				// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This is meant to be a REPL, no way to avoid eval.
				eval( $__repl_input_line );
				$__repl_output = (string) ob_get_clean();
				if ( 0 < strlen( $__repl_output ) ) {
					$__repl_output = rtrim( $__repl_output, "\n" ) . "\n";
				}
				fwrite( STDOUT, $__repl_output );
			} else {
				if ( ! self::starts_with( 'return', $__repl_input_line ) ) {
					$__repl_input_line = 'return ' . $__repl_input_line;
				}

				// Write directly to STDOUT, to sidestep any output buffers created by plugins
				ob_start();
				// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This is meant to be a REPL, no way to avoid eval.
				$__repl_eval_result = eval( $__repl_input_line );
				$__repl_output      = (string) ob_get_clean();
				if ( 0 < strlen( $__repl_output ) ) {
					echo rtrim( $__repl_output, "\n" ) . "\n";
				}
				echo '=> ';
				var_dump( $__repl_eval_result );
				fwrite( STDOUT, (string) ob_get_clean() );
			}
		}
	}

	private static function non_expressions() {
		return implode(
			'|',
			array(
				'echo',
				'global',
				'unset',
				'function',
				'do',
				'while',
				'for',
				'foreach',
				'if',
				'switch',
				'include',
				'include\_once',
				'require',
				'require\_once',
			)
		);
	}

	private function prompt() {
		$full_line = false;

		$done = false;
		do {
			// @phpstan-ignore booleanNot.alwaysTrue
			$prompt = ( ! $done && false !== $full_line ) ? '--> ' : $this->prompt;

			$fp = popen( self::create_prompt_cmd( $prompt, $this->history_file ), 'r' );

			$__repl_input_line = $fp ? fgets( $fp ) : '';

			if ( $fp ) {
				pclose( $fp );
			}

			if ( ! $__repl_input_line ) {
				break;
			}

			$__repl_input_line = rtrim( $__repl_input_line, "\n" );

			if ( $__repl_input_line && '\\' === $__repl_input_line[ strlen( $__repl_input_line ) - 1 ] ) {
				$__repl_input_line = substr( $__repl_input_line, 0, -1 );
			} else {
				$done = true;
			}

			$full_line .= $__repl_input_line;

		} while ( ! $done );

		if ( false === $full_line ) {
			return 'exit';
		}

		return $full_line;
	}

	private static function create_prompt_cmd( $prompt, $history_path ) {
		$prompt       = escapeshellarg( $prompt );
		$history_path = escapeshellarg( $history_path );
		if ( getenv( 'WP_CLI_CUSTOM_SHELL' ) ) {
			$shell_binary = (string) getenv( 'WP_CLI_CUSTOM_SHELL' );
		} else {
			$shell_binary = '/bin/bash';
		}

		if ( ! is_file( $shell_binary ) || ! is_readable( $shell_binary ) ) {
			WP_CLI::error( "The shell binary '{$shell_binary}' is not valid. You can override the shell to be used through the WP_CLI_CUSTOM_SHELL environment variable." );
		}

		$shell_binary = escapeshellarg( $shell_binary );

		$cmd = 'set -f; '
			. "history -r {$history_path}; "
			. 'LINE=""; '
			. "read -re -p {$prompt} LINE; "
			. '[ $? -eq 0 ] || exit; '
			. 'history -s -- "$LINE"; '
			. "history -w {$history_path}; "
			. 'echo $LINE; ';

		return "{$shell_binary} -c " . escapeshellarg( $cmd );
	}

	private function set_history_file() {
		$data = getcwd() . get_current_user();

		$this->history_file = \WP_CLI\Utils\get_temp_dir() . 'wp-cli-history-' . md5( $data );
	}

	private static function starts_with( $tokens, $__repl_input_line ) {
		return preg_match( "/^($tokens)[\(\s]+/", $__repl_input_line );
	}
}
