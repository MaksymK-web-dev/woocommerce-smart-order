<?php
/**
 * Coding-rule tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Pins the house rules: legal PHP that phpcs passes without comment.
 */
final class CodingRulesTest extends TestCase {

	/**
	 * Project root.
	 */
	private static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Directories that contain this project's PHP.
	 *
	 * @return string[]
	 */
	private static function directories(): array {
		return array(
			self::root() . '/wp-content/plugins/woocommerce-smart-order',
			self::root() . '/wp-content/mu-plugins',
			self::root() . '/tools',
		);
	}

	/**
	 * Our PHP files.
	 *
	 * @return array<string, string> Path => contents.
	 */
	private static function sources(): array {
		$files = array();

		foreach ( self::directories() as $directory ) {
			if ( ! is_dir( $directory ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$path = $file->getPathname();

				if ( str_contains( $path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR ) ) {
					continue;
				}

				$files[ $path ] = (string) file_get_contents( $path );
			}
		}

		return $files;
	}

	/**
	 * The ABSPATH guard is the one legitimate `exit`.
	 *
	 * @param string $source File contents.
	 * @return string Contents with the guard removed.
	 */
	private static function stripAbspathGuard( string $source ): string {
		$stripped = preg_replace(
			'/if\s*\(\s*!\s*defined\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{\s*exit;\s*\}/s',
			'',
			$source
		);

		return is_string( $stripped ) ? $stripped : $source;
	}

	/**
	 * The scan must find files, or every assertion below passes for the wrong reason.
	 */
	public function testSourcesWereActuallyFound(): void {
		self::assertGreaterThanOrEqual( 20, count( self::sources() ) );
	}

	/**
	 * `<?=` depends on ini settings on some hosts.
	 */
	public function testNoShortEchoTags(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertStringNotContainsString( '<?=', $source, basename( $path ) . ' uses a short echo tag.' );
		}
	}

	/**
	 * `<?` is the same problem with more ways to go wrong.
	 */
	public function testNoShortOpenTags(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertSame( 0, preg_match_all( '/<\?(?!php|=xml)/', $source ), basename( $path ) . ' uses a short open tag.' );
		}
	}

	/**
	 * eval() executes arbitrary code.
	 */
	public function testNoEval(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertSame( 0, preg_match_all( '/\beval\s*\(/', $source ), basename( $path ) . ' calls eval().' );
		}
	}

	/**
	 * die() and exit() end the request without a response object.
	 */
	public function testNoDieOrExitInApplicationCode(): void {
		foreach ( self::sources() as $path => $source ) {
			if ( str_contains( str_replace( '\\', '/', $path ), '/tools/' ) ) {
				continue;
			}

			$source = self::stripAbspathGuard( $source );

			self::assertSame(
				0,
				preg_match_all( '/\b(die|exit)\s*(\(|;)/', $source ),
				basename( $path ) . ' calls die() or exit().'
			);
		}
	}

	/**
	 * The CLI driver may exit, but only with a numeric status.
	 */
	public function testCliExitAlwaysCarriesAStatus(): void {
		$cli = self::root() . '/tools/wc-setup.php';

		if ( ! is_readable( $cli ) ) {
			self::markTestSkipped( 'CLI driver not present.' );
		}

		$source = self::stripAbspathGuard( (string) file_get_contents( $cli ) );

		self::assertSame(
			0,
			preg_match_all( '/\bexit\s*;/', $source ),
			'wc-setup.php uses a bare exit; without a status code.'
		);

		self::assertSame(
			0,
			preg_match_all( '/\bexit\s*\(\s*[^0-9\s)]/', $source ),
			'wc-setup.php exits with something other than a numeric status.'
		);
	}

	/**
	 * Control flow must be a statement, not a short-circuited expression.
	 */
	public function testNoShortCircuitControlFlow(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertSame(
				0,
				preg_match_all( '/(\|\||&&)\s*(exit|return|throw|wp_die|die)\b/', $source ),
				basename( $path ) . ' uses a short-circuit as control flow.'
			);
		}
	}

	/**
	 * No debug helpers left behind.
	 */
	public function testNoDebugHelpers(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertSame(
				0,
				preg_match_all( '/\b(var_dump|print_r|error_log)\s*\(/', $source ),
				basename( $path ) . ' contains a debug helper.'
			);
		}
	}

	/**
	 * Nothing here needs a weak hash, and using one invites a false sense of security.
	 */
	public function testNoWeakHashes(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertSame( 0, preg_match_all( '/\b(md5|sha1)\s*\(/', $source ), basename( $path ) . ' uses a weak hash.' );
		}
	}

	/**
	 * Variable variables and extract() make data flow impossible to follow.
	 */
	public function testNoDynamicVariableTricks(): void {
		foreach ( self::sources() as $path => $source ) {
			self::assertSame( 0, preg_match_all( '/\$\$[a-zA-Z_]/', $source ), basename( $path ) . ' uses variable variables.' );
			self::assertSame( 0, preg_match_all( '/\bextract\s*\(/', $source ), basename( $path ) . ' calls extract().' );
		}
	}

}
