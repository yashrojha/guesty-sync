/**
* JavaScript (Modern Clipboard API)
*/
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.guesty-copy-shortcode').forEach(code => {
        code.addEventListener('click', () => {
            const text = code.dataset.shortcode;
            navigator.clipboard.writeText(text).then(() => {
                const msg = code.nextElementSibling;
                msg.textContent = 'Copied!';
                setTimeout(() => msg.textContent = '', 1500);
            });
        });
    });
});

/**
* Guesty API Testing Code
*/
jQuery(document).ready(function ($) {
    $('#guesty-test-connection').on('click', function () {
        $('#guesty-test-result').text('Testing...');
        $.post(ajaxurl, {
            action: 'guesty_test_connection'
        }, function (response) {
            if (response.success) {
                $('#guesty-test-result').text('✅ ' + response.data);
				setTimeout(function() {
                    window.location.href = window.location.href; 
                }, 1000);
            } else {
                $('#guesty-test-result').text('❌ ' + response.data);
            }
        });
    });
});

/**
* Guesty Be API Testing Code
*/
jQuery(document).ready(function ($) {
    $('#guesty-be-test-connection').on('click', function () {
        $('#guesty-be-test-result').text('Testing...');
        $.post(ajaxurl, {
            action: 'guesty_be_test_connection'
        }, function (response) {
            if (response.success) {
                $('#guesty-be-test-result').text('✅ ' + response.data);
				setTimeout(function() {
                    window.location.href = window.location.href; 
                }, 1000);
            } else {
                $('#guesty-be-test-result').text('❌ ' + response.data);
            }
        });
    });
});

/**
* Trending Region auto save on change
*/
jQuery(function ($) {

  $("#all-regions, #trending-regions").sortable({
    connectWith: '.item-sortable-list',
    placeholder: 'ui-state-highlight',
    update: function () {
		let selected = [];

		$('#trending-regions li').each(function () {
		  selected.push($(this).data('value'));
		});

		$.post(ajaxurl, {
		  action: 'guesty_save_trending_regions',
		  regions: selected
		});
    }
  });
});
jQuery(function ($) {
  $('.region-list').sortable({
    connectWith: '.region-list',
    placeholder: 'region-placeholder',
    update: saveTrendingRegions
  });
  function saveTrendingRegions() {

    
  }
});

/**
 * Featured Properties auto save on change (MAX 6)
 */
jQuery(function ($) {

  const MAX_ITEMS = 6;

  $("#all-properties, #featured-properties").sortable({
    connectWith: '.item-sortable-list',
    placeholder: 'ui-state-highlight',
    receive: function (e, ui) {
      // Only validate when item is dropped INTO featured list
      if ($(this).attr('id') === 'featured-properties') {
        const count = $('#featured-properties li').length;

        if (count > MAX_ITEMS) {
          alert('You can select a maximum of 6 featured properties.');
          $(ui.sender).sortable('cancel');
          return;
        }
      }
    },
    update: function () {
		let ids = [];

		$('#featured-properties li').each(function () {
		  ids.push($(this).data('id'));
		});

		$.post(ajaxurl, {
		  action: 'guesty_save_featured_properties',
		  ids: ids
		});
    }
  });
});

/**
 * Top Amenities order set on change (MAX 6)
 */
jQuery(function ($) {

  const MAX_ITEMS = 6;

  $("#available-amenities, #selected-amenities").sortable({
    connectWith: '.item-sortable-list',
    placeholder: 'ui-state-highlight',
    receive: function (e, ui) {
      // Only validate when item is dropped INTO featured list
      if ($(this).attr('id') === 'selected-amenities') {
        const count = $('#selected-amenities li').length;

        if (count > MAX_ITEMS) {
          alert('You can select a maximum of 6 top Amenities.');
          $(ui.sender).sortable('cancel');
          return;
        }
      }
    },
	update: function(event, ui) {
		var ordered = [];
		$("#selected-amenities li").each(function() {
			ordered.push($(this).data('id'));
		});
		$("#top_amenities_ordered").val(ordered.join(','));
	}
  }).disableSelection();
});


/**
 * Guesty Property JS – Progress Controller (ALL + SINGLE)
 */
