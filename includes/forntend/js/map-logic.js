/**
 * Register the js [guesty_property_map]
 */
function initGuestyMap() {
	if (typeof guestyData === 'undefined') return;

	const mapElement = document.getElementById("guesty-custom-map");
	const centerPos = { lat: -28.0167, lng: 153.4000 };
	
	const bounds = new google.maps.LatLngBounds();
	
	const map = new google.maps.Map(mapElement, {
		zoom: 12,
		center: centerPos,
		mapId: "<?php echo get_option('google_map_id'); ?>",
		styles: [
			{ "stylers": [{ "saturation": -100 }] },
			{ "featureType": "water", "stylers": [{ "color": "#ffffff" }] }
		],
		disableDefaultUI: true,
		zoomControl: true
	});

	const infoWindow = new google.maps.InfoWindow();

	guestyData.locations.forEach(prop => {
		const position = { lat: prop.lat, lng: prop.lng };

		// 2. Extend the bounds to include this marker's position
		bounds.extend(position);
				
		// Create custom HTML for the marker
        const markerTag = document.createElement('div');
        markerTag.className = 'guesty-marker';
        markerTag.style.backgroundImage = `url(${prop.logo})`;
		
		// 2. Create the Advanced Marker
		const marker = new google.maps.marker.AdvancedMarkerElement({
			position: position,
			map: map,
			content: markerTag,
			title: prop.title
		});

		// Click to open "Picasso" Style Card
		marker.addListener("click", () => {
			// Build the Swiper HTML
			const slidesHtml = prop.images.map(url => `
				<div class="swiper-slide">
					<a href="${prop.link}">
						<div class="card-img" style="background-image: url('${url}');"></div>
					</a>
				</div>
			`).join('');
			
			const content = `
				<div class="guesty-map-card">
					<div class="swiper mapSwiper">
						<div class="swiper-wrapper">
							${slidesHtml}
						</div>
						<div class="swiper-button-next" title="Next slide"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M7.15703 6.175L10.9737 10L7.15703 13.825L8.33203 15L13.332 10L8.33203 5L7.15703 6.175Z" fill="black"/></svg></div>
						<div class="swiper-button-prev" title="Previous slide"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M12.843 6.175L9.0263 10L12.843 13.825L11.668 15L6.66797 10L11.668 5L12.843 6.175Z" fill="black"/></svg></div>
						<div class="swiper-pagination"></div>
					</div>
					<div class="card-content">
						<a href="${prop.link}"><h3>${prop.title}</h3></a>
						<p class="region">${prop.region}</p>
						<p class="specs">${prop.specs}</p>
					</div>
				</div>`;
			
			infoWindow.setContent(content);
			infoWindow.open(map, marker);
			
			// CRITICAL: Initialize Swiper ONLY when InfoWindow is ready
			google.maps.event.addListenerOnce(infoWindow, 'domready', () => {
				new Swiper(".mapSwiper", {
					loop: true,
					pagination: {
						el: ".swiper-pagination",
						clickable: true,
					},
					navigation: {
						nextEl: ".swiper-button-next",
						prevEl: ".swiper-button-prev",
					},
				});
			});
		});
	});
	
	// 3. Tell the map to fit all extended bounds
	map.fitBounds(bounds);
}
window.addEventListener('load', initGuestyMap);