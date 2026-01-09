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
	 *     Restarting shell...
	 *     wp>
	 *
	 *     # Watch a directory for changes and auto-restart.
	 *     $ wp shell --watch=wp-content/plugins/my-plugin
	 *     wp> // Make changes to files in the plugin directory
	 *     Detected changes in wp-content/plugins/my-plugin, restarting shell...
	 *     wp>
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
			} while ( WP_CLI\Shell\REPL::EXIT_CODE_RESTART === $exit_code );
		}
	}

	/**
	 * Resolve and validate the watch path.
	 *
	 * @param string $path Path to watch.
	 * @return string Absolute path to watch.
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
}
