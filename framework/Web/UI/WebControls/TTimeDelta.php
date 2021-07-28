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
use Prado\Exceptions\TInvalidDataValueException;

/**
 * TTimeDelta class
 *
 * TTimeDelta is shows time difference in a label as '(# seconds|minutes|hours|etc)'.  This
 * embeds javascript to keep the TTimeDelta up to date.  PartialElement enables displaying
 * the following element when time is getting close to changing the first significant element.
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
		$data = [];
		if (($style = $this->getStyle()) === 'full') {
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
		$sigelements = $this->getSignificantElements();
		$options['ID'] = $this->getClientID();
		$options['ServerTime'] = time();
		$options['OriginTime'] = $this->getTimeStamp();
		$options['ClickToChange'] = $this->getClickSeeDateTime();
		$options['UseServerTime'] = $this->getUseServerTime();
		$options['Separator'] = $this->getSeparator();
		$options['DisplayZero'] = $this->getDisplayZero();
		$options['SignificantElements'] = ($sigelements === '*' ? 10 : $sigelements);
		$options['PartialElement'] = $this->getPartialElement();
		$options['PartialCount'] = [
			$this->getYearsWithMonths(),
			$this->getMonthsWithWeeks(),
			$this->getWeeksWithDays(),
			$this->getDaysWithHours(),
			$this->getHoursWithMinutes(),
			$this->getMinutesWithSeconds(),
			5
		];
		
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
	
	/**
	 * @return bool whether to use the serve time (or  client time), default true (server time)
	 */
	public function getUseServerTime()
	{
		return $this->getViewState('useServeTime', true);
	}
	
	/**
	 * @param bool $v whether to use the serve time (or  client time)
	 */
	public function setUseServerTime($v)
	{
		$this->setViewState('useServeTime', TPropertyValue::ensureBoolean($v));
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
	 * Display units that have zero numeric value.
	 * @return bool display units that have zero numeric value, default false.
	 */
	public function getDisplayZero()
	{
		return $this->getViewState('displayzero', false);
	}
	
	/**
	 * Display units that have zero numeric value.
	 * @param bool $display display units that have zero numeric value.
	 */
	public function setDisplayZero($display)
	{
		$this->setViewState('displayzero', TPropertyValue::ensureBoolean($display));
	}
	
	/**
	 * The number of significant Elements to display.
	 * @return int The number of significant Elements to display, default 1.
	 */
	public function getSignificantElements()
	{
		return $this->getViewState('significantElements', 1);
	}
	
	/**
	 * The number of significant Elements to display.
	 * @param bool $sigElements The number of significant Elements to display.
	 */
	public function setSignificantElements($sigElements)
	{
		$this->setViewState('significantElements', TPropertyValue::ensureInteger($sigElements));
	}
	
	/**
	 * This allows the control set to one significant element to display
	 * the next important element when it is close to changing significant elements.
	 * @return bool display the next significant element when close to changing elements, default true.
	 */
	public function getPartialElement()
	{
		return $this->getViewState('partialElement', true);
	}
	
	/**
	 * @param bool $partial display the next significant element when close to changing elements.
	 */
	public function setPartialElement($partial)
	{
		$this->setViewState('partialElement', TPropertyValue::ensureBoolean($partial));
	}
	
	/**
	 * When the time is within a certain number of minutes, also show the seconds.
	 * This works when PartialElement is true.
	 * @return int number of minutes, default 4.
	 */
	public function getMinutesWithSeconds()
	{
		return $this->getViewState('minutesWithSeconds', 4);
	}
	
	/**
	 * When the time is within a certain number of minutes, also show the seconds.
	 * This works when PartialElement is true.
	 * @param mixed $minutes
	 * @return int $minutes number of minutes.
	 */
	public function setMinutesWithSeconds($minutes)
	{
		$this->setViewState('minutesWithSeconds', TPropertyValue::ensureInteger($minutes));
	}
	
	/**
	 * When the time is within a certain number of hours, also show the minutes.
	 * This works when PartialElement is true.
	 * @return int number of hours, default 3
	 */
	public function getHoursWithMinutes()
	{
		return $this->getViewState('hoursWithMinutes', 3);
	}
	
	/**
	 * When the time is within a certain number of hours, also show the minutes.
	 * This works when PartialElement is true.
	 * @param mixed $hours
	 * @return int $hours number of hours.
	 */
	public function setHoursWithMinutes($hours)
	{
		$this->setViewState('hoursWithMinutes', TPropertyValue::ensureInteger($hours));
	}
	
	/**
	 * When the time is within a certain number of days, also show the hours.
	 * This works when PartialElement is true.
	 * @return int number of days, default 3
	 */
	public function getDaysWithHours()
	{
		return $this->getViewState('daysWithHours', 3);
	}
	
	/**
	 * When the time is within a certain number of days, also show the hours.
	 * This works when PartialElement is true.
	 * @param mixed $days
	 * @return int $days number of days.
	 */
	public function setDaysWithHours($days)
	{
		$this->setViewState('daysWithHours', TPropertyValue::ensureInteger($days));
	}
	
	/**
	 * When the time is within a certain number of weeks, also show the days.
	 * This works when PartialElement is true.
	 * @return int number of weeks, default 2
	 */
	public function getWeeksWithDays()
	{
		return $this->getViewState('weeksWithDays', 2);
	}
	
	/**
	 * When the time is within a certain number of weeks, also show the days.
	 * This works when PartialElement is true.
	 * @param mixed $weeks
	 * @return int $weeks number of weeks.
	 */
	public function setWeeksWithDays($weeks)
	{
		$this->setViewState('weeksWithDays', TPropertyValue::ensureInteger($weeks));
	}
	
	/**
	 * When the time is within a certain number of months, also show the weeks.
	 * This works when PartialElement is true.
	 * @return int number of months, default 3
	 */
	public function getMonthsWithWeeks()
	{
		return $this->getViewState('monthsWithWeeks', 3);
	}
	
	/**
	 * When the time is within a certain number of months, also show the weeks.
	 * This works when PartialElement is true.
	 * @param mixed $months
	 * @return int $months number of months.
	 */
	public function setMonthsWithWeeks($months)
	{
		$this->setViewState('monthsWithWeeks', TPropertyValue::ensureInteger($months));
	}
	
	/**
	 * When the time is within a certain number of years, also show the months.
	 * This works when PartialElement is true.
	 * @return int number of years, default 2
	 */
	public function getYearsWithMonths()
	{
		return $this->getViewState('yearsWithMonths', 2);
	}
	
	/**
	 * When the time is within a certain number of years, also show the months.
	 * This works when PartialElement is true.
	 * @param mixed $years
	 * @return int $years number of years.
	 */
	public function setYearsWithMonths($years)
	{
		$this->setViewState('yearsWithMonths', TPropertyValue::ensureInteger($years));
	}
	
	/**
	 * The style of the time difference, full is full text, short is short text,
	 * and narrow is one letter.
	 * @return string style of the time delta
	 */
	public function getStyle()
	{
		return $this->getViewState('style', 'short');
	}
	
	/**
	 * The style of the time delta
	 * @param string $style style of the time delta
	 */
	public function setStyle($style)
	{
		$style = TPropertyValue::ensureString($style);
		if (!in_array($style = strtolower($style), ['full', 'short', 'narrow'])) {
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
