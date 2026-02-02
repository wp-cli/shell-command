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
	 * : Attach the shell to a specific WordPress action or filter hook.
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
	 *     # Start a shell after the 'init' action has fired.
	 *     $ wp shell --hook=init
	 */
	public function __invoke( $_, $assoc_args ) {
		$hook = Utils\get_flag_value( $assoc_args, 'hook', '' );

		if ( $hook ) {
			// Check if the hook has already fired.
			if ( did_action( $hook ) ) {
				// Hook already fired, start the shell immediately.
				$this->start_shell( $assoc_args );
			} else {
				// Hook hasn't fired yet. We need to attach a callback and let WordPress continue.
				// Since shell is interactive and blocks execution, we'll attach the callback
				// and let it start when the hook fires naturally.
				$shell_started = false;
				add_action(
					$hook,
					function () use ( $assoc_args, &$shell_started ) {
						if ( ! $shell_started ) {
							$shell_started = true;
							$this->start_shell( $assoc_args );
						}
					},
					10
				);
				// Note: The shell will start when the hook fires during WordPress lifecycle.
				// Since commands typically run after WordPress is loaded, most common hooks
				// will have already fired. For hooks that haven't fired yet, the shell will
				// start when they do fire.
			}
		} else {
			// No hook specified, start immediately.
			$this->start_shell( $assoc_args );
		}
	}

	/**
	 * Start the shell REPL.
	 *
	 * @param array<string,bool|string> $assoc_args Associative arguments.
	 */
	private function start_shell( $assoc_args ) {
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
			$repl = new $class( 'wp> ' );
			$repl->start();
		}
	}
}
