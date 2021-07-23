<?php
/**
 * TTimeDelta class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/pradosoft/prado
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 * @package Prado\Web\UI\WebControls
 */

namespace Prado\Web\UI\WebControls;

use Prado\Prado;
use Prado\TPropertyValue;
use Prado\Web\Javascripts\TJavaScript;

/**
 * TTimeDelta class
 *
 * TimeAgo is shows time and date in a label as '(# seconds|minutes|hours|etc) ago'.  This
 * embeds javascript to keep the TTimeAgo up to date.  As time changes,
 * the TTimeAgo is kept up to date.  The resolution depends on how far ago
 * the moment is.
 *
 * When clicking on a time ago label, the entry turns into the date and time stamp for specifics.
 * @author Brad Anderson <belisoful@icloud.com>
 * @package Prado\Web\UI
 * @since 4.2.0
 */

class TTimeDelta extends TLabel
{
	protected function getDurationData()
	{
		if (($style = strtolower($this->getStyle())) === 'full') {
			$data = [
				'year' => [
					'one' => '{0} year',
					'other' => '{0} years'
				],
				'month' => [
					'one' => '{0} month',
					'other' => '{0} months'
				],
				'week' => [
					'one' => '{0} week',
					'other' => '{0} weeks'
				],
				'day' => [
					'one' => '{0} day',
					'other' => '{0} days'
				],
				'hour' => [
					'one' => '{0} hour',
					'other' => '{0} hours'
				],
				'minute' => [
					'one' => '{0} minute',
					'other' => '{0} minutes'
				],
				'second' => [
					'one' => '{0} second',
					'other' => '{0} seconds'
				]
			];
		} elseif ($style === 'short') {
			$data = [
				'year' => [
					'one' => '{0} yr',
					'other' => '{0} yrs'
				],
				'month' => [
					'one' => '{0} mth',
					'other' => '{0} mths'
				],
				'week' => [
					'one' => '{0} wk',
					'other' => '{0} wks'
				],
				'day' => [
					'one' => '{0} day',
					'other' => '{0} days'
				],
				'hour' => [
					'one' => '{0} hr',
					'other' => '{0} hr'
				],
				'minute' => [
					'one' => '{0} min',
					'other' => '{0} min'
				],
				'second' => [
					'one' => '{0} sec',
					'other' => '{0} sec'
				]
			];
		} elseif ($style === 'narrow') {
			$data = [
				'year' => [
					'one' => '{0}y',
					'other' => '{0}y'
				],
				'month' => [
					'one' => '{0}m',
					'other' => '{0}m'
				],
				'week' => [
					'one' => '{0}w',
					'other' => '{0}w'
				],
				'day' => [
					'one' => '{0}d',
					'other' => '{0}d'
				],
				'hour' => [
					'one' => '{0}h',
					'other' => '{0}h'
				],
				'minute' => [
					'one' => '{0}m',
					'other' => '{0}m'
				],
				'second' => [
					'one' => '{0}s',
					'other' => '{0}s'
				]
			];
		}
		return $data;
	}
	
	/**
	 * Adds attribute name-value pairs to renderer.
	 * This overrides the parent implementation with additional button specific attributes.
	 * @param \Prado\Web\UI\THtmlWriter $writer the writer used for the rendering purpose
	 */
	protected function addAttributesToRender($writer)
	{
		if ($this->getPage()->getClientSupportsJavaScript() && $this->getEnabled(true)) {
			$writer->addAttribute('id', $this->getClientID());
		}
		parent::addAttributesToRender($writer);
	}

	/**
	 * Registers CSS and JS.
	 * This method is invoked right before the control rendering, if the control is visible.
	 * @param mixed $param event parameter
	 */
	public function onPreRender($param)
	{
		parent::onPreRender($param);
		if ($this->getPage()->getClientSupportsJavaScript() && $this->getEnabled(true)) {
			$this->registerClientScript();
		}
	}

