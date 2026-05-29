<?php

/**
 * TApplicationComponent class
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado;

use Prado\TApplicationMode;

/**
 * TApplicationComponent class
 *
 * TApplicationComponent is the base class for all components that are
 * application-related, such as controls, modules, services, etc.
 *
 * TApplicationComponent mainly defines a few properties that are shortcuts
 * to some commonly used methods. The {@see getApplication Application}
 * property gives the application instance that this component belongs to;
 * {@see getService Service} gives the current running service;
 * {@see getRequest Request}, {@see getResponse Response} and {@see getSession Session}
 * return the request and response modules, respectively;
 * And {@see getUser User} gives the current user instance.
 *
 * Besides, TApplicationComponent defines two shortcut methods for
 * publishing private files: {@see publishAsset} and {@see publishFilePath}.
 *
 * Each TApplicationComponent instance retains a reference to the
 * {@see TApplication} that was current at construction time.  In multi-application
 * environments this lets a component stay bound to its owning application even
 * when a second application is pushed onto the global singleton slot.  Use
 * {@see isCurrentApplication}, {@see ensureCurrentApplication}, and
 * {@see makeCurrentApplication} to inspect and manage that binding.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Brad Anderson <belisoful@icloud.com> Multi-application support v4.4.0
 * @since 3.0
 */
class TApplicationComponent extends \Prado\TComponent
{
	public const FX_CACHE_FILE = 'fxevent.cache';

	/**
	 * The application instance that has owned this component since construction.
	 * Excluded from serialization so that the entire {@see TApplication} object
	 * graph is not embedded in a serialized component.  {@see __wakeup()} re-adopts
	 * the current global singleton after deserialization.
	 */
	private ?TApplication $_application = null;

	/**
	 * Captures the current global application and initializes global-event listening.
	 */
	public function __construct()
	{
		$this->resolveApplication();
		parent::__construct();
	}

	/**
	 * Excludes {@see $_application} from serialization to prevent the entire
	 * {@see TApplication} object graph from being embedded in serialized components.
	 * @param array $exprops by reference
	 */
	protected function _getZappableSleepProps(&$exprops)
	{
		parent::_getZappableSleepProps($exprops);
		$exprops[] = "\0" . __CLASS__ . "\0_application";
	}

	/**
	 * Re-adopts the current global application singleton after deserialization.
	 */
	public function __wakeup()
	{
		$this->resolveApplication();
		parent::__wakeup();
	}

	/**
	 * TApplicationComponents auto listen to global events.
	 *
	 * @return bool returns whether or not to listen.
	 */
	public function getAutoGlobalListen()
	{
		return true;
	}

	/**
	 * This caches the 'fx' events for PRADO classes in the application cache
	 * @param object $class The object to get the 'fx' events.
	 * @return string[] fx events from a specific class
	 */
	protected function getClassFxEvents($class)
	{
		static $_classfx = [];
		static $_classfxSize = 0;
		static $_loaded = false;

		$app = $this->getApplication();
		$cacheFile = $mode = null;
		if ($app) {
			$cacheFile = $app->getRuntimePath() . DIRECTORY_SEPARATOR . self::FX_CACHE_FILE;
			if ((($mode = $app->getMode()) === TApplicationMode::Normal || $mode === TApplicationMode::Performance) && !$_loaded) {
				$_loaded = true;
				if (($content = @file_get_contents($cacheFile)) !== false) {
					$_classfx = @unserialize($content) ?? [];
					$_classfxSize = count($_classfx);
				}
			}
		}
		$className = $class::class;
		if (array_key_exists($className, $_classfx)) {
			return $_classfx[$className];
		}
		$fx = parent::getClassFxEvents($class);
		$_classfx[$className] = $fx;
		if ($cacheFile) {
			if ($mode === TApplicationMode::Performance) {
				file_put_contents($cacheFile, serialize($_classfx), LOCK_EX);
			} elseif ($mode === TApplicationMode::Normal) {
				static $_flipClassMap = null;

				if ($_flipClassMap === null) {
					$_flipClassMap = array_flip(Prado::$classMap);
				}
				$classData = array_intersect_key($_classfx, $_flipClassMap);
				if (($c = count($classData)) > $_classfxSize) {
					$_classfxSize = $c;
					file_put_contents($cacheFile, serialize($_classfx), LOCK_EX);
				}
			}
		}
		return $fx;
	}

	/**
	 * Returns the application instance this component has been bound to since
	 * construction.  When {@see $_application} is null (e.g. after deserialization
	 * before {@see __wakeup} has run), the global singleton is resolved first via
	 * {@see resolveApplication}.
	 *
	 * @return ?TApplication current application instance, or null if none is registered.
	 */
	public function getApplication()
	{
		$this->resolveApplication();
		return $this->getApplicationDirect();
	}

