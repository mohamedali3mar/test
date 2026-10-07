#!/usr/bin/env python3
"""
اختبارات HTTP والحماية عبر Apache الحقيقي مع ملفات .htaccess (المنفذ 8080).
تتطلب نشرًا جديدًا: tests/deploy.sh  ثم: python3 tests/http_security.py
لموقع اختبار آخر: WOOD_HTTP_BASE=http://localhost:8097/ WOOD_HTTP_SITE=/path/to/site WOOD_HTTP_DB=wood_xxx
"""
import base64
import hashlib
import hmac
import html as htmllib
import http.client
import http.cookiejar
import json
import os
import re
import shutil
import struct
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('WOOD_HTTP_BASE', 'http://localhost:8080/')
SITE = os.environ.get('WOOD_HTTP_SITE', '/srv/wood')
DB = os.environ.get('WOOD_HTTP_DB', 'wood_e2e')
KEY = 'k7Hq2Zp9Lw4Xv1Nb8Rt5'
# الموقع يحظر البرامج الآلية حسب User-Agent، فالعميل يرسل اسم متصفح حقيقي افتراضيًا
CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'
FIREFOX_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0'
ADMIN = ('admin', 'Correct-Horse-9')
PASS = 0
FAILS = []


def check(label, ok, detail=''):
    global PASS
    if ok:
        PASS += 1
        print('  PASS  ' + label)
    else:
        FAILS.append(label + (' -> ' + str(detail) if detail else ''))
        print('  FAIL  ' + label + ('  -> ' + str(detail) if detail else ''))


def section(t):
    print('\n== ' + t)


def sql(q):
    return subprocess.run(['mariadb', '-uroot', DB, '-N', '-e', q], capture_output=True, text=True).stdout.strip()


