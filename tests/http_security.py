#!/usr/bin/env python3
"""
اختبارات HTTP والحماية عبر Apache الحقيقي مع ملفات .htaccess (المنفذ 8080).
تتطلب نشرًا جديدًا: tests/deploy.sh  ثم: python3 tests/http_security.py
"""
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://localhost:8080/'
SITE = '/srv/wood'
KEY = 'k7Hq2Zp9Lw4Xv1Nb8Rt5'
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
    return subprocess.run(['mariadb', '-uroot', 'wood_e2e', '-N', '-e', q], capture_output=True, text=True).stdout.strip()


def db_checksum():
    # users.last_seen_at يتغير مع أي طلب (مرة كل دقيقة على الأكثر) فلا يدخل في المقارنة
    users = sql("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, username, password_hash, auth_version, role, is_active, display_name) ORDER BY id)) FROM users")
    return users + sql("CHECKSUM TABLE settings, counters, wood_types, warehouses, items, stock, documents, document_lines, audit_log")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def req(self, path, data=None, headers=None, method=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers=headers or {}, method=method)
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
          'app/storage/installed.lock', 'app/storage/logs/php-error.log', 'app/storage/sessions/', 'app/', '.htaccess', 'app/.htaccess',
          'README.md', 'DESIGN_RULES.md', 'backup.sql', 'site.zip', 'app/storage/reset.allow']:
    s, _, body = anon.get(p)
    check(f'{p} -> 403', s == 403 and 'DB' not in body and 'password' not in body, s)
for p in ['.user.ini', 'php.ini', 'assets/.user.ini', 'assets/php.ini']:
    s, _, _ = anon.get(p)
    check(f'ملف إعدادات PHP {p} -> 403', s == 403, s)
for p in ['backup.sql.gz', 'site.tar.gz', 'site.tgz', 'site.7z', 'site.rar', 'index.php.old', 'index.php.orig', 'index.php.bak',
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
assets = re.findall(r'"(assets/(?:css|js)/app\.(?:css|js)\?v=[^"]+)"', html)
check('روابط CSS و JS في الصفحة تحمل رقم الإصدار', len(assets) == 2, assets)
for p in assets + ['assets/fonts/cairo-arabic-400-normal.woff2']:
    s, h, _ = anon.get(p)
    vals = h.get_all('Cache-Control') or []
    check(f'{p}: Cache-Control immutable مرة واحدة', s == 200 and vals == ['public, max-age=31536000, immutable'], (s, vals))
s, h, _ = anon.get(assets[0] if assets else 'assets/css/app.css', headers={'Accept-Encoding': 'gzip'})
check('CSS مضغوط gzip عند طلبه', h.get('Content-Encoding') == 'gzip', h.get('Content-Encoding'))

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

section('رسائل الخطأ لا تكشف تفاصيل')
cfg = open(SITE + '/app/config.php').read()
open(SITE + '/app/config.php', 'w').write(cfg.replace("'Test-Pass-2026'", "'wrong-db-pass'"))
time.sleep(3)  # OPcache يعيد قراءة الملفات المعدلة كل ثانيتين افتراضيًا
s, _, body = Client().get('index.php?r=login')
open(SITE + '/app/config.php', 'w').write(cfg)
time.sleep(3)
check('قاعدة بيانات غير متاحة: رسالة عامة 500', s == 500 and 'حدث خطأ غير متوقع' in body, s)
check('بدون SQLSTATE أو مسارات أو أسماء أو كلمات مرور', not re.search(r'SQLSTATE|PDO|/srv/wood|wood_app|wood_e2e|Test-Pass|wrong-db-pass|config\.php|bootstrap|/lib/', body))
s, _, _ = Client().get('index.php?r=login')
check('بعد إعادة الإعدادات يعود النظام للعمل', s == 200, s)

print(f'\nHTTP: {PASS} passed, {len(FAILS)} failed')
for f in FAILS:
    print('  - ' + f)
raise SystemExit(1 if FAILS else 0)
