<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Track your E-tiGo ride</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root { --green: #0a7d4f; --ink: #14201b; --muted: #5b6b64; --line: #e3e9e6; --bg: #f6f8f7; --danger: #c0262d; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: var(--bg); }
        header { background: var(--green); color: #fff; padding: 14px 16px; font-weight: 600; }
        #map { height: 45vh; min-height: 260px; background: #dfe7e3; }
        main { padding: 16px; max-width: 560px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
        .status { font-size: 1.15rem; font-weight: 600; margin: 0 0 4px; }
        .muted { color: var(--muted); font-size: .92rem; margin: 2px 0; }
        .driver { display: flex; gap: 12px; align-items: center; }
        .driver img { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; background: var(--line); }
        .plate { display: inline-block; font-weight: 700; letter-spacing: .06em; border: 2px solid var(--ink); border-radius: 6px; padding: 2px 8px; margin-top: 6px; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .actions a { flex: 1 1 140px; text-align: center; text-decoration: none; font-weight: 600; padding: 12px; border-radius: 10px; }
        .call-112 { background: var(--danger); color: #fff; }
        .call-safety { border: 2px solid var(--green); color: var(--green); }
        .notice { text-align: center; padding: 40px 16px; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<header>E-tiGo · Live trip</header>
<div id="map" role="img" aria-label="Map showing the driver and the pickup point"></div>
<main>
    <div id="notice" class="card notice" hidden></div>
    <div id="content">
        <div class="card">
            <p class="status" id="status">Loading your trip…</p>
            <p class="muted" id="eta"></p>
            <p class="muted" id="booked-by"></p>
        </div>
        <div class="card" id="driver-card" hidden>
            <div class="driver">
                <img id="driver-photo" alt="" hidden>
                <div>
                    <p class="status" id="driver-name"></p>
                    <p class="muted" id="car"></p>
                    <span class="plate" id="plate" hidden></span>
                </div>
            </div>
            <p class="muted">Check the plate number before you get in.</p>
        </div>
        <div class="card">
            <p class="muted"><strong>Pickup:</strong> <span id="pickup"></span></p>
            <p class="muted"><strong>Drop-off:</strong> <span id="destination"></span></p>
        </div>
        <div class="actions">
            <a class="call-112" id="call-112" href="tel:112">Call 112</a>
            <a class="call-safety" id="call-safety" hidden>E-tiGo safety line</a>
        </div>
    </div>
</main>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function () {
    var endpoint = @json($endpoint);
    var map = null, driverMarker = null, pickupMarker = null, timer = null;
    var $ = function (id) { return document.getElementById(id); };

    function text(id, value) { $(id).textContent = value || ''; }

    function ensureMap(lat, lng) {
        if (map || typeof L === 'undefined') { return; }
        map = L.map('map', { zoomControl: false }).setView([lat, lng], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
    }

    function stop(message) {
        clearInterval(timer);
        $('content').hidden = true;
        $('notice').hidden = false;
        $('notice').textContent = message;
    }

    function render(data) {
        var ride = data.ride, driver = ride.driver || null, live = data.driver_location;
        var pickup = [parseFloat(ride.pickup.lat), parseFloat(ride.pickup.lng)];

        text('status', ride.status_label);
        text('pickup', ride.pickup.address);
        text('destination', ride.destination.address);
        text('booked-by', ride.is_third_party && ride.booked_by_first_name ? 'Booked for you by ' + ride.booked_by_first_name : '');
        var minutes = data.eta && data.eta.duration_minutes != null ? Math.max(1, Math.round(data.eta.duration_minutes)) : null;
        text('eta', minutes === null ? '' : 'About ' + minutes + ' min ' + (data.eta.target === 'destination' ? 'to drop-off' : 'to pickup'));

        if (driver) {
            $('driver-card').hidden = false;
            text('driver-name', driver.first_name);
            var car = driver.vehicle ? [driver.vehicle.colour, driver.vehicle.make, driver.vehicle.model].filter(Boolean).join(' ') : '';
            text('car', car);
            if (driver.vehicle && driver.vehicle.plate_number) { $('plate').hidden = false; text('plate', driver.vehicle.plate_number); }
            if (driver.profile_photo_url) { $('driver-photo').hidden = false; $('driver-photo').src = driver.profile_photo_url; }
        }

        if (data.safety) {
            $('call-112').href = 'tel:' + data.safety.emergency_number;
            text('call-112', 'Call ' + data.safety.emergency_number);
            if (data.safety.safety_line) { $('call-safety').hidden = false; $('call-safety').href = 'tel:' + data.safety.safety_line; }
        }

        ensureMap(pickup[0], pickup[1]);
        if (!map) { return; }
        if (!pickupMarker) { pickupMarker = L.circleMarker(pickup, { radius: 8, color: '#0a7d4f' }).addTo(map).bindTooltip('Pickup'); }
        if (live) {
            var position = [live.lat, live.lng];
            if (!driverMarker) {
                driverMarker = L.marker(position).addTo(map).bindTooltip('Your driver');
                map.fitBounds([position, pickup], { padding: [40, 40], maxZoom: 16 });
            } else {
                driverMarker.setLatLng(position);
            }
        }

        if (ride.status === 'completed') { clearInterval(timer); text('eta', 'You have arrived. Thank you for riding with E-tiGo.'); }
        if (ride.status === 'cancelled') { clearInterval(timer); text('eta', 'This ride was cancelled.'); }
    }

    function poll() {
        fetch(endpoint, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (response.status === 404 || response.status === 410) { stop('This tracking link has expired.'); return null; }
                return response.ok ? response.json() : null;
            })
            .then(function (data) { if (data) { render(data); } })
            .catch(function () { /* try again on the next tick */ });
    }

    poll();
    timer = setInterval(poll, 10000);
})();
</script>
</body>
</html>
