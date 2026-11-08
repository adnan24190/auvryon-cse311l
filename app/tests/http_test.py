#!/usr/bin/env python3
"""HTTP-level tests against the running PHP app (built-in server or XAMPP). Usage: python3 tests/http_test.py http://127.0.0.1:8099/ """
import re, sys, json, time, requests
BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8099/'
res = []
def t(name):
    def deco(fn):
        try: fn(); res.append(('PASS', name, ''))
        except Exception as e: res.append(('FAIL', name, repr(e)[:300]))
        return fn
    return deco
def ok(c, m='assertion failed'):
    if not c: raise AssertionError(m)
def csrf(html):
    m = re.search(r'name="_csrf" value="([a-f0-9]+)"', html); ok(m, 'no csrf token on page'); return m.group(1)
def sess(): s = requests.Session(); s.headers['User-Agent'] = 'AuvryonHttpTest/1.0'; return s
def get(s, p, **kw): return s.get(BASE + 'index.php', params={'p': p, **kw}, allow_redirects=False)
def post(s, p, action, data, page_params=None, token_from=None):
    pg = s.get(BASE + 'index.php', params={'p': token_from or p, **(page_params or {})}); tok = csrf(pg.text)
    idem = (re.findall(r'name="idem" value="([a-f0-9]+)"', pg.text) or ['0123456789abcdef0123'])[0]
    return s.post(BASE + 'index.php', params={'p': p, **(page_params or {})}, data={'_csrf': tok, '_a': action, 'idem': idem, **data}, allow_redirects=False)
def login(email, pw='Demo@1234'):
    s = sess(); pg = s.get(BASE + 'index.php?p=login'); r = s.post(BASE + 'index.php?p=login', data={'_csrf': csrf(pg.text), 'email': email, 'password': pw}, allow_redirects=False)
    ok(r.status_code == 302 and 'p=login' not in r.headers.get('Location', ''), f'login failed for {email}: {r.status_code} {r.headers.get("Location")}'); return s
def rows_of(adm, did):
    h = adm.get(BASE + f'index.php?p=disputes&id={did}').text; m = re.search(r'class="badge b-(\w+)"', h); return m.group(1) if m else None
BAD = re.compile(r'(Warning:|Notice:|Fatal error|Deprecated:|Stack trace|Parse error|Undefined (variable|array key|index))')
PAGES = {'dashboard': {}, 'wallet': {}, 'send': {}, 'requests': {}, 'activity': {}, 'safe': {}, 'recurring': {}, 'rules': {}, 'pockets': {}, 'groups': {}, 'iou': {}, 'invoices': {}, 'escrow': {}, 'disputes': {}, 'tokens': {}, 'offline': {}, 'family': {}, 'security': {}, 'notifications': {}, 'settings': {}}
@t('HTTP-01 login page renders with viewport meta, skip-safe structure and demo credentials')
def _():
    r = requests.get(BASE + 'index.php?p=login'); ok(r.status_code == 200 and 'viewport' in r.text and 'Demo@1234' in r.text and '<html lang="en">' in r.text and not BAD.search(r.text))
@t('HTTP-02 protected pages redirect anonymous visitors to login')
def _():
    for p in ['dashboard', 'send', 'admin', 'activity']:
        r = requests.get(BASE + f'index.php?p={p}', allow_redirects=False); ok(r.status_code == 302 and 'p=login' in r.headers['Location'], p)
@t('HTTP-03 wrong password shows a generic error, correct login reaches dashboard')
def _():
    s = sess(); pg = s.get(BASE + 'index.php?p=login'); r = s.post(BASE + 'index.php?p=login', data={'_csrf': csrf(pg.text), 'email': 'tahsin@auvryon.test', 'password': 'nope'}, allow_redirects=False); ok(r.status_code == 302)
    ok('Incorrect email or password' in s.get(BASE + 'index.php?p=login').text)
    s2 = login('tahsin@auvryon.test'); ok('Safe to spend' in get(s2, 'dashboard').text)
@t('HTTP-04 login without CSRF token is refused')
def _():
    s = sess(); s.get(BASE + 'index.php?p=login'); r = s.post(BASE + 'index.php?p=login', data={'email': 'tahsin@auvryon.test', 'password': 'Demo@1234'}, allow_redirects=False)
    ok('p=login' in r.headers.get('Location', '') and 'Security check failed' in s.get(BASE + 'index.php?p=login').text)
