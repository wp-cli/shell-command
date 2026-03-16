<?php

namespace WP_CLI\Shell;

use WP_CLI;

class REPL {

	private $prompt;

	private $history_file;

	private $watch_path;

	private $watch_mtime;

	const EXIT_CODE_RESTART = 10;

	public function __construct( $prompt ) {
		$this->prompt = $prompt;

		$this->set_history_file();
	}

	/**
	 * Set a path to watch for changes.
	 *
	 * @param string $path Path to watch for changes.
	 */
	public function set_watch_path( $path ) {
		$this->watch_path  = $path;
		$this->watch_mtime = $this->get_recursive_mtime( $path );
	}

	public function start() {
		while ( true ) {
			// Check for file changes if watching
			if ( $this->watch_path && $this->has_changes() ) {
				WP_CLI::log( "Detected changes in {$this->watch_path}, restarting shell..." );
				return self::EXIT_CODE_RESTART;
			}
			$__repl_input_line = $this->prompt();

			if ( '' === $__repl_input_line ) {
				continue;
			}

			// Check for special exit command
			if ( 'exit' === trim( $__repl_input_line ) ) {
				return 0;
			}

			// Check for special restart command
			if ( 'restart' === trim( $__repl_input_line ) ) {
				WP_CLI::log( 'Restarting shell...' );
				return self::EXIT_CODE_RESTART;
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

			$line = $fp ? fgets( $fp ) : '';

			if ( $fp ) {
				pclose( $fp );
			}

			if ( ! $line ) {
				break;
			}

			$line = rtrim( $line, "\n" );

			if ( $line && '\\' === $line[ strlen( $line ) - 1 ] ) {
				$line = substr( $line, 0, -1 );
			} else {
				$done = true;
			}

			$full_line .= $line;

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
		} elseif ( is_file( '/bin/bash' ) && is_readable( '/bin/bash' ) ) {
			// Prefer /bin/bash when available since we use bash-specific commands.
			$shell_binary = '/bin/bash';
		} elseif ( getenv( 'SHELL' ) && self::is_bash_shell( (string) getenv( 'SHELL' ) ) ) {
			// Only use SHELL as fallback if it's bash (we use bash-specific commands).
			$shell_binary = (string) getenv( 'SHELL' );
		} else {
			// Final fallback for systems without /bin/bash.
			$shell_binary = 'bash';
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

	/**
	 * Check if a shell binary is bash or bash-compatible.
	 *
	 * @param string $shell_path Path to the shell binary.
	 * @return bool True if the shell is bash, false otherwise.
	 */
	private static function is_bash_shell( $shell_path ) {
		if ( ! is_file( $shell_path ) || ! is_readable( $shell_path ) ) {
			return false;
		}
		// Check if the basename is exactly 'bash' or starts with 'bash' followed by a version/variant.
		$basename = basename( $shell_path );
		return 'bash' === $basename || 0 === strpos( $basename, 'bash-' );
	}

	private function set_history_file() {
		$data = getcwd() . get_current_user();

		$this->history_file = \WP_CLI\Utils\get_temp_dir() . 'wp-cli-history-' . md5( $data );
	}

	private static function starts_with( $tokens, $line ) {
		return preg_match( "/^($tokens)[\(\s]+/", $line );
	}

	/**
	 * Check if the watched path has changes.
	 *
	 * @return bool True if changes detected, false otherwise.
	 */
	private function has_changes() {
		if ( ! $this->watch_path ) {
			return false;
		}

		$current_mtime = $this->get_recursive_mtime( $this->watch_path );
		return $current_mtime !== $this->watch_mtime;
	}

	/**
	 * Get the most recent modification time for a path recursively.
	 *
	 * @param string $path Path to check.
	 * @return int Most recent modification time.
	 */
	private function get_recursive_mtime( $path ) {
		$mtime = 0;

		if ( is_file( $path ) ) {
			$file_mtime = filemtime( $path );
			return false !== $file_mtime ? $file_mtime : 0;
		}

		if ( is_dir( $path ) ) {
			$dir_mtime = filemtime( $path );
			$mtime     = false !== $dir_mtime ? $dir_mtime : 0;

			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::SELF_FIRST
				);

				foreach ( $iterator as $file ) {
					/** @var \SplFileInfo $file */
					$file_mtime = $file->getMTime();
					if ( $file_mtime > $mtime ) {
						$mtime = $file_mtime;
					}
				}
			} catch ( \UnexpectedValueException $e ) {
				// Handle unreadable directories/files gracefully.
				WP_CLI::warning(
					sprintf(
						'Could not read path "%s" while checking for changes: %s',
						$path,
						$e->getMessage()
					)
				);
			}
		}

		return $mtime;
	}
}
