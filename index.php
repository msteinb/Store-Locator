<!DOCTYPE html>
<html>
<head>
	<title>Store Locator</title>

	<link href="/storelocator/css/style.css" media="screen" rel="stylesheet" type="text/css" />
</head>
<body>

<div id="storelocatorApp">
	<div id="storeList">
		<form class="search" name="search" method="post" action="">
			<input type="text" name="address" placeholder="Search: City, State or Zip" />
			<button class="searchBtn">Go</button>
		</form>
		<ul class="list"></ul>
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

<!-- Dependencies -->

<script src="//ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<script src="js/libs/underscore.js"></script>
<script src="js/libs/backbone.js"></script>
<script src="//maps.googleapis.com/maps/api/js?key=AIzaSyAfw9FI5V26iEmbKlf4MVcyd2xjwX5Nds8&sensor=false"></script>

<!-- Application Source --> 

<script src="js/src/app.js"></script>
<script>
	$(function() {
		var appView = new Storelocator.AppView();
		appView.render();
	});
</script>

</body>
</html>