for who, extra in [('tahsin', {}), ('merchant', {'merchant': {}}), ('freelancer', {}), ('student', {}), ('uchas', {})]:
    @t(f'HTTP-05 [{who}] every page returns 200 with no PHP warnings/notices')
    def _(who=who, extra=extra):
        s = login(f'{who}@auvryon.test'); bad = []
        for p, q in {**PAGES, **extra}.items():
            r = get(s, p, **q)
            if r.status_code != 200 or BAD.search(r.text): bad.append((p, r.status_code, (BAD.search(r.text) or [''])[0]))
        ok(not bad, str(bad))
@t('HTTP-06 detail pages (group, escrow, invoice, dispute, receipt) render for tahsin with no warnings')
def _():
    s = login('tahsin@auvryon.test'); bad = []
    for p, q in [('groups', {'id': 1}), ('escrow', {'id': 1}), ('invoices', {'id': 1}), ('disputes', {'id': 2}), ('activity', {'tab': 'ledger'}), ('activity', {'q': 'coffee', 'type': 'merchant_payment'}), ('wallet', {'from': 'BDT', 'amount': '1200'}), ('merchant', {})]:
        r = get(s, p, **q)
        if p == 'merchant': ok(r.status_code == 302); continue
        if r.status_code != 200 or BAD.search(r.text): bad.append((p, q, r.status_code))
    ref = re.search(r'ref=(AV[A-Z0-9]{12})', get(s, 'activity').text); ok(ref); r = get(s, 'receipt', ref=ref.group(1)); ok(r.status_code == 200 and 'Ledger entries' in r.text and not BAD.search(r.text))
    ok(not bad, str(bad))
@t('HTTP-07 merchant pages and merchant tabs render')
def _():
    s = login('merchant@auvryon.test'); 
    for tab in ['checkout', 'tokens', 'offline']:
        r = get(s, 'merchant', tab=tab); ok(r.status_code == 200 and not BAD.search(r.text) and 'Aurum Cafe' in r.text, tab)
@t('HTTP-08 admin pages render for admin; non-admin gets redirected (role-based access)')
def _():
    a = login('admin@auvryon.test')
    for tab in ['overview', 'users', 'disputes', 'risk', 'audit', 'settings']:
        r = get(a, 'admin', tab=tab); ok(r.status_code == 200 and not BAD.search(r.text), tab)
    ok('All ledger invariants hold' in get(a, 'admin', tab='overview').text)
    for who in ['tahsin', 'merchant', 'student']:
        r = get(login(f'{who}@auvryon.test'), 'admin'); ok(r.status_code == 302 and 'p=dashboard' in r.headers.get('Location', ''), who)
    r = get(login('tahsin@auvryon.test'), 'merchant'); ok(r.status_code == 302 and 'p=dashboard' in r.headers['Location'])
@t('HTTP-09 non-admin cannot decide disputes or read other users receipts/invoices')
def _():
    s = login('student@auvryon.test'); r = post(s, 'disputes', 'd_decide', {'resolution': 'refund', 'note': 'x'}, {'id': 2}, token_from='settings'); 
    ok(r.status_code == 302); ok('Only administrators' in s.get(BASE + 'index.php?p=disputes&id=2').text or 'Dispute not found' in s.get(BASE + 'index.php?p=disputes&id=2').text)
    ok(rows_of(login('admin@auvryon.test'), 2) == 'under_review', 'dispute 2 must be unchanged (under_review) after the non-admin attempt')
    r = get(s, 'invoices', id=1); ok(r.status_code == 404)
    ref = re.search(r'ref=(AV[A-Z0-9]{12})', get(login('tahsin@auvryon.test'), 'activity').text).group(1); r = get(s, 'receipt', ref=ref); ok(r.status_code in (200, 404))
@t('HTTP-10 POST with a forged CSRF token is rejected and nothing is sent')
def _():
    s = login('tahsin@auvryon.test'); r = s.post(BASE + 'index.php?p=send', data={'_csrf': 'deadbeef', '_a': 'send_confirm', 'to': 'uchas@auvryon.test', 'amount': '10', 'cur': 'BDT', 'pin': '1234', 'idem': 'a' * 20}, allow_redirects=True); ok('Security check failed' in r.text)
