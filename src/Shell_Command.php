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
	 * [--hook=<hook>]
	 * : Ensure that a specific WordPress action hook has fired before starting the shell.
	 * This validates that the preconditions associated with that hook are met.
	 * Only hooks that have already been triggered can be used (e.g., init, plugins_loaded, wp_loaded).
	 * ---
	 * default: ''
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Call get_bloginfo() to get the name of the site.
	 *     $ wp shell
	 *     wp> get_bloginfo( 'name' );
	 *     => string(6) "WP-CLI"
	 *
	 *     # Start a shell, ensuring the 'init' hook has already fired.
	 *     $ wp shell --hook=init
	 *
	 *     # Start a shell in quiet mode, suppressing return value output.
	 *     $ wp shell --quiet
	 *     wp> $a = "hello";
	 *     wp>
	 */
	public function __invoke( $_, $assoc_args ) {
		$hook = Utils\get_flag_value( $assoc_args, 'hook', '' );

		// No hook specified, start immediately.
		if ( ! $hook ) {
			$this->start_shell( $assoc_args );
			return;
		}

		// Check if the hook has already fired.
		if ( did_action( $hook ) ) {
			// Hook already fired, start the shell immediately.
			$this->start_shell( $assoc_args );
			return;
		}

		// Hook hasn't fired yet.
		WP_CLI::error(
			sprintf(
				"The '%s' hook has not fired yet. " .
				'The shell command runs after WordPress is loaded, so only hooks that have already been triggered can be used. ' .
				'Common hooks that are available include: init, plugins_loaded, wp_loaded.',
				$hook
			)
		);
	}

	/**
	 * Start the shell REPL.
	 *
	 * @param array<string,bool|string> $assoc_args Associative arguments.
	 */
	private function start_shell( $assoc_args ) {
		$class = WP_CLI\Shell\REPL::class;
		$quiet = (bool) WP_CLI::get_config( 'quiet' );

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
			$repl = new $class( 'wp> ', $quiet );
			$repl->start();
		}
	}
}
