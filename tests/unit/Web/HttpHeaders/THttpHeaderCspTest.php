<?php

/**
 * THttpHeaderCspTest
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

use Prado\Web\HttpHeaders\TCspDirective;
use Prado\Web\HttpHeaders\THttpHeaderCsp;
use Prado\Web\HttpHeaders\THttpHeaderReportingEndpoints;
use Prado\Web\HttpHeaders\THttpHeadersManager;
use Prado\Web\Javascripts\TJavaScript;
use Prado\Web\THttpHeaderName;

/**
 * Unit tests for {@see THttpHeaderCsp}.
 *
 * Does not exercise the full send pipeline — that belongs to
 * {@see THttpHeaderCspIntegrationTest}. Covers every public method of the
 * class directly: policy CRUD, header name/value rendering, NONCE
 * substitution, `setHeaderValue()` parsing, and the two lifecycle hooks.
 */
class THttpHeaderCspTest extends PHPUnit\Framework\TestCase
{
	private THttpHeaderCsp $csp;

	protected function setUp(): void
	{
		$this->csp = new THttpHeaderCsp();
		TJavaScript::setScriptNonce(null);
	}

	protected function tearDown(): void
	{
		TJavaScript::setScriptNonce(null);
	}

	// =========================================================================
	// NONCE constant
	// =========================================================================

	public function testNonceConstantValue(): void
	{
		self::assertSame('NONCE', THttpHeaderCsp::NONCE);
	}

	// =========================================================================
	// getHeaderName / ReportOnly
	// =========================================================================

	public function testGetHeaderNameDefaultIsContentSecurityPolicy(): void
	{
		self::assertSame(THttpHeaderName::ContentSecurityPolicy, $this->csp->getHeaderName());
	}

	public function testGetHeaderNameReportOnlyIsContentSecurityPolicyReportOnly(): void
	{
		$this->csp->setReportOnly(true);
		self::assertSame(THttpHeaderName::ContentSecurityPolicyReportOnly, $this->csp->getHeaderName());
	}

	public function testGetHeaderNameRevertsToCspWhenReportOnlySetFalse(): void
	{
		$this->csp->setReportOnly(true);
		$this->csp->setReportOnly(false);
		self::assertSame(THttpHeaderName::ContentSecurityPolicy, $this->csp->getHeaderName());
	}

	// =========================================================================
	// getReportOnly / setReportOnly
	// =========================================================================

	public function testGetReportOnlyDefaultFalse(): void
	{
		self::assertFalse($this->csp->getReportOnly());
	}

	public function testSetReportOnlyTrue(): void
	{
		$this->csp->setReportOnly(true);
		self::assertTrue($this->csp->getReportOnly());
	}

	public function testSetReportOnlyStringTrue(): void
	{
		$this->csp->setReportOnly('true');
		self::assertTrue($this->csp->getReportOnly());
	}

	public function testSetReportOnlyStringFalse(): void
	{
		$this->csp->setReportOnly(true);
		$this->csp->setReportOnly('false');
		self::assertFalse($this->csp->getReportOnly());
	}

	public function testSetReportOnlyStringOne(): void
	{
		$this->csp->setReportOnly('1');
		self::assertTrue($this->csp->getReportOnly());
	}

	// =========================================================================
	// getReplace — CSP headers are always non-replacing
	// =========================================================================

	public function testGetReplaceReturnsFalseForEnforcingCsp(): void
	{
		self::assertFalse($this->csp->getReplace());
	}

	public function testGetReplaceReturnsFalseForReportOnlyCsp(): void
	{
		$this->csp->setReportOnly(true);
		self::assertFalse($this->csp->getReplace());
	}

	// =========================================================================
	// getPolicies — default and after modification
	// =========================================================================

	public function testGetPoliciesDefaultIsEmptyArray(): void
	{
		self::assertSame([], $this->csp->getPolicies());
	}

	public function testGetPoliciesReturnsMapAfterAddPolicy(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		self::assertSame([TCspDirective::DefaultSrc => "'self'"], $this->csp->getPolicies());
	}

	public function testGetPoliciesReturnsRawStringAfterUnparseableSetHeaderValue(): void
	{
		$this->csp->setHeaderValue('$#@!');
		self::assertIsString($this->csp->getPolicies());
	}

	// =========================================================================
	// hasPolicy
	// =========================================================================