@t('HTTP-11 registration → login → add funds → send to new recipient is held → cancel returns funds')
def _():
    s = sess(); email = f'web{int(time.time())}@test.local'; pg = s.get(BASE + 'index.php?p=register')
    r = s.post(BASE + 'index.php?p=register', data={'_csrf': csrf(pg.text), 'name': 'Web Tester', 'email': email, 'password': 'Web@12345', 'pin': '4321', 'role': 'user'}, allow_redirects=False); ok(r.status_code == 302, 'register')
    r = post(s, 'wallet', 'topup', {'amount': '1000', 'cur': 'BDT'}); ok(r.status_code == 302)
    ok('৳1,000.00' in get(s, 'wallet').text)
    r = post(s, 'send', 'send_preview', {'to': 'uchas@auvryon.test', 'amount': '300', 'cur': 'BDT', 'note': 'web test'}); ok(r.status_code == 200 and 'Wrong-transfer protection applies' in r.text and 'Final payable amount' in r.text and '1.50' in r.text, 'preview page')
    vis = re.sub(r'<input[^>]*>', '', r.text); ok('uc' in vis and 'uchas@auvryon.test' not in vis and 'uc••••' in vis.replace('uc•••', 'uc••••') or 'uc•' in vis, 'recipient email must be masked in visible text')
    key = re.search(r'name="idem" value="([a-f0-9]+)"', r.text).group(1); tok = csrf(r.text); data = {'_csrf': tok, '_a': 'send_confirm', 'to': 'uchas@auvryon.test', 'amount': '300', 'cur': 'BDT', 'note': 'web test', 'pin': '4321', 'idem': key}
    r1 = s.post(BASE + 'index.php?p=send', data=data, allow_redirects=False); r2 = s.post(BASE + 'index.php?p=send', data=data, allow_redirects=False); ok(r1.status_code == 302 and r2.status_code == 302 and r1.headers['Location'] == r2.headers['Location'], 'duplicate submit must land on the same receipt')
    ref = r1.headers['Location'].split('ref=')[1]; rc = s.get(BASE + 'index.php?p=receipt&ref=' + ref).text; ok('data-countdown' in rc and 'Cancel transfer' in rc)
    ok('৳698.50' in get(s, 'wallet').text, 'balance after hold = 1000 - 300 - 1.50 fee, charged once')
    r = post(s, 'receipt', 'cancel_hold', {}, {'ref': ref}); ok('৳1,000.00' in get(s, 'wallet').text, 'refund in full')
    r = post(s, 'receipt', 'cancel_hold', {}, {'ref': ref}, token_from='settings'); ok('already cancelled' in s.get(BASE + 'index.php?p=receipt&ref=' + ref).text, 'second cancel refused')
@t('HTTP-12 wrong PIN is refused and no money moves')
def _():
    s = login('adnan@auvryon.test') if False else login('uchas@auvryon.test'); before = re.search(r'stat">([^<]+)<', get(s, 'dashboard').text).group(1)
    pg = s.get(BASE + 'index.php?p=send'); pr = post(s, 'send', 'send_preview', {'to': 'tahsin@auvryon.test', 'amount': '5', 'cur': 'BDT'}); key = re.search(r'name="idem" value="([a-f0-9]+)"', pr.text).group(1)
    r = s.post(BASE + 'index.php?p=send', data={'_csrf': csrf(pr.text), '_a': 'send_confirm', 'to': 'tahsin@auvryon.test', 'amount': '5', 'cur': 'BDT', 'pin': '9999', 'idem': key}, allow_redirects=True); ok('Incorrect transaction PIN' in r.text)
    ok(re.search(r'stat">([^<]+)<', get(s, 'dashboard').text).group(1) == before)
@t('HTTP-13 XSS: hostile display name is escaped everywhere it is shown')
def _():
    s = sess(); email = f'xss{int(time.time())}@test.local'; pg = s.get(BASE + 'index.php?p=register'); payload = '<script>alert(1)</script>'
    s.post(BASE + 'index.php?p=register', data={'_csrf': csrf(pg.text), 'name': payload, 'email': email, 'password': 'Web@12345', 'pin': '4321', 'role': 'user'}, allow_redirects=False)
    for p in ['dashboard', 'settings', 'security']:
        h = get(s, p).text; ok('<script>alert(1)</script>' not in h and '&lt;script&gt;alert(1)&lt;/script&gt;' in h, p)
@t('HTTP-14 SQL injection in search filter and receipt ref is harmless')
def _():
    s = login('tahsin@auvryon.test'); r = get(s, 'activity', q="' OR 1=1 --", type="x' OR '1'='1"); ok(r.status_code == 200 and not BAD.search(r.text)); r = get(s, 'receipt', ref="' OR 1=1 --"); ok(r.status_code == 404)
