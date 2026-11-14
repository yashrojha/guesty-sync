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

    // Unified region + property field.
    // Pills hold what is selected. Only the text still in the input is matched.
    const where = document.getElementById('guesty-where');
    if (where) {
        const MAX_PROPERTIES = 8;
        const form = where.closest('form');
        const input = where.querySelector('.guesty-where-input');
        const clearBtn = where.querySelector('.guesty-where-clear');
        const selectedWrap = where.querySelector('.guesty-where-selected');
        const pillsEl = where.querySelector('.guesty-where-pills');
        const valuesEl = where.querySelector('.guesty-where-values');
        const panel = where.querySelector('.guesty-where-panel');
        const list = where.querySelector('.guesty-where-list');
        const regionHeading = where.querySelector('[data-heading="region"]');
        const propertyHeading = where.querySelector('[data-heading="property"]');
        const more = where.querySelector('.guesty-where-more');
        const moreLink = where.querySelector('.guesty-where-more-link');
        const archiveUrl = moreLink ? moreLink.href : '';
        const empty = where.querySelector('.guesty-where-empty');
        const control = where.querySelector('.guesty-where-control');
        const regionOptions = Array.prototype.slice.call(where.querySelectorAll('.guesty-where-option[data-type="region"]'));
        const propertyOptions = Array.prototype.slice.call(where.querySelectorAll('.guesty-where-option[data-type="property"]'));
        const state = { pills: [], query: '' };
        let open = false;
        let active = null;
        let applyDraft = false;
        let draft = '';
        let keepOpen = false;
        let closeTimer = null;

        function norm(value) {
            return (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
        }

        function summary() {
            return state.pills.map(function (pill) {
                return pill.type === 'region' ? pill.label : pill.title;
            }).join(', ');
        }

        function isChosen(opt) {
            return state.pills.some(function (pill) {
                if (opt.dataset.type === 'property') {
                    return pill.type === 'property' && pill.id === opt.dataset.id;
                }
                return pill.type === 'region' && pill.label === opt.dataset.label;
            });
        }

        function regionPills() {
            return state.pills.filter(function (pill) { return pill.type === 'region'; });
        }

        function propertyPills() {
            return state.pills.filter(function (pill) { return pill.type === 'property'; });
        }

        function markOptions() {
            var regions = {};
            var properties = {};
            state.pills.forEach(function (pill) {
                if (pill.type === 'region') regions[pill.label] = true;
                if (pill.type === 'property') properties[pill.id] = true;
            });
            regionOptions.forEach(function (opt) {
                opt.setAttribute('aria-selected', regions[opt.dataset.label] ? 'true' : 'false');
            });
            propertyOptions.forEach(function (opt) {
                opt.setAttribute('aria-selected', properties[opt.dataset.id] ? 'true' : 'false');
            });
        }

        function renderPills() {
            pillsEl.replaceChildren();
            state.pills.forEach(function (pill) {
                var el = document.createElement('span');
                el.className = 'guesty-where-pill';
                el.dataset.type = pill.type;
                var text = document.createElement('span');
                text.className = 'guesty-where-pill-label';
                text.textContent = pill.type === 'region' ? pill.label : pill.title;
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'guesty-where-pill-remove';
                remove.setAttribute('aria-label', 'Remove ' + text.textContent);
                remove.textContent = '\u00d7';
                remove.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                });
                remove.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    removePill(pill);
                });
                el.appendChild(text);
                el.appendChild(remove);
                pillsEl.appendChild(el);
            });
            if (selectedWrap) selectedWrap.hidden = state.pills.length === 0;
            input.placeholder = 'Somewhere to stay';
        }

        function syncFields() {
            valuesEl.replaceChildren();
            regionPills().forEach(function (pill) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'city[]';
                hidden.value = pill.label;
                valuesEl.appendChild(hidden);
            });
            propertyPills().forEach(function (pill) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'property_id[]';
                hidden.value = pill.id;
                valuesEl.appendChild(hidden);
            });
            var query = document.createElement('input');
            query.type = 'hidden';
            query.name = 'q';
            query.value = state.query;
            if (!state.query) query.disabled = true;
            valuesEl.appendChild(query);
        }

        function refreshSelection() {
            markOptions();
            renderPills();
            syncFields();
            updateClear();
        }

        function updateClear() {
            var hasValue = state.pills.length > 0 || !!state.query || (open && input.value.trim() !== '');
            clearBtn.hidden = !hasValue;
        }

        function visibleOptions() {
            return regionOptions.concat(propertyOptions).filter(function (opt) {
                return !opt.hidden;
            });
        }

        function clearActive() {
            if (active) active.classList.remove('is-active');
            active = null;
            input.removeAttribute('aria-activedescendant');
        }

        function ensureVisible(opt) {
            var listRect = list.getBoundingClientRect();
            var optRect = opt.getBoundingClientRect();
            if (optRect.top < listRect.top) {
                list.scrollTop -= listRect.top - optRect.top;
            } else if (optRect.bottom > listRect.bottom) {
                list.scrollTop += optRect.bottom - listRect.bottom;
            }
        }

        function setActive(opt) {
            if (active) active.classList.remove('is-active');
            active = opt || null;
            if (!active) {
                input.removeAttribute('aria-activedescendant');
                return;
            }
            active.classList.add('is-active');
            input.setAttribute('aria-activedescendant', active.id);
            ensureVisible(active);
        }

        function filter(raw) {
            var query = norm(raw);
            var regionCount = 0;
            regionOptions.forEach(function (opt) {
                var match = !isChosen(opt) && (!query || norm(opt.dataset.label).indexOf(query) !== -1);
                opt.hidden = !match;
                if (match) regionCount++;
            });

            var ranked = propertyOptions.map(function (opt, index) {
                if (!query || isChosen(opt)) return { opt: opt, score: 99, index: index };
                var label = norm(opt.dataset.label);
                var city = norm(opt.dataset.city);
                var score = 99;
                if (label.indexOf(query) === 0) score = 0;
                else if (label.indexOf(query) !== -1) score = 1;
                else if (city.indexOf(query) !== -1) score = 2;
                return { opt: opt, score: score, index: index };
            }).filter(function (item) {
                return item.score < 99;
            });

            ranked.sort(function (a, b) {
                return a.score - b.score || a.index - b.index;
            });

            propertyOptions.forEach(function (opt) { opt.hidden = true; });
            var shown = ranked.slice(0, MAX_PROPERTIES);
            shown.forEach(function (item) {
                item.opt.hidden = false;
                list.insertBefore(item.opt, more);
            });

            if (regionHeading) regionHeading.hidden = regionCount === 0;
            if (propertyHeading) propertyHeading.hidden = shown.length === 0;
            if (more) {
                var typed = (raw || '').trim();
                more.hidden = ranked.length <= MAX_PROPERTIES;
                if (!more.hidden && moreLink) {
                    var params = new URLSearchParams();
                    if (typed) params.set('q', typed);
                    regionPills().forEach(function (pill) {
                        params.append('city[]', pill.label);
                    });
                    var qs = params.toString();
                    moreLink.href = qs ? archiveUrl + (archiveUrl.indexOf('?') === -1 ? '?' : '&') + qs : archiveUrl;
                }
            }
            if (empty) {
                empty.hidden = !(query.length > 0 && regionCount === 0 && shown.length === 0);
            }
            if (active && active.hidden) clearActive();
        }

        function focusInput() {
            try {
                input.focus({ preventScroll: true });
            } catch (err) {
                input.focus();
            }
        }

        function openPanel() {
            if (open) return;
            open = true;
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            if (summary() && input.value === summary()) {
                input.value = '';
            }
            filter(input.value);
            updateClear();
        }

        function closePanel() {
            if (!open) return;
            open = false;
            panel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            clearActive();
            input.value = summary() || state.query || '';
            updateClear();
        }

        function removePill(pill) {
            state.pills = state.pills.filter(function (item) { return item !== pill; });
            refreshSelection();
            if (open) filter(input.value);
            keepOpen = true;
            focusInput();
        }

        function toggleRegion(opt) {
            var label = opt.dataset.label;
            var existing = state.pills.find(function (pill) {
                return pill.type === 'region' && pill.label === label;
            });
            if (existing) {
                state.pills = state.pills.filter(function (pill) { return pill !== existing; });
            } else {
                state.pills.push({ type: 'region', label: label });
            }
            refreshSelection();
            keepOpen = true;
            focusInput();
        }

        function toggleProperty(opt) {
            var id = opt.dataset.id;
            var existing = state.pills.find(function (pill) {
                return pill.type === 'property' && pill.id === id;
            });
            if (existing) {
                state.pills = state.pills.filter(function (pill) { return pill !== existing; });
            } else {
                state.pills.push({
                    type: 'property',
                    id: id,
                    title: opt.dataset.label,
                    url: opt.dataset.url
                });
            }
            refreshSelection();
            keepOpen = true;
            focusInput();
        }

        function selectOption(opt) {
            if (!opt || opt.hidden) return;
            if (opt.dataset.type === 'property') toggleProperty(opt);
            else toggleRegion(opt);
        }

        function clearSelection() {
            state.pills = [];
            state.query = '';
            applyDraft = false;
            draft = '';
            input.value = '';
            refreshSelection();
            if (open) filter('');
            else openPanel();
            focusInput();
        }

        function captureDraft() {
            if (!open) {
                applyDraft = false;
                return;
            }
            draft = input.value.trim();
            applyDraft = true;
        }

        valuesEl.querySelectorAll('input[name="city[]"]').forEach(function (field) {
            if (field.value) state.pills.push({ type: 'region', label: field.value });
        });
        valuesEl.querySelectorAll('input[name="property_id"], input[name="property_id[]"]').forEach(function (field) {
            propertyOptions.forEach(function (opt) {
                if (opt.dataset.id === field.value) {
                    state.pills.push({
                        type: 'property',
                        id: opt.dataset.id,
                        title: opt.dataset.label,
                        url: opt.dataset.url
                    });
                }
            });
        });
        var initialQuery = valuesEl.querySelector('input[name="q"]');
        if (initialQuery && initialQuery.value) {
            state.query = initialQuery.value;
        }
        refreshSelection();
        if (!open) input.value = summary() || state.query || '';

        panel.addEventListener('mousedown', function (e) {
            if (e.target.closest('a')) return;
            e.preventDefault();
        });

        input.addEventListener('focus', openPanel);
        input.addEventListener('input', function () {
            if (!open) openPanel();
            filter(input.value);
            clearActive();
            updateClear();
        });
        input.addEventListener('blur', function () {
            window.clearTimeout(closeTimer);
            closeTimer = window.setTimeout(function () {
                if (keepOpen) {
                    keepOpen = false;
                    return;
                }
                closePanel();
            }, 150);
        });
        input.addEventListener('keydown', function (e) {
            var options = visibleOptions();
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!open) openPanel();
                options = visibleOptions();
                if (!options.length) return;
                var index = options.indexOf(active);
                if (e.key === 'ArrowDown') {
                    index = index < options.length - 1 ? index + 1 : 0;
                } else {
                    index = index > 0 ? index - 1 : options.length - 1;
                }
                setActive(options[index]);
                return;
            }
            if (e.key === 'Enter') {
                if (open && active) {
                    e.preventDefault();
                    selectOption(active);
                    return;
                }
                captureDraft();
                return;
            }
            if ((e.key === 'Escape' || e.key === 'Esc') && open) {
                e.preventDefault();
                e.stopPropagation();
                keepOpen = false;
                input.blur();
                return;
            }
            if (e.key === 'Backspace' && input.value === '' && state.pills.length) {
                e.preventDefault();
                state.pills.pop();
                refreshSelection();
                if (open) filter('');
            }
        });

        list.addEventListener('click', function (e) {
            var opt = e.target.closest('.guesty-where-option');
            if (!opt || opt.hidden || !list.contains(opt)) return;
            selectOption(opt);
        });
        list.addEventListener('mousemove', function (e) {
            var opt = e.target.closest('.guesty-where-option');
            if (!opt || opt.hidden || !list.contains(opt)) return;
            if (opt !== active) setActive(opt);
        });

        clearBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            clearSelection();
        });

        control.addEventListener('mousedown', function (e) {
            if (e.target.closest('.guesty-where-pill-remove') || e.target.closest('.guesty-where-clear') || e.target === input) {
                return;
            }
            e.preventDefault();
            focusInput();
        });

        if (form) {
            var submitBtn = form.querySelector('.guesty-action button');
            if (submitBtn) {
                submitBtn.addEventListener('mousedown', captureDraft);
            }
            form.addEventListener('submit', function (e) {
                if (applyDraft) {
                    state.query = draft;
                    applyDraft = false;
                }
                syncFields();
                var properties = propertyPills();
                if (properties.length === 1 && !regionPills().length && !state.query && properties[0].url) {
                    e.preventDefault();
                    var url = new URL(properties[0].url, window.location.href);
                    var checkIn = form.querySelector('[name="checkIn"]');
                    var checkOut = form.querySelector('[name="checkOut"]');
                    var guests = form.querySelector('[name="minOccupancy"]');
                    if (checkIn && checkIn.value) url.searchParams.set('checkIn', checkIn.value);
                    if (checkOut && checkOut.value) url.searchParams.set('checkOut', checkOut.value);
                    if (guests && guests.value) url.searchParams.set('minOccupancy', guests.value);
                    window.location.assign(url.toString());
                }
            });
        }
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