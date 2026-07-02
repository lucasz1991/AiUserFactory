'use strict';

const { captureTaskPreview, startTaskPreview } = require('../lib/preview.cjs');
const { fillFirstMatchingInput } = require('../lib/fill_input.cjs');
const {
  clickFirstVisibleElement,
  elementCandidatesFromInput,
} = require('../lib/find_visible_element.cjs');
const {
  maxAgeSeconds,
  normalizeText,
  optionBoolean,
  optionNumber,
  optionString,
  scalarInputValue,
  scanMailList,
  setWorkflowVariable,
  stringListFrom,
  taskOptions,
  valueFromPath,
  variableName,
  wait,
  workflowVariableRoot,
} = require('../lib/mail_list.cjs');

function candidateKey(candidate = {}) {
  return [
    candidate.subject || '',
    candidate.sender || '',
    candidate.dateText || '',
    String(candidate.text || '').slice(0, 240),
  ].join('|').toLowerCase();
}

function textMatches(value, filters = []) {
  const text = normalizeText(value).toLowerCase();

  return filters.some((filter) => text.includes(normalizeText(filter).toLowerCase()));
}

function filterMatches(candidate = {}, subjectFilters = [], titleFilters = []) {
  if (subjectFilters.length === 0 && titleFilters.length === 0) {
    return true;
  }

  if (subjectFilters.length > 0 && textMatches(candidate.subject || candidate.text, subjectFilters)) {
    return true;
  }

  if (titleFilters.length > 0 && textMatches(candidate.title || candidate.text, titleFilters)) {
    return true;
  }

  return false;
}

