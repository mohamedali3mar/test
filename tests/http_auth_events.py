#!/usr/bin/env python3
"""
اختبارات HTTP لسجل الدخول والأمان عبر Apache الحقيقي (المنفذ 8080):
زر تحديث قاعدة البيانات للترقية 002، وتسجيل محاولات الدخول الفاشلة والمحظورة والناجحة،
والتهريب في صفحة الإعدادات، والتحديث التلقائي، وتغيير كلمة المرور، وانتهاء الجلسة، والخروج،
واستعادة كلمة المرور من install.php.
تعمل بعد نشر جديد (tests/deploy.sh) أو بعد tests/http_security.py على نفس النشر:
  python3 -I tests/http_auth_events.py
"""
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://localhost:8080/'
SITE = '/srv/wood'
KEY = 'k7Hq2Zp9Lw4Xv1Nb8Rt5'
ADMIN = ('admin', 'Correct-Horse-9')
REPO = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
UA_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'
UA_EVIL = 'Mozilla/5.0 "><script>alert(7)</script>'
LABELS = ['دخول ناجح', 'محاولة دخول فاشلة', 'دخول محظور مؤقتًا', 'تسجيل خروج', 'تغيير كلمة المرور', 'استعادة كلمة المرور', 'انتهاء الجلسة']
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


def last_event_id():
    return int(sql('SELECT COALESCE(MAX(id), 0) FROM auth_events') or 0)


def events_after(marker):
    """[(event, username, ip, user_agent)] بترتيب الإضافة"""
    out = sql(f'SELECT event, username, ip, user_agent FROM auth_events WHERE id > {int(marker)} ORDER BY id')
    return [tuple((line.split('\t') + ['', '', '', ''])[:4]) for line in out.splitlines() if line]


