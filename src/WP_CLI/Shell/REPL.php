<?php

namespace WP_CLI\Shell;

use WP_CLI;

class REPL {

	private $prompt;

	private $history_file;

	/** @var bool Whether to suppress automatic output. */
	private $quiet;

	private $watch_path;

	private $watch_mtime;

	const EXIT_CODE_RESTART = 10;

	/**
	 * @param string $prompt Prompt to display.
	 * @param bool   $quiet  Whether to suppress automatic output.
	 */
	public function __construct( $prompt, $quiet = false ) {
		$this->prompt = $prompt;
		$this->quiet  = $quiet;

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
				try {
					// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This is meant to be a REPL, no way to avoid eval.
					eval( $__repl_input_line );
				} catch ( \Throwable $e ) {
					// Display the error message but continue the session
					fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" );
				}
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
				$__repl_eval_had_error = false;
				try {
					// phpcs:ignore Squiz.PHP.Eval.Discouraged -- This is meant to be a REPL, no way to avoid eval.
					$__repl_eval_result = eval( $__repl_input_line );
				} catch ( \Throwable $e ) {
					// Display the error message but continue the session
					fwrite( STDERR, get_class( $e ) . ': ' . $e->getMessage() . "\n" );
					$__repl_eval_had_error = true;
					$__repl_eval_result    = null;
				}
				$__repl_output = (string) ob_get_clean();
				if ( 0 < strlen( $__repl_output ) ) {
					echo rtrim( $__repl_output, "\n" ) . "\n";
				}
				if ( $__repl_eval_had_error ) {
					continue;
				}
				ob_start();
				if ( ! $this->quiet ) {
					echo '=> ';
					var_dump( $__repl_eval_result );
				}
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
		$is_windows = \WP_CLI\Utils\is_windows();

		if ( getenv( 'WP_CLI_CUSTOM_SHELL' ) ) {
			$shell_binary = (string) getenv( 'WP_CLI_CUSTOM_SHELL' );
		} elseif ( $is_windows ) {
			$shell_binary = 'powershell.exe';
		} elseif ( is_file( '/bin/bash' ) && is_readable( '/bin/bash' ) ) {
			// Prefer /bin/bash when available since we use bash-specific commands.
			$shell_binary = '/bin/bash';
		} elseif ( getenv( 'SHELL' ) && self::is_supported_shell( (string) getenv( 'SHELL' ) ) ) {
			// Use SHELL as fallback if it's a supported shell (bash or ksh).
			$shell_binary = (string) getenv( 'SHELL' );
		} else {
			// Final fallback for systems without /bin/bash.
			$shell_binary = 'bash';
		}

		$is_powershell = $is_windows && 'powershell.exe' === $shell_binary;

		if ( $is_powershell ) {
			// PowerShell uses ` (backtick) for escaping but for strings single quotes are literal.
			// If prompt contains single quotes, we double them in PowerShell.
			$prompt_for_ps = str_replace( "'", "''", $prompt );
			$cmd = "\$line = Read-Host -Prompt '{$prompt_for_ps}'; Write-Output \$line;";
			return "powershell.exe -NoProfile -Command \"{$cmd}\"";
		}

		$prompt       = escapeshellarg( $prompt );
		$history_path = escapeshellarg( $history_path );

		$is_ksh       = self::is_ksh_shell( $shell_binary );
		$shell_binary = escapeshellarg( $shell_binary );

		if ( $is_ksh ) {
			// ksh does not support bash-specific history commands or `read -e`/`read -p`.
			// Use POSIX-compatible read and print the prompt via printf to stderr.
			$cmd = 'set -f; '
				. 'LINE=""; '
				. "printf %s {$prompt} >&2; "
				. 'IFS= read -r LINE || exit; '
				. 'printf \'%s\n\' "$LINE"; ';
		} else {
			$cmd = 'set -f; '
				. "history -r {$history_path}; "
				. 'LINE=""; '
				. "read -re -p {$prompt} LINE; "
				. '[ $? -eq 0 ] || exit; '
				. 'history -s -- "$LINE"; '
				. "history -w {$history_path}; "
				. 'printf \'%s\n\' "$LINE"; ';
		}

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

	/**
	 * Check if a shell binary is ksh or a ksh-compatible shell (mksh, pdksh, ksh93, etc.).
	 *
	 * @param string $shell_path Path to the shell binary.
	 * @return bool True if the shell is ksh-compatible, false otherwise.
	 */
	private static function is_ksh_shell( $shell_path ) {
		if ( ! is_file( $shell_path ) || ! is_readable( $shell_path ) ) {
			return false;
		}
		$basename = basename( $shell_path );
		// Matches ksh, ksh93, ksh88, mksh, pdksh, etc.
		return 0 === strpos( $basename, 'ksh' )
			|| 'mksh' === $basename
			|| 'pdksh' === $basename;
	}

	/**
	 * Check if a shell binary is supported (bash or ksh-compatible).
	 *
	 * @param string $shell_path Path to the shell binary.
	 * @return bool True if the shell is supported, false otherwise.
	 */
	private static function is_supported_shell( $shell_path ) {
		return self::is_bash_shell( $shell_path ) || self::is_ksh_shell( $shell_path );
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
