'use strict';

const crypto = require('crypto');
const { normalizeText } = require('./runtime-utils.cjs');

function usernameFromSubject(runtimeConfig) {
  const subject = runtimeConfig.subject || {};
  const accountUsername = normalizeText(subject.accountUsername || subject.account_username);
  const desiredEmail = normalizeText(subject.desiredEmail || subject.desired_email);
  const source = accountUsername || desiredEmail;
  const localPart = source.includes('@') ? source.split('@')[0] : source;

  return localPart
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .replace(/[._-]{2,}/g, '-')
    .slice(0, 64);
}

function uniqueValues(values) {
  return Array.from(new Set(values.filter((value) => normalizeText(value) !== '')));
}

function trimUsernameCandidate(value) {
  return normalizeText(value)
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .replace(/[._-]{2,}/g, '-')
    .slice(0, 64);
}

function generateUsernameCandidates(baseUsername, maxAttempts = 12) {
  const base = trimUsernameCandidate(baseUsername);
  const requestedAttempts = Number(maxAttempts);
  const targetAttempts = Number.isFinite(requestedAttempts)
    ? Math.max(1, Math.min(50, Math.floor(requestedAttempts)))
    : 12;

  if (!base) {
    return [];
  }

  const candidates = [base];
  const trailingNumber = base.match(/^(.*?)(\d{1,6})$/);

  if (trailingNumber) {
    const prefix = trailingNumber[1] || base;
    const currentNumber = Number(trailingNumber[2]);
    const width = trailingNumber[2].length;

    for (let offset = 1; candidates.length < targetAttempts && offset <= targetAttempts * 2; offset += 1) {
      candidates.push(trimUsernameCandidate(`${prefix}${String(currentNumber + offset).padStart(width, '0')}`));
    }
  }

  for (let suffix = 1; candidates.length < targetAttempts && suffix <= targetAttempts * 2; suffix += 1) {
    candidates.push(trimUsernameCandidate(`${base}${suffix}`));
  }

  while (candidates.length < targetAttempts) {
    candidates.push(trimUsernameCandidate(`${base}${crypto.randomInt(10, 99999)}`));
  }

  return uniqueValues(candidates).slice(0, targetAttempts);
}

function randomCharacter(characters) {
  return characters[crypto.randomInt(0, characters.length)];
}

function shuffleString(value) {
  const characters = value.split('');

  for (let index = characters.length - 1; index > 0; index -= 1) {
    const swapIndex = crypto.randomInt(0, index + 1);
    [characters[index], characters[swapIndex]] = [characters[swapIndex], characters[index]];
  }

  return characters.join('');
}

function generateAccountPassword(length = 24) {
  const requestedLength = Number(length);
  const targetLength = Number.isFinite(requestedLength)
    ? Math.max(16, Math.floor(requestedLength))
    : 24;
  const categories = [
    'abcdefghijkmnopqrstuvwxyz',
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    '23456789',
    '!@#$%^&*_-+=?',
  ];
  const characters = categories.map(randomCharacter);
  const allCharacters = categories.join('');

  while (characters.length < targetLength) {
    characters.push(randomCharacter(allCharacters));
  }

  return shuffleString(characters.join(''));
}

module.exports = {
  generateAccountPassword,
  generateUsernameCandidates,
  trimUsernameCandidate,
  usernameFromSubject,
};