// Start the first check when the page loads
let statusInterval = null;
jQuery(document).ready(function ($) {
    monitorSyncStatus();
	/**
     * 🔄 SYNC ALL
     */
	$('#guesty-sync-all').on('click', function () {
		const $btn = $(this);

		$.post(guestySync.ajax, {
			action: 'guesty_trigger_manual_sync',
			nonce: guestySync.nonce
		}).done(function (res) {
			if (!res.success) {
				alert(res.data?.message || 'Sync already running');
				return;
			}
			// 1. Give immediate visual feedback
			$btn.prop('disabled', true);
			$('.guesty-sync-single').prop('disabled', true);
			$('#guesty-progress-status').text('Waiting for background process to start...');

			// Start the monitor loop (this handles page refresh automatically)
			if (!statusInterval) {
				statusInterval = setInterval(monitorSyncStatus, 1000);
			}
		});
	});
	/**
     * 🏠 SINGLE PROPERTY SYNC
     */
	$('.guesty-sync-single').on('click', function (e) {
		e.preventDefault();
		const $btn = $(this);
		const pid = $btn.data('id'); // Ensure your button has data-id="123"

		$.post(guestySync.ajax, {
			action: 'guesty_trigger_single_sync',
			nonce: guestySync.nonce,
			pid: pid
		}).done(function (res) {
			if (!res.success) {
				alert(res.data?.message || 'Sync already running');
				return;
			}
			if (res.success) {
				// Disable UI
				$('.guesty-sync-single, #guesty-sync-all').prop('disabled', true);
				
				// Show the progress bar immediately
				$('#guesty-progress-wrapper').fadeIn();
				$('#guesty-progress-status').text('Initializing property sync...');

				// Start the monitor loop (this handles page refresh automatically)
				if (!statusInterval) {
					statusInterval = setInterval(monitorSyncStatus, 1000);
				}
			} else {
				alert(res.data.message);
			}
		});
	});
	function renderProgress(data) {
		if (!data) return;
		$('#guesty-progress-wrapper').show();
		let percent = data.image_total
			? Math.round((data.image_current / data.image_total) * 100)
			: 0;

		$('#cssProgress-bar').css('width', percent + '%');
		$('#cssProgress-bar').addClass('cssProgress-active');
		$('#cssProgress-label').text(percent + '%');

		let message = data.mode === 'all'
			? `🔄 Syncing all properties — Property ${data.property_current || '-'} of ${data.property_total || '-'} — ${data.status} (${data.image_current}/${data.image_total})`
			: `🏠 Syncing property ${data.property || '-'} — ${data.status} (${data.image_current}/${data.image_total})`;

		$('#guesty-progress-status').text(message);
	}
	function monitorSyncStatus() {
		$.post(guestySync.ajax, {
			action: 'guesty_get_sync_status',
			nonce: guestySync.nonce
		}).done(function (res) {
			if (res.success && res.data) {
				const data = res.data;
				if (data.running) {
					renderProgress(data);
					// If we aren't already polling, start now
					if (!statusInterval) {
						statusInterval = setInterval(monitorSyncStatus, 1000);
					}
					$('.guesty-sync-single, #guesty-sync-all').prop('disabled', true);
				} else {
					// If it stopped running, clear the interval to save resources
					if (data.status === 'Completed') {
						if (!statusInterval) return;
						$('#guesty-progress-status').text('✅ All Properties Sync Completed.');
						setTimeout(() => $('#guesty-progress-wrapper').fadeOut(), 2000);
					} else if (data.status === 'Completed-Single') {
						if (!statusInterval) return;
						$('#guesty-progress-status').text('✅ Single Property Sync Completed.');
						setTimeout(() => $('#guesty-progress-wrapper').fadeOut(), 2000);
					} else {
						$('#guesty-progress-wrapper').fadeOut();
					}
					$('.guesty-sync-single, #guesty-sync-all').prop('disabled', false);
					clearInterval(statusInterval);
					statusInterval = null;
				}
			}
		});
	}
});

/**
 * Bedroom Manager – add / edit / delete bedrooms and bed types, pick room photos
 */
