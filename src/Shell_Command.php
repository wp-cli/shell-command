<?php

use WP_CLI\Utils;

class Shell_Command extends WP_CLI_Command {

	/**
	 * Opens an interactive PHP console for running and testing PHP code.
	 *
	 * `wp shell` allows you to evaluate PHP statements and expressions
	 * interactively, from within a WordPress environment. Type a bit of code,
	 * hit enter, and see the code execute right before you. Because WordPress
	 * is loaded, you have access to all the functions, classes and globals
	 * that you can use within a WordPress plugin, for example.
	 *
	 * The `restart` command reloads the shell by spawning a new PHP process,
	 * allowing modified code to be fully reloaded. Note that this requires
	 * the `pcntl_exec()` function. If not available, the shell restarts
	 * in-process, which resets variables but doesn't reload PHP files.
	 *
	 * ## OPTIONS
	 *
	 * [--basic]
	 * : Force the use of WP-CLI's built-in PHP REPL, even if the Boris or
	 * PsySH PHP REPLs are available.
	 *
	 * [--watch=<path>]
	 * : Watch a file or directory for changes and automatically restart the shell.
	 * Only works with the built-in REPL (--basic).
	 *
	 * ## EXAMPLES
	 *
	 *     # Call get_bloginfo() to get the name of the site.
	 *     $ wp shell
	 *     wp> get_bloginfo( 'name' );
	 *     => string(6) "WP-CLI"
	 *
	 *     # Restart the shell to reload code changes.
	 *     $ wp shell
	 *     wp> restart
	 *     Restarting shell in new process...
	 *     wp>
	 *
	 *     # Watch a directory for changes and auto-restart.
	 *     $ wp shell --watch=wp-content/plugins/my-plugin
	 *     wp> // Make changes to files in the plugin directory
	 *     Detected changes in wp-content/plugins/my-plugin, restarting shell...
	 *     wp>
	 *
	 * @param string[] $_ Positional arguments. Unused.
	 * @param array{basic?: bool, watch?: string} $assoc_args Associative arguments.
	 */
	public function __invoke( $_, $assoc_args ) {
		$watch_path = Utils\get_flag_value( $assoc_args, 'watch', false );

		if ( $watch_path && ! Utils\get_flag_value( $assoc_args, 'basic' ) ) {
			WP_CLI::warning( 'The --watch option only works with the built-in REPL. Enabling --basic mode.' );
			$assoc_args['basic'] = true;
		}

		$class = WP_CLI\Shell\REPL::class;

		$implementations = array(
			\Psy\Shell::class,
			\Boris\Boris::class,
			WP_CLI\Shell\REPL::class,
		);

		if ( ! Utils\get_flag_value( $assoc_args, 'basic' ) ) {
			foreach ( $implementations as $candidate ) {
				if ( class_exists( $candidate ) ) {
					$class = $candidate;
					break;
				}
			}
		}

		/**
		 * @var class-string $class
		 */

		if ( \Psy\Shell::class === $class ) {
			$shell = new Psy\Shell();
			$shell->run();
		} else {
			/**
			 * @var class-string<WP_CLI\Shell\REPL> $class
			 */
			if ( $watch_path ) {
				$watch_path = $this->resolve_watch_path( $watch_path );
			}

			do {
				$repl = new $class( 'wp> ' );
				if ( $watch_path ) {
					$repl->set_watch_path( $watch_path );
				}
				$exit_code = $repl->start();

				// If restart requested, exec a new PHP process to reload all code
				if ( WP_CLI\Shell\REPL::EXIT_CODE_RESTART === $exit_code ) {
					$this->restart_process( $assoc_args );
					// If restart_process() returns, pcntl_exec is not available, continue in-process
				}
			} while ( WP_CLI\Shell\REPL::EXIT_CODE_RESTART === $exit_code );
		}
	}

	/**
	 * Resolve and validate the watch path.
	 *
	 * @param string $path Path to watch.
	 * @return string|never Absolute path to watch.
	 */
	private function resolve_watch_path( $path ) {
		if ( ! file_exists( $path ) ) {
			WP_CLI::error( "Watch path does not exist: {$path}" );
		}

		$realpath = realpath( $path );
		if ( false === $realpath ) {
			WP_CLI::error( "Could not resolve watch path: {$path}" );
		}

		return $realpath;
	}

	/**
	 * Restart the shell by spawning a new PHP process.
	 *
	 * This replaces the current process with a new one to fully reload all code.
	 * Falls back to in-process restart if pcntl_exec is not available.
	 *
	 * @param array{basic?: bool, watch?: string} $assoc_args Command arguments to preserve.
	 */
	private function restart_process( $assoc_args ) {
		// Check if pcntl_exec is available
		if ( ! function_exists( 'pcntl_exec' ) ) {
			WP_CLI::debug( 'pcntl_exec not available, falling back to in-process restart', 'shell' );
			return;
		}

		// Build the command to restart wp shell with the same arguments
		$php_binary = Utils\get_php_binary();

		// Get the WP-CLI script path
		$wp_cli_script = null;
		foreach ( array( 'argv', '_SERVER' ) as $source ) {
			if ( 'argv' === $source && is_array( $GLOBALS['argv'] ) && isset( $GLOBALS['argv'][0] ) ) {
				$wp_cli_script = $GLOBALS['argv'][0];
				break;
			} elseif ( '_SERVER' === $source && is_array( $_SERVER['argv'] ) && isset( $_SERVER['argv'][0] ) ) {
				$wp_cli_script = $_SERVER['argv'][0];
				break;
			}
		}

		if ( ! $wp_cli_script ) {
			WP_CLI::debug( 'Could not determine WP-CLI script path, falling back to in-process restart', 'shell' );
			return;
		}

		// Build arguments array
		$args = array( $php_binary, $wp_cli_script, 'shell' );

		if ( Utils\get_flag_value( $assoc_args, 'basic' ) ) {
			$args[] = '--basic';
		}

		$watch_path = Utils\get_flag_value( $assoc_args, 'watch', false );
		if ( $watch_path ) {
			$args[] = '--watch=' . $watch_path;
		}

		// Add the path argument if present in $_SERVER
		if ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
			foreach ( $_SERVER['argv'] as $arg ) {
				if ( is_string( $arg ) && '--path=' === substr( $arg, 0, 7 ) ) {
					$args[] = $arg;
				}
			}
		}

		WP_CLI::log( 'Restarting shell in new process...' );

		// Replace the current process with a new one
		// Note: pcntl_exec does not return on success
		pcntl_exec( $php_binary, array_slice( $args, 1 ) );

		// If we reach here, exec failed
		WP_CLI::warning( 'Failed to restart process, falling back to in-process restart' );
	}
}
