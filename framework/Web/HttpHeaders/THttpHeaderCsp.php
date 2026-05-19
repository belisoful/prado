<?php

/**
 * THttpHeaderCsp class
 *
 * @author Fabio Bas <ctrlaltca[at]gmail[dot]com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\HttpHeaders;

use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\Javascripts\TJavaScript;
use Prado\Web\THttpHeaderName;

/**
 * THttpHeaderCsp class
 *
 * THttpHeaderCsp emits a `Content-Security-Policy` header. Set
 * {@see setReportOnly() ReportOnly} to `true` to emit
 * `Content-Security-Policy-Report-Only` instead — the same directives apply
 * but violations are reported without blocking resources.
 *
 * Configure via {@see \Prado\Web\HttpHeaders\THttpHeadersManager THttpHeadersManager}:
 *
 * ```xml
 * <module id="headers" class="THttpHeadersManager">
 *   <header class="THttpHeaderCsp">
 *      <policy Name="default-src">'self' www.gstatic.com NONCE</policy>
 *      <policy Name="frame-src">'self' www.google.com</policy>
 *   </header>
 * </module>
 * ```
 *
 * Or in PHP:
 * ```php
 * [
 *     'class'      => THttpHeaderCsp::class,
 *     'properties' => ['ReportOnly' => true],
 *     'policies'   => [
 *         ['name' => TCspDirective::DefaultSrc, 'value' => "'self' NONCE"],
 *     ],
 * ]
 * ```
 *
 * The manager also accepts a plain `HeaderName="Content-Security-Policy"` node
 * and calls {@see setHeaderValue()} to parse the directives automatically:
 * ```php
 * ['properties' => [
 *     'HeaderName'  => THttpHeaderName::ContentSecurityPolicy,
 *     'HeaderValue' => "default-src 'self'; script-src 'self' 'nonce-abc'",
 * ]]
 * ```
 *
 * The special placeholder {@see NONCE} in any policy value is replaced at
 * render time with the per-request nonce from
 * {@see \Prado\Web\Javascripts\TJavaScript::getScriptNonce()}.
 *
 * **Note:** {@see TCspDirective::Sandbox} is silently ignored by browsers in
 * report-only mode. When `ReportOnly` is `true` and `sandbox` is present,
 * `initComplete()` logs a warning and omits the directive from the emitted value.
 *
 * @author Fabio Bas <ctrlaltca[at]gmail[dot]com>
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP
 * @link https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy
 */
class THttpHeaderCsp extends THttpHeaderBase
{
	/**
	 * @var array<string,string>|string directive name => value map, or a raw
	 *   unparseable value string as a fallback.
	 */
	protected $_policies = [];

	/**
	 * @var bool whether to emit the report-only variant of the header.
	 */
	private $_reportOnly = false;

	/**
	 * Placeholder replaced at render time with the `'nonce-<value>'` expression
	 * from {@see \Prado\Web\Javascripts\TJavaScript::getScriptNonce()}.
	 */
	public const NONCE = 'NONCE';

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Loads policies from `$config` and, when any value references {@see NONCE},
	 * fetches the per-request nonce from `TSecurityManager` and stores it via
	 * {@see TJavaScript::setScriptNonce()} so inline scripts share the same nonce.
	 * @param array|\Prado\Xml\TXmlElement $config configuration for this header.
	 */
	public function init($config): void
	{
		parent::init($config);
		$this->loadPolicies($config);

		$policies = $this->getPolicies();
		if (is_array($policies)) {
			foreach ($policies as $value) {
				if (str_contains($value, self::NONCE)) {
					$nonce = Prado::getApplication()->getSecurityManager()->getCSPNonce();
					TJavaScript::setScriptNonce($nonce);
					break;
				}
			}
		}
	}

	/**
	 * Validates this header's own state after all sibling headers are loaded.
	 * Logs a warning and removes the `sandbox` directive when
	 * {@see getReportOnly() ReportOnly} is `true`, because browsers silently
	 * ignore `sandbox` in report-only mode and including it is misleading.
	 */
	public function initComplete(): void
	{
		if ($this->getReportOnly() && $this->hasPolicy(TCspDirective::Sandbox)) {
			Prado::log(
				'The CSP "sandbox" directive is silently ignored by browsers in'
				. ' report-only mode and has been omitted from the header value.',
				\Prado\Util\TLogger::WARNING,
				static::class
			);
			$this->removePolicy(TCspDirective::Sandbox);
		}
	}