def db_checksum():
    # users.last_seen_at يتغير مع أي طلب (مرة كل دقيقة على الأكثر) فلا يدخل في المقارنة
    users = sql("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, username, password_hash, auth_version, role, is_active, display_name) ORDER BY id)) FROM users")
    return users + sql("CHECKSUM TABLE settings, counters, wood_types, warehouses, items, stock, documents, document_lines, audit_log")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, ua=CHROME_UA):
        self.ua = ua
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def req(self, path, data=None, headers=None, method=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        hdrs = {'User-Agent': self.ua}
        hdrs.update(headers or {})
        r = urllib.request.Request(BASE + path, data=body, headers=hdrs, method=method)
        try:
            resp = self.opener.open(r, timeout=30)
            return resp.status, resp.headers, resp.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode('utf-8', 'replace')

    def get(self, path, headers=None):
        return self.req(path, headers=headers)

    def post(self, path, data, headers=None):
        return self.req(path, data=data, headers=headers)

    def csrf(self, path):
        status, _, html = self.get(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
        return m.group(1) if m else '', html

    def cookie(self, name='WOODSESSID'):
        for c in self.jar:
            if c.name == name:
                return c
        return None

    def login(self, user=None, password=None):
        # القيم الافتراضية تُقرأ وقت الاستدعاء لأن كلمة المرور تتغير أثناء الاختبار
        token, _ = self.csrf('index.php?r=login')
        return self.post('index.php?r=login', {'csrf': token, 'username': user or ADMIN[0], 'password': password or ADMIN[1]})


def token_of(html):
    m = re.search(r'name="request_token" value="([a-f0-9]{32})"', html)
    return m.group(1) if m else ''


def raw_get(path, headers=None):
    """طلب بدون أي ترويسة User-Agent (urllib تضيف ترويسة افتراضية دائمًا)"""
    u = urllib.parse.urlsplit(BASE)
    conn = http.client.HTTPConnection(u.hostname, u.port or 80, timeout=30)
    conn.request('GET', u.path + path, headers=headers or {})
    resp = conn.getresponse()
    out = resp.status, resp.read().decode('utf-8', 'replace')
    conn.close()
    return out


def session_file(cl):
    return f'{SITE}/app/storage/sessions/sess_{cl.cookie().value}'


def edit_session(cl, pattern, fn):
    """يعدل قيمة رقمية في ملف الجلسة على الخادم (مثل وقت الدخول) لاختبار انتهاء المدة"""
    path = session_file(cl)
    content = open(path).read()
    m = re.search(pattern, content)
    if not m:
        return False
    open(path, 'w').write(content[:m.start(1)] + str(fn(int(m.group(1)))) + content[m.end(1):])
    return True


def totp(secret, step):
    """RFC 6238: HMAC-SHA1، خطوة 30 ثانية، 6 أرقام"""
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    mac = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = mac[-1] & 0x0F
    return '%06d' % ((struct.unpack('>I', mac[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)


def totp_step():
    return int(time.time()) // 30


def fresh_code(secret):
    """رمز خطوة لم تُستخدم بعد (أكبر من totp_last_step) ومقبولة الآن، مع انتظار الساعة إذا لزم"""
    last = int(sql(f"SELECT IFNULL(totp_last_step, -1) FROM users WHERE username = '{ADMIN[0]}'") or -1)
    while True:
        step = max(last + 1, totp_step())
        if step <= totp_step() + 1:
            return totp(secret, step)
        time.sleep(30 - time.time() % 30 + 0.2)


def wrong_code(secret):
    valid = {totp(secret, totp_step() + d) for d in range(-2, 3)}
    return next(c for c in ('%06d' % n for n in range(1000000)) if c not in valid)


def tfa_state():
    return sql(f"SELECT CONCAT(totp_enabled, ':', IFNULL(totp_secret, '-')) FROM users WHERE username = '{ADMIN[0]}'")


# ------------------------------------------------------------------
section('التثبيت عبر HTTP')
c = Client()
token, html = c.csrf('install.php')
status, _, html = c.post('install.php', {'csrf': token, 'install_key': KEY, 'company_name': 'شركة الاختبار', 'warehouse_name': 'المخزن الرئيسي',
                                          'username': ADMIN[0], 'password': ADMIN[1], 'password_confirm': ADMIN[1]})
check('التثبيت نجح', 'تم التثبيت' in html, status)
check('ملف القفل أُنشئ', os.path.exists(SITE + '/app/storage/installed.lock'))

section('صفحة التثبيت بعد التثبيت')
shutil.copy(os.path.join(os.path.dirname(__file__), '..', 'public_html', 'install.php'), SITE + '/install.php')
subprocess.run(['chown', 'www-data:www-data', SITE + '/install.php'])
s, _, html = Client().get('install.php')
check('صفحة التثبيت مغلقة (403) ولا تكشف حالة قاعدة البيانات', s == 403 and 'مثبت بالفعل' in html and 'قاعدة البيانات' not in html, s)
os.remove(SITE + '/app/storage/installed.lock')
s, _, html = Client().get('install.php')
check('مغلقة أيضًا بدون ملف القفل لأن المدير موجود', s == 403, s)
t, html = Client().csrf('install.php')
s, _, html = c.post('install.php', {'csrf': t, 'install_key': KEY, 'username': 'hacker', 'password': 'Another-Pass-1', 'password_confirm': 'Another-Pass-1',
                                    'company_name': 'x', 'warehouse_name': 'y'})
check('لا يمكن إنشاء مدير ثانٍ عبر صفحة التثبيت', sql("SELECT COUNT(*) FROM users") == '1')

section('فلتر برامج الأتمتة حسب User-Agent (index.php و install.php)')
for ua in ['curl/8.5.0', 'python-requests/2.31.0', 'Python-urllib/3.12', 'sqlmap/1.7.12#stable (https://sqlmap.org)', 'Wget/1.21.4',
           'Go-http-client/1.1', 'Mozilla/5.0 (compatible; Nmap Scripting Engine; https://nmap.org/book/nse.html)', 'CURL/7.0', '']:
    for path in ['index.php?r=login', 'install.php', 'index.php']:
        s, _, body = Client(ua=ua).get(path)
        check(f'{path} مع {ua or "User-Agent فارغ"} -> 403', s == 403 and 'افتحها من متصفح الإنترنت' in body and 'مثبت بالفعل' not in body, s)
for path in ['index.php?r=login', 'install.php']:
    s, body = raw_get(path)
    check(f'{path} بدون ترويسة User-Agent إطلاقًا -> 403', s == 403 and 'افتحها من متصفح الإنترنت' in body, s)
s, h, body = Client(ua='curl/8.5.0').get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('طلب X-Live من curl: JSON 403', s == 403 and h.get('Content-Type', '').startswith('application/json'), s)
browsers = {
    'Chrome': CHROME_UA,
    'Safari iOS': 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    'Safari macOS': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Firefox': FIREFOX_UA,
    'Edge': CHROME_UA + ' Edg/129.0.0.0',
    'Samsung Internet': 'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
    'HeadlessChrome (Playwright)': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/129.0.6668.29 Safari/537.36',
}
for name, ua in browsers.items():
    s, _, body = Client(ua=ua).get('index.php?r=login')
    s2, _, body2 = Client(ua=ua).get('install.php')
    check(f'متصفح مسموح: {name}', s == 200 and 'سجّل الدخول' in body and s2 == 403 and 'مثبت بالفعل' in body2, (s, s2))

section('استعادة كلمة المرور بملف reset.allow ومفتاح التثبيت')
open(SITE + '/app/storage/reset.allow', 'w').close()
subprocess.run(['chown', 'www-data:www-data', SITE + '/app/storage/reset.allow'])
r = Client()
t, html = r.csrf('install.php')
check('وضع الاستعادة يظهر فقط مع الملف', 'استعادة كلمة مرور المدير' in html)
s, _, html = r.post('install.php', {'csrf': t, 'install_key': 'wrong-key-0000000000', 'username': ADMIN[0], 'password': 'New-Password-77', 'password_confirm': 'New-Password-77'})
check('مفتاح خاطئ مرفوض في الاستعادة', 'مفتاح التثبيت غير صحيح' in html)
t, html = r.csrf('install.php')
s, _, html = r.post('install.php', {'csrf': t, 'install_key': KEY, 'username': ADMIN[0], 'password': 'New-Password-77', 'password_confirm': 'New-Password-77'})
check('الاستعادة نجحت وحُذف ملف السماح', 'تم تعيين كلمة المرور' in html and not os.path.exists(SITE + '/app/storage/reset.allow'))
check('ملف install.php حذف نفسه', not os.path.exists(SITE + '/install.php'))
ADMIN = (ADMIN[0], 'New-Password-77')

# ------------------------------------------------------------------
section('الوصول بدون تسجيل دخول')
anon = Client()
for route in ['inventory', 'receive', 'sell', 'transfer', 'documents', 'document&id=1', 'print&id=1', 'types', 'warehouses', 'settings',
              'account', 'users', 'monitor']:
    s, h, _ = anon.get('index.php?r=' + route)
    check(f'GET {route} يحوّل إلى الدخول', s == 303 and 'r=login' in (h.get('Location') or ''), s)
s, _, _ = anon.post('index.php?r=receive', {'csrf': 'x'})
check('POST بدون دخول مرفوض (403)', s == 403, s)
s, h, body = anon.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('API بدون دخول يرجع 401 JSON', s == 401 and h.get('Content-Type', '').startswith('application/json'), s)

section('الملفات الداخلية محمية')
for p in ['app/config.php', 'app/config.sample.php', 'app/bootstrap.php', 'app/lib/core.php', 'app/migrations/001_initial.sql',
          'app/migrations/003_two_factor.sql', 'app/lib/totp.php', 'app/lib/security.php', 'app/pages/asset.php',
          'app/storage/installed.lock', 'app/storage/logs/php-error.log', 'app/storage/sessions/', 'app/', '.htaccess', 'app/.htaccess',
          'README.md', 'DESIGN_RULES.md', 'backup.sql', 'site.zip', 'app/storage/reset.allow']:
    s, _, body = anon.get(p)
    check(f'{p} -> 403', s == 403 and 'DB' not in body and 'password' not in body, s)
for p in ['.user.ini', 'php.ini', 'assets/.user.ini', 'assets/php.ini']:
    s, _, _ = anon.get(p)
    check(f'ملف إعدادات PHP {p} -> 403', s == 403, s)
for p in ['backup.sql.gz', 'backup.sql.bz2', 'backup.sql.xz', 'backup.sql.zst', 'site.tar.gz', 'site.tgz', 'site.7z', 'site.rar', 'index.php.old', 'index.php.orig', 'index.php.bak',
          'app.js.tmp', 'db.backup', 'db.bkp', 'config.php~', 'index.php~', 'BACKUP.SQL.GZ']:
    s, _, _ = anon.get(p)
    check(f'نسخة احتياطية أو أرشيف {p} -> 403', s == 403, s)
s, _, _ = anon.get('.well-known/acme-challenge/test-token')
check('مسار .well-known غير محظور (لتجديد SSL)', s == 404, s)
s, _, _ = anon.get('assets/')
check('لا عرض لمحتويات المجلدات', s == 403, s)
s, _, body = anon.get('index.php/extra/path')
check('روابط PATH_INFO مرفوضة', s == 404, s)
s, _, body = anon.get('index.php?r=nope')
check('مسار غير معروف: 404 برسالة عربية', s == 404 and 'غير موجودة' in body, s)

# ------------------------------------------------------------------
section('الدخول والجلسة')
a = Client()
a.get('index.php?r=login')
before = a.cookie().value
s, h, _ = a.login()
after = a.cookie()
check('الدخول ناجح', s == 303 and 'r=inventory' in h.get('Location', ''), s)
check('معرف الجلسة تغير بعد الدخول', after is not None and after.value != before)
raw = a.get('index.php?r=login')[1]
s, h, _ = Client().get('index.php?r=login')
set_cookie = h.get('Set-Cookie', '')
check('ملف الجلسة HttpOnly و SameSite=Lax', 'HttpOnly' in set_cookie and 'SameSite=Lax' in set_cookie, set_cookie)

section('ترويسات الأمان')
s, h, html = a.get('index.php?r=inventory')
for name in ['Content-Security-Policy', 'X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy']:
    vals = h.get_all(name) or []
    check(f'{name} موجودة مرة واحدة', len(vals) == 1, vals)
check("CSP تمنع السكربت المضمّن", "script-src 'self'" in (h.get('Content-Security-Policy') or '') and 'unsafe-inline' not in (h.get('Content-Security-Policy') or ''))
check('لا ترويسة X-Powered-By', h.get('X-Powered-By') is None)
check('لا سكربت مضمّن في الصفحات', re.search(r'<script(?![^>]*\bsrc=)(?![^>]*application/json)[^>]*>', html) is None)
for name in ['Cross-Origin-Opener-Policy', 'Cross-Origin-Resource-Policy', 'X-Permitted-Cross-Domain-Policies', 'Permissions-Policy', 'Cache-Control']:
    vals = h.get_all(name) or []
    check(f'الصفحة: {name} موجودة مرة واحدة', len(vals) == 1, vals)
check('الصفحة: COOP و CORP بقيمة same-origin', h.get('Cross-Origin-Opener-Policy') == 'same-origin' and h.get('Cross-Origin-Resource-Policy') == 'same-origin',
      (h.get('Cross-Origin-Opener-Policy'), h.get('Cross-Origin-Resource-Policy')))
check('الصفحة: X-Permitted-Cross-Domain-Policies none', h.get('X-Permitted-Cross-Domain-Policies') == 'none', h.get('X-Permitted-Cross-Domain-Policies'))
check('الصفحة: Cache-Control لم تغيره .htaccess', h.get('Cache-Control') == 'no-store, private', h.get('Cache-Control'))
check('على http لا تضاف upgrade-insecure-requests إلى CSP', 'upgrade-insecure-requests' not in (h.get('Content-Security-Policy') or ''))
check('على http ملف الجلسة بدون بادئة __Host-', a.cookie() is not None and a.cookie('__Host-WOODSESSID') is None)
s, h, _ = a.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('API: رد JSON', s == 200 and h.get('Content-Type', '').startswith('application/json'), s)
for name in ['X-Content-Type-Options', 'Referrer-Policy', 'Cross-Origin-Opener-Policy', 'Cross-Origin-Resource-Policy',
             'X-Permitted-Cross-Domain-Policies', 'Cache-Control']:
    vals = h.get_all(name) or []
    check(f'API: {name} موجودة مرة واحدة', len(vals) == 1, vals)
check('API: CORP same-origin و Cache-Control no-store', h.get('Cross-Origin-Resource-Policy') == 'same-origin' and h.get('Cache-Control') == 'no-store, private',
      (h.get('Cross-Origin-Resource-Policy'), h.get('Cache-Control')))
s, h, _ = anon.get('assets/css/app.css')
check('الملفات الثابتة: nosniff مرة واحدة', len(h.get_all('X-Content-Type-Options') or []) == 1)
# ملف CSS عام برقم إصدار من وقت تعديل الملف؛ سكربت الواجهة يُقدم عبر r=asset للمسجلين فقط (يُختبر أدناه)
assets = re.findall(r'"(assets/css/app\.css\?v=[^"]+)"', html)
check('رابط CSS في الصفحة يحمل رقم الإصدار', len(assets) == 1, assets)
for p in assets + ['assets/fonts/cairo-arabic-400-normal.woff2']:
    s, h, _ = anon.get(p)
    vals = h.get_all('Cache-Control') or []
    check(f'{p}: Cache-Control immutable مرة واحدة', s == 200 and vals == ['public, max-age=31536000, immutable'], (s, vals))
s, h, _ = anon.get(assets[0] if assets else 'assets/css/app.css', headers={'Accept-Encoding': 'gzip'})
check('CSS مضغوط gzip عند طلبه', h.get('Content-Encoding') == 'gzip', h.get('Content-Encoding'))

section('سكربت الواجهة للمستخدمين المسجلين فقط')
for who, cl in [('زائر', anon), ('مستخدم مسجل', a)]:
    for p in ['assets/js/app.js', 'assets/js/twofactor.js', 'assets/js/app.js?v=1.0.0', 'assets//js/app.js', 'assets/js/./app.js', 'assets/js/app%2ejs']:
        s, _, body = cl.get(p)
        check(f'{who}: GET {p} -> 403', s == 403 and 'WoodCalc' not in body and 'otpauth' not in body, s)
    s, _, body = cl.get('assets/js/APP.JS')
    check(f'{who}: GET assets/js/APP.JS مرفوض', s in (403, 404) and 'WoodCalc' not in body, s)
s, _, body = anon.get('assets/js/vendor/qrcode.js')
check('مكتبة QR (مفتوحة المصدر) عامة', s == 200 and 'Kazuhiko Arase' in body, s)
s, h, body = anon.get('index.php?r=asset&f=app.js')
check('index.php?r=asset بدون دخول يحوّل للدخول', s == 303 and 'r=login' in h.get('Location', '') and 'WoodCalc' not in body, s)
s, h, body = a.get('index.php?r=asset&f=app.js&v=test')
local_js = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'public_html', 'assets', 'js', 'app.js'), encoding='utf-8').read()
check('للمستخدم المسجل: JavaScript بنفس محتوى الملف', s == 200 and h.get('Content-Type') == 'application/javascript; charset=utf-8' and body == local_js, (s, h.get('Content-Type')))
check('  nosniff مرة واحدة', (h.get_all('X-Content-Type-Options') or []) == ['nosniff'], h.get_all('X-Content-Type-Options'))
check('  Cache-Control: private, max-age=86400 فقط', (h.get_all('Cache-Control') or []) == ['private, max-age=86400'], h.get_all('Cache-Control'))
check('  بدون Pragma: no-cache من الجلسة', h.get('Pragma') is None, h.get('Pragma'))
etag = h.get('ETag') or ''
check('  ETag من وقت التعديل والحجم', re.fullmatch(r'"[0-9a-f]+-[0-9a-f]+"', etag) is not None, etag)
s, h, body = a.get('index.php?r=asset&f=app.js', headers={'If-None-Match': etag})
check('  If-None-Match مطابق -> 304 بدون محتوى', s == 304 and body == '' and h.get('ETag') == etag, s)
s, _, body = a.get('index.php?r=asset&f=app.js', headers={'If-None-Match': '"0-0"'})
check('  If-None-Match قديم -> 200', s == 200 and 'WoodCalc' in body, s)
s, h, body = a.get('index.php?r=asset&f=twofactor.js')
check('twofactor.js للمستخدم المسجل', s == 200 and 'data-otpauth' in body and h.get('Content-Type') == 'application/javascript; charset=utf-8', s)
for f in ['nope.js', '../app/config.php', '../../app/config.php', 'js/app.js', 'vendor/qrcode.js', 'app.css', '', 'APP.JS', 'app.js\x00', '/etc/passwd']:
    s, _, body = a.get('index.php?r=asset&f=' + urllib.parse.quote(f))
    check(f'r=asset&f={f!r} -> 404', s == 404 and 'WoodCalc' not in body and 'Test-Pass' not in body, s)
s, _, _ = a.get('index.php?r=asset&f[]=app.js')
check('r=asset مع f كمصفوفة -> 404', s == 404, s)
s, _, _ = a.post('index.php?r=asset&f=app.js', {})
check('r=asset عبر POST -> 405', s == 405, s)
s, _, html = a.get('index.php?r=inventory')
check('الصفحات تحمّل السكربت عبر r=asset برقم نسخة من وقت التعديل', re.search(r'<script src="index\.php\?r=asset&amp;f=app\.js&amp;v=[0-9.]+-\d+" defer></script>', html) is not None)
check('  ولا تشير إلى assets/js/app.js مباشرة', 'assets/js/app.js' not in html)
check('  رابط CSS برقم نسخة من وقت التعديل', re.search(r'assets/css/app\.css\?v=[0-9.]+-\d+', html) is not None)
s, _, html = Client().get('index.php?r=login')
check('صفحة الدخول بدون سكربت', s == 200 and '<script' not in html)

# ------------------------------------------------------------------
section('CSRF')
token, html = a.csrf('index.php?r=types')
sum_before = db_checksum()
for label, data in [('بدون رمز', {'action': 'create', 'name': 'نوع1'}), ('رمز خاطئ', {'action': 'create', 'name': 'نوع2', 'csrf': 'a' * 64}),
                    ('رمز كمصفوفة', {'action': 'create', 'name': 'نوع3', 'csrf[]': token})]:
    s, _, body = a.post('index.php?r=types', data)
    check(f'POST {label} -> 400', s == 400 and 'انتهت صلاحية النموذج' in body, s)
check('قاعدة البيانات لم تتغير بعد طلبات CSRF', db_checksum() == sum_before)
s, _, body = a.post('index.php?r=logout', {'csrf': 'bad'})
check('تسجيل الخروج يتطلب رمزًا صحيحًا', s == 400, s)

section('رفض POST الصادر من موقع آخر (Sec-Fetch-Site) حتى مع رمز صحيح')
sum_before = db_checksum()
for v in ['cross-site', 'same-site', 'CROSS-SITE']:
    s, _, body = a.post('index.php?r=types', {'csrf': token, 'action': 'create', 'name': 'نوع من موقع آخر'}, headers={'Sec-Fetch-Site': v})
    check(f'Sec-Fetch-Site: {v} -> 400', s == 400 and 'انتهت صلاحية النموذج' in body, s)
s, _, body = a.post('index.php?r=logout', {'csrf': token}, headers={'Sec-Fetch-Site': 'cross-site'})
check('تسجيل خروج من موقع آخر مرفوض', s == 400, s)
check('قاعدة البيانات لم تتغير', db_checksum() == sum_before)
x = Client()
t_login, _ = x.csrf('index.php?r=login')
s, _, body = x.post('index.php?r=login', {'csrf': t_login, 'username': ADMIN[0], 'password': ADMIN[1]}, headers={'Sec-Fetch-Site': 'cross-site'})
check('تسجيل دخول من موقع آخر مرفوض (Login CSRF)', s == 400 and x.get('index.php?r=inventory')[0] == 303, s)
for i, v in enumerate(['same-origin', 'none']):
    s, _, _ = a.post('index.php?r=types', {'csrf': token, 'action': 'create', 'name': f'نوع اختبار {i + 1}'}, headers={'Sec-Fetch-Site': v})
    check(f'Sec-Fetch-Site: {v} مقبول', s == 303, s)

section('مدخلات خبيثة')
payload = '"><script>alert(1)</script><img src=x onerror=alert(2)>'
a.post('index.php?r=types', {'csrf': token, 'action': 'create', 'name': payload})
s, _, html = a.get('index.php?r=types')
check('XSS: النص يُعرض مُهرّبًا', '&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)' not in html)
for q in ["' OR 1=1 -- ", '1; DROP TABLE users', '%_\\', '"><svg onload=alert(1)>']:
    s1, _, h1 = a.get('index.php?r=inventory&q=' + urllib.parse.quote(q))
    s2, _, h2 = a.get('index.php?r=documents&q=' + urllib.parse.quote(q) + '&type=1%20OR%201=1&warehouse=abc&from=2026-13-45&page=-5')
    check(f'بحث بنص خبيث آمن: {q!r}', s1 == 200 and s2 == 200 and 'SQLSTATE' not in h1 + h2 and '<svg onload' not in h1 + h2)
check('جدول المستخدمين سليم', sql('SELECT COUNT(*) FROM users') == '1')
s, _, body = a.post('index.php?r=receive', {'csrf': token, 'warehouse_id[]': '1', 'width': ['1', '2'], 'request_token': 'x'})
check('حقول مرسلة كمصفوفات لا تسبب خطأ', s == 200 and 'تعذر الحفظ' in body, s)

# ------------------------------------------------------------------
section('البيانات الأساسية للاختبار')
a.post('index.php?r=types', {'csrf': token, 'action': 'create', 'name': 'موسكي'})
type_id = sql("SELECT id FROM wood_types WHERE name='موسكي'")
_, html = a.csrf('index.php?r=receive')
rt = token_of(html)
receipt = {'csrf': token, 'warehouse_id': '1', 'wood_type_id': type_id, 'width': '10', 'width_unit': 'cm', 'thickness': '50', 'thickness_unit': 'mm',
           'length': '3', 'length_unit': 'm', 'quantity': '10', 'party_name': '', 'reference': '', 'notes': '', 'request_token': rt}
s, h, _ = a.post('index.php?r=receive', receipt)
check('وارد عبر HTTP', s == 303 and sql("SELECT qty_on_hand FROM stock") == '10', s)
s, h, _ = a.post('index.php?r=receive', receipt)
_, _, html = a.get(h.get('Location', 'index.php?r=receive').replace(BASE, ''))
check('إعادة إرسال نفس الوارد لا تضيف (الرصيد 10)', sql("SELECT qty_on_hand FROM stock") == '10' and 'سُجل من قبل' in html)
changed = dict(receipt, quantity='99')
s, _, html = a.post('index.php?r=receive', changed)
check('نفس الرمز ببيانات مختلفة يرفض برسالة واضحة', 'حُفظ سابقًا' in html and sql("SELECT qty_on_hand FROM stock") == '10')
item_id = sql("SELECT id FROM items LIMIT 1")

section('تجاوز المخزون بطلب مباشر يتخطى الواجهة')
_, html = a.csrf('index.php?r=sell')
sale = {'csrf': token, 'action': 'confirm', 'warehouse_id': '1', 'party_name': '', 'notes': '', 'request_token': token_of(html),
        'lines[0][type_id]': '', 'lines[0][item_id]': item_id, 'lines[0][quantity]': '11', 'lines[0][price]': '20000'}
s, _, html = a.post('index.php?r=sell', sale)
check('بيع 11 والمتاح 10 مرفوض من الخادم', 'أكبر من المتاح' in html and sql("SELECT qty_on_hand FROM stock") == '10')
sale['lines[0][price]'] = '0'
sale['lines[0][quantity]'] = '1'
s, _, html = a.post('index.php?r=sell', sale)
check('سعر صفر مرفوض من الخادم', 'أكبر من صفر' in html and sql("SELECT COUNT(*) FROM documents WHERE kind='sale'") == '0')

section('التزامن عبر HTTP: جلستان تؤكدان بيع 7 من 10 في نفس اللحظة')
b = Client()
b.login(*ADMIN)
results = []
for rnd in range(5):
    sql(f"UPDATE stock SET qty_on_hand = 10 WHERE item_id = {item_id}")
    forms = []
    for cl in (a, b):
        t, html = cl.csrf('index.php?r=sell')
        forms.append((cl, {'csrf': t, 'action': 'confirm', 'warehouse_id': '1', 'party_name': '', 'notes': '', 'request_token': token_of(html),
                           'lines[0][type_id]': '', 'lines[0][item_id]': item_id, 'lines[0][quantity]': '7', 'lines[0][price]': '100'}))
    out = []
    threads = [threading.Thread(target=lambda cl=cl, f=f: out.append(cl.post('index.php?r=sell', f)[0])) for cl, f in forms]
    for th in threads:
        th.start()
    for th in threads:
        th.join()
    results.append((sorted(out), sql(f"SELECT qty_on_hand FROM stock WHERE item_id = {item_id}")))
check('في كل الجولات نجح بيع واحد فقط والرصيد 3', all(r == ([200, 303], '3') for r in results), results)

# ------------------------------------------------------------------
section('طلبات GET لا تغير البيانات')
sum_before = db_checksum()
doc_id = sql("SELECT MAX(id) FROM documents")
for path in ['index.php?r=logout', f'index.php?r=document&id={doc_id}&action=cancel&confirm=1', 'index.php?r=types&action=delete&id=1',
             'index.php?r=settings&action=migrate', 'index.php?r=receive&quantity=5', 'index.php?r=sell&action=confirm',
             'index.php?r=api&op=stock', 'index.php?r=inventory', 'index.php?r=documents', f'index.php?r=print&id={doc_id}']:
    s, _, _ = a.get(path)
check('لا تغيير في قاعدة البيانات بعد كل طلبات GET', db_checksum() == sum_before)
s, _, body = a.get('index.php?r=logout')
check('تسجيل الخروج عبر GET مرفوض (405)', s == 405, s)

section('API')
s, h, body = a.get('index.php?r=api&op=version', headers={'X-Live': '1'})
data = json.loads(body)
check('رقم إصدار البيانات', s == 200 and data['v'].isdigit())
s, _, body = a.post('index.php?r=api&op=version', {'csrf': token}, headers={'X-Live': '1'})
check('API يقبل GET فقط', s == 405, s)
v1 = data['v']
_, html = a.csrf(f'index.php?r=document&id={doc_id}')
a.post(f'index.php?r=document&id={doc_id}', {'csrf': token, 'action': 'cancel', 'confirm': '1', 'reason': ''})
v2 = json.loads(a.get('index.php?r=api&op=version', headers={'X-Live': '1'})[2])['v']
check('الإلغاء رفع رقم الإصدار', int(v2) == int(v1) + 1, (v1, v2))
s, _, _ = a.post(f'index.php?r=document&id={doc_id}', {'csrf': token, 'action': 'cancel', 'confirm': '1', 'reason': ''})
check('الإلغاء الثاني لا يغير الرصيد', sql(f"SELECT qty_on_hand FROM stock WHERE item_id = {item_id}") == '10')

# ------------------------------------------------------------------
section('تغيير كلمة المرور ينهي الجلسات الأخرى')
s, _, _ = b.get('index.php?r=inventory')
check('الجلسة الثانية تعمل قبل التغيير', s == 200, s)
_, html = a.csrf('index.php?r=account')
s, h, _ = a.post('index.php?r=account', {'csrf': token, 'action': 'password', 'current_password': ADMIN[1], 'new_password': 'Changed-Pass-55',
                                         'confirm_password': 'Changed-Pass-55'})
check('تغيير كلمة المرور نجح', s == 303, s)
s, h, _ = b.get('index.php?r=inventory')
check('الجلسة الأخرى انتهت وتحوّل للدخول', s == 303 and 'r=login' in h.get('Location', ''), s)
s, _, _ = a.get('index.php?r=inventory')
check('الجلسة الحالية مستمرة', s == 200, s)
ADMIN = (ADMIN[0], 'Changed-Pass-55')

section('انتهاء الجلسة عند الخمول (التحديث التلقائي لا يمدها)')
sid = a.cookie().value
sess_file = f'{SITE}/app/storage/sessions/sess_{sid}'
check('الجلسات تُحفظ في مجلد النظام الخاص', os.path.exists(sess_file), sess_file)
for _ in range(3):
    a.get('index.php?r=api&op=version', headers={'X-Live': '1'})
content = open(sess_file).read()
last = int(re.search(r'last_activity\|i:(\d+);', content).group(1))
old = last - 3 * 3600
open(sess_file, 'w').write(content.replace(f'last_activity|i:{last};', f'last_activity|i:{old};'))
s, h, _ = a.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('بعد مدة الخمول: التحديث التلقائي يرجع 401', s == 401, s)
s, h, _ = a.get('index.php?r=inventory')
check('والصفحة تحوّل للدخول', s == 303, s)

section('الجلسة مربوطة بالمتصفح (User-Agent وقت الدخول)')
u = Client()
u.login(*ADMIN)
check('الجلسة تعمل من نفس المتصفح', u.get('index.php?r=inventory')[0] == 200)
thief = Client(ua=FIREFOX_UA)
thief.jar.set_cookie(u.cookie())
s, h, _ = thief.get('index.php?r=inventory')
check('نفس ملف تعريف الجلسة من متصفح مختلف: الدخول مطلوب', s == 303 and 'r=login' in h.get('Location', ''), s)
s, h, _ = u.get('index.php?r=inventory')
check('والجلسة نفسها حُذفت (المتصفح الأصلي يسجل الدخول من جديد)', s == 303, s)
u2 = Client()
u2.login(*ADMIN)
thief2 = Client(ua=FIREFOX_UA)
thief2.jar.set_cookie(u2.cookie())
s, _, _ = thief2.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('طلب التحديث التلقائي من متصفح مختلف: 401', s == 401, s)

section('المدة القصوى للجلسة (12 ساعة من الدخول، حتى مع النشاط)')
v = Client()
v.login(*ADMIN)
check('وقت الدخول محفوظ في الجلسة', edit_session(v, r'login_at\|i:(\d+);', lambda t: t - 11 * 3600))
s, _, _ = v.get('index.php?r=inventory')
check('بعد 11 ساعة: الجلسة مستمرة', s == 200, s)
edit_session(v, r'login_at\|i:(\d+);', lambda t: t - 2 * 3600)
s, _, body = v.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('بعد 13 ساعة: التحديث التلقائي يرجع 401 رغم النشاط المستمر', s == 401, s)
s, h, _ = v.get('index.php?r=inventory')
check('والصفحة تحوّل للدخول', s == 303 and 'r=login' in h.get('Location', ''), s)
s, _, html = v.get('index.php?r=login')
check('مع رسالة عربية توضح السبب', 'ساعة من تسجيل الدخول' in html)
v2 = Client()
v2.login(*ADMIN)
edit_session(v2, r'login_at\|i:(\d+);', lambda t: t - 13 * 3600)
s, h, _ = v2.get('index.php?r=inventory')
check('بعد 13 ساعة: طلب صفحة عادي يحوّل للدخول', s == 303 and 'r=login' in h.get('Location', ''), s)

section('تحديد محاولات الدخول')
sql("DELETE FROM login_attempts")
t = Client()
msgs = []
for i in range(6):
    s, _, html = t.login(ADMIN[0], 'wrong-password-' + str(i))
    msgs.append('محاولات دخول كثيرة' in html)
check('5 محاولات خاطئة ثم الحظر في السادسة', msgs == [False] * 5 + [True], msgs)
s, h, html = t.login(*ADMIN)
check('حتى كلمة المرور الصحيحة محظورة أثناء الحظر من نفس العنوان', 'محاولات دخول كثيرة' in html)
sql("DELETE FROM login_attempts")
s, h, _ = t.login(*ADMIN)
check('بعد انتهاء الحظر يعمل الدخول', s == 303)

section('التحقق بخطوتين: التفعيل من الإعدادات')
sql("DELETE FROM login_attempts")
m = Client()
m.login(*ADMIN)
other = Client()
other.login(*ADMIN)
token, html = m.csrf('index.php?r=account')
check('قسم «التحقق بخطوتين» في الإعدادات مع زر البدء', 'التحقق بخطوتين' in html and 'value="tfa_start"' in html)
check('  بدون سكربت QR قبل بدء التفعيل', 'twofactor.js' not in html and 'qrcode.js' not in html)
s, h, _ = m.post('index.php?r=account', {'csrf': token, 'action': 'tfa_start'})
check('بدء التفعيل يعيد التوجيه إلى القسم', s == 303 and h.get('Location', '').endswith('#two-factor'), (s, h.get('Location')))
s, _, html = m.get('index.php?r=account')
mm = re.search(r'<textarea id="tfa_secret"[^>]*>([A-Z2-7 ]+)</textarea>', html)
secret = mm.group(1).replace(' ', '') if mm else ''
mu = re.search(r'data-otpauth="([^"]+)"', html)
uri = htmllib.unescape(mu.group(1)) if mu else ''
check('السر 32 حرفًا بمجموعات من 4 للإدخال اليدوي', len(secret) == 32 and mm.group(1) == ' '.join(secret[i:i + 4] for i in range(0, 32, 4)), mm and mm.group(1))
check('رابط otpauth: الجهة اسم الشركة والحساب اسم المستخدم',
      uri.startswith('otpauth://totp/') and f'secret={secret}&' in uri and 'issuer=' + urllib.parse.quote('شركة الاختبار') in uri
      and uri.split('?')[0].endswith(':' + ADMIN[0]) and htmllib.escape(uri, quote=False) in html, uri)
check('مكتبة QR و twofactor.js تُحمّلان في هذه الحالة', 'src="assets/js/vendor/qrcode.js?v=' in html and 'src="index.php?r=asset&amp;f=twofactor.js&amp;v=' in html)
check('  بدون سكربت مضمّن', re.search(r'<script(?![^>]*\bsrc=)(?![^>]*application/json)[^>]*>', html) is None)
check('السر لا يُحفظ في قاعدة البيانات قبل التأكيد', tfa_state() == '0:-', tfa_state())
s, _, html = m.post('index.php?r=account', {'csrf': token, 'action': 'tfa_confirm', 'tfa_code': wrong_code(secret)})
check('رمز خاطئ عند التأكيد مرفوض', s == 200 and 'الرمز غير صحيح' in html and 'aria-invalid="true"' in html and tfa_state() == '0:-', s)
version_before = sql(f"SELECT auth_version FROM users WHERE username = '{ADMIN[0]}'")
enable_step = totp_step()
s, h, _ = m.post('index.php?r=account', {'csrf': token, 'action': 'tfa_confirm', 'tfa_code': totp(secret, enable_step)})
check('الرمز الصحيح يفعّل التحقق ويحفظ السر', s == 303 and tfa_state() == '1:' + secret, (s, tfa_state()))
check('  رقم إصدار الدخول ارتفع', int(sql(f"SELECT auth_version FROM users WHERE username = '{ADMIN[0]}'")) == int(version_before) + 1)
check('  الجلسة الأخرى انتهت', other.get('index.php?r=inventory')[0] == 303)
check('  الجلسة الحالية مستمرة', m.get('index.php?r=inventory')[0] == 200)
token, html = m.csrf('index.php?r=account')
check('  الحالة «مفعل» مع نموذج الإيقاف', 'value="tfa_disable"' in html and 'مفعل' in html and 'tfa_secret' not in html)
m.post('index.php?r=logout', {'csrf': token})

section('التحقق بخطوتين: تسجيل الدخول')
n = Client()
s, h, _ = n.login(*ADMIN)
check('كلمة المرور الصحيحة تنقل لخطوة الرمز وليس للمخزون', s == 303 and 'r=login' in h.get('Location', '') and 'inventory' not in h.get('Location', ''), (s, h.get('Location')))
s, h, _ = n.get('index.php?r=inventory')
check('الدخول لم يكتمل قبل الرمز', s == 303 and 'r=login' in h.get('Location', ''), s)
s, _, _ = n.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('  والواجهة البرمجية ترجع 401', s == 401, s)
t2, html = n.csrf('index.php?r=login')
check('صفحة الدخول تطلب «رمز التحقق من تطبيق المصادقة»', 'رمز التحقق من تطبيق المصادقة' in html and 'name="code"' in html and 'name="password"' not in html)
s, _, html = n.post('index.php?r=login', {'csrf': t2, 'step': 'code', 'code': wrong_code(secret)})
check('رمز خاطئ مرفوض', s == 200 and 'رمز التحقق غير صحيح' in html and 'name="code"' in html, s)
s, _, html = n.post('index.php?r=login', {'csrf': t2, 'step': 'code', 'code': totp(secret, enable_step)})
check('الرمز الذي استُخدم في التفعيل لا يُقبل مرة أخرى', 'رمز التحقق غير صحيح' in html and n.get('index.php?r=inventory')[0] == 303)
s, _, html = n.post('index.php?r=login', {'csrf': t2, 'step': 'code', 'code': 'abc'})
check('رمز بصيغة خاطئة مرفوض', 'رمز التحقق غير صحيح' in html)
code = fresh_code(secret)
s, _, body = n.post('index.php?r=login', {'csrf': t2, 'step': 'code', 'code': code}, headers={'Sec-Fetch-Site': 'cross-site'})
check('خطوة الرمز محمية من الطلبات القادمة من موقع آخر', s == 400, s)
s, h, _ = n.post('index.php?r=login', {'csrf': t2, 'step': 'code', 'code': code})
check('الرمز الصحيح يكمل الدخول', s == 303 and 'r=inventory' in h.get('Location', ''), (s, h.get('Location')))
check('  الدخول مكتمل', n.get('index.php?r=inventory')[0] == 200)
p2 = Client()
p2.login(*ADMIN)
t3, _ = p2.csrf('index.php?r=login')
s, _, html = p2.post('index.php?r=login', {'csrf': t3, 'step': 'code', 'code': code})
check('نفس رمز الدخول في جلسة أخرى مرفوض (إعادة استخدام)', 'رمز التحقق غير صحيح' in html and p2.get('index.php?r=inventory')[0] == 303)
check('مهلة الخطوة الثانية محفوظة في الجلسة', edit_session(p2, r's:7:"expires";i:(\d+);', lambda t: t - 301))
s, _, html = p2.post('index.php?r=login', {'csrf': t3, 'step': 'code', 'code': fresh_code(secret)})
check('بعد 5 دقائق: رجوع لخطوة كلمة المرور برسالة عربية', 'انتهت مهلة إدخال رمز التحقق' in html and 'name="password"' in html and 'name="code"' not in html)
check('  والدخول لم يكتمل', p2.get('index.php?r=inventory')[0] == 303)
q = Client()
q.login(*ADMIN)
t4, _ = q.csrf('index.php?r=login')
s, h, _ = q.post('index.php?r=login', {'csrf': t4, 'step': 'cancel'})
s, _, html = q.get('index.php?r=login')
check('زر الرجوع يعيد خطوة كلمة المرور', 'name="password"' in html and 'name="code"' not in html)

section('التحقق بخطوتين: محاولات الرمز ضمن حدود الدخول')
sql("DELETE FROM login_attempts")
w = Client()
w.login(*ADMIN)
tw, _ = w.csrf('index.php?r=login')
msgs = []
for i in range(5):
    msgs.append('محاولات دخول كثيرة' in w.post('index.php?r=login', {'csrf': tw, 'step': 'code', 'code': wrong_code(secret)})[2])
check('كلمة المرور + 4 رموز خاطئة ثم الحظر', msgs == [False, False, False, False, True], msgs)
s, _, html = w.post('index.php?r=login', {'csrf': tw, 'step': 'code', 'code': fresh_code(secret)})
check('  حتى الرمز الصحيح محظور أثناء الحظر', 'محاولات دخول كثيرة' in html and w.get('index.php?r=inventory')[0] == 303)
sql("DELETE FROM login_attempts")

section('التحقق بخطوتين: الإيقاف يتطلب كلمة المرور والرمز')
token, html = n.csrf('index.php?r=account')
s, _, html = n.post('index.php?r=account', {'csrf': token, 'action': 'tfa_disable', 'tfa_password': 'Wrong-Pass-000', 'tfa_code': fresh_code(secret)})
check('كلمة مرور خاطئة: الإيقاف مرفوض', s == 200 and 'كلمة المرور الحالية غير صحيحة' in html and tfa_state() == '1:' + secret, s)
s, _, html = n.post('index.php?r=account', {'csrf': token, 'action': 'tfa_disable', 'tfa_password': ADMIN[1], 'tfa_code': wrong_code(secret)})
check('رمز خاطئ: الإيقاف مرفوض', s == 200 and 'رمز التحقق غير صحيح' in html and tfa_state() == '1:' + secret, s)
sql("DELETE FROM login_attempts")
other = Client()
other.login(*ADMIN)
to, _ = other.csrf('index.php?r=login')
other.post('index.php?r=login', {'csrf': to, 'step': 'code', 'code': fresh_code(secret)})
check('جلسة ثانية بالتحقق بخطوتين', other.get('index.php?r=inventory')[0] == 200)
s, h, _ = n.post('index.php?r=account', {'csrf': token, 'action': 'tfa_disable', 'tfa_password': ADMIN[1], 'tfa_code': fresh_code(secret)})
check('كلمة المرور والرمز صحيحان: تم الإيقاف ومُسح السر', s == 303 and tfa_state() == '0:-', (s, tfa_state()))
check('  الجلسة الأخرى انتهت', other.get('index.php?r=inventory')[0] == 303)
check('  الجلسة الحالية مستمرة', n.get('index.php?r=inventory')[0] == 200)
s, h, _ = Client().login(*ADMIN)
check('الدخول بعد الإيقاف بكلمة المرور فقط', s == 303 and 'r=inventory' in h.get('Location', ''), (s, h.get('Location')))

section('الاستعادة من install.php توقف التحقق بخطوتين')
sql(f"UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', totp_enabled = 1, totp_last_step = NULL WHERE username = '{ADMIN[0]}'")
s, h, _ = Client().login(*ADMIN)
check('مفعل: كلمة المرور وحدها لا تكفي', s == 303 and 'r=login' in h.get('Location', ''))
shutil.copy(os.path.join(os.path.dirname(__file__), '..', 'public_html', 'install.php'), SITE + '/install.php')
open(SITE + '/app/storage/reset.allow', 'w').close()
subprocess.run(['chown', 'www-data:www-data', SITE + '/install.php', SITE + '/app/storage/reset.allow'])
r = Client()
t, html = r.csrf('install.php')
check('صفحة الاستعادة تذكر إيقاف التحقق بخطوتين', 'التحقق بخطوتين' in html)
s, _, html = r.post('install.php', {'csrf': t, 'install_key': KEY, 'username': ADMIN[0], 'password': 'Recovered-Pass-88', 'password_confirm': 'Recovered-Pass-88'})
check('الاستعادة نجحت وأوقفت التحقق بخطوتين', 'تم تعيين كلمة المرور' in html and tfa_state() == '0:-', tfa_state())
ADMIN = (ADMIN[0], 'Recovered-Pass-88')
s, h, _ = Client().login(*ADMIN)
check('الدخول بكلمة المرور الجديدة بدون رمز', s == 303 and 'r=inventory' in h.get('Location', ''), (s, h.get('Location')))

section('رسائل الخطأ لا تكشف تفاصيل')
cfg = open(SITE + '/app/config.php').read()
open(SITE + '/app/config.php', 'w').write(cfg.replace("'Test-Pass-2026'", "'wrong-db-pass'"))
time.sleep(3)  # OPcache يعيد قراءة الملفات المعدلة كل ثانيتين افتراضيًا
s, _, body = Client().get('index.php?r=login')
open(SITE + '/app/config.php', 'w').write(cfg)
time.sleep(3)
check('قاعدة بيانات غير متاحة: رسالة عامة 500', s == 500 and 'حدث خطأ غير متوقع' in body, s)
check('بدون SQLSTATE أو مسارات أو أسماء أو كلمات مرور',
      not re.search(r'SQLSTATE|PDO|/srv/wood|wood_app|wood_e2e|Test-Pass|wrong-db-pass|config\.php|bootstrap|/lib/|' + re.escape(SITE) + '|' + re.escape(DB), body))
s, _, _ = Client().get('index.php?r=login')
check('بعد إعادة الإعدادات يعود النظام للعمل', s == 200, s)

print(f'\nHTTP: {PASS} passed, {len(FAILS)} failed')
for f in FAILS:
    print('  - ' + f)
raise SystemExit(1 if FAILS else 0)
