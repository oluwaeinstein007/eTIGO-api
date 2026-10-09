#!/usr/bin/env node

import { setTimeout as sleep } from 'node:timers/promises';
import { parsePassengerAccounts } from './passenger-simulator-accounts.mjs';

const help = `Etigo local passenger ride simulator

Configure these environment variables, then run:
  node --env-file=.env --env-file=.env.passenger-simulator.local scripts/passenger-simulator.mjs

Required:
  PASSENGER_SIMULATOR_MODE=local
  PASSENGER_SIM_API_URL=https://<your-ngrok-host>/api/v1
  PASSENGER_SIM_DRIVER_PHONE=<Toyib test driver phone>
  PASSENGER_SIM_DRIVER_OTP=123456
  REVERB_APP_KEY=<local Reverb app key>

Optional:
  PASSENGER_SIM_ACCOUNTS_JSON=<JSON array of passenger phone entries>
  PASSENGER_SIM_TEST_OTP=123456 (local test OTP; never used outside local/ngrok)
  PASSENGER_SIM_DRIVER_OTP=123456 (local test OTP; never used outside local/ngrok)
  PASSENGER_SIM_PICKUP_RADIUS_METERS=10
  PASSENGER_SIM_DESTINATION_MIN_KM=2
  PASSENGER_SIM_DESTINATION_MAX_KM=6
  PASSENGER_SIM_TOKEN=<additional existing passenger Sanctum token>
  PASSENGER_SIM_PICKUP_ADDRESS, PASSENGER_SIM_DESTINATION_ADDRESS
  PASSENGER_SIM_INTERVAL_MIN_SECONDS=45
  PASSENGER_SIM_INTERVAL_MAX_SECONDS=120
  PASSENGER_SIM_NO_RESPONSE_TIMEOUT_SECONDS=90
  PASSENGER_SIM_TIP_CHANCE=0.35
  PASSENGER_SIM_PAYMENT_WAIT_TIMEOUT_SECONDS=120
  PASSENGER_SIM_TIP_MIN=100
  PASSENGER_SIM_TIP_MAX=500
  REVERB_HOST=<defaults to API host>
  REVERB_PORT=<defaults to 443 for HTTPS, otherwise 8080>
  REVERB_WS_SCHEME=<defaults to wss for HTTPS, otherwise ws>
  PASSENGER_SIM_MAX_RIDES=0 (0 means run until Ctrl+C)

The driver app must accept and complete rides. This script never acts as a
driver. Payment method is cash so the simulator does not initiate card charges.
`;

const stopController = new AbortController();

function waitFor(milliseconds) {
  return sleep(milliseconds, undefined, { signal: stopController.signal }).catch(() => {});
}

if (process.argv.includes('--help') || process.argv.includes('-h')) {
  process.stdout.write(help);
  process.exit(0);
}

function required(name) {
  const value = process.env[name]?.trim();
  if (!value) {
    throw new Error(`Missing required environment variable: ${name}`);
  }
  return value;
}

function numberFromEnv(name, fallback, { min = -Infinity, max = Infinity } = {}) {
  const raw = process.env[name];
  const value = raw === undefined || raw === '' ? fallback : Number(raw);
  if (!Number.isFinite(value) || value < min || value > max) {
    throw new Error(`${name} must be a number between ${min} and ${max}.`);
  }
  return value;
}

function assertLocalTunnel(apiUrl) {
  if (process.env.PASSENGER_SIMULATOR_MODE !== 'local') {
    throw new Error('Set PASSENGER_SIMULATOR_MODE=local to enable ride creation.');
  }

  const host = apiUrl.hostname.toLowerCase();
  const isLoopback = ['localhost', '127.0.0.1', '::1'].includes(host);
  const isNgrok = host.endsWith('.ngrok-free.app')
    || host.endsWith('.ngrok.io')
    || host.endsWith('.ngrok.app');

  if (!isLoopback && !isNgrok) {
    throw new Error('API host must be localhost or an ngrok host; production hosts are blocked.');
  }
  if (!['http:', 'https:'].includes(apiUrl.protocol)) {
    throw new Error('PASSENGER_SIM_API_URL must use http or https.');
  }
}

