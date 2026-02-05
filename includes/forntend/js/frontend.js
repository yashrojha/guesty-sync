/**
 * Register the Frontend Dateselect js
 */
document.addEventListener('DOMContentLoaded', function () {
    const picker = new Litepicker({
		element: document.getElementById('checkin_date_display'),
		elementEnd: document.getElementById('checkout_date_display'),
		singleMode: false,
		autoApply: true,
		mobileFriendly: true,
        minDate: new Date(),
		numberOfMonths: window.innerWidth > 768 ? 2 : 1,
        numberOfColumns: window.innerWidth > 768 ? 2 : 1,		
		format: 'DD MMM, YYYY',
		lang: 'en-US',
		locale: {
            weekdaysMin: ['S', 'M', 'T', 'W', 'T', 'F', 'S'], 
        },
		showTooltip: true,
		tooltipNumber: (totalDays) => {
            return totalDays - 1;
        },
		tooltipText: {
            one: 'day',
            other: 'days'
        },
		setup: (picker) => {
			picker.on('selected', (start, end) => {
				if (!end) return;
				document.getElementById('checkin_date').value =
					start.format('YYYY-MM-DD');
				document.getElementById('checkout_date').value =
					end.format('YYYY-MM-DD');
				document.getElementById('checkin_date_display').value =
					start.format('YYYY-MM-DD');
				document.getElementById('checkout_date_display').value =
					end.format('YYYY-MM-DD');
			});
		}
	});
});
