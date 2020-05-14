Feature: TestCase
  In order to have typed TestCases
  As a Psalm user
  I need Psalm to typecheck my test cases

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true" %s>
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="PsalmWordPress\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php
      namespace NS;

      """
  Scenario: apply_filters returns correct type.
    Given I have the following code
      """
      $should_confirm = apply_filters( 'should_return', 'yes' );
      substr( $should_confirm, 1, 1 );
      """
    When I run Psalm
    Then I see no errors

  Scenario: wp_parse_args infers correct type.
    Given I have the following code
      """
      $args = wp_parse_args( [ 'foo' => 'bar' ], [ 'baz' => 'bing' ] );
      $args['foo'];
      """
    When I run Psalm
    Then I see no errors

  Scenario: is_wp_error case is excluded from type.
    Given I have the following code
      """
      /** @var \WP_Error|int */
      $var = 'foo';
      if ( ! is_wp_error( $var ) ) {
          $int = $var + 1;
      }
      """
    When I run Psalm
    Then I see no errors
  Scenario: wp_list_pluck return types are inferred.
    Given I have the following code
      """
      $list = [
        [
          'id' => 1,
        ]
      ];

      $ids = wp_list_pluck( $list, 'id' );
      """
    When I run Psalm
    Then I see no errors