function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function parseFrame(frame) {
  const parsed = JSON.parse(String(frame));
  if (typeof parsed.data === 'string') {
    try {
      parsed.data = JSON.parse(parsed.data);
    } catch {
      // Some Pusher frames contain plain text data.
    }
  }
  return parsed;
}

async function fetchJson(url, token = null, options = {}) {
  const response = await fetch(url, {
    ...options,
    signal: AbortSignal.timeout(15000),
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...options.headers,
    },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const message = body.message ?? `HTTP ${response.status}`;
    throw new Error(`${new URL(url).pathname}: HTTP ${response.status}: ${message}`);
  }
  return body;
}

async function waitForRidePayment(apiBase, rideId, token, timeoutSeconds) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  while (!stopController.signal.aborted) {
    const response = await fetchJson(
      `${apiBase}/rides/${encodeURIComponent(rideId)}/receipt`,
      token,
    );
    const payment = response.receipt?.payment;
    if (payment) return payment;

    const remaining = deadline - Date.now();
    if (remaining <= 0) return null;
    process.stdout.write(`Waiting for payment record for ride ${rideId}.\n`);
    await waitFor(Math.min(5000, remaining));
  }
  return null;
}

async function loadPassengerAccounts(apiBase, fallbackToken) {
  const accounts = [];
  if (fallbackToken) {
    try {
      const identity = await fetchJson(`${apiBase}/auth/me`, fallbackToken);
      if (identity.user?.type === 'passenger') {
        accounts.push({ token: fallbackToken, label: 'configured passenger' });
      }
    } catch {
      process.stderr.write('Configured passenger token was rejected; checking the local accounts file.\n');
    }
  }

  accounts.push(...parsePassengerAccounts(process.env.PASSENGER_SIM_ACCOUNTS_JSON ?? '').map((account) => ({
    ...account,
    token: null,
  })));

  if (accounts.length === 0) {
    throw new Error('No existing passenger accounts could be authenticated. The simulator does not register users.');
  }
  return accounts;
}

async function authenticatePassengerAccount(apiBase, account) {
  if (account.token) {
    return true;
  }

  const code = process.env.PASSENGER_SIM_TEST_OTP?.trim() || '123456';
  try {
    await fetchJson(`${apiBase}/auth/otp/send`, null, {
      method: 'POST',
      body: JSON.stringify({ phone: account.phone }),
    });
  } catch (error) {
    if (!error.message.includes('429')) {
      return false;
    }
  }

  try {
    const verification = await fetchJson(`${apiBase}/auth/otp/verify`, null, {
      method: 'POST',
      body: JSON.stringify({
        phone: account.phone,
        code,
        type: 'passenger',
      }),
    });
    if (!verification.token || verification.user?.type !== 'passenger') {
      return false;
    }
    account.token = verification.token;
    return true;
  } catch (error) {
    if (error.message.includes('429')) {
      throw error;
    }
    return false;
  }
}

async function authenticateLocationDriver(apiBase) {
  const phone = required('PASSENGER_SIM_DRIVER_PHONE');
  const code = process.env.PASSENGER_SIM_DRIVER_OTP?.trim() || '123456';
  while (!stopController.signal.aborted) {
    try {
      await fetchJson(`${apiBase}/auth/otp/send`, null, {
        method: 'POST',
        body: JSON.stringify({ phone }),
      });
      break;
    } catch (error) {
      if (!error.message.includes('429')) {
        throw error;
      }
      process.stderr.write('Driver test account is on the local OTP cooldown; retrying in 60 seconds.\n');
      await waitFor(60000);
    }
  }
  if (stopController.signal.aborted) {
    throw new Error('Stopped while waiting for the driver OTP cooldown.');
  }
  const verification = await fetchJson(`${apiBase}/auth/otp/verify`, null, {
    method: 'POST',
    body: JSON.stringify({ phone, code, type: 'driver' }),
  });
  if (!verification.token || verification.user?.type !== 'driver') {
    throw new Error('Configured location account did not authenticate as a driver.');
  }
  return verification.token;
}

