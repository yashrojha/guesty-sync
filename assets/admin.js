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
						$('#guesty-progress-status').text('✅ All Property Sync Completed.');
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
 * Listing Rooms Images Choose code
 */
jQuery(document).ready(function($) {
    // 1. CHOOSE / CHANGE IMAGE
    $(document).on('click', '.choose-bedroom-image', function(e) {
        e.preventDefault();
        var button = $(this);
        var container = button.closest('.room-card');

        var frame = wp.media({
            title: 'Select Bedroom Image',
            button: { text: 'Use this image' },
            multiple: false,
            library: {
                type: 'image',
                uploadedTo: wp.media.view.settings.post.id // Only images from this post
            }
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            var thumb = attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
            
            container.find('.image-preview').html('<img src="' + thumb + '" />');
            container.find('.room-image-id').val(attachment.id);
            container.find('.remove-bedroom-image').show(); // Show remove button
            button.text('Change Image');
        });

        frame.open();
    });

    // 2. REMOVE IMAGE
    $(document).on('click', '.remove-bedroom-image', function(e) {
        e.preventDefault();
        var button = $(this);
        var container = button.closest('.room-card');

        // Clear the preview and the hidden input value
        container.find('.image-preview').empty();
        container.find('.room-image-id').val('');
        
        // Hide remove button and reset text
        button.hide();
        container.find('.choose-bedroom-image').text('Choose Bedroom Image');
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