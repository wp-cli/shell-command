Feature: WordPress REPL

  Scenario: Blank session
    Given a WP install

    And an empty_session file:
      """
      """

    When I run `wp shell < empty_session`
    And I run `wp shell --basic < empty_session`
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

  Scenario: Restart shell
    Given a WP install
    And a session file:
      """
      $a = 1;
      restart
      $b = 2;
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      Restarting shell...
      """
    And STDOUT should contain:
      """
      => int(2)
      """

  Scenario: Exit shell
    Given a WP install
    And a session file:
      """
      $a = 1;
      exit
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      => int(1)
      """
    And STDOUT should not contain:
      """
      exit
      """

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

  Scenario: Exception handling preserves session state
    Given a WP install
    And a session file:
      """
      $foo = 'test_value';
      require 'nonexistent_file.txt';
      echo $foo;
      """

    When I try `wp shell --basic < session`
    Then STDOUT should contain:
      """
      test_value
      """
    And STDERR should contain:
      """
      Failed opening required 'nonexistent_file.txt'
      """

  Scenario: Exception handling for expression errors
    Given a WP install
    And a session file:
      """
      $bar = 'preserved';
      nonexistent_function();
      $bar;
      """

    When I try `wp shell --basic < session`
    Then STDOUT should contain:
      """
      string(9) "preserved"
      """
    And STDERR should contain:
      """
      Error: Call to undefined function nonexistent_function()
      """

  Scenario: User can define variable named $line
    Given a WP install
    And a session file:
      """
      $line = 'this should work';
      $line;
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      => string(16) "this should work"
      """
    And STDOUT should contain:
      """
      => string(16) "this should work"
      """

  Scenario: User can define variables named $out and $evl
    Given a WP install
    And a session file:
      """
      $out = 'out should work';
      $evl = 'evl should work';
      $out;
      $evl;
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      => string(15) "out should work"
      """
    And STDOUT should contain:
      """
      => string(15) "evl should work"
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

    And an empty_session file:
      """
      """

    When I try `wp shell --basic --hook=shutdown < empty_session`
    Then STDERR should contain:
      """
      Error: The 'shutdown' hook has not fired yet
      """
    And the return code should be 1

  Scenario: Quiet mode suppresses return value output
    Given a WP install
    And a session file:
      """
      $a = "hello";
      """

    When I run `wp shell --basic --quiet < session`
    Then STDOUT should be empty

  Scenario: Quiet mode still shows echo output
    Given a WP install
    And a session file:
      """
      echo "test output";
      """

    When I run `wp shell --basic --quiet < session`
    Then STDOUT should contain:
      """
      test output
      """

  Scenario: Quiet mode with expression
    Given a WP install
    And a session file:
      """
      get_bloginfo('name');
      """

    When I run `wp shell --basic --quiet < session`
    Then STDOUT should be empty

  Scenario: Normal mode shows return value
    Given a WP install
    And a session file:
      """
      $a = "hello";
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      string(5) "hello"
      """
