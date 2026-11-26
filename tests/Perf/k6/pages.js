// SPDX-License-Identifier: AGPL-3.0-only

// Times the pages of tests/Perf/pages.json against a running Foresi, seeded by
// tests/Perf/seed.php: first one person clicking through every page, then ten people at
// once. bin/perf starts it; see docs/performance.md.
//
//     k6 run -e BASE_URL=http://127.0.0.1:8080 -e SEED=var/perf/seed.json tests/Perf/k6/pages.js
//
// A page whose p95 breaks its budget makes k6 exit non-zero (k6 thresholds).

import http from 'k6/http';
import { check, fail } from 'k6';
import exec from 'k6/execution';

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8080';
const seed = JSON.parse(open(__ENV.SEED || '../../../var/perf/seed.json'));
const pages = JSON.parse(open('../pages.json')).pages;
const password = __ENV.PERF_PASSWORD || 'perf-password-1';
// One pass of one person reads every page this many times.
const ROUNDS = Number(__ENV.ROUNDS || 20);
// Budgets of the approved design: web.dev's "good" time to first byte for one person, its
// "poor" line for ten (https://web.dev/articles/ttfb).
const ONE_P95 = Number(__ENV.ONE_P95 || 800);
const TEN_P95 = Number(__ENV.TEN_P95 || 1800);

const samples = {
    contact: seed.samples.contact,
    invoice: seed.samples.invoice,
    project: seed.samples.project,
    task: seed.samples.task,
    deal: seed.samples.deal,
    receivedDocument: seed.samples.received_document,
};
const ownerEmail = seed.owner.email;
// A read token of the owner, issued by bin/perf for each run (app:api-token:issue).
const apiToken = __ENV.API_TOKEN || '';

function pathOf(page) {
    return page.path.replace(/\{(\w+)\}/g, (_, key) => {
        if (!samples[key]) {
            fail(`The seed has no sample ${key} for ${page.name}.`);
        }
        return samples[key];
    });
}

// SCENARIO=one: one person reads every page ROUNDS times. SCENARIO=ten: ten people at once
// for TEN_DURATION, each reading the pages in turn. bin/perf runs one, then ten, so their
// numbers never mix.
const SCENARIO = __ENV.SCENARIO || 'one';
const thresholds = { checks: ['rate==1'] };
if (SCENARIO === 'one') {
    for (const page of pages) {
        thresholds[`http_req_duration{page:${page.name}}`] = [`p(95)<${ONE_P95}`];
    }
} else {
    thresholds['http_req_duration{expected_response:true}'] = [`p(95)<${TEN_P95}`];
}

export const options = {
    scenarios: {
        [SCENARIO]: SCENARIO === 'one'
            ? { executor: 'per-vu-iterations', vus: 1, iterations: ROUNDS, maxDuration: '20m' }
            : { executor: 'constant-vus', vus: 10, duration: __ENV.TEN_DURATION || '60s', gracefulStop: '10s' },
    },
    thresholds,
    summaryTrendStats: ['min', 'med', 'p(95)', 'max', 'count'],
    // Each person keeps the session cookie of their own sign-in.
    noCookiesReset: true,
};

const PEOPLE = SCENARIO === 'one' ? 1 : 10;

function signIn() {
    const jar = new http.CookieJar();
    const form = http.get(`${BASE_URL}/login`, { jar, tags: { page: 'sign-in' } });
    const token = form.html().find('input[name="_csrf_token"]').attr('value');
    const response = http.post(`${BASE_URL}/login`, { _username: ownerEmail, _password: password, _csrf_token: token }, { jar, tags: { page: 'sign-in' } });
    if (!check(response, { 'signed in': (r) => r.status === 200 && !r.url.endsWith('/login') })) {
        fail(`Sign-in as ${ownerEmail} failed: ${response.status} ${response.url}`);
    }
    return jar.cookiesForURL(BASE_URL);
}

// Each person signs in once, one after another: the sign-in throttle allows five attempts a
// minute and starts over after each success, so ten people signing in at once would be refused.
export function setup() {
    const sessions = [];
    for (let person = 0; person < PEOPLE; ++person) {
        sessions.push(signIn());
    }
    return { sessions };
}

let signedIn = false;

export default function (data) {
    if (!signedIn) {
        const cookies = data.sessions[(exec.vu.idInTest - 1) % data.sessions.length];
        const jar = http.cookieJar();
        for (const [name, values] of Object.entries(cookies)) {
            jar.set(BASE_URL, name, values[0]);
        }
        signedIn = true;
    }
    const list = SCENARIO === 'ten' ? [pages[exec.scenario.iterationInTest % pages.length]] : pages;
    for (const page of list) {
        const params = { tags: { page: page.name }, redirects: 0 };
        if (page.api) {
            if (!apiToken) {
                fail(`Set API_TOKEN to time ${page.name}.`);
            }
            params.headers = { Authorization: `Bearer ${apiToken}` };
            params.jar = new http.CookieJar();
        }
        const response = http.get(`${BASE_URL}${pathOf(page)}`, params);
        check(response, { [`${page.name} is 200`]: (r) => r.status === 200 });
    }
}

export function handleSummary(data) {
    const out = __ENV.OUT || `var/perf/pages-${SCENARIO}.json`;
    if (SCENARIO === 'ten') {
        const all = data.metrics['http_req_duration{expected_response:true}'];
        const result = { people: 10, budgetP95Ms: TEN_P95, ...(all ? all.values : {}), requestsPerSecond: data.metrics.http_reqs ? data.metrics.http_reqs.values.rate : null, checksPassed: data.metrics.checks ? data.metrics.checks.values.rate : null };
        return { [out]: JSON.stringify(result, null, 2), stdout: `ten people: med ${result.med.toFixed(0)} ms, p95 ${result['p(95)'].toFixed(0)} ms, ${result.requestsPerSecond.toFixed(1)} requests/s\n` };
    }
    const rows = [];
    for (const page of pages) {
        const metric = data.metrics[`http_req_duration{page:${page.name}}`];
        if (metric) {
            rows.push({ page: page.name, path: page.path, ...metric.values, ok: Object.values(metric.thresholds || {}).every((t) => t.ok) });
        }
    }
    const result = { people: 1, budgetP95Ms: ONE_P95, pages: rows, checksPassed: data.metrics.checks ? data.metrics.checks.values.rate : null };
    return {
        [out]: JSON.stringify(result, null, 2),
        stdout: `${rows.map((r) => `${r.page.padEnd(20)} med ${r.med.toFixed(0).padStart(5)} ms  p95 ${r['p(95)'].toFixed(0).padStart(5)} ms${r.ok ? '' : '  OVER BUDGET'}`).join('\n')}\n`,
    };
}