def arabic_int(s):
    return int(s.translate(str.maketrans('٠١٢٣٤٥٦٧٨٩', '0123456789')).replace(' ', '').replace(',', ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, ua=UA_CHROME):
        self.ua = ua
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def req(self, path, data=None, headers=None, method=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers={'User-Agent': self.ua, **(headers or {})}, method=method)
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
        token, _ = self.csrf('index.php?r=login')
        return self.post('index.php?r=login', {'csrf': token, 'username': user or ADMIN[0], 'password': password or ADMIN[1]})

    def version(self):
        return json.loads(self.get('index.php?r=api&op=version', headers={'X-Live': '1'})[2])['v']


# ------------------------------------------------------------------
section('التهيئة')
if sql('SELECT COUNT(*) FROM users') in ('', '0'):
    c = Client()
    token, _ = c.csrf('install.php')
    s, _, html = c.post('install.php', {'csrf': token, 'install_key': KEY, 'company_name': 'شركة الاختبار', 'warehouse_name': 'المخزن الرئيسي',
                                        'username': ADMIN[0], 'password': ADMIN[1], 'password_confirm': ADMIN[1]})
    check('تثبيت جديد عبر HTTP', 'تم التثبيت' in html, s)
else:
    # النظام مثبت من اختبار سابق وكلمة المرور تغيرت: تُعاد إلى قيمة معروفة مباشرة في القاعدة
    pw_hash = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', ADMIN[1]], capture_output=True, text=True).stdout.strip()
    sql(f"UPDATE users SET password_hash = '{pw_hash}', auth_version = auth_version + 1 WHERE username = '{ADMIN[0]}'")
    check('النظام مثبت مسبقًا: كلمة مرور المدير أعيدت للاختبار', pw_hash.startswith('$2y$'))
sql('DELETE FROM login_attempts')
LOOPBACK = {'127.0.0.1', '::/64'}

# ------------------------------------------------------------------
section('نظام قائم قبل الترقية 002: الدخول يعمل وزر التحديث ينشئ السجل')
migrations = [int(f[:3]) for f in os.listdir(os.path.join(REPO, 'public_html', 'app', 'migrations')) if re.match(r'^\d{3}_[a-z0-9_]+\.sql$', f)]
if max(migrations) == 2:
    sql('DROP TABLE IF EXISTS auth_events')
    sql("UPDATE settings SET value = '1' WHERE name = 'schema_version'")
    a = Client()
    s, _, html = a.login(ADMIN[0], 'wrong-before-migration')
    check('الدخول الخاطئ يعمل برسالته بدون جدول السجل', s == 200 and 'غير صحيحة' in html, s)
    s, h, _ = a.login()
    check('الدخول الصحيح يعمل بدون جدول السجل', s == 303 and 'r=inventory' in h.get('Location', ''), s)
    token, html = a.csrf('index.php?r=settings')
    check('الإعدادات تعرض زر التحديث وملاحظة السجل بدل الخطأ',
          'تحديث قاعدة البيانات مطلوب' in html and 'يبدأ السجل بعد تحديث قاعدة البيانات' in html and 'id="live-auth-events"' in html)
    s, h, _ = a.post('index.php?r=settings', {'csrf': token, 'action': 'migrate'})
    check('زر «تحديث قاعدة البيانات» نجح', s == 303, s)
    check('الترقية أنشأت الجدول ورقم الإصدار 2', sql("SHOW TABLES LIKE 'auth_events'") == 'auth_events'
          and sql("SELECT value FROM settings WHERE name = 'schema_version'") == '2')
    _, _, html = a.get('index.php?r=settings')
    check('لا ترقية معلقة بعد التحديث', 'تحديث قاعدة البيانات مطلوب' not in html)
    sql('DELETE FROM login_attempts')
else:
    print('  SKIP  يوجد ترقية أحدث من 002، فلا تُحاكى قاعدة الإصدار 1 على هذا النشر')
check('جدول السجل موجود', sql("SHOW TABLES LIKE 'auth_events'") == 'auth_events')

# ------------------------------------------------------------------
section('محاولات الدخول')
marker = last_event_id()
x = Client()
x.login(ADMIN[0], 'wrong-password-1')
x.login(ADMIN[0], 'wrong-password-2')
s, _, html = Client(UA_EVIL).login('<script>alert(1)</script>', 'whatever-123')
check('اسم خبيث يُرفض برسالة عامة', 'غير صحيحة' in html, s)
a = Client()
s, h, _ = a.login()
check('الدخول الصحيح', s == 303 and 'r=inventory' in h.get('Location', ''), s)
rows = events_after(marker)
check('سُجلت فاشلتان ثم الخبيثة ثم الناجحة', [(r[0], r[1]) for r in rows] == [
    ('login_fail', 'admin'), ('login_fail', 'admin'), ('login_fail', '<script>alert(1)</script>'), ('login_ok', 'admin')], rows)
check('العنوان هو عنوان الاتصال الحقيقي', len(rows) == 4 and all(r[2] in LOOPBACK for r in rows), [r[2] for r in rows])
check('المتصفح محفوظ كما أُرسل', len(rows) == 4 and rows[0][3] == UA_CHROME and rows[2][3] == UA_EVIL, [r[3] for r in rows])

section('التحديث التلقائي')
v1 = a.version()
Client().login(ADMIN[0], 'wrong-password-3')
v2 = a.version()
check('محاولة فاشلة ترفع رقم إصدار البيانات فتتحدث الإعدادات المفتوحة', int(v2) == int(v1) + 1, (v1, v2))
s, _, html = a.get('index.php?r=settings', headers={'X-Live': '1'})
check('طلب التحديث التلقائي للإعدادات يعيد منطقة السجل', s == 200 and 'id="live-auth-events" data-live' in html, s)

section('الحظر المؤقت')
marker = last_event_id()
# الحظر يُسجل مرة في الدقيقة لكل عنوان؛ اختبار سابق على نفس النشر قد يكون سجل حظرًا من 127.0.0.1 للتو
sql(f"UPDATE auth_events SET created_at = created_at - INTERVAL 2 MINUTE WHERE event = 'login_locked' AND id <= {marker}")
t = Client('curl/8.5.0')
locked = []
for i in range(7):
    s, _, html = t.login('ghost_user', f'wrong-{i}')
    locked.append('محاولات دخول كثيرة' in html)
check('5 محاولات فاشلة ثم الحظر', locked == [False] * 5 + [True, True], locked)
events = [r[0] for r in events_after(marker) if r[1] == 'ghost_user']
check('سُجلت 5 فاشلة وحظر واحد (التكرار خلال دقيقة لا يُغرق السجل)', events == ['login_fail'] * 5 + ['login_locked'], events)

# ------------------------------------------------------------------
section('صفحة الإعدادات')
s, _, html = a.get('index.php?r=settings')
check('الصفحة تعمل', s == 200, s)
check('عنوان القسم ومنطقة التحديث', '>سجل الدخول والأمان</h2>' in html and 'id="live-auth-events" data-live' in html)
check('اسم المستخدم الخبيث مهرّب', '&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)' not in html)
check('المتصفح الخبيث مهرّب', '&lt;script&gt;alert(7)&lt;/script&gt;' in html and '<script>alert(7)' not in html)
check('لا سكربت مضمّن في الصفحة', re.search(r'<script(?![^>]*\bsrc=)[^>]*>', html) is None)
for label in ['محاولة دخول فاشلة', 'دخول محظور مؤقتًا', 'دخول ناجح']:
    check('التسمية: ' + label, label in html)
check('الفشل مميز بالفئة الدلالية الموجودة', '<span class="status status-empty">محاولة دخول فاشلة</span>' in html
      and '<span class="status status-empty">دخول محظور مؤقتًا</span>' in html)
check('وصف المتصفح المختصر', 'Chrome على Windows' in html and '>curl<' in html)
m = re.search(r'محاولات الدخول الفاشلة <strong>([^<]+)</strong>.*?المحظورة مؤقتًا <strong>([^<]+)</strong>', html, re.S)
check('ملخص آخر 24 ساعة: 9 فاشلة على الأقل وحظر واحد على الأقل', m is not None and arabic_int(m.group(1)) >= 9 and arabic_int(m.group(2)) >= 1,
      m.groups() if m else 'no summary')
check('لا شرطة طويلة في الصفحة', re.search('[\u2013\u2014]', html) is None)

# ------------------------------------------------------------------
section('تغيير كلمة المرور')
marker = last_event_id()
token, _ = a.csrf('index.php?r=settings')
new_pw = 'AuthLog-Pass-71'
s, _, _ = a.post('index.php?r=settings', {'csrf': token, 'action': 'password', 'current_password': ADMIN[1], 'new_password': new_pw, 'confirm_password': new_pw})
check('تغيير كلمة المرور نجح', s == 303, s)
ADMIN = (ADMIN[0], new_pw)
check('سُجل تغيير كلمة المرور باسم المدير', [(r[0], r[1]) for r in events_after(marker)] == [('password_changed', 'admin')], events_after(marker))

section('انتهاء الجلسة عند الخمول')
marker = last_event_id()
sess_file = f'{SITE}/app/storage/sessions/sess_{a.cookie().value}'
with open(sess_file, encoding='utf-8') as fh:
    content = fh.read()
last = int(re.search(r'last_activity\|i:(\d+);', content).group(1))
with open(sess_file, 'w', encoding='utf-8') as fh:
    fh.write(content.replace(f'last_activity|i:{last};', f'last_activity|i:{last - 3 * 3600};'))
s, h, _ = a.get('index.php?r=inventory')
check('الجلسة الخاملة تحوّل للدخول', s == 303 and 'r=login' in h.get('Location', ''), s)
a.get('index.php?r=login')
check('سُجل انتهاء الجلسة مرة واحدة باسم المدير', [(r[0], r[1]) for r in events_after(marker)] == [('session_expired', 'admin')], events_after(marker))

section('الخروج')
a.login()
marker = last_event_id()
s, _, _ = a.get('index.php?r=logout')
check('الخروج عبر GET مرفوض ولا يُسجل', s == 405 and events_after(marker) == [], s)
s, _, _ = a.post('index.php?r=logout', {'csrf': 'bad'})
check('الخروج برمز CSRF خاطئ مرفوض ولا يُسجل', s == 400 and events_after(marker) == [], s)
token, _ = a.csrf('index.php?r=inventory')
s, h, _ = a.post('index.php?r=logout', {'csrf': token})
check('الخروج نجح', s == 303 and 'r=login' in h.get('Location', ''), s)
check('سُجل الخروج باسم المدير', [(r[0], r[1]) for r in events_after(marker)] == [('logout', 'admin')], events_after(marker))
before = events_after(marker)
s, _, _ = Client().post('index.php?r=logout', {'csrf': 'x'})
check('زائر بدون جلسة لا يسجل خروجًا', s == 303 and events_after(marker) == before, s)

# ------------------------------------------------------------------
section('استعادة كلمة المرور من install.php')
marker = last_event_id()
shutil.copy(os.path.join(REPO, 'public_html', 'install.php'), SITE + '/install.php')
open(SITE + '/app/storage/reset.allow', 'w').close()
subprocess.run(['chown', 'www-data:www-data', SITE + '/install.php', SITE + '/app/storage/reset.allow'])
rescue = Client()
token, html = rescue.csrf('install.php')
check('وضع الاستعادة متاح', 'استعادة كلمة مرور المدير' in html)
reset_pw = 'Reset-AuthLog-88'
s, _, html = rescue.post('install.php', {'csrf': token, 'install_key': KEY, 'username': 'ADMIN', 'password': reset_pw, 'password_confirm': reset_pw})
check('الاستعادة نجحت', 'تم تعيين كلمة المرور' in html, s)
ADMIN = (ADMIN[0], reset_pw)
check('سُجلت الاستعادة بالاسم المحفوظ', [(r[0], r[1]) for r in events_after(marker)] == [('password_reset', 'admin')], events_after(marker))
for leftover in [SITE + '/install.php', SITE + '/app/storage/reset.allow']:
    if os.path.exists(leftover):
        os.remove(leftover)

section('كل أنواع الأحداث ظاهرة في الصفحة')
f = Client()
s, h, _ = f.login()
check('الدخول بكلمة المرور المستعادة', s == 303, s)
_, _, html = f.get('index.php?r=settings')
for label in LABELS:
    check('ظاهر: ' + label, label in html)
sql('DELETE FROM login_attempts')

print(f'\nHTTP auth log: {PASS} passed, {len(FAILS)} failed')
for fl in FAILS:
    print('  - ' + fl)
raise SystemExit(1 if FAILS else 0)