function valueAsSearchText(value) {
  if (value === undefined || value === null) {
    return '';
  }

  if (typeof value === 'string') {
    return normalizeText(value);
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  try {
    return normalizeText(JSON.stringify(value));
  } catch {
    return normalizeText(value);
  }
}

function resolveSearchValue(context = {}, configuredValue = '') {
  const configured = normalizeText(configuredValue);

  if (!configured) {
    return '';
  }

  const root = workflowVariableRoot(context);
  const candidatePaths = Array.from(new Set([
    configured,
    configured.replace(/^workflowVariables\./, 'workflow_variables.'),
    configured.replace(/^workflow_variables\./, 'workflowVariables.'),
    `workflow_variables.${configured}`,
    `workflowVariables.${configured}`,
    `lastResult.${configured}`,
  ]));

  for (const path of candidatePaths) {
    const resolved = valueFromPath(root, path);
    const text = valueAsSearchText(resolved);

    if (text !== '') {
      return text;
    }
  }

  return configured;
}

async function refreshActivePage(context = {}, fallbackPage = null) {
  if (typeof context.refreshActivePage !== 'function') {
    return fallbackPage;
  }

  const refreshedPage = await context.refreshActivePage().catch(() => null);

  return refreshedPage || fallbackPage;
}

async function applyWebmailSearch(page, context = {}, config = {}) {
  const searchInputSelector = normalizeText(config.searchInputSelector);
  const configuredSearchValue = normalizeText(config.configuredSearchValue);
  const searchValue = normalizeText(config.searchValue);
  const searchButtonSelector = normalizeText(config.searchButtonSelector);
  const searchWaitMs = Math.max(0, Math.min(30000, Number(config.searchWaitMs || 0)));
  const timeoutMs = Math.max(500, Math.min(120000, Number(config.timeoutMs || 12000)));
  const enabled = searchInputSelector !== '' && searchValue !== '';
  const debug = {
    enabled,
    ok: !enabled,
    status: enabled ? 'pending' : 'inactive',
    statusMessage: enabled
      ? ''
      : (searchInputSelector || configuredSearchValue
        ? 'Webmail-Suche nicht aktiv: Suchfeld-Selector und Suchwert muessen gesetzt sein.'
        : 'Webmail-Suche nicht konfiguriert.'),
    status_message: enabled
      ? ''
      : (searchInputSelector || configuredSearchValue
        ? 'Webmail-Suche nicht aktiv: Suchfeld-Selector und Suchwert muessen gesetzt sein.'
        : 'Webmail-Suche nicht konfiguriert.'),
    searchInputSelector,
    search_input_selector: searchInputSelector,
    configuredSearchValue,
    configured_search_value: configuredSearchValue,
    searchValue: enabled ? searchValue : '',
    search_value: enabled ? searchValue : '',
    searchButtonSelector,
    search_button_selector: searchButtonSelector,
    searchWaitMs,
    search_wait_ms: searchWaitMs,
    submitMode: searchButtonSelector ? 'button' : 'enter',
    submit_mode: searchButtonSelector ? 'button' : 'enter',
  };

  if (!enabled) {
    return { enabled: false, ok: true, debug, page };
  }

  const fillResult = await fillFirstMatchingInput(
    page,
    [searchInputSelector],
    searchValue,
    timeoutMs,
    { context },
  );

  debug.fillResult = fillResult;
  debug.fill_result = fillResult;

  if (!fillResult?.ok) {
    debug.ok = false;
    debug.status = 'failed';
    debug.statusMessage = 'Webmail-Suchfeld wurde nicht gefunden oder konnte nicht gefuellt werden.';
    debug.status_message = debug.statusMessage;

    return { enabled: true, ok: false, debug, page };
  }

  if (searchButtonSelector !== '') {
    const submitResult = await clickFirstVisibleElement(
      page,
      elementCandidatesFromInput({ selector: searchButtonSelector }, {
        textKeys: ['text', 'value', 'label'],
      }),
      timeoutMs,
      { context },
    );

    debug.submitResult = submitResult;
    debug.submit_result = submitResult;

    if (!submitResult) {
      debug.ok = false;
      debug.status = 'failed';
      debug.statusMessage = 'Webmail-Suchen-Button wurde nicht gefunden oder konnte nicht geklickt werden.';
      debug.status_message = debug.statusMessage;

      return { enabled: true, ok: false, debug, page };
    }
  } else if (page?.keyboard && typeof page.keyboard.press === 'function') {
    await page.keyboard.press('Enter').catch((error) => {
      debug.enterError = error.message;
      debug.enter_error = error.message;
    });
    debug.enterPressed = !debug.enterError;
    debug.enter_pressed = !debug.enterError;
  } else {
    debug.ok = false;
    debug.status = 'failed';
    debug.statusMessage = 'Webmail-Suche konnte nicht abgeschickt werden, weil kein Button konfiguriert ist und Enter nicht verfuegbar war.';
    debug.status_message = debug.statusMessage;

    return { enabled: true, ok: false, debug, page };
  }

  if (debug.enterError) {
    debug.ok = false;
    debug.status = 'failed';
    debug.statusMessage = `Webmail-Suche konnte per Enter nicht abgeschickt werden: ${debug.enterError}`;
    debug.status_message = debug.statusMessage;

    return { enabled: true, ok: false, debug, page };
  }

  if (searchWaitMs > 0) {
    await wait(searchWaitMs);
  }

  const refreshedPage = await refreshActivePage(context, page);

  debug.ok = true;
  debug.status = 'applied';
  debug.statusMessage = 'Webmail-Suche wurde ausgefuehrt.';
  debug.status_message = debug.statusMessage;

  return { enabled: true, ok: true, debug, page: refreshedPage };
}

function secondsLabel(seconds) {
  if (!Number.isFinite(Number(seconds))) {
    return 'unbekannt';
  }

  const value = Math.max(0, Number(seconds));

  if (value < 60) {
    return `${Math.round(value)}s`;
  }

  if (value < 3600) {
    return `${Math.round(value / 60)}m`;
  }

  if (value < 86400) {
    return `${Math.round(value / 3600)}h`;
  }

  return `${Math.round(value / 86400)}d`;
}

function candidateIds(candidate = {}) {
  return [candidate.mailId, candidate.mail_id, candidate.token, candidate.index]
    .map((value) => normalizeText(value).toLowerCase())
    .filter(Boolean);
}

function idMatchesCandidate(candidate = {}, mailIds = []) {
  if (mailIds.length === 0) {
    return true;
  }

  const ids = candidateIds(candidate);

  return mailIds.some((mailId) => ids.includes(normalizeText(mailId).toLowerCase()));
}

function candidateDebug(candidate = {}, filters = {}) {
  const ageNumber = Number(candidate.ageSeconds);
  const hasAge = candidate.ageSeconds !== null
    && candidate.ageSeconds !== undefined
    && Number.isFinite(ageNumber);
  const maximumAgeSeconds = Number(filters.maximumAgeSeconds || 0);
  const idAccepted = idMatchesCandidate(candidate, filters.mailIds || []);
  const ageAccepted = maximumAgeSeconds <= 0
    || (hasAge ? ageNumber <= maximumAgeSeconds : filters.includeUnknownAge === true);
  const textAccepted = filterMatches(candidate, filters.subjectFilters || [], filters.titleFilters || []);
  const subjectTitleFiltersIgnored = filters.subjectTitleFiltersIgnored === true;
  const accepted = idAccepted && ageAccepted && textAccepted;
  const reasons = [];

  if (!idAccepted) {
    reasons.push('Mail-ID passt nicht');
  }

  if (maximumAgeSeconds > 0) {
    if (!hasAge && filters.includeUnknownAge === true) {
      reasons.push('Zeit unbekannt, aber erlaubt');
    } else if (!hasAge) {
      reasons.push('Zeit unbekannt');
    } else if (ageNumber > maximumAgeSeconds) {
      reasons.push(`zu alt: ${secondsLabel(ageNumber)} > ${secondsLabel(maximumAgeSeconds)}`);
    } else {
      reasons.push(`Zeit passt: ${secondsLabel(ageNumber)} <= ${secondsLabel(maximumAgeSeconds)}`);
    }
  } else {
    reasons.push('kein Zeitfilter aktiv');
  }

  if (subjectTitleFiltersIgnored) {
    reasons.push('Betreff-/Titelfilter durch Webmail-Suche ignoriert');
  } else if (!textAccepted) {
    reasons.push('Betreff-/Titelfilter passt nicht');
  } else if ((filters.subjectFilters || []).length > 0 || (filters.titleFilters || []).length > 0) {
    reasons.push('Betreff-/Titelfilter passt');
  }

  return {
    accepted,
    idAccepted,
    id_accepted: idAccepted,
    ageAccepted,
    age_accepted: ageAccepted,
    textAccepted,
    text_accepted: textAccepted,
    reason: reasons.join('; '),
    mailId: candidate.mailId || candidate.mail_id || '',
    mail_id: candidate.mail_id || candidate.mailId || '',
    token: candidate.token || '',
    dateText: candidate.dateText || '',
    date_text: candidate.dateText || '',
    receivedAt: candidate.receivedAt || candidate.received_at || null,
    received_at: candidate.received_at || candidate.receivedAt || null,
    receivedAtBrowser: candidate.receivedAtBrowser || candidate.received_at_browser || null,
    received_at_browser: candidate.received_at_browser || candidate.receivedAtBrowser || null,
    browserTimezone: candidate.receivedAtBrowserTimezone || candidate.received_at_browser_timezone || null,
    browser_timezone: candidate.received_at_browser_timezone || candidate.receivedAtBrowserTimezone || null,
    browserGmtOffsetHours: candidate.browserGmtOffsetHours ?? candidate.browser_gmt_offset_hours ?? null,
    browser_gmt_offset_hours: candidate.browser_gmt_offset_hours ?? candidate.browserGmtOffsetHours ?? null,
    sourceGmtOffsetHours: candidate.sourceGmtOffsetHours ?? candidate.source_gmt_offset_hours ?? null,
    source_gmt_offset_hours: candidate.source_gmt_offset_hours ?? candidate.sourceGmtOffsetHours ?? null,
    dateParseKind: candidate.dateParseKind || candidate.date_parse_kind || '',
    date_parse_kind: candidate.date_parse_kind || candidate.dateParseKind || '',
    ageSeconds: hasAge ? ageNumber : null,
    age_seconds: hasAge ? ageNumber : null,
    ageLabel: hasAge ? secondsLabel(ageNumber) : 'unbekannt',
    age_label: hasAge ? secondsLabel(ageNumber) : 'unbekannt',
    subjectTitleFiltersIgnored,
    subject_title_filters_ignored: subjectTitleFiltersIgnored,
    subject: candidate.subject || '',
    title: candidate.title || '',
    sender: candidate.sender || '',
    preview: candidate.preview || '',
    text: String(candidate.text || '').slice(0, 240),
    frameUrl: candidate.frameUrl || '',
    frame_url: candidate.frameUrl || '',
    top: candidate.top ?? null,
  };
}

async function publishMailScanDebug(context = {}, debug = {}) {
  context.mailListScanDebug = debug;
  context.mail_list_scan_debug = debug;

  const pages = []
    .concat(context.pages || [])
    .concat(context.page ? [context.page] : [])
    .filter(Boolean);
  const seenFrames = new Set();

  for (const page of pages) {
    const frames = typeof page.frames === 'function' ? page.frames() : [page];

    for (const frame of frames) {
      if (!frame || typeof frame.evaluate !== 'function' || seenFrames.has(frame)) {
        continue;
      }

      seenFrames.add(frame);

      await frame.evaluate((payload) => {
        window.__workflowMailListScanDebug = payload;
        window.__workflowDebug = {
          ...(window.__workflowDebug || {}),
          mailListScan: payload,
        };
      }, debug).catch(() => null);
    }
  }
}

async function run(context = {}) {
  let page = context.page;
  const input = context.input || {};
  const options = taskOptions(input);
  const scalarValue = scalarInputValue(input);
  const listSelector = optionString(options, input, ['list_selector', 'listSelector'], normalizeText(input.selector || input.elementSelector || input.element_selector));
  const listItemSelector = optionString(options, input, ['list_item_selector', 'listItemSelector', 'item_selector', 'itemSelector'], scalarValue);
  const outputArrayName = variableName(optionString(options, input, ['output_array_name', 'outputArrayName', 'output_name', 'outputName'], 'inbox_mails'), 'inbox_mails');
  const maximumAgeSeconds = maxAgeSeconds(options, input, 0);
  const includeUnknownAge = optionBoolean(options, input, ['include_unknown_age', 'includeUnknownAge'], maximumAgeSeconds <= 0);
  const maxItems = Math.max(1, Math.min(200, optionNumber(options, input, ['max_items', 'maxItems', 'limit'], 50)));
  const waitForNewMailSeconds = Math.max(0, Math.min(3600, optionNumber(options, input, ['wait_for_new_mail_seconds', 'waitForNewMailSeconds', 'wait_seconds', 'waitSeconds'], 0)));
  const mailTimeGmtOffsetHours = Math.max(-14, Math.min(14, optionNumber(options, input, [
    'mail_time_gmt_offset_hours',
    'mailTimeGmtOffsetHours',
    'source_gmt_offset_hours',
    'sourceGmtOffsetHours',
  ], 0)));
  const subjectFilters = stringListFrom(optionString(options, input, ['subject_filter', 'subjectFilter', 'subject_must_contain', 'subjectMustContain'], ''), []);
  const titleFilters = stringListFrom(optionString(options, input, ['title_filter', 'titleFilter', 'title_must_contain', 'titleMustContain'], ''), []);
  const mailIds = stringListFrom(optionString(options, input, ['mail_ids', 'mailIds', 'message_ids', 'messageIds'], ''), []);
  const searchInputSelector = optionString(options, input, [
    'search_input_selector',
    'searchInputSelector',
    'search_field_selector',
    'searchFieldSelector',
  ], '');
  const configuredSearchValue = optionString(options, input, [
    'search_value',
    'searchValue',
    'search_field_value',
    'searchFieldValue',
    'search_text',
    'searchText',
  ], '');
  const searchValue = resolveSearchValue(context, configuredSearchValue);
  const searchButtonSelector = optionString(options, input, [
    'search_button_selector',
    'searchButtonSelector',
    'search_submit_selector',
    'searchSubmitSelector',
  ], '');
  const searchWaitMs = Math.max(0, Math.min(30000, optionNumber(options, input, ['search_wait_ms', 'searchWaitMs'], 1200)));
  const searchTimeoutMs = Math.max(500, Math.min(120000, optionNumber(options, input, ['search_timeout_ms', 'searchTimeoutMs'], 12000)));

  if (!page || (typeof page.frames !== 'function' && typeof page.evaluate !== 'function')) {
    return { ok: false, status: 'failed', statusMessage: 'Kein Page-Handle fuer den Mail-Inbox-Scan vorhanden.' };
  }

  startTaskPreview(context);

  const searchResult = await applyWebmailSearch(page, context, {
    searchInputSelector,
    configuredSearchValue,
    searchValue,
    searchButtonSelector,
    searchWaitMs,
    timeoutMs: searchTimeoutMs,
  });
  page = searchResult.page || page;
  context.page = page;

  const webmailSearchDebug = searchResult.debug || {};
  const subjectTitleFiltersIgnored = searchResult.enabled === true && searchResult.ok === true;
  const effectiveSubjectFilters = subjectTitleFiltersIgnored ? [] : subjectFilters;
  const effectiveTitleFilters = subjectTitleFiltersIgnored ? [] : titleFilters;
  const filterConfig = {
    maximumAgeSeconds,
    includeUnknownAge,
    subjectFilters: effectiveSubjectFilters,
    titleFilters: effectiveTitleFilters,
    mailIds,
    subjectTitleFiltersIgnored,
  };
  const baseDebug = {
    task: 'mail.inbox_list_scan',
    listSelector,
    list_selector: listSelector,
    listItemSelector,
    list_item_selector: listItemSelector,
    outputArrayName,
    output_array_name: outputArrayName,
    maxItems,
    max_items: maxItems,
    maxAgeSeconds: maximumAgeSeconds || null,
    max_age_seconds: maximumAgeSeconds || null,
    maxAgeMinutes: maximumAgeSeconds > 0 ? Math.round((maximumAgeSeconds / 60) * 100) / 100 : null,
    max_age_minutes: maximumAgeSeconds > 0 ? Math.round((maximumAgeSeconds / 60) * 100) / 100 : null,
    includeUnknownAge,
    include_unknown_age: includeUnknownAge,
    subjectFilters: effectiveSubjectFilters,
    subject_filters: effectiveSubjectFilters,
    titleFilters: effectiveTitleFilters,
    title_filters: effectiveTitleFilters,
    configuredSubjectFilters: subjectFilters,
    configured_subject_filters: subjectFilters,
    configuredTitleFilters: titleFilters,
    configured_title_filters: titleFilters,
    subjectTitleFiltersIgnored,
    subject_title_filters_ignored: subjectTitleFiltersIgnored,
    mailIds,
    mail_ids: mailIds,
    waitForNewMailSeconds,
    wait_for_new_mail_seconds: waitForNewMailSeconds,
    mailTimeGmtOffsetHours,
    mail_time_gmt_offset_hours: mailTimeGmtOffsetHours,
    webmailSearchEnabled: searchResult.enabled === true,
    webmail_search_enabled: searchResult.enabled === true,
    webmailSearch: webmailSearchDebug,
    webmail_search: webmailSearchDebug,
  };
  let latestDebug = {
    ...baseDebug,
    scannedAt: new Date().toISOString(),
    scanned_at: new Date().toISOString(),
    pollCount: 0,
    poll_count: 0,
    totalCandidates: 0,
    total_candidates: 0,
    acceptedCandidates: 0,
    accepted_candidates: 0,
    candidates: [],
  };

  if (searchResult.enabled === true && searchResult.ok !== true) {
    latestDebug = {
      ...latestDebug,
      status: 'failed',
      statusMessage: webmailSearchDebug.statusMessage || 'Webmail-Suche konnte nicht ausgefuehrt werden.',
      status_message: webmailSearchDebug.status_message || webmailSearchDebug.statusMessage || 'Webmail-Suche konnte nicht ausgefuehrt werden.',
    };

    await publishMailScanDebug(context, latestDebug);

    return captureTaskPreview(context, {
      ok: false,
      status: 'failed',
      statusMessage: latestDebug.statusMessage,
      status_message: latestDebug.statusMessage,
      mailListScanDebug: latestDebug,
      mail_list_scan_debug: latestDebug,
      workflowVariables: context.workflowVariables,
      workflow_variables: context.workflow_variables,
    }, true);
  }

  let pollCount = 0;

  const collectFiltered = async () => {
    const candidates = await scanMailList(page, {
      ...options,
      list_selector: listSelector,
      list_item_selector: listItemSelector,
      max_items: maxItems,
      mail_time_gmt_offset_hours: mailTimeGmtOffsetHours,
    });
    const evaluatedCandidates = candidates.map((candidate) => ({
      candidate,
      debug: candidateDebug(candidate, filterConfig),
    }));
    const accepted = evaluatedCandidates
      .filter((entry) => entry.debug.accepted)
      .map((entry) => entry.candidate);
    const scannedAt = new Date();
    const acceptedSince = maximumAgeSeconds > 0
      ? new Date(scannedAt.getTime() - (maximumAgeSeconds * 1000))
      : null;

    latestDebug = {
      ...baseDebug,
      scannedAt: scannedAt.toISOString(),
      scanned_at: scannedAt.toISOString(),
      acceptedSince: acceptedSince ? acceptedSince.toISOString() : null,
      accepted_since: acceptedSince ? acceptedSince.toISOString() : null,
      pollCount,
      poll_count: pollCount,
      totalCandidates: candidates.length,
      total_candidates: candidates.length,
      acceptedCandidates: accepted.length,
      accepted_candidates: accepted.length,
      rejectedCandidates: candidates.length - accepted.length,
      rejected_candidates: candidates.length - accepted.length,
      candidates: evaluatedCandidates.map((entry) => entry.debug).slice(0, 30),
      foundDateTexts: evaluatedCandidates
        .map((entry) => entry.debug.dateText)
        .filter(Boolean)
        .slice(0, 30),
      found_date_texts: evaluatedCandidates
        .map((entry) => entry.debug.dateText)
        .filter(Boolean)
        .slice(0, 30),
    };

    await publishMailScanDebug(context, latestDebug);
    await captureTaskPreview(context, {
      status: 'running',
      statusMessage: `${accepted.length}/${candidates.length} Mail-Listeneintraege passen zum aktuellen Filter.`,
      mailListScanDebug: latestDebug,
      mail_list_scan_debug: latestDebug,
    }, true).catch(() => null);

    return accepted;
  };

  const byKey = new Map();

  for (const candidate of await collectFiltered()) {
    byKey.set(candidateKey(candidate), candidate);
  }

  const initialCount = byKey.size;
  const deadline = Date.now() + (waitForNewMailSeconds * 1000);

  while (waitForNewMailSeconds > 0 && Date.now() < deadline) {
    await wait(Math.min(5000, Math.max(0, deadline - Date.now())));
    pollCount += 1;

    const before = byKey.size;

    for (const candidate of await collectFiltered()) {
      byKey.set(candidateKey(candidate), candidate);
    }

    if (byKey.size > before && byKey.size > initialCount) {
      break;
    }
  }

  const filtered = Array.from(byKey.values())
    .slice(0, maxItems)
    .map((candidate, index) => ({ ...candidate, index }));

  setWorkflowVariable(context, outputArrayName, filtered);

  return captureTaskPreview(context, {
    ok: true,
    status: 'success',
    statusMessage: `${filtered.length} Mail-Listeneintraege wurden ermittelt.`,
    outputArrayName,
    output_array_name: outputArrayName,
    candidateCount: filtered.length,
    candidate_count: filtered.length,
    maxAgeSeconds: maximumAgeSeconds || null,
    max_age_seconds: maximumAgeSeconds || null,
    listSelector,
    list_selector: listSelector,
    listItemSelector,
    list_item_selector: listItemSelector,
    subjectFilters: effectiveSubjectFilters,
    subject_filters: effectiveSubjectFilters,
    titleFilters: effectiveTitleFilters,
    title_filters: effectiveTitleFilters,
    configuredSubjectFilters: subjectFilters,
    configured_subject_filters: subjectFilters,
    configuredTitleFilters: titleFilters,
    configured_title_filters: titleFilters,
    subjectTitleFiltersIgnored,
    subject_title_filters_ignored: subjectTitleFiltersIgnored,
    mailIds,
    mail_ids: mailIds,
    waitForNewMailSeconds,
    wait_for_new_mail_seconds: waitForNewMailSeconds,
    mailTimeGmtOffsetHours,
    mail_time_gmt_offset_hours: mailTimeGmtOffsetHours,
    webmailSearchEnabled: searchResult.enabled === true,
    webmail_search_enabled: searchResult.enabled === true,
    webmailSearch: webmailSearchDebug,
    webmail_search: webmailSearchDebug,
    pollCount,
    poll_count: pollCount,
    mailListScanDebug: {
      ...latestDebug,
      finalCandidateCount: filtered.length,
      final_candidate_count: filtered.length,
    },
    mail_list_scan_debug: {
      ...latestDebug,
      finalCandidateCount: filtered.length,
      final_candidate_count: filtered.length,
    },
    workflowVariables: context.workflowVariables,
    workflow_variables: context.workflow_variables,
  }, true);
}

module.exports = { key: 'mail.inbox_list_scan', run };