jQuery(document).ready(function ($) {

    if (!$('#bedroom-cards-container').length) return;

    // Running counter so new cards always get a unique index key
    let bedroomCounter = $('#bedroom-cards-container .bedroom-card').length;

    // ── helpers ──────────────────────────────────────────────────────────────

    function updateBedroomCount() {
        $('#bedroom-count-display').text($('#bedroom-cards-container .bedroom-card').length);
    }

    // Room types that support a beds section
    const BED_ROOM_TYPES = ['BEDROOM', 'LIVING_ROOM', ''];

    function applyRoomTypeVisibility( $card ) {
        const type = $card.find('.bedroom-type-select').val() || 'BEDROOM';
        $card.attr('data-room-type', type);
        if ( BED_ROOM_TYPES.indexOf(type) !== -1 ) {
            $card.find('.bedroom-beds-section').slideDown(150);
        } else {
            $card.find('.bedroom-beds-section').slideUp(150);
        }
    }

    /**
     * Clone the PHP-rendered card template, swap __INDEX__ for a real number,
     * and give the new bedroom a default name.
     */
    function createBedroomCard() {
        const idx      = bedroomCounter++;
        const label    = 'Room ' + ($('#bedroom-cards-container .bedroom-card').length + 1);
        let   template = $('#bedroom-card-template').html();

        // Replace every occurrence of __INDEX__ with the real index
        template = template.replace(/__INDEX__/g, idx);

        const $card = $(template);
        $card.find('.bedroom-name-input').val(label);
        $card.find('.bedroom-type-select').val('BEDROOM'); // default to Bedroom type
        applyRoomTypeVisibility($card);
        return $card;
    }

    // ── apply room-type visibility on load (for existing cards) ──────────────

    $('#bedroom-cards-container .bedroom-card').each(function () {
        applyRoomTypeVisibility( $(this) );
    });

    // ── room type change: show/hide beds section ──────────────────────────────

    $(document).on('change', '.bedroom-type-select', function () {
        applyRoomTypeVisibility( $(this).closest('.bedroom-card') );
    });

    // ── add bedroom ───────────────────────────────────────────────────────────

    $('#add-bedroom-btn').on('click', function () {
        const $card = createBedroomCard();
        $('#bedroom-cards-container').append($card);
        updateBedroomCount();

        // Scroll new card into view
        $card[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    // ── delete bedroom ────────────────────────────────────────────────────────

    $(document).on('click', '.bedroom-delete-btn', function () {
        if (!confirm('Remove this bedroom? This cannot be undone until you save the page.')) return;
        $(this).closest('.bedroom-card').remove();
        updateBedroomCount();
    });

    // ── add bed type row inside a bedroom ─────────────────────────────────────

    $(document).on('click', '.add-bed-btn', function () {
        const $card       = $(this).closest('.bedroom-card');
        const bedroomIdx  = $card.data('index');
        const $bedsList   = $card.find('.beds-list');
        const bedIdx      = $bedsList.find('.bed-row').length;

        // Grab one existing <select> to clone its options (preserves PHP-rendered option list)
        const $optionsClone = $bedsList.find('.bed-type-select').first().clone();
        $optionsClone.val('KING_BED');
        $optionsClone.attr('name', 'custom_bedrooms[' + bedroomIdx + '][beds][' + bedIdx + '][type]');
        $optionsClone.addClass('bed-type-select');

        const $row = $('<div class="bed-row"></div>').attr('data-bed-index', bedIdx);
        $row.append(
            $('<input type="number" min="1" max="10" class="small-text bed-qty-input">')
                .attr('name', 'custom_bedrooms[' + bedroomIdx + '][beds][' + bedIdx + '][quantity]')
                .val(1)
        );
        $row.append($optionsClone);
        $row.append('<button type="button" class="button-link bed-remove-btn" title="Remove bed type">&times;</button>');

        $bedsList.append($row);
    });

    // ── remove bed type row ───────────────────────────────────────────────────

    $(document).on('click', '.bed-remove-btn', function () {
        const $list = $(this).closest('.beds-list');
        if ($list.find('.bed-row').length <= 1) {
            alert('Each bedroom must have at least one bed type.');
            return;
        }
        $(this).closest('.bed-row').remove();
    });

    // ── image picker ──────────────────────────────────────────────────────────

    $(document).on('click', '.choose-custom-bedroom-image', function (e) {
        e.preventDefault();
        const $btn  = $(this);
        const $card = $btn.closest('.bedroom-card');

        const frame = wp.media({
            title   : 'Select Bedroom Photo',
            button  : { text: 'Use this photo' },
            multiple: false,
            library : {
                type      : 'image',
                uploadedTo: wp.media.view.settings.post.id,
            },
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            const thumb = (attachment.sizes && attachment.sizes.thumbnail)
                ? attachment.sizes.thumbnail.url
                : attachment.url;

            $card.find('.bedroom-image-preview').html('<img src="' + thumb + '">');
            $card.find('.bedroom-image-id').val(attachment.id);
            $card.find('.remove-custom-bedroom-image').show();
            $btn.text('Change Photo');
        });

        frame.open();
    });

    $(document).on('click', '.remove-custom-bedroom-image', function (e) {
        e.preventDefault();
        const $card = $(this).closest('.bedroom-card');
        $card.find('.bedroom-image-preview').empty();
        $card.find('.bedroom-image-id').val('');
        $(this).hide();
        $card.find('.choose-custom-bedroom-image').text('Choose Photo');
    });

    // ── drag-to-reorder (jQuery UI Sortable, already loaded by WP) ────────────

    $('#bedroom-cards-container').sortable({
        handle     : '.bedroom-drag-handle',
        placeholder: 'bedroom-sort-placeholder',
        forcePlaceholderSize: true,
        axis       : 'y',
    });

    // ── reset bedrooms from Guesty ────────────────────────────────────────────

    $(document).on('click', '.bedroom-reset-btn', function () {
        if (!confirm('This will clear all custom bedroom data. On the next Guesty sync, bedrooms will be re-seeded from Guesty. Continue?')) return;

        const $btn   = $(this);
        const postId = $btn.data('post-id');

        $btn.prop('disabled', true).text('Resetting…');

        $.post(guestySync.ajax, {
            action : 'guesty_reset_custom_bedrooms',
            nonce  : guestySync.nonce,
            post_id: postId,
        }).done(function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert(res.data || 'Could not reset bedrooms.');
                $btn.prop('disabled', false).text('Reset from Guesty');
            }
        });
    });

});

/**
 * Property Icon Choose code
 */
jQuery(document).ready(function ($) {
	let frame;

	$('#guesty-icon-upload').on('click', function (e) {
		e.preventDefault();

		if (frame) { frame.open(); return; }

		frame = wp.media({
			title: 'Select Property Icon',
			button: { text: 'Use Icon' },
			multiple: false
		});

		frame.on('select', function () {
			const attachment = frame.state().get('selection').first().toJSON();
			
			// Fallback to full URL if thumbnail size isn't generated
			const imgUrl = (attachment.sizes && attachment.sizes.thumbnail) 
				? attachment.sizes.thumbnail.url 
				: attachment.url;

			$('#guesty_property_icon_id').val(attachment.id);
			$('#guesty-icon-preview').html('<img src=\"' + imgUrl + '\" style=\"max-width:100%;\" />');
			$('#guesty-icon-remove').show();
			$('#guesty-icon-upload').text('Change Icon');
		});

		frame.open();
	});

	$('#guesty-icon-remove').on('click', function (e) {
		e.preventDefault();
		$('#guesty_property_icon_id').val('');
		$('#guesty-icon-preview').html('<span style=\"color:#999;\">No Icon Selected</span>');
		$(this).hide();
		$('#guesty-icon-upload').text('Set Property Icon');
	});
});

/**
 * Property Floor Plan (PDF) Choose code
 */
jQuery(document).ready(function ($) {
	let pdfFrame;

	$('#guesty-pdf-upload').on('click', function (e) {
		e.preventDefault();

		if (pdfFrame) { pdfFrame.open(); return; }

		pdfFrame = wp.media({
			title: 'Select Property Floor Plan (PDF)',
			button: { text: 'Use this PDF' },
			library: { type: 'application/pdf' }, // Forces PDF selection
			multiple: false
		});

		pdfFrame.on('select', function () {
			const attachment = pdfFrame.state().get('selection').first().toJSON();
			$('#guesty_floor_plan_id').val(attachment.id);
			$('#pdf-filename').text(attachment.filename);
			$('#guesty-pdf-remove').show();
			$('#guesty-pdf-upload').text('Change PDF');
		});

		pdfFrame.open();
	});

	$('#guesty-pdf-remove').on('click', function (e) {
		e.preventDefault();
		$('#guesty_floor_plan_id').val('');
		$('#pdf-filename').text('No file selected');
		$(this).hide();
		$('#guesty-pdf-upload').text('Upload PDF Plan');
	});
});