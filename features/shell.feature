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

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      test_value
      """
    And STDERR should contain:
      """
      Error: Failed opening required 'nonexistent_file.txt'
      """

  Scenario: Exception handling for expression errors
    Given a WP install
    And a session file:
      """
      $bar = 'preserved';
      nonexistent_function();
      $bar;
      """

    When I run `wp shell --basic < session`
    Then STDOUT should contain:
      """
      string(9) "preserved"
      """
    And STDERR should contain:
      """
      Error: Call to undefined function nonexistent_function()
      """
