<!DOCTYPE html>
<html>
<head>
	<title>Store Locator</title>
	<style>
		
	</style>
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
	<script type="text/javascript" src="/js/underscore.js"></script>
	<script type="text/javascript" src="/js/backbone.js"></script>
	<script type="text/javascript" src="http://maps.googleapis.com/maps/api/js?key=AIzaSyAfw9FI5V26iEmbKlf4MVcyd2xjwX5Nds8&sensor=false" ></script>
	<script>
		$(function() {
			var Storelocator = Storelocator || {};
			
			function sanitizeName(name) {
				return name.toLowerCase().replace(/ /g, '-');
			}
		//*	
			Storelocator.Router = Backbone.Router.extend({
				routes: {
					'search/:query': 'search',
					'store/:name': 'loadStore'
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
	
					this.mapView.selectMarker(store);
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
		//*	
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
					
					return def.promise();
				}
			});
		//*	
			Storelocator.StoreView = Backbone.View.extend({
				tagName: 'li',
				className: 'store-row',
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
					var name = this.model.get('name');
					//Router.navigate('store/' + sanitizeName(name));
					//this.trigger('select', this.model);
					this.model.set({selected: true});
				}
			});
		//*	
			Storelocator.StoreListView = Backbone.View.extend({
				el: $('#storeList'),
				
				initialize: function() {
					var self = this;
					
					this.storeViews = [];
					
					this.options.stores.each(function(store, index) {
						var storeView = new Storelocator.StoreView({model: store});
						self.storeViews.push(storeView);
					});
				},
				
				render: function() {
					var self = this;
					
					self.$('.list').empty();
					
					_.each(this.storeViews, function(storeView, index) {
						self.$('.list').append(storeView.render().el);
					});
				},
				
				search: function(event) {
					event.preventDefault();
					
					var self = this,
						form = $(event.target),
						address = $('input[name=address]', form).val(),
						geocoder = new google.maps.Geocoder();
						
					geocoder.geocode({'address': address}, function(results, status) {
						self.trigger('locator:updateLocation', results[0].geometry.location, results.formatted_address);
						var coords = {
							lat: results[0].geometry.location.lat(),
							lng: results[0].geometry.location.lng()
						}
						$.when(self.options.stores.sortByDistanceFrom(coords)).then(function(stores) {
							self.refreshList(stores);
						});
					});
				},
				
				refreshList: function(stores) {
					var self = this;
					this.storeViews = [];
					
					stores.each(function(store, index) {
						var storeView = new Storelocator.StoreView({model: store});
						storeView.bind('select', function(store) {
							self.trigger('locator:select', store);
						});
						
						if (store.get('distance').value < 40233.6) {
							self.storeViews.push(storeView);
						}
					});
					
					this.render();
				}
			});
		//*	
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
				},
				
				setMarkers: function() {
					var self = this;
					
					this.markers = {},
					
					this.options.stores.each(function(store) {
						self.markers[store.get('id')] = new google.maps.Marker({
							map: self.map,
							position: store.getGoogleLatLng(),
							title: store.get('name')
						});
					});
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
					var hereMarker = new google.maps.Marker({
							animation: google.maps.Animation.DROP,
							map: this.map,
							position: coords,
							title: formatted_address
						});
					
					this.map.setCenter(coords);
					this.map.setZoom(this.options.selectZoomLevel);
				}
			});
			
			Storelocator.AppView = Backbone.View.extend({
				el: $('#storelocatorApp'),
				
				initialize: function() {
					this.stores = new Storelocator.Stores([{"id":"1","lat":"40.70834","lng":"-74.01093","name":"Store One","address":"100 Broadway","city":"New York","state":"New York","zip":"10005","country":"USA"},{"id":"2","lat":"40.71065","lng":"-74.00892","name":"Store Two","address":"200 Broadway","city":"New York","state":"New York","zip":"10038","country":"USA"},{"id":"3","lat":"40.71538","lng":"-74.00543","name":"Store Three","address":"300 Broadway","city":"New York","state":"New York","zip":"10007","country":"USA"}]);
					
					this.mapView = new Storelocator.MapView({
						center: {lat: 20, lng: -90},
						initialZoomLevel: 3,
						selectZoomLevel: 15,
						stores: this.stores
					});
					
					var router = new Storelocator.Router({
						stores: this.stores,
						mapView: this.mapView
					});
					
					Backbone.history.start({pushState: true, root: '/storelocator/storelocator.php/'});
					
					this.stores.bind('change:selected', function(store) {
						router.navigate(sanitizeName(store.get('name')));
					});
					
					this.storeListView = new Storelocator.StoreListView({stores: this.stores});
				},
				
				render: function() {
					this.storeListView.render();
					this.mapView.render();
				}
			});
			
					
			var appView = new Storelocator.AppView();
			appView.render();
			
		/*	
			var stores = new Storelocator.Stores([{"id":"1","lat":"25.772030","lng":"-80.191810","name":"FOOTSOLDIERS","address":"1 Main Street","city":"Anycity","state":"Anystate","zip":"12345","country":"USA","hasLocation":0},{"id":"4","lat":"40.724437","lng":"-73.815551","name":"Second Store","address":"75-31 150 Street","city":"Queens","state":"NY","zip":"11367","country":"USA","hasLocation":1},{"id":"5","lat":"25.775160","lng":"-80.193630","name":"Third Store","address":"76 Green St","city":"Blah Blah","state":"MA","zip":"98765","country":"USA","hasLocation":1},{"id":"6","lat":"25.772040","lng":"-80.191820","name":"Store Four","address":"12 Road Street","city":"ooga booga","state":"KY","zip":"65498","country":"USA","hasLocation":1},{"id":"7","lat":"40.494469","lng":"-74.427139","name":"HP Store","address":"432 Felton Ave","city":"Highland Park","state":"NJ","zip":"08904-2623","country":"United States","hasLocation":1},{"id":"8","lat":"40.723387","lng":"-73.817416","name":"Ye Olde Store","address":"147-11 76th Avenue","city":"Flushing","state":"NY","zip":"11367","country":"USA","hasLocation":1}]);
			var StoreListView = new Storelocator.StoreListView({stores: stores});
			storeListView.render();
			
			var mapView = new Storelocator.MapView({
				center: {lat: 20, lng: -90},
				initialZoomLevel: 3,
				selectZoomLevel: 15,
				stores: stores
			});
			
			mapView.render();
			storeListView.bind('locator:select', mapView.selectMarker, mapView);
			storeListView.bind('locator:updateLocation', mapView.updateLocation, mapView);
			
			var Router = new Storelocator.Router({
				stores: stores,
				mapView: mapView
			});
			
			Backbone.history.start({pushState: true, root: '/sprayground/storelocator.php/'});
		//*/
			
			
		
		});
	</script>
	<style>
		#storeList {position:absolute;z-index:20;left:25px;top:25px;background:white;width:300px;background-color: #F8F8F8;border: 2px solid #004080;-webkit-border-radius:4px;-moz-border-radius:4px;border-radius:4px}
		#storeList .search {background-color: #F0F0FF;padding:10px;border-top-right-radius:2px;-moz-border-radius-topright:2px;-webkit-border-top-right-radius:2px;border-top-left-radius:2px;-moz-border-radius-topleft:2px;-webkit-border-top-left-radius:2px}
		#storeList input[name=address] {border: 1px solid #CCC;padding: 4px;width: 225px;margin: 0 6px 0 0;}
		#storeList .list {list-style: none;padding: 0;margin: 0;}
		#storeList .list li, .info-window {font-family:Arial, Helvetica, sans-serif}
		#storeList .list li {cursor:pointer;padding: 10px;border-top:1px solid #004080;position:relative}
		#storeList .list li.alt {background:#eaeaea;}
		#storeList .list .title, .info-window .title {font-size: 22px}
		#storeList .list div, .info-window div {font-size: 12px; margin-bottom: 3px}
		#storeList .list .distance {position:absolute; right:10px; top:45%}
		#mapCanvas {position:absolute;width:100%;height:100%;min-height:90%;left:0px;top:0px;z-index:1;border:0;padding:0;margin:0;}
		
	</style>
</head>
<body>

<div id="storelocatorApp">
	<div id="storeList">
		<form class="search" name="search" method="post" action="">
			<input type="text" name="address" placeholder="Search: City, State or Zip" />
			<button>Go</button>
		</form>
		<ul class="list">
			
		</ul>
	</div>
	
	<div id="mapCanvas"></div>
</div>

<!-- Templates -->

<script type="text/template" id="storeTpl">
	<div class="title"><%= name %></div>
	<div class="address"><%= address %></div>
	<div class="city"><%= city %>, <%= state %> <%= zip %></div>
	<div class="distance">10 Miles</div>
</script>

<script type="text/template" id="infoWindowTpl">
	<div class="info-window">
		<div class="title"><%= name %></div>
		<div class="address"><%= address %></div>
		<div class="city"><%= city %>, <%= state %> <%= zip %></div>
	</div>
</script>

</body>
</html>