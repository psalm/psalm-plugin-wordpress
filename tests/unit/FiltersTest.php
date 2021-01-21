<?php
namespace PsalmWordPress\Tests;

use Psalm;

class FiltersTest extends BaseTest {
	use Psalm\Tests\Traits\InvalidCodeAnalysisTestTrait;
	use Psalm\Tests\Traits\ValidCodeAnalysisTestTrait;

	 /**
	 * @return iterable<string,array{string,assertions?:array<string,string>,error_levels?:string[]}>
	 */
	public function providerValidCodeParse() : iterable {
		return [
			'add_filter with docblock' => [
				<<<'EOD'
				<?php
				/**
				 * @param array $missing_sizes Array with the missing image sub-sizes.
				 * @param array $image_meta    The image meta data.
				 * @param int   $attachment_id The image attachment post ID.
				 */
				$result = apply_filters( 'test_get_missing_image_subsizes', 1, 2, 3 );

				add_filter( 'test_get_missing_image_subsizes', function ( array $sizes, array $image_meta, int $attachment_id ) {
					return $sizes;
				}, 10, 3 );
				EOD,
			],
			'add_filter with no docblock' => [
				<<<'EOD'
				<?php
				$result = apply_filters( 'test_filter', true, 1, 1.1 );

				add_filter( 'test_filter', function ( bool $param1, int $param2, float $param3 ) {
					return false;
				}, 10, 3 );
				EOD,
			],
			'add_filter with double apply_filters call' => [
				<<<'EOD'
				<?php
				/**
				 * @param int $missing_sizes
				 * @param int $image_meta
				 * @param int   $attachment_id
				 */
				$result = apply_filters( 'test_filter', 1, 2, 3 );

				/** documented above */
				$result = apply_filters( 'test_filter', true, 1, 1.1 );

				add_filter( 'test_filter', function ( int $param1, int $param2, int $param3 ) {
					return 1;
				}, 10, 3 );
				EOD,
			]
		];
	}

	/**
	 * @return iterable<string,array{string,error_message:string,2?:string[],3?:bool,4?:string}>
	 */
	public function providerInvalidCodeParse() : iterable {
		return [
			'add_filter fails wrong types' => [
				<<<'EOD'
				<?php
				/**
				 * @param array $missing_sizes Array with the missing image sub-sizes.
				 * @param array $image_meta    The image meta data.
				 * @param int   $attachment_id The image attachment post ID.
				 */
				$result = apply_filters( 'test_get_missing_image_subsizes', 1, 2, 3 );

				add_filter( 'test_get_missing_image_subsizes', function ( int $sizes, array $image_meta, int $attachment_id ) {
					return $sizes;
				}, 10, 3 );
				EOD,
				'error_message' => 'InvalidArgument',
			],
			'add_filter no type found' => [
				<<<'EOD'
				<?php
				$foo = 1;
				$result = apply_filters( 'test_get_missing_image_subsizes', $foo );

				add_filter( 'test_get_missing_image_subsizes', function ( int $param ) {
					return 1;
				} );
				EOD,
				'error_message' => 'InvalidArdgument',
			]
		];
	}
}