	public function testHasPolicyReturnsFalseByDefault(): void
	{
		self::assertFalse($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	public function testHasPolicyReturnsTrueAfterAddPolicy(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	public function testHasPolicyReturnsFalseForUnaddedDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		self::assertFalse($this->csp->hasPolicy(TCspDirective::ScriptSrc));
	}

	public function testHasPolicyReturnsFalseAfterRemovePolicy(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->removePolicy(TCspDirective::DefaultSrc);
		self::assertFalse($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	public function testHasPolicyReturnsFalseWhenPoliciesIsRawString(): void
	{
		$this->csp->setHeaderValue('$#@!');
		self::assertFalse($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	// =========================================================================
	// addPolicy
	// =========================================================================

	public function testAddPolicyAddsNewDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::ScriptSrc, "'self'");
		self::assertTrue($this->csp->hasPolicy(TCspDirective::ScriptSrc));
	}

	public function testAddPolicyReplacesExistingDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'none'");
		$policies = $this->csp->getPolicies();
		self::assertSame("'none'", $policies[TCspDirective::DefaultSrc]);
	}

	public function testAddPolicyIsNoOpWhenPoliciesIsRawString(): void
	{
		$this->csp->setHeaderValue('$#@!');
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		// Must still be a raw string; addPolicy is a no-op.
		self::assertIsString($this->csp->getPolicies());
	}

	public function testAddPolicyStoresEmptyValueForBareDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::UpgradeInsecureRequests, '');
		self::assertTrue($this->csp->hasPolicy(TCspDirective::UpgradeInsecureRequests));
		$policies = $this->csp->getPolicies();
		self::assertSame('', $policies[TCspDirective::UpgradeInsecureRequests]);
	}

	// =========================================================================
	// removePolicy
	// =========================================================================

	public function testRemovePolicyRemovesExistingDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->removePolicy(TCspDirective::DefaultSrc);
		self::assertFalse($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	public function testRemovePolicyIsNoOpForAbsentDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->removePolicy(TCspDirective::ScriptSrc);
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	public function testRemovePolicyIsNoOpWhenPoliciesIsRawString(): void
	{
		$this->csp->setHeaderValue('$#@!');
		$raw = $this->csp->getPolicies();
		$this->csp->removePolicy(TCspDirective::DefaultSrc);
		self::assertSame($raw, $this->csp->getPolicies());
	}

	// =========================================================================
	// getReportToNames
	// =========================================================================

	public function testGetReportToNamesReturnsEmptyArrayByDefault(): void
	{
		self::assertSame([], $this->csp->getReportToNames());
	}

	public function testGetReportToNamesReturnsEndpointName(): void
	{
		$this->csp->addPolicy(TCspDirective::ReportTo, 'csp-endpoint');
		self::assertSame(['csp-endpoint'], $this->csp->getReportToNames());
	}

	public function testGetReportToNamesTrimsWhitespace(): void
	{
		$this->csp->addPolicy(TCspDirective::ReportTo, '  my-endpoint  ');
		self::assertSame(['my-endpoint'], $this->csp->getReportToNames());
	}

	public function testGetReportToNamesReturnsEmptyArrayForEmptyValue(): void
	{
		$this->csp->addPolicy(TCspDirective::ReportTo, '');
		self::assertSame([], $this->csp->getReportToNames());
	}

	public function testGetReportToNamesReturnsEmptyArrayWhenPoliciesIsRawString(): void
	{
		$this->csp->setHeaderValue('$#@!');
		self::assertSame([], $this->csp->getReportToNames());
	}

	// =========================================================================
	// getHeaderValue
	// =========================================================================

	public function testGetHeaderValueEmptyPoliciesReturnsEmptyString(): void
	{
		self::assertSame('', $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueSingleDirective(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		self::assertSame("default-src 'self'", $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueMultipleDirectivesSemicolonSeparated(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::ScriptSrc, "'self'");
		self::assertSame("default-src 'self'; script-src 'self'", $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueBareDirectiveNoTrailingSpace(): void
	{
		$this->csp->addPolicy(TCspDirective::UpgradeInsecureRequests, '');
		self::assertSame('upgrade-insecure-requests', $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueMixedNormalAndBareDirectives(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::UpgradeInsecureRequests, '');
		self::assertSame("default-src 'self'; upgrade-insecure-requests", $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueNoncePlaceholderReplacedWhenNonceSet(): void
	{
		TJavaScript::setScriptNonce('abc123');
		$this->csp->addPolicy(TCspDirective::ScriptSrc, "'self' " . THttpHeaderCsp::NONCE);
		self::assertSame("script-src 'self' 'nonce-abc123'", $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueNoncePlaceholderLeftWhenNonceNull(): void
	{
		// Nonce is null (not yet generated) — NONCE token is left in-place.
		TJavaScript::setScriptNonce(null);
		$this->csp->addPolicy(TCspDirective::ScriptSrc, "'self' " . THttpHeaderCsp::NONCE);
		self::assertStringContainsString(THttpHeaderCsp::NONCE, $this->csp->getHeaderValue());
	}

	public function testGetHeaderValueRawStringReturnedWhenPoliciesUnparseable(): void
	{
		$raw = '$#@!';
		$this->csp->setHeaderValue($raw);
		self::assertSame($raw, $this->csp->getHeaderValue());
	}

	// =========================================================================
	// setHeaderValue — parsing
	// =========================================================================

	public function testSetHeaderValueSingleDirectiveWithValue(): void
	{
		$this->csp->setHeaderValue("default-src 'self'");
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
		$policies = $this->csp->getPolicies();
		self::assertSame("'self'", $policies[TCspDirective::DefaultSrc]);
	}

	public function testSetHeaderValueBareDirective(): void
	{
		$this->csp->setHeaderValue('upgrade-insecure-requests');
		self::assertTrue($this->csp->hasPolicy(TCspDirective::UpgradeInsecureRequests));
		$policies = $this->csp->getPolicies();
		self::assertSame('', $policies[TCspDirective::UpgradeInsecureRequests]);
	}

	public function testSetHeaderValueMultipleDirectives(): void
	{
		$this->csp->setHeaderValue("default-src 'self'; script-src 'self' 'unsafe-inline'");
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
		self::assertTrue($this->csp->hasPolicy(TCspDirective::ScriptSrc));
		$policies = $this->csp->getPolicies();
		self::assertSame("'self'", $policies[TCspDirective::DefaultSrc]);
		self::assertSame("'self' 'unsafe-inline'", $policies[TCspDirective::ScriptSrc]);
	}

	public function testSetHeaderValueHandlesExtraWhitespace(): void
	{
		$this->csp->setHeaderValue("  default-src   'self'  ;  script-src 'none'  ");
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
		self::assertTrue($this->csp->hasPolicy(TCspDirective::ScriptSrc));
	}

	public function testSetHeaderValueMixedNormalAndBareDirectives(): void
	{
		$this->csp->setHeaderValue("default-src 'self'; upgrade-insecure-requests");
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
		self::assertTrue($this->csp->hasPolicy(TCspDirective::UpgradeInsecureRequests));
		$policies = $this->csp->getPolicies();
		self::assertSame('', $policies[TCspDirective::UpgradeInsecureRequests]);
	}

	public function testSetHeaderValueUnparseableInputStoredAsRawString(): void
	{
		$raw = '$#@!';
		$this->csp->setHeaderValue($raw);
		self::assertIsString($this->csp->getPolicies());
		self::assertSame($raw, $this->csp->getPolicies());
	}

	public function testSetHeaderValueEmptyStringStoredAsRawString(): void
	{
		$this->csp->setHeaderValue('');
		// Empty input cannot produce a directive map; stored as raw.
		self::assertIsString($this->csp->getPolicies());
	}

	public function testSetHeaderValueReplacesExistingPolicies(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->setHeaderValue("script-src 'none'");
		self::assertFalse($this->csp->hasPolicy(TCspDirective::DefaultSrc));
		self::assertTrue($this->csp->hasPolicy(TCspDirective::ScriptSrc));
	}

	public function testSetHeaderValueSandboxWithTokens(): void
	{
		$this->csp->setHeaderValue('sandbox allow-scripts allow-same-origin');
		self::assertTrue($this->csp->hasPolicy(TCspDirective::Sandbox));
		$policies = $this->csp->getPolicies();
		self::assertSame('allow-scripts allow-same-origin', $policies[TCspDirective::Sandbox]);
	}

	// =========================================================================
	// setHeaderValue ↔ getHeaderValue round-trip
	// =========================================================================

	public function testRoundTripSingleDirective(): void
	{
		$value = "default-src 'self'";
		$this->csp->setHeaderValue($value);
		self::assertSame($value, $this->csp->getHeaderValue());
	}

	public function testRoundTripMultipleDirectives(): void
	{
		$value = "default-src 'self'; script-src 'self'; upgrade-insecure-requests";
		$this->csp->setHeaderValue($value);
		self::assertSame($value, $this->csp->getHeaderValue());
	}

	// =========================================================================
	// initComplete
	// =========================================================================

	public function testInitCompleteIsNoOpWhenNotReportOnly(): void
	{
		$this->csp->addPolicy(TCspDirective::Sandbox, '');
		$this->csp->initComplete();
		// sandbox must still be present.
		self::assertTrue($this->csp->hasPolicy(TCspDirective::Sandbox));
	}

	public function testInitCompleteRemovesSandboxWhenReportOnly(): void
	{
		$this->csp->setReportOnly(true);
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::Sandbox, '');
		$this->csp->initComplete();
		self::assertFalse($this->csp->hasPolicy(TCspDirective::Sandbox));
	}

	public function testInitCompletePreservesOtherDirectivesWhenSandboxRemoved(): void
	{
		$this->csp->setReportOnly(true);
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::Sandbox, '');
		$this->csp->initComplete();
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	public function testInitCompleteIsNoOpWhenReportOnlyButNoSandbox(): void
	{
		$this->csp->setReportOnly(true);
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->initComplete();
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
	}

	// =========================================================================
	// finalizeHeader
	// =========================================================================

	public function testFinalizeHeaderIsNoOpWhenNoReportTo(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		// No manager, no report-to — must not throw.
		$this->csp->finalizeHeader();
		$this->addToAssertionCount(1);
	}

	public function testFinalizeHeaderIsNoOpWhenNoManager(): void
	{
		$this->csp->addPolicy(TCspDirective::ReportTo, 'missing-endpoint');
		// No manager set — must return early without throwing.
		$this->csp->finalizeHeader();
		$this->addToAssertionCount(1);
	}

	public function testFinalizeHeaderDoesNotThrowWhenEndpointMissing(): void
	{
		// Manager present but no matching Reporting-Endpoints header — logs a
		// warning and returns; must not throw.
		$manager = new THttpHeadersManager();
		$this->csp->setManager($manager);
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::ReportTo, 'missing-endpoint');
		$this->csp->finalizeHeader();
		$this->addToAssertionCount(1);
	}

	public function testFinalizeHeaderDoesNotThrowWhenEndpointPresent(): void
	{
		$manager = new THttpHeadersManager();
		$re = new THttpHeaderReportingEndpoints();
		$re->addEndpoint('csp-ep', 'https://example.com/csp');

		// Wire both headers to the same manager via reflection so getHeaders() sees them.
		$ref = new ReflectionProperty(THttpHeadersManager::class, '_headers');
		$ref->setAccessible(true);
		$ref->setValue($manager, [$this->csp, $re]);

		$this->csp->setManager($manager);
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		$this->csp->addPolicy(TCspDirective::ReportTo, 'csp-ep');

		$this->csp->finalizeHeader();
		$this->addToAssertionCount(1);
	}

	// =========================================================================
	// init — array config
	// =========================================================================

	public function testInitWithEmptyArrayConfigDoesNotThrow(): void
	{
		$this->csp->init([]);
		$this->addToAssertionCount(1);
	}

	public function testInitLoadsArrayPolicies(): void
	{
		$this->csp->init([
			'policies' => [
				['name' => TCspDirective::DefaultSrc, 'value' => "'self'"],
				['name' => TCspDirective::ScriptSrc,  'value' => "'none'"],
			],
		]);
		self::assertTrue($this->csp->hasPolicy(TCspDirective::DefaultSrc));
		self::assertTrue($this->csp->hasPolicy(TCspDirective::ScriptSrc));
	}

	// =========================================================================
	// __toString — inherited from THttpHeaderBase
	// =========================================================================

	public function testToStringEnforcingFormat(): void
	{
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		self::assertSame(
			"Content-Security-Policy: default-src 'self'",
			(string) $this->csp
		);
	}

	public function testToStringReportOnlyFormat(): void
	{
		$this->csp->setReportOnly(true);
		$this->csp->addPolicy(TCspDirective::DefaultSrc, "'self'");
		self::assertSame(
			"Content-Security-Policy-Report-Only: default-src 'self'",
			(string) $this->csp
		);
	}
}
