Feature: WordPress REPL

  Scenario: Blank session
    Given a WP install

    When I run `wp shell < /dev/null`
    And I run `wp shell --basic < /dev/null`
    Then STDOUT should be empty

  Scenario: Persistent environment
    Given a WP install
    And a session file:
      """
      function is_empty_string( $str ) { return strlen( $str ) == 0; }
      $a = get_option('home');
      is_empty_string( $a );
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      bool(false)
      """

  Scenario: Multiline support (basic)
    Given a WP install
    And a session file:
      """
      function is_empty_string( $str ) { \
          return strlen( $str ) == 0; \
      }

      function_exists( 'is_empty_string' );
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      bool(true)
      """

  Scenario: Use custom shell path
    Given a WP install

    And a session file:
      """
      return true;
      """

    When I try `WP_CLI_CUSTOM_SHELL=/nonsense/path wp shell --basic < session`
    Then STDOUT should be empty
    And STDERR should contain:
      """
      Error: The shell binary '/nonsense/path' is not valid.
      """

    When I try `WP_CLI_CUSTOM_SHELL=/bin/bash wp shell --basic < session`
    Then STDOUT should contain:
      """
      bool(true)
      """
    And STDERR should be empty

  Scenario: Use SHELL environment variable as fallback for bash
    Given a WP install

    And a session file:
      """
      return true;
      """

    # SHELL pointing to bash should work (when bash is available).
    When I try `SHELL=/bin/bash wp shell --basic < session`
    Then STDOUT should contain:
      """
      bool(true)
      """
    And STDERR should be empty

    # SHELL pointing to non-bash binary should be ignored and fall back to /bin/bash.
    When I try `SHELL=/bin/sh wp shell --basic < session`
    Then STDOUT should contain:
      """
      bool(true)
      """
    And STDERR should be empty

    # SHELL pointing to invalid path should be ignored and fall back to /bin/bash.
    When I try `SHELL=/nonsense/path wp shell --basic < session`
    Then STDOUT should contain:
      """
      bool(true)
      """
    And STDERR should be empty

  Scenario: Input starting with dash
    Given a WP install
    And a session file:
      """
      -1
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      int(-1)
      """
    And STDERR should not contain:
      """
      history: -1: invalid option
      """

  Scenario: Shell with hook parameter
    Given a WP install
    And a session file:
      """
      did_action('init');
      """

    When I run `wp shell --basic --hook=init < session`
    Then STDOUT should contain:
      """
      int(1)
      """

  Scenario: Shell with hook parameter using plugins_loaded hook
    Given a WP install
    And a session file:
      """
      did_action('plugins_loaded');
      """

    When I run `wp shell --basic --hook=plugins_loaded < session`
    Then STDOUT should contain:
      """
      int(1)
      """

  Scenario: Shell with hook parameter for hook that hasn't fired
    Given a WP install

    When I try `wp shell --basic --hook=shutdown < /dev/null`
    Then STDERR should contain:
      """
      Error: The 'shutdown' hook has not fired yet
      """
    And the return code should be 1