	/**
	 * Returns the stored application reference without triggering lazy resolution.
	 * Unlike {@see getApplication}, this method never calls {@see resolveApplication}
	 * and may return null even when a global singleton exists.
	 *
	 * @return ?TApplication the stored application instance, or null.
	 * @since 4.4.0
	 */
	protected function getApplicationDirect(): ?TApplication
	{
		return $this->_application;
	}

	/**
	 * Stores an application reference directly, bypassing {@see resolveApplication}.
	 * Passing null clears the stored reference; a subsequent call to
	 * {@see getApplication} will then re-resolve from the global singleton.
	 *
	 * @param ?TApplication $app the application instance to store, or null to clear.
	 * @since 4.4.0
	 */
	protected function setApplicationDirect(?TApplication $app): void
	{
		$this->_application = $app;
	}

	/**
	 * Populates {@see $_application} from the global singleton when it has not
	 * yet been set.  Has no effect when {@see $_application} is already non-null,
	 * preserving the component's original application binding in multi-application
	 * environments.
	 *
	 * @since 4.4.0
	 */
	protected function resolveApplication(): void
	{
		if ($this->getApplicationDirect() === null) {
			$this->setApplicationDirect(Prado::getApplication());
		}
	}

	/**
	 * Returns whether this component's stored application is the current global singleton.
	 * Returns false when {@see $_application} is null or when it differs from
	 * {@see Prado::getApplication}.
	 *
	 * @return bool true if the stored application is the current global singleton.
	 * @since 4.4.0
	 */
	public function isCurrentApplication(): bool
	{
		$app = $this->getApplicationDirect();
		return $app !== null && $app === Prado::getApplication();
	}

	/**
	 * Updates the stored application reference to the current global singleton when
	 * they differ.  Has no effect when the global singleton is null (preserving the
	 * existing reference) or when the component is already bound to the current singleton.
	 *
	 * @agents remove this.  a component should not switch between apps like this.
	 */
	public function ensureCurrentApplication(): void
	{
		$globalApp = Prado::getApplication();
		if ($globalApp !== null && $this->getApplicationDirect() !== $globalApp) {
			$this->setApplicationDirect($globalApp);
		}
	}

	/**
	 * Promotes the stored application to be the current global singleton.
	 * Has no effect when {@see $_application} is null or when it is already the
	 * current global singleton.
	 *
	 * @since 4.4.0
	 */
	public function makeCurrentApplication(): void
	{
		$app = $this->getApplicationDirect();
		if ($app !== null && !$this->isCurrentApplication()) {
			Prado::setApplication($app);
		}
	}

	/**
	 * @return ?\Prado\TService the current service, or null if no application is available.
	 */
	public function getService()
	{
		return $this->getApplication()?->getService();
	}

	/**
	 * @return ?\Prado\Web\THttpRequest the current user request, or null if no application is available.
	 */
	public function getRequest()
	{
		return $this->getApplication()?->getRequest();
	}

	/**
	 * @return ?\Prado\Web\THttpResponse the response, or null if no application is available.
	 */
	public function getResponse()
	{
		return $this->getApplication()?->getResponse();
	}

	/**
	 * @return ?\Prado\Web\THttpSession user session, or null if no application is available.
	 */
	public function getSession()
	{
		return $this->getApplication()?->getSession();
	}

	/**
	 * @return ?\Prado\Security\IUser information about the current user, or null if no application is available.
	 */
	public function getUser()
	{
		return $this->getApplication()?->getUser();
	}

	/**
	 * Publishes a private asset and gets its URL.
	 * This method will publish a private asset (file or directory)
	 * and gets the URL to the asset. Note, if the asset refers to
	 * a directory, all contents under that directory will be published.
	 * Also note, it is recommended that you supply a class name as the second
	 * parameter to the method (e.g. publishAsset($assetPath,__CLASS__) ).
	 * By doing so, you avoid the issue that child classes may not work properly
	 * because the asset path will be relative to the directory containing the child class file.
	 *
	 * @param string $assetPath path of the asset that is relative to the directory containing the specified class file.
	 * @param string $className name of the class whose containing directory will be prepend to the asset path. If null, it means $this::class.
	 * @return string URL to the asset path.
	 */
	public function publishAsset($assetPath, $className = null)
	{
		if ($className === null) {
			$className = $this::class;
		}
		$class = TComponentReflection::getReflectionClassByType($className);
		$fullPath = dirname($class->getFileName()) . DIRECTORY_SEPARATOR . $assetPath;
		return $this->publishFilePath($fullPath);
	}

	/**
	 * Publishes a file or directory and returns its URL.
	 * @param string $fullPath absolute path of the file or directory to be published
	 * @param mixed $checkTimestamp
	 * @return string URL to the published file or directory
	 */
	public function publishFilePath($fullPath, $checkTimestamp = false)
	{
		return $this->getApplication()?->getAssetManager()?->publishFilePath($fullPath, $checkTimestamp);
	}
}
