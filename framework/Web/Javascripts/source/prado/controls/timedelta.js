/*! PRADO TTimeDelta javascript file | github.com/pradosoft/prado */

/**
 * TTimeDelta client class.
 *
 */


Prado.WebUI.TTimeDelta = jQuery.klass(Prado.WebUI.Control,
{
	onInit : function(options)
	{
		this.options = options || {};
		this.startTime = (new Date()).getTime()/1000;
		this.showDT = false;
		this.loopTimeout = false;
		this.waitTime = 0;
		
		if(this.options.ClickToChange) {
			jQuery(this.element).css('cursor', 'pointer');
			jQuery(this.element).click(this, function(event){event.data.eventMouseDown();});
		}
		this.drawLoop();
	},
	
	
	eventMouseDown : function()
	{
		this.showDT = !this.showDT;
		this.render();
	},
	
	
	drawLoop : function() 
	{
		this.render();
		this.loopTimeout = setTimeout(() => {this.drawLoop();}, this.waitTime * 1000);
	},
	
	
	Localize : function(type, time) 
	{
		if (time == 1) {
			str = this.options.LocalizeStrings[type]['one'];
		} else {
			str = this.options.LocalizeStrings[type]['other'];
		}	
		return str.replace('{0}', time);
	},
	
	
	render : function()
	{
		var current = (new Date()).getTime()/1000;
		delta = current - this.options.OriginTime;
		
		if(this.options.UseServerTime) {
			delta += this.options.ServerTime - this.startTime;
		}
		
		if(delta < 0) {
			delta *= -1;
			isFuture = true;
		} else {
			isFuture = false;
		}
		
		timing = [
			{'type': 'year', 'seconds' : 86400*365.2421896698, 'period': 86400*365.2421896698*2, 'sig' : this.options.PartialCount[0]},
			{'type': 'month', 'seconds' : 2629743.7656, 'period': 86400*30.43684913915*12, 'sig' : this.options.PartialCount[1]},
			{'type': 'week', 'seconds' : 604800, 'period': 86400*30.43684913915, 'sig' : this.options.PartialCount[2]},
			{'type': 'day', 'seconds' : 86400, 'period': 86400*7, 'sig' : this.options.PartialCount[3]},
			{'type': 'hour', 'seconds' : 3600, 'period': 86400, 'sig' : this.options.PartialCount[4]},
			{'type': 'minute', 'seconds' : 60, 'period': 3600, 'sig' : this.options.PartialCount[5]},
			{'type': 'second', 'seconds' : 1, 'period': 60, 'sig' : this.options.PartialCount[6]}
		];
		
		if (delta >= timing[0].period) {
			date = new Date();
			date.setTime(this.options.OriginTime * 1000);
			options = {year: 'numeric', month: 'short'};
			str = new Intl.DateTimeFormat('default', options).format(date);
			this.waitTime = 3600/2;
		} else {
			_delta = delta;
			initialType = false;
			digits = 0;
			str = '';
			importantNextElement = false;
			for (i = 0; i < timing.length;  i++) {
				num = Math.floor(_delta / timing[i].seconds);
				if (num != 0 && initialType == false) {
					initialType = i;
				}
				if (initialType !== false && ((digits < this.options.SignificantElements)
					|| (this.options.PartialElement && digits == 1 && importantNextElement)
				)) {
					if (this.options.DisplayZero || num != 0) {
						if (str.length != 0) {
							str += this.options.Separator;
						}
						str += this.Localize(timing[i].type, num);
						_delta -= num * timing[i].seconds;
					}
					digits++;
					importantNextElement = (num <= timing[i].sig);
					this.waitTime = (_delta % timing[i].seconds);
					if (!isFuture) {
						this.waitTime = timing[i].seconds - this.waitTime;
					}
				}
			}
			if(initialType == false) {
				str = this.Localize('second', 0);
				this.waitTime = 1;
			}
		}
		// the waitTime needs to be computed regardless of showDT
		if(this.showDT) {
			date = new Date();
			date.setTime(this.options.OriginTime * 1000);
			options = {
				  year: 'numeric', month: 'long', day: 'numeric',
				  hour: 'numeric', minute: 'numeric', second: 'numeric'
				};
			str = new Intl.DateTimeFormat('default', options).format(date);
		}
		this.element.innerHTML = str;
	}
});
