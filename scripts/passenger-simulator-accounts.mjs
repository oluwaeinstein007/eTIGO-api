/**
 * Parse and validate the local passenger-only account array once at startup.
 * Entries with an explicit non-passenger type are discarded.
 * @param {string} contents
 * @returns {Array<{phone: string, label: string}>}
 */
export function parsePassengerAccounts(contents) {
  if (!contents?.trim()) {
    return [];
  }

  let entries;
  try {
    entries = JSON.parse(contents);
  } catch {
    throw new Error('PASSENGER_SIM_ACCOUNTS_JSON must contain a valid JSON array.');
  }
  if (!Array.isArray(entries)) {
    throw new Error('PASSENGER_SIM_ACCOUNTS_JSON must contain a JSON array.');
  }

  const accounts = [];
  const seenPhones = new Set();
  for (const entry of entries) {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
      continue;
    }
    if (entry.type && entry.type !== 'passenger') {
      continue;
    }
    if (typeof entry.phone !== 'string') {
      continue;
    }
    const phone = entry.phone.replace(/[\s()-]/g, '');
    if (/^\+?\d{8,15}$/.test(phone) && !seenPhones.has(phone)) {
      seenPhones.add(phone);
      accounts.push({ phone, label: `passenger account ${accounts.length + 1}` });
    }
  }

  return accounts;
}