	/**
	 * Validates that every `report-to` endpoint name resolves to a sibling
	 * {@see THttpHeaderReportingEndpoints} header. Called just before headers are
	 * sent; logs a warning for each unresolved reference.
	 */
	public function finalizeHeader(): void
	{
		$reportToNames = $this->getReportToNames();
		if (empty($reportToNames) || ($manager = $this->getManager()) === null) {
			return;
		}

		$declaredNames = [];
		foreach ($manager->getHeaders() as $header) {
			if ($header instanceof THttpHeaderReportingEndpoints) {
				foreach ($header->getEndpointNames() as $name) {
					$declaredNames[$name] = true;
				}
			}
		}
		foreach ($reportToNames as $name) {
			if (!isset($declaredNames[$name])) {
				Prado::log(
					'CSP report-to references endpoint "' . $name . '" which is not'
					. ' declared in any Reporting-Endpoints header.',
					\Prado\Util\TLogger::WARNING,
					static::class
				);
			}
		}
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/**
	 * @return string the textual name of the header.
	 */
	public function getHeaderName(): string
	{
		return $this->getReportOnly()
			? THttpHeaderName::ContentSecurityPolicyReportOnly
			: THttpHeaderName::ContentSecurityPolicy;
	}

	/**
	 * @return bool whether the header is sent in report-only mode.
	 */
	public function getReportOnly(): bool
	{
		return $this->_reportOnly;
	}

	/**
	 * Sets whether to emit `Content-Security-Policy-Report-Only` instead of
	 * `Content-Security-Policy`. In report-only mode violations are reported
	 * but resources are not blocked.
	 * @param bool|string $value coerced to `bool` via {@see TPropertyValue::ensureBoolean()}.
	 */
	public function setReportOnly($value): void
	{
		$this->_reportOnly = TPropertyValue::ensureBoolean($value);
	}

	/**
	 * Returns the directive map, or the raw unparseable string when
	 * {@see setHeaderValue()} could not parse its input.
	 * @return array<string,string>|string
	 */
	public function getPolicies(): array|string
	{
		return $this->_policies;
	}

	/**
	 * Returns `true` when the named directive is present in the policy map.
	 * Always returns `false` when policies are stored as an unparseable raw string.
	 * @param string $name directive name, e.g. {@see TCspDirective::ReportTo}
	 */
	public function hasPolicy(string $name): bool
	{
		return is_array($this->_policies) && array_key_exists($name, $this->_policies);
	}

	/**
	 * Adds or replaces a single directive in the policy map.
	 * If the policies are stored as an unparseable raw string, the call is a no-op.
	 * @param string $name  directive name, e.g. {@see TCspDirective::ReportTo}
	 * @param string $value directive value; pass an empty string for bare directives
	 *   such as `upgrade-insecure-requests`.
	 */
	public function addPolicy(string $name, string $value): void
	{
		if (is_array($this->_policies)) {
			$this->_policies[$name] = $value;
		}
	}

	/**
	 * Removes a directive from the policy map by name.
	 * If the policies are stored as an unparseable raw string, the call is a no-op.
	 * @param string $name directive name, e.g. {@see TCspDirective::Sandbox}
	 */
	public function removePolicy(string $name): void
	{
		if (is_array($this->_policies)) {
			unset($this->_policies[$name]);
		}
	}

	/**
	 * Returns the endpoint group name(s) referenced by the `report-to` directive,
	 * or an empty array if the directive is not configured.
	 * @return string[]
	 */
	public function getReportToNames(): array
	{
		if (!is_array($this->_policies)) {
			return [];
		}
		$key = TCspDirective::ReportTo;
		if (isset($this->_policies[$key])) {
			$name = trim($this->_policies[$key]);
			if ($name !== '') {
				return [$name];
			}
		}
		return [];
	}

	/**
	 * Builds the CSP header value by joining all directives with `'; '`.
	 * Replaces {@see NONCE} placeholders with the current per-request nonce.
	 * Bare directives (e.g. `upgrade-insecure-requests`) are emitted without a
	 * trailing space. Returns the raw string unchanged when policies could not
	 * be parsed.
	 * @return string the header value.
	 */
	public function getHeaderValue(): string
	{
		if (is_string($this->_policies)) {
			return $this->_policies;
		}
		$nonce = TJavaScript::getScriptNonce();
		$nonceDirective = $nonce !== null ? '\'nonce-' . $nonce . '\'' : null;
		$parts = [];
		foreach ($this->_policies as $name => $value) {
			if ($nonceDirective !== null) {
				$value = str_replace(self::NONCE, $nonceDirective, $value);
			}
			$parts[] = trim($value) !== '' ? $name . ' ' . $value : $name;
		}
		return implode('; ', $parts);
	}

	/**
	 * Parses a raw CSP directive string into the policy map.
	 * Directives are split on `'; '`; bare directives (e.g.
	 * `upgrade-insecure-requests`) are stored with an empty-string value.
	 * Unparseable input is stored as-is for pass-through. Called automatically
	 * when the manager promotes a plain `HeaderValue` config node.
	 * @param mixed $value e.g. `"default-src 'self'; script-src 'self' 'nonce-abc'"`
	 */
	public function setHeaderValue($value): void
	{
		$value = TPropertyValue::ensureString($value);
		$directives = preg_split('/\s*;\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		if ($directives === false || empty($directives)) {
			$this->_policies = $value;
			return;
		}
		$policies = [];
		foreach ($directives as $directive) {
			$directive = trim($directive);
			if ($directive === '') {
				continue;
			}
			// Directive name is one or more alphanumeric/hyphen/underscore chars.
			// Everything after the first whitespace run is the value.
			if (preg_match('/^([a-zA-Z0-9_-]+)(?:\s+(.+))?$/', $directive, $m)) {
				$policies[$m[1]] = $m[2] ?? '';
			} else {
				// Unparseable segment — store raw and stop
				$this->_policies = $value;
				return;
			}
		}
		$this->_policies = $policies;
	}

	// =========================================================================
	// Protected helpers
	// =========================================================================

	/**
	 * Loads directives from an array or XML configuration node.
	 * @param mixed $config configuration node
	 */
	protected function loadPolicies($config): void
	{
		if (is_array($config)) {
			if (isset($config['policies']) && is_array($config['policies'])) {
				foreach ($config['policies'] as $policy) {
					$this->addPolicy($policy['name'], $policy['value']);
				}
			}
		} else {
			foreach ($config->getElementsByTagName('policy') as $header) {
				$properties = $header->getAttributes();
				$name = $properties->remove('Name');
				$this->addPolicy($name, $header->getValue());
			}
		}
	}
}
