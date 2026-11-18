<?php
/**
 * Base URL validation tests.
 *
 * @package WSO\SmartOrder
 */

declare( strict_types = 1 );

namespace WSO\SmartOrder\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WSO\SmartOrder\Support\Url;

/**
 * Pins that an endpoint must be a real http(s) URL.
 *
 * A pasted API key used to become `http://<key>` here, which leaked the key into the
 * rendered form and pointed requests at a host that does not exist.
 */
final class UrlTest extends TestCase {

	/**
	 * Real endpoints survive normalisation.
	 */
	public function testAcceptsRealEndpoints(): void {
		$cases = array(
			'https://openrouter.ai/api/v1/'       => 'https://openrouter.ai/api/v1',
			'https://api.openai.com/v1'           => 'https://api.openai.com/v1',
			'http://localhost:11434/v1'           => 'http://localhost:11434/v1',
			'http://127.0.0.1:8080/v1'            => 'http://127.0.0.1:8080/v1',
			'  https://api.groq.com/openai/v1  '  => 'https://api.groq.com/openai/v1',
			'https://generativelanguage.googleapis.com/v1beta/openai' => 'https://generativelanguage.googleapis.com/v1beta/openai',
		);

		foreach ( $cases as $input => $expected ) {
			self::assertSame( $expected, Url::normalizeBaseUrl( $input ), $input );
		}
	}

	/**
	 * A pasted API key is not an endpoint.
	 */
	public function testRejectsAPastedApiKey(): void {
		self::assertSame( '', Url::normalizeBaseUrl( 'AIzaSyEXAMPLEnotarealkey' ) );
		self::assertSame( '', Url::normalizeBaseUrl( 'sk-proj-abc123' ) );
	}

	/**
	 * A host without a dot cannot be a real endpoint.
	 */
	public function testRejectsHostWithoutDot(): void {
		self::assertSame( '', Url::normalizeBaseUrl( 'https://notahost' ) );
	}

	/**
	 * Only http and https are accepted.
	 */
	public function testRejectsOtherSchemes(): void {
		self::assertSame( '', Url::normalizeBaseUrl( 'javascript:alert(1)' ) );
		self::assertSame( '', Url::normalizeBaseUrl( 'ftp://example.com/v1' ) );
		self::assertSame( '', Url::normalizeBaseUrl( 'file:///etc/passwd' ) );
	}

	/**
	 * Empty input stays empty, so "no override" remains expressible.
	 */
	public function testEmptyStaysEmpty(): void {
		self::assertSame( '', Url::normalizeBaseUrl( '' ) );
		self::assertSame( '', Url::normalizeBaseUrl( '   ' ) );
	}

	/**
	 * A query or fragment means this is a full request URL, not a base.
	 */
	public function testRejectsQueryAndFragment(): void {
		self::assertSame( '', Url::normalizeBaseUrl( 'https://example.com/v1?key=1' ) );
		self::assertSame( '', Url::normalizeBaseUrl( 'https://example.com/v1#frag' ) );
	}

	/**
	 * An out-of-range port is not an endpoint.
	 */
	public function testRejectsBadPort(): void {
		self::assertSame( '', Url::normalizeBaseUrl( 'http://example.com:70000/v1' ) );
	}
}
