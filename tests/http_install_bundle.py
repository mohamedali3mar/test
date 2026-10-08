#!/usr/bin/env python3
"""
تثبيت جديد بنفس طريق صاحب الموقع على Hostinger: ملف ZIP مفكوك، و config.php من tools/hostinger-config.php
داخل app/، ثم install.php من المتصفح، ثم دورة عمل قصيرة. يشغله tests/install_bundle.sh لكل تخطيط
(النطاق مباشرة، أو مجلد فرعي).

البيئة: WOOD_BASE (عنوان الموقع، مثل http://localhost:8080/ أو http://localhost:8080/wood/)، WOOD_DB، WOOD_KEY (مفتاح التثبيت).
"""
import http.cookiejar
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ['WOOD_BASE']
DB = os.environ['WOOD_DB']
KEY = os.environ['WOOD_KEY']
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


def sql(q):
    return subprocess.run(['mariadb', '-uroot', DB, '-N', '-e', q], capture_output=True, text=True).stdout.strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
        self.token = ''

    def req(self, path, data=None, raw=False):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers={
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36'})
        try:
            resp = self.opener.open(r, timeout=120)
            s, h, c = resp.status, resp.headers, resp.read()
        except urllib.error.HTTPError as e:
            s, h, c = e.code, e.headers, e.read()
        return s, h, (c if raw else c.decode('utf-8', 'replace'))

    def page(self, path):
        s, _, html = self.req(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
        if m:
            self.token = m.group(1)
        return s, html

    def post(self, path, data):
        return self.req(path, data=dict(data, csrf=self.token))


def token_of(html):
    m = re.search(r'name="request_token" value="([a-f0-9]{32})"', html)
    return m.group(1) if m else ''


def option_value(html, select_id, label):
    m = re.search(r'<select[^>]*id="' + re.escape(select_id) + r'"[^>]*>(.*?)</select>', html, re.S)
    for value, text in re.findall(r'<option value="([^"]*)"[^>]*>([^<]*)</option>', m.group(1) if m else ''):
        if text.strip() == label:
            return value
    return ''


print(f'\n== تثبيت جديد على {BASE}')
c = Client()
s, html = c.page('install.php')
check('صفحة التثبيت تفتح بلا أخطاء إعدادات', s == 200 and 'name="install_key"' in html and 'غير مكتملة' not in html and 'تعذر الاتصال' not in html, s)
check('حقول التثبيت كما في الدليل', all(f'name="{n}"' in html for n in ['install_key', 'company_name', 'branch_name', 'warehouse_name', 'username', 'password', 'password_confirm']))
s, _, html = c.post('install.php', {'install_key': 'wrong-key-0000000000', 'company_name': 'دلتا للأخشاب', 'branch_name': 'الفرع الرئيسي',
                                    'warehouse_name': 'المخزن الرئيسي', 'username': 'owner', 'password': 'Owner-Pass-2026', 'password_confirm': 'Owner-Pass-2026'})
check('مفتاح تثبيت خاطئ مرفوض', 'مفتاح التثبيت غير صحيح' in html)
c.page('install.php')
s, _, html = c.post('install.php', {'install_key': KEY, 'company_name': 'دلتا للأخشاب', 'branch_name': 'الفرع الرئيسي',
                                    'warehouse_name': 'المخزن الرئيسي', 'username': 'owner', 'password': 'Owner-Pass-2026', 'password_confirm': 'Owner-Pass-2026'})
check('التثبيت نجح بمفتاح config.php', 'تم التثبيت' in html, s)
s, _, _ = c.req('install.php')
check('install.php حذف نفسه', s == 404, s)
applied = sql("SELECT GROUP_CONCAT(name ORDER BY name) FROM settings WHERE name LIKE 'migration\\_%'")
check('الترقيات كلها مطبقة (001 إلى 006)', applied == ','.join(f'migration_{i:03d}' for i in range(1, 7))
      and sql("SELECT value FROM settings WHERE name = 'schema_version'") == '6', applied)

for path in ['app/config.php', 'app/config.sample.php', 'app/storage/logs/php-error.log', 'app/storage/installed.lock', 'app/migrations/001_initial.sql',
             'app/lib/core.php', 'app/vendor/autoload.php', 'assets/js/app.js', 'assets/js/tables.js', '.htaccess']:
    s, _, body = Client().req(path)
    check(f'{path} مرفوض من المتصفح', s in (403, 404) and 'password' not in body and 'install_key' not in body, s)
s, _, _ = Client().req('assets/css/app.css')
check('ملف CSS العام متاح', s == 200, s)

c = Client()
c.page('index.php?r=login')
s, h, _ = c.post('index.php?r=login', {'username': 'owner', 'password': 'Owner-Pass-2026'})
check('دخول المدير', s == 303 and 'r=home' in (h.get('Location') or ''), s)
s, html = c.page('index.php?r=inventory')
check('المخزون يفتح وفيه اسم الشركة', s == 200 and 'دلتا للأخشاب' in html, s)
s, _, body = c.req('index.php?r=asset&f=tables.js')
check('tables.js للمستخدم المسجل', s == 200 and 'data-tools-slot' in body, s)

c.page('index.php?r=types')
c.post('index.php?r=types', {'action': 'create', 'name': 'زان'})
_, html = c.page('index.php?r=receive')
t = option_value(html, 'wood_type_id', 'زان')
wh = option_value(html, 'warehouse_id', 'المخزن الرئيسي')
s, h, _ = c.post('index.php?r=receive', {'warehouse_id': wh, 'wood_type_id': t, 'width': '10', 'width_unit': 'cm', 'thickness': '5', 'thickness_unit': 'cm',
                                         'length': '3', 'length_unit': 'm', 'quantity': '20', 'party_name': '', 'reference': '', 'notes': '', 'request_token': token_of(html)})
check('وارد', s == 303 and 'done=' in (h.get('Location') or ''), s)
item = sql(f'SELECT id FROM items WHERE wood_type_id = {t}') if t else ''
_, html = c.page('index.php?r=sell')
s, h, _ = c.post('index.php?r=sell', {'action': 'confirm', 'warehouse_id': wh, 'party_name': 'عميل', 'notes': '', 'request_token': token_of(html),
                                      'lines[0][type_id]': '', 'lines[0][item_id]': item, 'lines[0][quantity]': '4', 'lines[0][price]': '20000'})
m = re.search(r'done=(\d+)', h.get('Location') or '')
check('فاتورة بيع', s == 303 and m is not None, s)
sale = m.group(1) if m else '0'
s, h, body = c.req(f'index.php?r=pdf&id={sale}', raw=True)
check('تحميل PDF الفاتورة (mPDF من app/vendor)', s == 200 and body.startswith(b'%PDF-'), s)
s, h, body = c.req('index.php?r=export&t=inventory&format=xlsx', raw=True)
check('تصدير المخزون إلى Excel', s == 200 and body[:2] == b'PK', s)
s, h, body = c.req('index.php?r=export&t=documents&format=pdf', raw=True)
check('تقرير PDF للسجل', s == 200 and body.startswith(b'%PDF-'), s)
s, html = c.page('index.php?r=settings')
check('الإعدادات بلا «تحديث قاعدة البيانات مطلوب»', s == 200 and 'تحديث قاعدة البيانات مطلوب' not in html, s)
check('الجلسات تُحفظ داخل app/storage/sessions', subprocess.run(['sh', '-c', 'ls /srv/wood/app/storage/sessions/sess_* /srv/wood/*/app/storage/sessions/sess_* 2>/dev/null | head -1'],
                                                              capture_output=True, text=True).stdout.strip() != '')

print(f'\nResult: {PASS} passed, {len(FAILS)} failed')
for f in FAILS:
    print('  - ' + f)
raise SystemExit(1 if FAILS else 0)
