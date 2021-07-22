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
		this.rerenderTimeout = false;
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
		this.rerenderTimeout = setTimeout(() => {this.drawLoop();}, this.waitTime * 1000);
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
		
		if(this.options.Compensate) {
			delta += this.options.ServerTime - this.startTime;
		}
		
		if(delta < 0) {
			delta *= -1;
			isFuture = true;
		} else {
			isFuture = false;
		}
		
		timing = [
			{'type': 'year', 'seconds' : 31556925.1874, 'period': 60*60*24*365.2421896698*2},
			{'type': 'month', 'seconds' : 2629743.7656, 'period': 12},
			{'type': 'week', 'seconds' : 604800, 'period': 30.43684913915, 'time' : Math.floor((delta / (60 * 60 * 24)) % 30) },
			{'type': 'day', 'seconds' : 86400, 'period': 7, 'time' : Math.floor((delta / (60 * 60 * 24)) % 7)},
			{'type': 'hour', 'seconds' : 3600, 'period': 24, 'time' : Math.floor((delta / (60 * 60)) % 24)},
			{'type': 'minute', 'seconds' : 60, 'period': 60, 'time' : Math.floor((delta / 60) % 60)},
			{'type': 'second', 'seconds' : 1, 'period': 60, 'time': Math.floor(delta % 60)}
		];
		
		if (delta >= timing[0].period) {
			date = new Date();
			date.setTime(this.options.OriginTime * 1000);
			options = {year: 'numeric', month: 'short'};
			str = new Intl.DateTimeFormat('default', options).format(date);
			wait = 3600;
		} else {
			for (i = 0; i < timing.length;  i++) {
				
			}
		}
		
		if(delta < 60) {
			seconds = Math.floor(delta);
			str = this.Localize('second', seconds);
			this.waitTime = 1;
		} else if(delta < 60*60) {
			minutes = Math.floor(delta/60);
			seconds = Math.floor(delta - minutes * 60);
			str = this.Localize('minute', minutes);
			this.waitTime = 60 - (seconds % 60);
			if (minutes <= 5) {
				str += this.options.Separator + this.Localize('second', seconds);
				this.waitTime = 1;
			}
		} else if(delta < 60*60*24) {
			hours = Math.floor(delta/(60*60));
			minutes = Math.floor(delta/60 - hours * 60);
			str = this.Localize('hour', hours);
			this.waitTime = (60*60) - (delta % (60*60));
			if (hours <= 2) {
				str += this.options.Separator + this.Localize('minute', minutes);
				this.waitTime = 60 - (seconds % 60);
			}
		} else if(delta < 60*60*24*7) {
			days = Math.floor(delta/(60*60*24));
			hours = Math.floor(delta/(60*60) - days * 24);
			str = this.Localize('day', days);
			this.waitTime = (60*60*24) - (delta % (60*60*24));
			if (days <= 2) {
				str += this.options.Separator + this.Localize('hour', hours);
				this.waitTime = (60*60) - (delta % (60*60));
			}
		} else if(delta < 60*60*24*30) {
			weeks = Math.floor(delta/(60*60*24*7));
			days = Math.floor(delta/(60*60*24) - weeks * 7);
			str = this.Localize('week', weeks);
			this.waitTime = (60*60*24*7) - (delta % (60*60*24*7));
			if (weeks <= 2) {
				str += this.options.Separator + this.Localize('day', days);
				this.waitTime = (60*60*24) - (delta % (60*60*24));
			}
		} else if(delta < 60*60*24*365) {
			months = Math.floor(delta/(60*60*24*30));
			weeks = Math.floor((delta/(60*60*24) - months * 30) / 7);
			str = this.Localize('month', months);
			this.waitTime = (60*60*24*30) - (delta % (60*60*24*30));
			if (months <= 2) {
				str += this.options.Separator + this.Localize('week', weeks);
				this.waitTime = (60*60*24*7) - ((delta % (60*60*24*30)) % (60*60*24*7));
			}
		} else {
		}
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
