var Storelocator = Storelocator || {};

$(function() {
	function sanitizeName(name) {
		return name.toLowerCase().replace(/ /g, '-');
	}
		
	Storelocator.Router = Backbone.Router.extend({
		routes: {
			'search/:query': 'search',
			':name': 'loadStore'
		},
		
		initialize: function(options) {
			_.extend(this, options);
		},
		
		search: function(query) {
			console.log(query);	
		},
		
		loadStore: function(name) {
			var store = this.stores.find(function(store) {
				return name == sanitizeName(store.get('name'));
			});
			
			store.set({selected:true});
			/*
				var address = store.get('address') + ' ' +
							  store.get('city') + ' ' +
							  store.get('state') + ' ' +
							  store.get('zip') + ' ' +
							  store.get('country');
							  
				this.storeListView.updateLocation(address);
				this.mapView.selectMarker(store);
			//*/
		}
	});
	
	Storelocator.Store = Backbone.Model.extend({
		defaults: {
			lat: 0,
			lng: 0,
			name: '',
			address: '',
			city: '',
			state: '',
			zip: '',
			country: '',
			hasLocation: '',
			distance: {},
			selected: false
		},
	
		getLatLng: function() {
			return {lat: this.get('lat'), lng: this.get('lng')};
		},
		
		getGoogleLatLng: function() {
			return new google.maps.LatLng(this.get('lat'), this.get('lng'));
		},
		
		setDistanceFrom: function(coords) {
			var self = this,
				dfr = $.Deferred(),
				origin = new google.maps.LatLng(coords.lat, coords.lng),
				directionsService = new google.maps.DirectionsService(),
				directionRequest = {
					origin: origin,
					destination: this.getGoogleLatLng(),
					travelMode: google.maps.TravelMode.DRIVING
				};
			
			directionsService.route(directionRequest, function(result, status) {
				self.set({distance: result.routes[0].legs[0].distance});
				dfr.resolve(self);
			});
			
			return dfr.promise();
		}
	});
		
	Storelocator.Stores = Backbone.Collection.extend({
		model: Storelocator.Store,
		
		comparator: function(store) {
			return store.get('distance').value;
		},
		
		initialize: function() {
			this.bind('change:selected', this.selectStore);
		},
		
		selectStore: function(store) {
			this.each(function(s) {
				if (s.get('id') != store.get('id')) {
					s.set({selected:false}, {silent:true});
				}
			});
		},
		
		sortByDistanceFrom: function(coords) {
			var self = this,
				distancesNeeded = this.length,
				dfr = $.Deferred();
			
			dfr.then(function(stores) {
				stores.sort();
			});
			
			this.each(function(store){
				$.when(store.setDistanceFrom(coords)).then(function(store) {
					if (--distancesNeeded == 0) {
						dfr.resolve(self);
					}
					
				});
			});
			
			return dfr.promise();
		}
	});
		
	Storelocator.StoreView = Backbone.View.extend({
		tagName: 'li',
		template: _.template($('#storeTpl').html()),
		
		events: {
			'click': 'selectStore'
		},
		
		render: function() {
			var tplVars = this.model.toJSON();
			$(this.el).html(this.template(tplVars));
			
			return this;
		},
		
		selectStore: function() {
			this.model.set({selected: true});
		}
	});
		
	Storelocator.StoreListView = Backbone.View.extend({
		el: $('#storeList'),
		
		initialize: function() {
			this.storeViews = [];
			
			this.options.stores.each(function(store, index) {
				var storeView = new Storelocator.StoreView({model: store});
				this.storeViews.push(storeView);
			}, this);
		},
		
		render: function() {
			this.$('.list').empty();

			_.each(this.storeViews, function(storeView, index) {
				this.$('.list').append(storeView.render().el);
			}, this);
		},
		
		refreshList: function(stores) {
			this.storeViews = [];
			
			stores.each(function(store, index) {
				var storeView = new Storelocator.StoreView({model: store});
				
				if (store.get('distance').value < 40233.6) {
					this.storeViews.push(storeView);
				}
			}, this);
			
			this.render();
		}
	});
		
	Storelocator.MapView = Backbone.View.extend({
		el: $('#mapCanvas'),
		
		initialize: function() {
			this.infoWindow = new google.maps.InfoWindow();
			this.options.stores.bind('change:selected', this.selectMarker, this);
		},
		
		render: function() {
			this.map = new google.maps.Map(document.getElementById('mapCanvas'), {
				center: new google.maps.LatLng(this.options.center.lat, this.options.center.lng),
				zoom: this.options.initialZoomLevel,
				minZoom: this.options.initialZoomLevel,
				mapTypeId: google.maps.MapTypeId.ROADMAP,
				panControl: false,
				zoomControlOptions: {
					position: google.maps.ControlPosition.RIGHT_TOP
				}
			});
	
			this.setMarkers();
			
			this.trigger('mapRendered');
		},
		
		setMarkers: function() {
			this.markers = {},
			
			this.options.stores.each(function(store) {
				this.markers[store.get('id')] = new google.maps.Marker({
					map: this.map,
					position: store.getGoogleLatLng(),
					title: store.get('name')
				});
			}, this);
		},
		
		selectMarker: function(store) {
			this.map.panTo(store.getGoogleLatLng());
			this.map.setZoom(this.options.selectZoomLevel);
			
			var infoWindowTpl = _.template($('#infoWindowTpl').html()),
				tplVars = store.toJSON();
			
			this.infoWindow.setContent(infoWindowTpl(tplVars));
			this.infoWindow.open(this.map, this.markers[store.get('id')]);
		},
		
		updateLocation: function(coords, formatted_address) {
			/*
			var hereMarker = new google.maps.Marker({
					animation: google.maps.Animation.DROP,
					map: this.map,
					position: coords,
					title: formatted_address
				});
			//*/
			this.map.setCenter(coords);
			this.map.setZoom(this.options.selectZoomLevel);
		}
	});
	
	Storelocator.AppView = Backbone.View.extend({
		el: $('#storelocatorApp'),
		
		initialize: function() {
			
		},
		
		events: {
			'submit #storeList .search': 'search'
		},
		
		search: function(event) {
			event.preventDefault();
			
			var self = this,
				form = $(event.target),
				address = $('input[name=address]', form).val(),
				geocoder = new google.maps.Geocoder();
				
			geocoder.geocode({'address': address}, function(results, status) {
				var coords = {
					lat: results[0].geometry.location.lat(),
					lng: results[0].geometry.location.lng()
				}
				
				$.when(self.stores.sortByDistanceFrom(coords)).then(function(stores) {
					self.storeListView.refreshList(stores);
					self.mapView.updateLocation(results[0].geometry.location, results.formatted_address);
					self.router.navigate('search/' + encodeURIComponent(address));
				});
			});
		},
		
		initialize: function() {
			this.stores = new Storelocator.Stores([{"id":"1","lat":"40.70834","lng":"-74.01093","name":"Store One","address":"100 Broadway","city":"New York","state":"New York","zip":"10005","country":"USA"},{"id":"2","lat":"40.71065","lng":"-74.00892","name":"Store Two","address":"200 Broadway","city":"New York","state":"New York","zip":"10038","country":"USA"},{"id":"3","lat":"40.71538","lng":"-74.00543","name":"Store Three","address":"300 Broadway","city":"New York","state":"New York","zip":"10007","country":"USA"},{"id":"4","lat":"34.05485","lng":"-118.38369","name":"Store Four","address":"1171 S Robertson Blvd","city":"Los Angeles","state":"CA","zip":"90035","country":"USA"},{"id":"5","lat":"34.05614","lng":"-118.39524","name":"Store Five","address":"1180 S Beverly Dr","city":"Los Angeles","state":"CA","zip":"90035","country":"USA"}]);
			
			this.mapView = new Storelocator.MapView({
				center: {lat: 20, lng: -90},
				initialZoomLevel: 3,
				selectZoomLevel: 15,
				stores: this.stores
			});
			
			this.mapView.bind('mapRendered', function() {
				this.router = new Storelocator.Router({
					stores: this.stores,
					mapView: this.mapView
				});
				
				Backbone.history.start({pushState: true, root: '/storelocator/storelocator.php/'});
				
				this.stores.bind('change:selected', function(store) {
					this.router.navigate(sanitizeName(store.get('name')));
				}, this);
			}, this);
			
			this.storeListView = new Storelocator.StoreListView({stores: this.stores});
		},
		
		render: function() {
			this.storeListView.render();
			this.mapView.render();
		}
	});
});