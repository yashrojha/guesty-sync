/**
 * Register the frontend date picker (Litepicker)
 */
document.addEventListener('DOMContentLoaded', function () {
    // Card Swiper (archive + featured)
    if (typeof Swiper !== 'undefined') {
        // Treat <768px as "mobile" for autoplay + UI tweaks
        var isMobile = window.matchMedia('(max-width: 767px)').matches;

        document.querySelectorAll('.card-swiper').forEach(function (el) {
            var slides = el.querySelectorAll('.swiper-slide');
            if (slides.length === 0) return;
            if (slides.length === 1) el.classList.add('single-slide');

            new Swiper(el, {
                slidesPerView: 1,
                spaceBetween: 0,
                loop: slides.length > 1,
                autoplay: isMobile && slides.length > 1 ? {
                    delay: 3000,
                    disableOnInteraction: false,
                } : false,
                navigation: {
                    nextEl: el.querySelector('.swiper-button-next'),
                    prevEl: el.querySelector('.swiper-button-prev'),
                },
                pagination: {
                    el: el.querySelector('.swiper-pagination'),
                    clickable: true,
                },
            });
        });
    }

    const pickerEl = document.getElementById('checkin_date_display');
    if (pickerEl) {
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
    }

    // Multi-select city dropdown
    const multiselect = document.getElementById('city-multiselect');
    if (multiselect) {
        const trigger    = multiselect.querySelector('.guesty-multiselect-trigger');
        const dropdown   = multiselect.querySelector('.guesty-multiselect-dropdown');
        const searchInput = multiselect.querySelector('.guesty-multiselect-search');
        const valueDisplay = multiselect.querySelector('.guesty-multiselect-value');
        const placeholder = multiselect.dataset.placeholder || 'Select…';
        const noResultsEl = multiselect.querySelector('.guesty-multiselect-no-results');

        function getChecked() {
            return [...multiselect.querySelectorAll('.guesty-multiselect-cb:checked')].map(cb => cb.value);
        }

        function syncOptionAria() {
            multiselect.querySelectorAll('.guesty-multiselect-option').forEach(function (opt) {
                var cb = opt.querySelector('.guesty-multiselect-cb');
                if (cb) {
                    opt.setAttribute('aria-selected', cb.checked ? 'true' : 'false');
                }
            });
        }

        function updateDisplay() {
            var checked = getChecked();
            syncOptionAria();
            if (checked.length > 0) {
                valueDisplay.textContent = checked.join(', ');
                valueDisplay.classList.add('has-value');
            } else {
                valueDisplay.textContent = placeholder;
                valueDisplay.classList.remove('has-value');
            }
        }

        function openDropdown() {
            dropdown.hidden = false;
            multiselect.setAttribute('aria-expanded', 'true');
            if (typeof searchInput.focus === 'function') {
                try {
                    searchInput.focus({ preventScroll: true });
                } catch (err) {
                    searchInput.focus();
                }
            }
        }

        function syncRegionSearchFilter() {
            var q = (searchInput.value || '').toLowerCase().trim();
            var anyVisible = false;
            var hasOptions = false;
            multiselect.querySelectorAll('.guesty-multiselect-option').forEach(function (opt) {
                hasOptions = true;
                var labelEl = opt.querySelector('.guesty-multiselect-option-label');
                var label = labelEl ? labelEl.textContent.toLowerCase() : '';
                var match = q === '' ? true : label.includes(q);
                opt.hidden = !match;
                if (match) {
                    anyVisible = true;
                }
            });
            if (noResultsEl) {
                noResultsEl.hidden = !(hasOptions && !anyVisible && q.length > 0);
            }
        }

        function closeDropdown() {
            dropdown.hidden = true;
            multiselect.setAttribute('aria-expanded', 'false');
            searchInput.value = '';
            multiselect.querySelectorAll('.guesty-multiselect-option').forEach(function (opt) {
                opt.hidden = false;
            });
            if (noResultsEl) {
                noResultsEl.hidden = true;
            }
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            if (dropdown.hidden) {
                openDropdown();
            } else {
                closeDropdown();
            }
        });

        searchInput.addEventListener('input', syncRegionSearchFilter);

        multiselect.querySelectorAll('.guesty-multiselect-cb').forEach(function (cb) {
            cb.addEventListener('change', updateDisplay);
        });

        // Toggle via button so focus never moves to the hidden checkbox (avoids scroll-into-view jumps in the list).
        multiselect.querySelectorAll('.guesty-multiselect-row').forEach(function (row) {
            row.addEventListener('click', function (e) {
                e.stopPropagation();
                var opt = row.closest('.guesty-multiselect-option');
                if (!opt || opt.hidden) {
                    return;
                }
                var cb = opt.querySelector('.guesty-multiselect-cb');
                if (!cb) {
                    return;
                }
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        document.addEventListener('click', function (e) {
            if (!multiselect.contains(e.target)) {
                closeDropdown();
            }
        });

        document.addEventListener('keydown', function (e) {
            if ((e.key === 'Escape' || e.key === 'Esc') && !dropdown.hidden) {
                closeDropdown();
                trigger.focus();
            }
        });

        updateDisplay();
    }

    // Mobile search modal behaviour
    const searchBox = document.querySelector('.guesty-search-bar-box');
    const mobileTrigger = document.querySelector('.guesty-search-bar-mobile-trigger');
    const closeButton = document.querySelector('.guesty-search-modal-close');

    if (searchBox && mobileTrigger && closeButton) {
        const openModal = () => {
            searchBox.classList.add('is-open');
            document.body.classList.add('guesty-search-modal-open');
            mobileTrigger.setAttribute('aria-expanded', 'true');
        };

        const closeModal = () => {
            searchBox.classList.remove('is-open');
            document.body.classList.remove('guesty-search-modal-open');
            mobileTrigger.setAttribute('aria-expanded', 'false');
        };

        mobileTrigger.addEventListener('click', openModal);
        closeButton.addEventListener('click', closeModal);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.key === 'Esc') {
                closeModal();
            }
        });
    }
});