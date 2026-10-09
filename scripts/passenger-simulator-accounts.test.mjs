import test from 'node:test';
import assert from 'node:assert/strict';

import { parsePassengerAccounts } from './passenger-simulator-accounts.mjs';

test('parses passenger entries and ignores explicit driver and admin entries', () => {
  const accounts = parsePassengerAccounts(JSON.stringify([
    { type: 'passenger', phone: '+234 800-000-0001' },
    { type: 'driver', phone: '+234 800-000-0002' },
    { type: 'admin', phone: '+234 800-000-0003' },
    { phone: '+234 800-000-0004' },
  ]));

  assert.deepEqual(accounts, [
    { phone: '+2348000000001', label: 'passenger account 1' },
    { phone: '+2348000000004', label: 'passenger account 2' },
  ]);
});

test('ignores malformed and duplicate account entries', () => {
  const accounts = parsePassengerAccounts(JSON.stringify([
    { type: 'passenger', phone: '+2348000000001' },
    { type: 'passenger', phone: '+2348000000001' },
    { type: 'passenger', phone: 'not-a-phone' },
    { type: 'passenger', email: 'user@example.test' },
  ]));

  assert.deepEqual(accounts, [
    { phone: '+2348000000001', label: 'passenger account 1' },
  ]);
});