	/**
	 * Registers the relevant JavaScript.
	 */
	protected function registerClientScript()
	{
		$options = TJavaScript::encode($this->getClientOptions());
		$className = $this->getClientClassName();
		$cs = $this->getPage()->getClientScript();
		$cs->registerPradoScript('timedelta');
		$cs->registerEndScript('prado:' . $this->getClientID(), "new $className($options);");
	}

	/**
	 * Gets the name of the javascript class responsible for performing postback for this control.
	 * This method overrides the parent implementation.
	 * @return string the javascript class name
	 */
	protected function getClientClassName()
	{
		return 'Prado.WebUI.TTimeDelta';
	}

	/**
	 * @return array the JavaScript options for this control
	 */
	protected function getClientOptions()
	{
		$options['ID'] = $this->getClientID();
		$options['ServerTime'] = time();
		$options['OriginTime'] = $this->getTimeStamp();
		$options['ClickToChange'] = $this->getClickSeeDateTime();
		$options['Compensate'] = $this->getCompensateUserTime();
		$options['Separator'] = $this->getSeparator();
		
		$local = $this->getDurationData();
		$options['LocalizeStrings'] = [
			'year' => $local['year'],
			'month' => $local['month'],
			'week' => $local['week'],
			'day' => $local['day'],
			'hour' => $local['hour'],
			'minute' => $local['minute'],
			'second' => $local['second']
		];
		;
		return $options;
	}
	
	/**
	 *	getDateTime is the date of the time ago label
	 */
	public function getDateTime()
	{
		return $this->getViewState('datetime', time());
	}
	
	/**
	 *	setDateTime sets the date of the time ago label
	 * @param mixed $v
	 */
	public function setDateTime($v)
	{
		$this->setViewState('datetime', TPropertyValue::ensureString($v));
	}
	
	/**
	 *	getClickSeeDateTime returns whether or not to allow clicking to change the label to
	 * the the exact time and date. Clicking a second time changes the time ago back to it's
	 * continuous function.
	 */
	public function getClickSeeDateTime()
	{
		return $this->getViewState('clicksee', true);
	}
	
	/**
	 *	getClickSeeDateTime returns whether or not to allow clicking to change the label to
	 * the the exact time and date. Clicking a second time changes the time ago back to it's
	 * continuous function.
	 * @param bool $v
	 */
	public function setClickSeeDateTime($v)
	{
		$this->setViewState('clicksee', TPropertyValue::ensureBoolean($v));
	}
	
	public function getCompensateUserTime()
	{
		return $this->getViewState('compensate', true);
	}
	public function setCompensateUserTime($v)
	{
		$this->setViewState('compensate', TPropertyValue::ensureBoolean($v));
	}
	
	/**
	 * The separator between components of a time delta
	 * @return string separator between time delta components
	 */
	public function getSeparator()
	{
		return $this->getViewState('separator', ' ');
	}
	
	/**
	 * The separator between components of a time delta
	 * @param string $separator separator between time delta components
	 */
	public function setSeparator($separator)
	{
		$this->setViewState('separator', TPropertyValue::ensureString($separator));
	}
	
	/**
	 * The separator between components of a time delta
	 * @return string separator between time delta components
	 */
	public function getStyle()
	{
		return $this->getViewState('style', 'Short');
	}
	
	/**
	 * The separator between components of a time delta
	 * @param string $style separator between time delta components
	 */
	public function setStyle($style)
	{
		$style = TPropertyValue::ensureString($style);
		if (!in_array(strtolower($style), ['full', 'short', 'narrow'])) {
			throw new TInvalidDataValueException('timedelta_bad_style', $style);
		}
		$this->setViewState('style', $style);
	}
	
	public function getTimeStamp()
	{
		$dt = $this->getDateTime();
		if (!$dt) {
			$dt = '2000-01-01 00:00:00';
		}
		if (is_numeric($dt)) {
			return $dt;
		}
		return strtotime($dt);
	}
}
