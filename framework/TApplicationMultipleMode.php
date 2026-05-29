<?php

/**
 * TApplicationMultipleMode class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado;

/**
 * TApplicationMultipleMode class.
 *
 * Controls how a {@see TApplication} registers itself with the global {@see Prado}
 * application pool at construction time.  Corresponds to the `MultipleMode`
 * attribute of the `<application>` element:
 *
 * ```xml
 * <application MultipleMode="Auto">
 * ```
 *
 * - **Auto** *(default)* — Adaptive: becomes the singleton when no application is
 *   registered; auto-enables multiple-application mode and joins the pool when
 *   another app is already running.
 * - **Multiple** — Always calls {@see Prado::setMultipleApplications(true)} and
 *   joins the pool, regardless of whether another application already exists.
 * - **Singleton** — Throws {@see \Prado\Exceptions\TInvalidOperationException} if a
 *   *different* application is already registered with Prado.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 4.4.0
 */
class TApplicationMultipleMode extends TEnumerable
{
	/** Adaptive mode (default): singleton when alone, multi-app when another instance exists. */
	public const Auto = 'Auto';

	/** Always joins the pool; enables {@see Prado::setMultipleApplications(true)} unconditionally. */
	public const Multiple = 'Multiple';

	/** Strict singleton: throws if a different application is already registered. */
	public const Singleton = 'Singleton';
}
