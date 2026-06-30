define([
    'uiComponent',
    'ko',
    'jquery',
    'mage/url',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/shipping-rate-registry',
    'Magento_Checkout/js/model/shipping-rate-processor/new-address'
], function (Component, ko, $, urlBuilder, quote, rateRegistry, rateProcessor) {
    'use strict';

    /** Debounce: returns a function that delays `fn` by `delay` ms after the last call. */
    function debounce(fn, delay) {
        var timer;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(null, args); }, delay);
        };
    }

    /**
     * Dynamically load Google Maps JS API with Places + Marker libraries.
     * Uses loading=async for optimal performance.
     */
    function loadGoogleMaps(apiKey) {
        return new Promise(function (resolve, reject) {
            if (window.google && window.google.maps) {
                resolve(window.google.maps);
                return;
            }

            var callbackName = '_gmapsLoaded_' + Date.now();
            window[callbackName] = function () {
                delete window[callbackName];
                resolve(window.google.maps);
            };

            var script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js'
                + '?key=' + encodeURIComponent(apiKey)
                + '&libraries=places,marker'
                + '&loading=async'
                + '&callback=' + callbackName;
            script.async = true;
            script.defer = true;
            script.onerror = function () {
                reject(new Error('Failed to load Google Maps API. Check your API key in admin.'));
            };
            document.head.appendChild(script);
        });
    }

    /**
     * Normalize a LatLng-like object to a plain {lat, lng} literal.
     * Works for both google.maps.LatLng and LatLngLiteral.
     */
    function toLatLngLiteral(pos) {
        if (!pos) return null;
        return {
            lat: typeof pos.lat === 'function' ? pos.lat() : pos.lat,
            lng: typeof pos.lng === 'function' ? pos.lng() : pos.lng
        };
    }

    return Component.extend({
        defaults: {
            template: 'Perspective_ShippingDistance/map',

            // Injected from jsLayout via layout XML helpers
            googleApiKey: '',
            googleMapId: '',
            storeLat: 47.888,
            storeLng: 33.393
        },

        /** @type {google.maps.Map|null} */
        mapInstance: null,

        /** @type {google.maps.marker.AdvancedMarkerElement|null} */
        markerInstance: null,

        /** Cached google.maps namespace — avoids undefined-closure bugs */
        _maps: null,

        /** Debounced version of calculateDistance (set in initialize) */
        _calculateDistanceDebounced: null,

        errorMessage: ko.observable(''),
        selectedAddress: ko.observable(''),
        isLoading: ko.observable(false),

        initialize: function () {
            this._super();
            // Debounce AJAX requests: wait 600ms after last marker move before calling backend
            this._calculateDistanceDebounced = debounce(this.calculateDistance.bind(this), 600);
            return this;
        },

        /**
         * Entry point called by data-bind="afterRender: initMap" on the map div.
         *
         * @param {HTMLElement} element
         */
        initMap: function (element) {
            var self = this;
            var key = self.googleApiKey;

            if (!key) {
                self.errorMessage(
                    'Google Maps API key is not configured. ' +
                    'Please set it in Stores → Configuration → Perspective → Shipping Distance.'
                );
                return;
            }

            // Warn developer if key format looks wrong (valid keys start with AIzaSy)
            if (key.length !== 39 || key.indexOf('AIzaSy') !== 0) {
                console.warn(
                    '[ShippingDistance] Google Maps API key looks invalid.\n' +
                    'Expected format: AIzaSy... (39 chars)\n' +
                    'Got: "' + key.substring(0, 8) + '..." (' + key.length + ' chars)\n' +
                    'Get a valid key at https://console.cloud.google.com'
                );
            }

            loadGoogleMaps(key).then(function (maps) {
                self._maps = maps;

                var lat = parseFloat(self.storeLat) || 50.4501;
                var lng = parseFloat(self.storeLng) || 30.5234;
                var center = { lat: lat, lng: lng };

                var mapOptions = {
                    center: center,
                    zoom: 12,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false,
                    gestureHandling: 'cooperative'
                };

                if (self.googleMapId) {
                    mapOptions.mapId = self.googleMapId;
                }

                self.mapInstance = new maps.Map(element, mapOptions);

                self._createMarker(maps, center);
                self._initAutocomplete(maps);

                // Click on map → move pin (debounced)
                self.mapInstance.addListener('click', function (event) {
                    var pos = toLatLngLiteral(event.latLng);
                    self._setMarkerPosition(pos);
                    self.reverseGeocode(pos.lat, pos.lng);
                    self._calculateDistanceDebounced(pos.lat, pos.lng);
                });

                // Geolocation
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(
                        function (position) {
                            var userPos = {
                                lat: position.coords.latitude,
                                lng: position.coords.longitude
                            };
                            self.mapInstance.setCenter(userPos);
                            self._setMarkerPosition(userPos);
                            self.reverseGeocode(userPos.lat, userPos.lng);
                            self._calculateDistanceDebounced(userPos.lat, userPos.lng);
                        },
                        null,
                        { timeout: 5000 }
                    );
                }
            }).catch(function (err) {
                self.errorMessage('Could not load Google Maps: ' + err.message);
            });
        },

        /**
         * Create a draggable marker.
         * Uses AdvancedMarkerElement when mapId is configured; falls back to classic Marker.
         *
         * @param {google.maps} maps
         * @param {{lat: number, lng: number}} center
         */
        _createMarker: function (maps, center) {
            var self = this;
            var useAdvanced = !!(
                self.googleMapId &&
                maps.marker &&
                maps.marker.AdvancedMarkerElement
            );

            if (useAdvanced) {
                self.markerInstance = new maps.marker.AdvancedMarkerElement({
                    position: center,
                    map: self.mapInstance,
                    gmpDraggable: true,
                    title: 'Drag to your delivery location'
                });

                self.markerInstance.addListener('dragend', function () {
                    var pos = toLatLngLiteral(self.markerInstance.position);
                    self.reverseGeocode(pos.lat, pos.lng);
                    self._calculateDistanceDebounced(pos.lat, pos.lng);
                });
            } else {
                // Classic Marker — still supported
                self.markerInstance = new maps.Marker({
                    position: center,
                    map: self.mapInstance,
                    draggable: true,
                    title: 'Drag to your delivery location',
                    animation: maps.Animation.DROP
                });

                self.markerInstance.addListener('dragend', function () {
                    var pos = toLatLngLiteral(self.markerInstance.getPosition());
                    self.reverseGeocode(pos.lat, pos.lng);
                    self._calculateDistanceDebounced(pos.lat, pos.lng);
                });
            }
        },

        /**
         * Move marker to a new position (works for both Marker types).
         *
         * @param {{lat: number, lng: number}} pos
         */
        _setMarkerPosition: function (pos) {
            if (!this.markerInstance) return;
            if (typeof this.markerInstance.setPosition === 'function') {
                this.markerInstance.setPosition(pos);   // classic Marker
            } else {
                this.markerInstance.position = pos;      // AdvancedMarkerElement
            }
        },

        /**
         * Initialise address autocomplete.
         * Prefers PlaceAutocompleteElement (new API); falls back to classic Autocomplete.
         *
         * @param {google.maps} maps
         */
        _initAutocomplete: function (maps) {
            var self = this;
            var container = document.getElementById('shipping-distance-autocomplete-container');
            if (!container) return;

            if (maps.places && maps.places.PlaceAutocompleteElement) {
                // ─── New API: PlaceAutocompleteElement (Web Component) ───────────────
                var placePicker = new maps.places.PlaceAutocompleteElement();
                placePicker.id = 'shipping-distance-place-picker';
                container.appendChild(placePicker);

                placePicker.addEventListener('gmp-select', function (event) {
                    var prediction = event.placePrediction || (event.detail && event.detail.placePrediction);
                    if (!prediction) return;

                    var place = prediction.toPlace();
                    place.fetchFields({ fields: ['displayName', 'formattedAddress', 'location'] })
                        .then(function () {
                            if (!place.location) {
                                self.errorMessage('No location details for the selected address.');
                                return;
                            }
                            var pos = toLatLngLiteral(place.location);
                            self.mapInstance.setCenter(pos);
                            self.mapInstance.setZoom(15);
                            self._setMarkerPosition(pos);
                            self.selectedAddress(place.formattedAddress || '');
                            // Use debounced version for autocomplete selection too
                            self._calculateDistanceDebounced(pos.lat, pos.lng);
                        })
                        .catch(function () {
                            self.errorMessage('Could not fetch location details.');
                        });
                });

            } else if (maps.places && maps.places.Autocomplete) {
                // ─── Legacy API: classic Autocomplete (for existing API keys) ──────
                var input = document.createElement('input');
                input.type = 'text';
                input.id = 'shipping-distance-autocomplete';
                input.className = 'shipping-distance-autocomplete-input';
                input.placeholder = 'Search address or drag the pin on the map';
                container.appendChild(input);

                var autocomplete = new maps.places.Autocomplete(input, {
                    fields: ['geometry', 'formatted_address']
                });

                autocomplete.addListener('place_changed', function () {
                    var place = autocomplete.getPlace();
                    if (!place.geometry || !place.geometry.location) {
                        self.errorMessage('No location details. Please select from the dropdown.');
                        return;
                    }
                    var pos = toLatLngLiteral(place.geometry.location);
                    self.mapInstance.setCenter(pos);
                    self.mapInstance.setZoom(15);
                    self._setMarkerPosition(pos);
                    self.selectedAddress(place.formatted_address || '');
                    self._calculateDistanceDebounced(pos.lat, pos.lng);
                });
            }
        },

        /**
         * Reverse-geocode lat/lng → human-readable address → selectedAddress observable.
         * Uses self._maps to avoid undefined-closure bug.
         *
         * @param {number} lat
         * @param {number} lng
         */
        reverseGeocode: function (lat, lng) {
            var self = this;
            var maps = self._maps || (window.google && window.google.maps);
            if (!maps) return;

            var geocoder = new maps.Geocoder();
            geocoder.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
                if (status === 'OK' && results && results[0]) {
                    self.selectedAddress(results[0].formatted_address);
                } else {
                    self.selectedAddress('');
                }
            });
        },

        /**
         * POST lat/lng to backend → saves distance + price to quote → refreshes shipping rates.
         * NOTE: call via this._calculateDistanceDebounced() to avoid rapid-fire requests.
         *
         * @param {number} lat
         * @param {number} lng
         */
        calculateDistance: function (lat, lng) {
            var self = this;
            self.errorMessage('');
            self.isLoading(true);

            $.ajax({
                url: urlBuilder.build('shipping-distance/ajax/calculate'),
                type: 'POST',
                data: {
                    lat: lat,
                    lng: lng,
                    form_key: $.cookie('form_key')
                },
                dataType: 'json'
            }).done(function (response) {
                if (response.success) {
                    if (response.message) {
                        self.errorMessage(response.message);
                    }
                    var address = quote.shippingAddress();
                    if (address) {
                        rateRegistry.set(address.getCacheKey(), null);
                        rateProcessor.getRates(address);
                    }
                } else {
                    self.errorMessage(response.message || 'An error occurred.');
                }
            }).fail(function () {
                self.errorMessage('An error occurred during distance calculation. Please try again.');
            }).always(function () {
                self.isLoading(false);
            });
        }
    });
});