async function readOnlineDriverContext(apiBase, token) {
  const context = await fetchJson(`${apiBase}/driver/location`, token);
  if (!context.is_online || !context.location || !context.city_id || !context.vehicle_class_id) {
    throw new Error('Online driver location, city, or vehicle class is unavailable.');
  }
  return context;
}

function offsetCoordinate(lat, lng, distanceKm, bearingRadians) {
  const earthRadiusKm = 6371;
  const angularDistance = distanceKm / earthRadiusKm;
  const latitude = lat * Math.PI / 180;
  const longitude = lng * Math.PI / 180;
  const destinationLatitude = Math.asin(
    Math.sin(latitude) * Math.cos(angularDistance)
      + Math.cos(latitude) * Math.sin(angularDistance) * Math.cos(bearingRadians),
  );
  const destinationLongitude = longitude + Math.atan2(
    Math.sin(bearingRadians) * Math.sin(angularDistance) * Math.cos(latitude),
    Math.cos(angularDistance) - Math.sin(latitude) * Math.sin(destinationLatitude),
  );
  return { lat: destinationLatitude * 180 / Math.PI, lng: destinationLongitude * 180 / Math.PI };
}

function makeRideCoordinates(location) {
  const pickupRadiusKm = numberFromEnv('PASSENGER_SIM_PICKUP_RADIUS_METERS', 10, { min: 0, max: 250 }) / 1000;
  const pickup = offsetCoordinate(location.lat, location.lng, Math.random() * pickupRadiusKm, Math.random() * Math.PI * 2);
  const minKm = numberFromEnv('PASSENGER_SIM_DESTINATION_MIN_KM', 2, { min: 0.5, max: 50 });
  const maxKm = numberFromEnv('PASSENGER_SIM_DESTINATION_MAX_KM', 6, { min: 0.5, max: 50 });
  if (minKm > maxKm) {
    throw new Error('PASSENGER_SIM_DESTINATION_MIN_KM cannot exceed MAX_KM.');
  }
  const destination = offsetCoordinate(
    pickup.lat,
    pickup.lng,
    minKm + Math.random() * (maxKm - minKm),
    Math.random() * Math.PI * 2,
  );
  return { pickup, destination };
}

function createRideSocket({ rideId, config, token, onStatus }) {
  const channelName = `private-ride.${rideId}`;
  const wsUrl = new URL(`/app/${encodeURIComponent(config.appKey)}`, config.wsOrigin);
  wsUrl.searchParams.set('protocol', '7');
  wsUrl.searchParams.set('client', 'js');
  wsUrl.searchParams.set('version', '8.4.0');
  wsUrl.searchParams.set('flash', 'false');

  let socket;
  let closed = false;
  let established = false;
  let subscribed = false;
  const pending = [];

  const ready = new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Reverb subscription timed out.')), 12000);
    socket = new WebSocket(wsUrl);

    socket.addEventListener('error', () => {
      if (!established) {
        clearTimeout(timeout);
        reject(new Error('Could not connect to the local Reverb WebSocket.'));
      }
    });

    socket.addEventListener('close', () => {
      if (!closed && !subscribed) {
        clearTimeout(timeout);
        reject(new Error('Reverb closed before the ride channel was subscribed.'));
      }
    });

    socket.addEventListener('message', async (event) => {
      let frame;
      try {
        frame = parseFrame(event.data);
      } catch {
        return;
      }

      if (frame.event === 'pusher:connection_established' && !established) {
        established = true;
        try {
          const connection = typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data;
          const response = await fetch(`${config.apiOrigin}/broadcasting/auth`, {
            method: 'POST',
            headers: {
              Accept: 'application/json',
              Authorization: `Bearer ${token}`,
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
              socket_id: connection.socket_id,
              channel_name: channelName,
            }),
            signal: AbortSignal.timeout(15000),
          });
          const authorization = await response.json().catch(() => ({}));
          if (!response.ok || !authorization.auth) {
            throw new Error(authorization.message ?? `Channel authorization failed (HTTP ${response.status}).`);
          }
          socket.send(JSON.stringify({
            event: 'pusher:subscribe',
            data: { auth: authorization.auth, channel: channelName },
          }));
        } catch (error) {
          clearTimeout(timeout);
          reject(error);
        }
      } else if (frame.event === 'pusher_internal:subscription_succeeded' && frame.channel === channelName) {
        subscribed = true;
        clearTimeout(timeout);
        resolve();
      } else if (frame.event === 'ride.status.updated' && frame.channel === channelName) {
        const status = frame.data?.status;
        if (status) {
          onStatus(status, 'socket');
        }
      } else if (frame.event === 'pusher:ping') {
        socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
      }
    });
  });

  return {
    ready,
    close() {
      closed = true;
      if (socket?.readyState === WebSocket.OPEN) {
        socket.close();
      }
    },
  };
}