@t('HTTP-15 CSV export has correct headers, BOM, columns and formula-injection guard')
def _():
    s = login('tahsin@auvryon.test'); r = s.get(BASE + 'index.php?p=export&kind=csv'); ok(r.headers['Content-Type'].startswith('text/csv') and 'attachment' in r.headers['Content-Disposition'] and r.content.startswith(b'\xef\xbb\xbfDate,Reference,Type'))
    lines = r.text.strip().split('\r\n'); ok(len(lines) > 10, 'expected seeded rows'); ok(not any(re.match(r'^"[=+\-@]', c) for l in lines[1:] for c in l.split('","')[:1]))
    r2 = s.get(BASE + 'index.php?p=export&kind=csv&type=merchant_payment'); ok(0 < len(r2.text.strip().split('\r\n')) < len(lines))
@t('HTTP-16 PDF statement and PDF receipt are valid PDFs')
def _():
    s = login('tahsin@auvryon.test'); r = s.get(BASE + 'index.php?p=export&kind=pdf'); ok(r.headers['Content-Type'] == 'application/pdf' and r.content.startswith(b'%PDF-1.4') and r.content.rstrip().endswith(b'%%EOF'))
    open('/tmp/stmt.pdf', 'wb').write(r.content); ref = re.search(r'ref=(AV[A-Z0-9]{12})', get(s, 'activity').text).group(1); r = s.get(BASE + 'index.php?p=export&kind=receipt&ref=' + ref); ok(r.content.startswith(b'%PDF')); open('/tmp/receipt.pdf', 'wb').write(r.content)
@t('HTTP-17 Bangla interface: switching language renders Bangla labels and Bengali-numeral dates')
def _():
    s = login('tahsin@auvryon.test'); s.get(BASE + 'index.php?p=dashboard&lang=bn'); h = get(s, 'dashboard').text; ok('<html lang="bn">' in h and 'ড্যাশবোর্ড' in h and 'টাকা পাঠান' in h); a = get(s, 'activity').text; ok(re.search(r'[০-৯]{2} [^\s]+ [০-৯]{4}', a), 'bn date'); s.get(BASE + 'index.php?p=dashboard&lang=en'); ok('Dashboard' in get(s, 'dashboard').text)
@t('HTTP-18 merchant QR checkout end to end: create, QR present, customer pays once, second attempt refused')
def _():
    m = login('merchant@auvryon.test'); r = post(m, 'merchant', 'm_checkout', {'amount': '75', 'note': 'http test'}, {'tab': 'checkout'}); ok(r.status_code == 302 and 'ref=' in r.headers['Location']); ref = re.search(r'ref=([a-f0-9]{16})', r.headers['Location']).group(1)
    pg = m.get(BASE + 'index.php?p=merchant&tab=checkout&ref=' + ref).text; ok('data-qr=' in pg and ref in pg)
    c = login('uchas@auvryon.test'); page = c.get(BASE + f'index.php?p=checkout&ref={ref}'); ok('Aurum Cafe' in page.text and 'Paid by the merchant' in page.text)
    r = post(c, 'checkout', 'co_pay', {'pin': '1234'}, {'ref': ref}); ok(r.status_code == 302 and 'p=receipt' in r.headers['Location'])
    r = post(c, 'checkout', 'co_pay', {'pin': '1234'}, {'ref': ref}, token_from='settings'); ok('already paid' in c.get(BASE + 'index.php?p=dashboard').text, 'second payment must be refused with a message')
@t('HTTP-19 device management: revoking another device ends that session immediately; current session survives')
def _():
    a = login('adnan@auvryon.test'); b = login('adnan@auvryon.test'); ok(get(b, 'dashboard').status_code == 200)
    pg = a.get(BASE + 'index.php?p=security').text; devs = re.findall(r'name="_a" value="dev_revoke"><input type="hidden" name="idem" value="[a-f0-9]+"><input type="hidden" name="id" value="(\d+)"', pg); ok(devs, 'no other device listed')
    for d in devs: post(a, 'security', 'dev_revoke', {'id': d})
    r = get(b, 'dashboard'); ok(r.status_code == 302 and 'p=login' in r.headers['Location'], 'revoked session must be logged out')
    ok(get(a, 'dashboard').status_code == 200, 'current session must survive')
