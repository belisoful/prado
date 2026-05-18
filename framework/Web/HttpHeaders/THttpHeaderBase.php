<?php

/**
 * THttpHeaderBase class file
 *
 * @author Fabio Bas <ctrlaltca[at]gmail[dot]com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\HttpHeaders;

use Prado\Web\THttpHeaderName;

/**
 * THttpHeaderBase class
 *
 * THttpHeaderBase is the abstract base for all typed HTTP header classes in the
 * HttpHeaders family. It owns the manager reference, the send/replace logic, and
 * the three lifecycle hooks ({@see init()}, {@see initComplete()},
 * {@see finalizeHeader()}) that subclasses override to build their header value.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.3.3
 */
abstract class THttpHeaderBase extends \Prado\TComponent
{
	/**
	 * Header names that may legally appear multiple times in the same HTTP
	 * response. {@see getReplace()} returns `false` for any header whose name
	 * matches one of these (case-insensitive).
	 *
	 * - {@see THttpHeaderName::SetCookie} — RFC 6265 §3: each cookie is a separate header.
	 * - {@see THttpHeaderName::Link} — RFC 8288: multiple Link headers are valid.
	 * - {@see THttpHeaderName::WWWAuthenticate} — RFC 7235: each challenge may be separate.
	 * - {@see THttpHeaderName::ContentSecurityPolicy} — CSP3: browser applies the intersection.
	 * - {@see THttpHeaderName::ContentSecurityPolicyReportOnly} — same as CSP.
	 *
	 * @var list<string>
	 */
	private const NON_REPLACING_HEADERS = [
		THttpHeaderName::SetCookie,
		THttpHeaderName::Link,
		THttpHeaderName::WWWAuthenticate,
		THttpHeaderName::ContentSecurityPolicy,
		THttpHeaderName::ContentSecurityPolicyReportOnly,
	];

	/**
	 * @var ?THttpHeadersManager the headers manager instance
	 */
	private $_manager;

	// =========================================================================
	// Lifecycle
	// =========================================================================

	/**
	 * Initializes the header.
	 * @param array|\Prado\Xml\TXmlElement $config configuration for this module.
	 */
	public function init($config): void
	{
	}

	/**
	 * Called by {@see THttpHeadersManager::initComplete()} after all headers are
	 * loaded. Override to validate state or interact with sibling headers.
	 */
	public function initComplete(): void
	{
	}

	/**
	 * Called by {@see THttpHeadersManager::finalizeHeaders()} immediately before
	 * headers are sent. Override for last-minute value adjustments such as
	 * injecting runtime-resolved tokens or removing the header conditionally.
	 */
	public function finalizeHeader(): void
	{
	}

	// =========================================================================
	// Properties
	// =========================================================================

	/**
	 * @return ?THttpHeadersManager the headers manager instance
	 */
	public function getManager(): ?THttpHeadersManager
	{
		return $this->_manager;
	}

	/**
	 * @param THttpHeadersManager $value the headers manager instance
	 */
	public function setManager($value): void
	{
		$this->_manager = $value;
	}

	/**
	 * Returns whether this header replaces any existing same-name header in the
	 * response. Returns `false` for headers in {@see NON_REPLACING_HEADERS} and
	 * `true` for all others. Maps to PHP's {@see header()} `$replace` argument.
	 *
	 * Subclasses may override; {@see THttpHeader} additionally exposes a
	 * per-instance {@see THttpHeader::setReplace() Replace} property.
	 *
	 * @return bool `false` for multi-value headers, `true` for singletons.
	 */
	public function getReplace(): bool
	{
		$name = $this->getHeaderName();
		foreach (self::NON_REPLACING_HEADERS as $h) {
			if (strcasecmp($name, $h) === 0) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return string the name of the header.
	 */
	abstract public function getHeaderName(): string;

	/**
	 * @return string the textual value of the header.
	 */
	abstract public function getHeaderValue(): string;

	/**
	 * Sets the header value from a raw configuration string.
	 * Store it verbatim or parse it into typed properties.
	 * @param mixed $value
	 */
	abstract public function setHeaderValue($value): void;

	// =========================================================================
	// Actions / rendering
	// =========================================================================

	/**
	 * Sends this header to the response.
	 * Uses {@see getReplace()} to decide whether to replace an existing
	 * same-name header (`true`) or append a new one (`false`).
	 * @param ?\Prado\Web\THttpResponse $response
	 */
	public function sendHeader($response = null): void
	{
		if ($response === null) {
			$response = $this->getManager()?->getResponse();
		}
		if ($response) {
			$response->appendHeader((string) $this, $this->getReplace());
		} else {
			$this->header((string) $this, $this->getReplace());
		}
	}

	/**
	 * Renders the header as a `Name: Value` string.
	 * @return string the header line
	 */
	public function __toString(): string
	{
		return $this->getHeaderName() . ': ' . $this->getHeaderValue();
	}

	/**
	 * Wraps PHP's built-in {@see \header()} as a protected seam for unit testing.
	 * @param string $header        Raw header string, e.g. `X-Frame-Options: DENY`.
	 * @param bool   $replace       Replace an existing same-name header. Default: `true`.
	 * @param int    $response_code HTTP response code to force; `0` leaves it unchanged.
	 */
	protected function header(string $header, bool $replace = true, int $response_code = 0): void
	{
		header($header, $replace, $response_code);
	}
}