async function main() {
  if (typeof WebSocket === 'undefined') {
    throw new Error('This script needs Node.js 22 or newer for its built-in WebSocket client.');
  }

  const apiUrl = new URL(required('PASSENGER_SIM_API_URL'));
  assertLocalTunnel(apiUrl);
  if (!apiUrl.pathname.replace(/\/+$/, '').endsWith('/api/v1')) {
    throw new Error('PASSENGER_SIM_API_URL must end with /api/v1.');
  }
  const fallbackToken = process.env.PASSENGER_SIM_TOKEN?.trim() || null;
  const apiBase = apiUrl.toString().replace(/\/+$/, '');
  const config = {
    apiOrigin: apiUrl.origin,
    appKey: required('REVERB_APP_KEY'),
    wsOrigin: '',
  };
  const defaultScheme = apiUrl.protocol === 'https:' ? 'wss' : 'ws';
  const scheme = (process.env.REVERB_WS_SCHEME ?? defaultScheme).toLowerCase();
  if (!['ws', 'wss'].includes(scheme)) {
    throw new Error('REVERB_WS_SCHEME must be ws or wss.');
  }
  const socketHost = process.env.REVERB_HOST?.trim() || apiUrl.hostname;
  const socketPort = numberFromEnv('REVERB_PORT', apiUrl.protocol === 'https:' ? 443 : 8080, { min: 1, max: 65535 });
  config.wsOrigin = `${scheme}://${socketHost}:${socketPort}`;

  const minInterval = numberFromEnv('PASSENGER_SIM_INTERVAL_MIN_SECONDS', 45, { min: 10, max: 86400 });
  const maxInterval = numberFromEnv('PASSENGER_SIM_INTERVAL_MAX_SECONDS', 120, { min: 10, max: 86400 });
  if (minInterval > maxInterval) {
    throw new Error('PASSENGER_SIM_INTERVAL_MIN_SECONDS cannot exceed MAX_SECONDS.');
  }
  const tipChance = numberFromEnv('PASSENGER_SIM_TIP_CHANCE', 0.35, { min: 0, max: 1 });
  const paymentWaitTimeoutSeconds = numberFromEnv(
    'PASSENGER_SIM_PAYMENT_WAIT_TIMEOUT_SECONDS',
    120,
    { min: 0, max: 3600 },
  );
  const noResponseTimeoutSeconds = numberFromEnv(
    'PASSENGER_SIM_NO_RESPONSE_TIMEOUT_SECONDS',
    90,
    { min: 15, max: 3600 },
  );
  const tipMin = numberFromEnv('PASSENGER_SIM_TIP_MIN', 100, { min: 50, max: 50000 });
  const tipMax = numberFromEnv('PASSENGER_SIM_TIP_MAX', 500, { min: 50, max: 50000 });
  if (tipMin > tipMax) {
    throw new Error('PASSENGER_SIM_TIP_MIN cannot exceed PASSENGER_SIM_TIP_MAX.');
  }
  const maxRides = numberFromEnv('PASSENGER_SIM_MAX_RIDES', 0, { min: 0 });
  const driverToken = await authenticateLocationDriver(apiBase);
  const driverContext = await readOnlineDriverContext(apiBase, driverToken);

  const passengerAccounts = await loadPassengerAccounts(apiBase, fallbackToken);

  process.stdout.write(`Passenger simulator connected to ${apiUrl.host}; press Ctrl+C to stop.\n`);
  process.stdout.write(`Found ${passengerAccounts.length} passenger account candidate(s); the simulator will choose randomly and use only existing passenger accounts.\n`);
  process.stdout.write('Keep Toyib online in the driver app. Requests use normal proximity matching.\n');

  let stopped = false;
  let createdRides = 0;
  process.once('SIGINT', () => { stopped = true; stopController.abort(); });
  process.once('SIGTERM', () => { stopped = true; stopController.abort(); });

  while (!stopped && (maxRides === 0 || createdRides < maxRides)) {
    try {
      let passengerAccount;
      while (!passengerAccount && passengerAccounts.length > 0 && !stopped) {
        const index = randomInt(0, passengerAccounts.length - 1);
        const candidate = passengerAccounts[index];
        try {
          if (await authenticatePassengerAccount(apiBase, candidate)) {
            passengerAccount = candidate;
          } else {
            process.stderr.write(`Skipping ${candidate.label}; it is not an existing passenger account.\n`);
            passengerAccounts.splice(index, 1);
          }
        } catch (error) {
          if (error.message.includes('429')) {
            process.stderr.write('Passenger login is rate-limited; retrying in 30 seconds.\n');
            await waitFor(30000);
          } else {
            throw error;
          }
        }
      }
      if (stopped) {
        break;
      }
      if (!passengerAccount) {
        throw new Error('No existing passenger accounts could be authenticated. The simulator never registers users.');
      }
      const currentDriverContext = await readOnlineDriverContext(apiBase, driverToken);
      const { pickup, destination } = makeRideCoordinates(currentDriverContext.location);
      const response = await fetchJson(`${apiBase}/rides`, passengerAccount.token, {
        method: 'POST',
        body: JSON.stringify({
          city_id: currentDriverContext.city_id,
          vehicle_class_id: currentDriverContext.vehicle_class_id,
          pickup_lat: pickup.lat,
          pickup_lng: pickup.lng,
          pickup_address: 'Near online driver location',
          destination_lat: destination.lat,
          destination_lng: destination.lng,
          destination_address: 'Nearby local destination',
          payment_method: 'cash',
        }),
      });
      const ride = response.ride;
      if (!ride?.id || !response.pin_code) {
        throw new Error('Ride API response did not include a ride ID and PIN.');
      }
      createdRides += 1;
      const requestedAt = Date.now();
      process.stdout.write(`Ride ${ride.id} requested by ${passengerAccount.label}; fare estimate ${ride.fare_currency ?? ''} ${ride.fare_estimate_amount ?? 'unknown'}.\n`);

      let latestStatus = ride.status ?? 'searching';
      let pinShown = false;
      let tipAttempted = false;
      const onStatus = (status, source) => {
        latestStatus = status;
        process.stdout.write(`Ride ${ride.id}: ${status} (${source}).\n`);
        if (status === 'driver_arrived' && !pinShown) {
          pinShown = true;
          process.stdout.write(`Start-trip PIN for ride ${ride.id}: ${response.pin_code}\n`);
        }
      };

      const channel = createRideSocket({ rideId: ride.id, config, token: passengerAccount.token, onStatus });
      try {
        await channel.ready;
        process.stdout.write(`Listening for ride updates on ${ride.id}.\n`);
      } catch (error) {
        channel.close();
        process.stderr.write(`Socket unavailable for ride ${ride.id}; polling ride status instead: ${error.message}\n`);
      }

      if (latestStatus === 'driver_arrived' && !pinShown) {
        pinShown = true;
        process.stdout.write(`Start-trip PIN for ride ${ride.id}: ${response.pin_code}\n`);
      }

      while (!stopped && !['completed', 'cancelled', 'no_driver_found'].includes(latestStatus)) {
        await waitFor(5000);
        if (stopped) {
          break;
        }
        const current = await fetchJson(`${apiBase}/rides/${encodeURIComponent(ride.id)}`, passengerAccount.token);
        const status = current.ride?.status;
        if (status && status !== latestStatus) {
          onStatus(status, 'poll');
        }
        if (status === 'driver_arrived' && !pinShown) {
          pinShown = true;
          process.stdout.write(`Start-trip PIN for ride ${ride.id}: ${response.pin_code}\n`);
        }

        const isStillSearching = status === 'requested' || status === 'searching';
        const searchTimedOut = Date.now() - requestedAt >= noResponseTimeoutSeconds * 1000;
        if (isStillSearching && searchTimedOut) {
          try {
            await fetchJson(`${apiBase}/rides/${encodeURIComponent(ride.id)}/cancel`, passengerAccount.token, {
              method: 'POST',
              body: JSON.stringify({ reason: 'wait_too_long' }),
            });
            onStatus('cancelled', 'no-response timeout');
          } catch (error) {
            const latest = await fetchJson(`${apiBase}/rides/${encodeURIComponent(ride.id)}`, passengerAccount.token);
            const latestRideStatus = latest.ride?.status;
            if (latestRideStatus && latestRideStatus !== latestStatus) {
              onStatus(latestRideStatus, 'poll');
            }
            if (latestRideStatus === 'requested' || latestRideStatus === 'searching') {
              process.stderr.write(`Could not cancel unanswered ride ${ride.id}: ${error.message}\n`);
            }
          }
        }
      }
      channel.close();

      if (stopped) {
        break;
      }

      if (latestStatus === 'completed' && Math.random() < tipChance && !tipAttempted) {
        tipAttempted = true;
        const amount = randomInt(tipMin, tipMax);
        const payment = await waitForRidePayment(
          apiBase,
          ride.id,
          passengerAccount.token,
          paymentWaitTimeoutSeconds,
        );
        if (!payment) {
          process.stderr.write(`Skipping tip for ride ${ride.id}; no payment record appeared within ${paymentWaitTimeoutSeconds} seconds.\n`);
        } else if (payment.status === 'failed') {
          process.stderr.write(`Skipping tip for ride ${ride.id}; the ride payment failed.\n`);
        } else if (!stopped) {
          await fetchJson(`${apiBase}/rides/${encodeURIComponent(ride.id)}/tip`, passengerAccount.token, {
            method: 'POST',
            body: JSON.stringify({ amount }),
          });
          process.stdout.write(`Added a cash tip of ₦${amount} to completed ride ${ride.id}.\n`);
        }
      }

      if (!stopped && (maxRides === 0 || createdRides < maxRides)) {
        const seconds = randomInt(minInterval, maxInterval);
        process.stdout.write(`Next request in about ${seconds} seconds.\n`);
        await waitFor(seconds * 1000);
      }
    } catch (error) {
      process.stderr.write(`Simulator error: ${error.message}\nRetrying in 30 seconds.\n`);
      await waitFor(30000);
    }
  }

  process.stdout.write(`Passenger simulator stopped after ${createdRides} ride request(s).\n`);
}

main().catch((error) => {
  process.stderr.write(`${error.message}\n\nRun with --help for configuration.\n`);
  process.exitCode = 1;
});