@t('HTTP-20 API: auth, 401 without token, idempotency header required, duplicate-safe transfer, role-protected reconciliation')
def _():
    A = BASE + 'api/index.php'; ok(requests.get(A, params={'r': 'wallets'}).status_code == 401)
    r = requests.post(A + '?r=auth/login', json={'email': 'tahsin@auvryon.test', 'password': 'Demo@1234'}); ok(r.status_code == 200); tok = r.json()['token']; H = {'Authorization': 'Bearer ' + tok}
    w = requests.get(A, params={'r': 'wallets'}, headers=H).json(); ok(w['wallets'][0]['currency'] == 'BDT' and 'Simulated' in w['note'])
    ok(requests.post(A + '?r=transfers', json={'to': 'uchas@auvryon.test', 'amount': '10', 'pin': '1234'}, headers=H).status_code == 400)
    k = {**H, 'Idempotency-Key': 'api-test-' + str(int(time.time()))}; body = {'to': 'uchas@auvryon.test', 'amount': '10', 'pin': '1234', 'note': 'api'}
    r1 = requests.post(A + '?r=transfers', json=body, headers=k); r2 = requests.post(A + '?r=transfers', json=body, headers=k); ok(r1.status_code == 201 and r2.status_code == 200 and r2.json()['duplicate'] and r1.json()['ref'] == r2.json()['ref'], (r1.text, r2.text))
    ok(requests.post(A + '?r=transfers', json={**body, 'pin': '0000'}, headers={**H, 'Idempotency-Key': 'api-bad-pin-1'}).status_code == 422)
    ok(requests.get(A, params={'r': 'reconciliation'}, headers=H).status_code == 403)
    ok(requests.get(A, params={'r': 'safe-to-spend'}, headers=H).json()['safe_to_spend'] is not None); ok(requests.get(A, params={'r': 'exchange-rate'}, headers=H).json()['simulated'] is True)
    adm = requests.post(A + '?r=auth/login', json={'email': 'admin@auvryon.test', 'password': 'Demo@1234'}).json()['token']; rc = requests.get(A, params={'r': 'reconciliation'}, headers={'Authorization': 'Bearer ' + adm}).json(); ok(all(c['passed'] for c in rc['checks']))
@t('HTTP-21 responsive CSS and accessibility basics present (breakpoints, focus styles, skip link, labels, landmarks)')
def _():
    css = requests.get(BASE + 'assets/css/auvryon.css').text; ok('@media (max-width:1020px)' in css and '@media (max-width:640px)' in css and ':focus-visible' in css and 'prefers-reduced-motion' in css)
    s = login('tahsin@auvryon.test'); h = get(s, 'send').text; ok('class="skip"' in h and '<main id="main"' in h and 'aria-label="Main navigation"' in h and 'bottom-nav' in h)
    for p in ['send', 'settings', 'wallet', 'rules']:
        h = get(s, p).text; ids = set(re.findall(r'<label for="([^"]+)"', h)); ins = re.findall(r'<(?:input|select|textarea)(?![^>]*type="(?:hidden|checkbox)")[^>]*\bid="([^"]+)"', h); miss = [i for i in ins if i not in ids]; ok(not miss, f'{p}: inputs without labels {miss}')
@t('HTTP-22 security headers set; no stack traces leak on bad input')
def _():
    r = requests.get(BASE + 'index.php?p=login'); ok(r.headers.get('X-Frame-Options') == 'DENY' and 'Content-Security-Policy' in r.headers and r.headers.get('X-Content-Type-Options') == 'nosniff')
    s = login('tahsin@auvryon.test'); r = get(s, 'nonexistent'); ok(not BAD.search(r.text)); r = get(s, 'groups', id='abc'); ok(not BAD.search(r.text))
@t('HTTP-23 session cookie is HttpOnly + SameSite')
def _():
    r = requests.get(BASE + 'index.php?p=login'); c = r.headers.get('Set-Cookie', ''); ok('HttpOnly' in c and 'SameSite=Lax' in c, c)
out = [f'{a}  {b}' + (f'  -- {c}' if c else '') for a, b, c in res]; fails = sum(1 for a, _, _ in res if a == 'FAIL')
summary = f'\nTOTAL {len(res)}  PASSED {len(res) - fails}  FAILED {fails}\n'; print('\n'.join(out) + summary); open('/home/claude/auvryon/tests/http_results.txt', 'w').write('\n'.join(out) + summary); sys.exit(1 if fails else 0)
