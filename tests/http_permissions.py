#!/usr/bin/env python3
"""
اختبارات HTTP للأدوار والصلاحيات وسجل المراقبة عبر Apache الحقيقي (مكتبة Python القياسية فقط).

البيئة:
  WOOD_BASE         عنوان الموقع (الافتراضي http://localhost:8080/)
  WOOD_ADMIN_USER   اسم المدير (الافتراضي admin)
  WOOD_ADMIN_PASS   كلمة مرور المدير (الافتراضي Correct-Horse-9)
  WOOD_INSTALL_KEY  مفتاح التثبيت إذا لم يكن الموقع مثبتًا بعد (الافتراضي k7Hq2Zp9Lw4Xv1Nb8Rt5)
  WOOD_DB           اسم قاعدة البيانات لفحوص SQL مباشرة عبر mariadb -uroot (اختياري)
  WOOD_ERROR_LOGS   ملفات سجلات أخطاء يُفحص ما يُضاف إليها أثناء الاختبار، مفصولة بفاصلة (اختياري)

التشغيل: WOOD_BASE=http://localhost:8094/ python3 -I tests/http_permissions.py
"""
import http.cookiejar
import json
import os
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('WOOD_BASE', 'http://localhost:8080/')
if not BASE.endswith('/'):
    BASE += '/'
ADMIN = (os.environ.get('WOOD_ADMIN_USER', 'admin'), os.environ.get('WOOD_ADMIN_PASS', 'Correct-Horse-9'))
KEY = os.environ.get('WOOD_INSTALL_KEY', 'k7Hq2Zp9Lw4Xv1Nb8Rt5')
DB = os.environ.get('WOOD_DB', '')
LOGS = [p for p in os.environ.get('WOOD_ERROR_LOGS', '').split(',') if p]
ANDROID_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'
RUN = secrets.token_hex(3)  # لاحقة تسمح بإعادة التشغيل على نفس قاعدة البيانات
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
    if not DB:
        return None
    return subprocess.run(['mariadb', '-uroot', DB, '-N', '-e', q], capture_output=True, text=True).stdout.strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self, ua=None):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        # متصفح حقيقي: مرشح أدوات الأتمتة يرفض User-Agent الافتراضي لـ Python
        self.ua = ua or 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'

    def req(self, path, data=None, headers=None, method=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        h = dict(headers or {})
        if self.ua:
            h['User-Agent'] = self.ua
        r = urllib.request.Request(BASE + path, data=body, headers=h, method=method)
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
        _, _, html = self.get(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
        return (m.group(1) if m else ''), html

    def login(self, user, password):
        token, _ = self.csrf('index.php?r=login')
        return self.post('index.php?r=login', {'csrf': token, 'username': user, 'password': password})

    def follow(self, resp):
        """يفتح صفحة التحويل بعد POST (نمط POST ثم إعادة توجيه ثم GET)"""
        status, headers, body = resp
        loc = headers.get('Location') if headers else None
        if status in (301, 302, 303) and loc:
            return self.get(loc.replace(BASE, ''))
        return resp


def token_of(html):
    m = re.search(r'name="request_token" value="([a-f0-9]{32})"', html)
    return m.group(1) if m else ''


def option_value(html, select_id, label):
    """قيمة الخيار في قائمة معينة حسب النص الظاهر"""
    m = re.search(r'<select[^>]*id="' + re.escape(select_id) + r'"[^>]*>(.*?)</select>', html, re.S)
    if not m:
        return ''
    for value, text in re.findall(r'<option value="([^"]*)"[^>]*>([^<]*)</option>', m.group(1)):
        if text.strip() == label:
            return value
    return ''


def log_sizes():
    return {p: (os.path.getsize(p) if os.path.exists(p) else 0) for p in LOGS}


# ------------------------------------------------------------------
logs_at_start = log_sizes()
section('التثبيت أو الدخول كمدير')
a = Client()
s, _, html = a.get('install.php')
if s == 200 and 'name="install_key"' in html and 'استعادة' not in html:
    token, _ = a.csrf('install.php')
    s, _, html = a.post('install.php', {'csrf': token, 'install_key': KEY, 'company_name': 'شركة اختبار الصلاحيات',
                                        'warehouse_name': 'المخزن الرئيسي', 'username': ADMIN[0], 'password': ADMIN[1], 'password_confirm': ADMIN[1]})
    check('التثبيت نجح', 'تم التثبيت' in html, s)
s, h, _ = a.login(*ADMIN)
check('دخول المدير', s == 303 and 'r=inventory' in (h.get('Location') or ''), s)
_, _, html = a.get('index.php?r=inventory')
for route in ['monitor', 'users', 'settings']:
    check(f'قائمة المدير فيها رابط {route}', f'href="index.php?r={route}"' in html)
check('رابط «حسابي» في رأس الصفحة', 'href="index.php?r=account"' in html and 'حسابي: ' in html)

section('بيانات أساسية ومستخدم موظف')
type_name = 'زان اختبار ' + RUN
token, _ = a.csrf('index.php?r=types')
a.post('index.php?r=types', {'csrf': token, 'action': 'create', 'name': type_name})
a.post('index.php?r=warehouses', {'csrf': token, 'action': 'create', 'name': 'مخزن الفرع ' + RUN})
_, html = a.csrf('index.php?r=receive')
type_id = option_value(html, 'wood_type_id', type_name)
wh1 = option_value(html, 'warehouse_id', 'المخزن الرئيسي')
_, _, thtml = a.get('index.php?r=transfer')
wh2 = option_value(thtml, 'to_warehouse_id', 'مخزن الفرع ' + RUN)
check('النوع والمخزنان موجودون', type_id != '' and wh1 != '' and wh2 != '', (type_id, wh1, wh2))
receipt = {'csrf': token, 'warehouse_id': wh1, 'wood_type_id': type_id, 'width': '10', 'width_unit': 'cm', 'thickness': '50', 'thickness_unit': 'mm',
           'length': '3', 'length_unit': 'm', 'quantity': '50', 'party_name': '', 'reference': '', 'notes': '', 'request_token': token_of(html)}
s, h, _ = a.post('index.php?r=receive', receipt)
check('وارد من المدير', s == 303 and 'done=' in (h.get('Location') or ''), s)
admin_doc = re.search(r'done=(\d+)', h.get('Location') or '')
admin_doc = admin_doc.group(1) if admin_doc else '0'
_, _, shtml = a.get('index.php?r=sell')
item_id = ''
m = re.search(r'<option value="(\d+)" data-type="' + re.escape(type_id) + r'"', shtml)
if m:
    item_id = m.group(1)
check('المقاس متاح للبيع', item_id != '')

staff_user = 'staff_' + RUN
staff_pass = 'Staff-Pass-' + RUN
staff_name = 'موظف المبيعات ' + RUN
token, _ = a.csrf('index.php?r=users')
s, h, html = a.follow(a.post('index.php?r=users', {'csrf': token, 'action': 'create', 'display_name': staff_name, 'username': staff_user,
                                                    'role': 'staff', 'password': staff_pass, 'password_confirm': staff_pass}))
check('المدير أضاف موظفًا', 'تمت إضافة المستخدم' in html and staff_user in html, s)
s, _, html = a.post('index.php?r=users', {'csrf': token, 'action': 'create', 'display_name': 'مكرر', 'username': staff_user.upper(),
                                          'role': 'staff', 'password': staff_pass, 'password_confirm': staff_pass})
check('اسم مستخدم مكرر مرفوض برسالة عربية', s == 200 and 'مستخدم بالفعل' in html, s)
staff_id = ''
for uid in re.findall(r'href="index.php\?r=users&amp;edit=(\d+)"', html):
    _, _, eh = a.get(f'index.php?r=users&edit={uid}')
    if f'تعديل المستخدم {staff_user}' in eh:
        staff_id = uid
check('معرف الموظف من صفحة المستخدمين', staff_id != '', staff_id)

# ------------------------------------------------------------------
section('الموظف: الصفحات المسموحة والممنوعة')
st = Client(ANDROID_UA)
s, h, _ = st.login(staff_user, staff_pass)
check('دخول الموظف', s == 303, s)
st2 = Client()
st2.login(staff_user, staff_pass)
_, _, html = st.get('index.php?r=inventory')
for route in ['monitor', 'users', 'settings']:
    check(f'قائمة الموظف بدون رابط {route}', f'href="index.php?r={route}"' not in html)
for route in ['receive', 'sell', 'transfer', 'documents', 'types', 'warehouses']:
    check(f'قائمة الموظف فيها رابط {route}', f'href="index.php?r={route}"' in html)
check('اسم الموظف المعروض في رأس الصفحة', staff_name in html)
for route in ['settings', 'users', 'monitor', 'users&edit=1', 'settings&action=migrate']:
    s, _, body = st.get('index.php?r=' + route)
    check(f'GET {route} للموظف -> 403 برسالة عربية', s == 403 and 'ليست لديك صلاحية' in body, s)
s, h, body = st.get('index.php?r=monitor', headers={'X-Live': '1'})
check('التحديث التلقائي لصفحة ممنوعة -> 403 JSON', s == 403 and h.get('Content-Type', '').startswith('application/json'), s)
for route in ['inventory', 'receive', 'sell', 'transfer', 'documents', f'document&id={admin_doc}', f'print&id={admin_doc}', 'account', 'types', 'warehouses']:
    s, _, body = st.get('index.php?r=' + route)
    check(f'GET {route} للموظف -> 200', s == 200 and 'ليست لديك صلاحية' not in body, s)
s, _, _ = st.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('API التحديث التلقائي للموظف -> 200', s == 200, s)
_, _, html = st.get('index.php?r=types')
check('صفحة الأنواع للموظف بدون نموذج إضافة أو تعديل أو حذف', 'value="create"' not in html and 'تعديل الاسم' not in html and 'delete=' not in html and type_name in html)
_, _, html = st.get(f'index.php?r=types&edit={type_id}')
check('رابط التعديل المباشر لا يفتح نموذج التعديل للموظف', 'value="rename"' not in html)
_, _, html = st.get(f'index.php?r=document&id={admin_doc}')
check('صفحة المستند للموظف بدون نموذج الإلغاء', 'id="live-cancel"' not in html and 'value="cancel"' not in html)
check('صفحة المستند: «بواسطة» المدير', '<dt>بواسطة</dt>' in html)

section('الموظف: طلبات POST الممنوعة مرفوضة من الخادم')
token, _ = st.csrf('index.php?r=inventory')
types_before = sql('SELECT COUNT(*), GROUP_CONCAT(name ORDER BY id) FROM wood_types')
forbidden = [
    ('types', {'action': 'create', 'name': 'نوع من موظف ' + RUN}),
    ('types', {'action': 'rename', 'id': type_id, 'name': 'اسم من موظف'}),
    ('types', {'action': 'delete', 'id': type_id}),
    ('warehouses', {'action': 'create', 'name': 'مخزن من موظف ' + RUN}),
    ('warehouses', {'action': 'delete', 'id': wh2}),
    (f'document&id={admin_doc}', {'action': 'cancel', 'confirm': '1', 'reason': 'محاولة'}),
    ('settings', {'action': 'migrate'}),
    ('settings', {'action': 'general', 'company_name': 'اختراق', 'currency': 'x', 'digits': 'western', 'volume_decimals': 'full',
                  'unit_width': 'cm', 'unit_thickness': 'mm', 'unit_length': 'm'}),
    ('users', {'action': 'create', 'display_name': 'دخيل', 'username': 'intruder_' + RUN, 'role': 'admin',
               'password': 'Intruder-Pass-1', 'password_confirm': 'Intruder-Pass-1'}),
    ('users', {'action': 'update', 'id': staff_id, 'display_name': staff_name, 'role': 'admin'}),
    ('monitor', {'x': '1'}),
]
for route, data in forbidden:
    s, _, body = st.post('index.php?r=' + route, dict(data, csrf=token))
    check(f'POST {route} {data.get("action", "")} للموظف -> 403', s == 403 and 'ليست لديك صلاحية' in body, s)
_, _, html = a.get('index.php?r=types')
check('الأنواع لم تتغير', type_name in html and 'نوع من موظف' not in html and 'اسم من موظف' not in html)
_, _, html = a.get(f'index.php?r=document&id={admin_doc}')
check('المستند ما زال ساريًا', 'id="live-cancel"' in html and 'ملغى منذ' not in html)
_, _, html = a.get('index.php?r=users')
check('لم يُنشأ حساب دخيل والموظف ما زال موظفًا', 'intruder_' not in html, '')
if DB:
    check('قاعدة البيانات: الأنواع والإعدادات والمستخدمون كما هي', sql('SELECT COUNT(*), GROUP_CONCAT(name ORDER BY id) FROM wood_types') == types_before
          and sql(f"SELECT role FROM users WHERE username = '{staff_user}'") == 'staff'
          and sql("SELECT value FROM settings WHERE name = 'company_name'") != 'اختراق')

section('الموظف: الوارد والبيع والتحويل')
_, html = st.csrf('index.php?r=receive')
s, h, _ = st.post('index.php?r=receive', dict(receipt, csrf=token, quantity='7', request_token=token_of(html)))
check('الموظف سجل وارد', s == 303 and 'done=' in (h.get('Location') or ''), s)
customer = '<script>alert("x")</script> مؤسسة & شركاه'
_, html = st.csrf('index.php?r=sell')
sale = {'csrf': token, 'action': 'confirm', 'warehouse_id': wh1, 'party_name': customer, 'notes': '', 'request_token': token_of(html),
        'lines[0][type_id]': '', 'lines[0][item_id]': item_id, 'lines[0][quantity]': '3', 'lines[0][price]': '20000'}
s, h, _ = st.post('index.php?r=sell', sale)
check('الموظف سجل فاتورة بيع', s == 303 and 'done=' in (h.get('Location') or ''), s)
m = re.search(r'done=(\d+)', h.get('Location') or '')
sale_doc = m.group(1) if m else '0'
_, html = st.csrf('index.php?r=transfer')
s, h, _ = st.post('index.php?r=transfer', {'csrf': token, 'action': 'save', 'warehouse_id': wh1, 'to_warehouse_id': wh2, 'notes': '',
                                           'request_token': token_of(html), 'lines[0][type_id]': '', 'lines[0][item_id]': item_id, 'lines[0][quantity]': '2'})
check('الموظف سجل تحويلًا', s == 303 and 'done=' in (h.get('Location') or ''), s)
_, _, html = st.get(f'index.php?r=document&id={sale_doc}')
check('مستند الموظف: «بواسطة» اسمه المعروض', staff_name in html and '<dt>بواسطة</dt>' in html)
check('اسم العميل مُهرّب في صفحة المستند', '&lt;script&gt;' in html and '<script>alert(' not in html)

section('الموظف: «حسابي» وتغيير كلمة المرور')
token, html = st.csrf('index.php?r=account')
check('صفحة حسابي تعرض الدور «موظف»', '<dd>موظف</dd>' in html)
new_staff_pass = 'Staff-New-' + RUN
s, h, html = st.follow(st.post('index.php?r=account', {'csrf': token, 'action': 'password', 'current_password': staff_pass,
                                                        'new_password': new_staff_pass, 'confirm_password': new_staff_pass}))
check('الموظف غيّر كلمة مروره', 'تم تغيير كلمة المرور' in html, s)
staff_pass = new_staff_pass
s, h, _ = st2.get('index.php?r=inventory')
check('جلسة الموظف الأخرى انتهت بعد تغيير كلمة المرور', s == 303 and 'r=login' in (h.get('Location') or ''), s)
st2 = Client()
st2.login(staff_user, staff_pass)
s, _, _ = st2.get('index.php?r=inventory')
check('الدخول بكلمة المرور الجديدة', s == 200, s)

# ------------------------------------------------------------------
section('المدير: صفحة المراقبة')
s, _, html = a.get('index.php?r=monitor')
check('صفحة المراقبة تفتح للمدير', s == 200 and '<h1>المراقبة</h1>' in html, s)
check('المناطق الحية بمعرفات ثابتة', all(f'id="{i}" data-live' in html for i in ['live-monitor-summary', 'live-monitor-activity', 'live-monitor-logins']))
check('عمليات الموظف ظاهرة باسمه', staff_name in html and 'فاتورة بيع رقم' in html and 'تحويل رقم' in html and 'إضافة مستخدم' in html)
check('اسم العميل الخبيث مُهرّب في السجل', '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;' in html and '<script>alert(' not in html)
check('الجهاز بالعربية', 'كروم على أندرويد' in html)
check('عنوان IP ظاهر', re.search(r'<td data-label="عنوان IP" class="nowrap">(127\.0\.0\.1|::1)</td>', html) is not None)
check('رابط المستند من السجل', f'href="index.php?r=document&amp;id={sale_doc}">عرض المستند</a>' in html)
check('كل خلايا الجدول لها data-label', re.search(r'<td(?![^>]*data-label)[^>]*>', html.split('id="live-monitor-activity"')[1].split('</section>')[0]) is None)
check('قسم سجل الدخول', 'سجل الدخول' in html)
check('المتصلون الآن يشمل الموظف', 'المتصلون الآن' in html and staff_name in html.split('id="live-monitor-summary"')[1].split('</section>')[0])
s, _, html = a.get(f'index.php?r=monitor&user={staff_id}')
activity = html.split('id="live-monitor-activity"')[1].split('</section>')[0]
check('تصفية بالمستخدم: عمليات الموظف فقط', 'فاتورة بيع رقم' in activity and 'إضافة مستخدم' not in activity and 'إضافة نوع خشب' not in activity)
s, _, html = a.get('index.php?r=monitor&group=users')
activity = html.split('id="live-monitor-activity"')[1].split('</section>')[0]
check('تصفية بنوع العملية: المستخدمون', 'إضافة مستخدم' in activity and 'فاتورة بيع رقم' not in activity)
s, _, html = a.get('index.php?r=monitor&q=' + urllib.parse.quote('<script>'))
check('بحث في التفاصيل', s == 200 and 'فاتورة بيع رقم' in html)
s, _, html = a.get('index.php?r=monitor&user=abc&group=x&from=2026-13-45&to=bad&doc=12a&page=-5&q=' + urllib.parse.quote("%_' OR 1=1 --"))
check('مرشحات غير صالحة تُتجاهل مع ملاحظة', s == 200 and 'تم تجاهل قيم غير صالحة' in html and 'SQLSTATE' not in html, s)
s, h, html = a.get('index.php?r=monitor', headers={'X-Live': '1'})
check('التحديث التلقائي لصفحة المراقبة يعيد الصفحة', s == 200 and 'id="live-monitor-activity"' in html, s)

section('المدير: صفحة المستخدمين وقواعد الأمان')
_, _, html = a.get('index.php?r=users')
check('الموظف «متصل الآن»', 'متصل الآن' in html)
check('خلايا جدول المستخدمين لها data-label', 'data-label="آخر ظهور"' in html and 'data-label="الدور"' in html)
admin_id = ''
for uid in re.findall(r'href="index.php\?r=users&amp;edit=(\d+)"', html):
    _, _, eh = a.get(f'index.php?r=users&edit={uid}')
    if f'تعديل المستخدم {ADMIN[0]}<' in eh:
        admin_id = uid
token, html = a.csrf(f'index.php?r=users&edit={admin_id}')
check('صفحة تعديل المدير لنفسه بدون تعطيل أو تعيين كلمة مرور', 'value="disable"' not in html and 'value="password"' not in html, admin_id)
s, _, html = a.post('index.php?r=users', {'csrf': token, 'action': 'disable', 'id': admin_id})
check('المدير لا يعطل نفسه (طلب مباشر)', s == 200 and 'لا يمكنك تعطيل حسابك' in html, s)
s, _, html = a.post('index.php?r=users', {'csrf': token, 'action': 'update', 'id': admin_id, 'display_name': 'المدير', 'role': 'staff'})
check('المدير لا يخفض نفسه (طلب مباشر)', s == 200 and 'لا يمكنك تغيير دورك' in html, s)
_, _, html = a.get('index.php?r=inventory')
check('المدير ما زال مديرًا', 'href="index.php?r=monitor"' in html)

section('تعطيل الموظف ينهي جلساته فورًا')
s, h, html = a.follow(a.post('index.php?r=users', {'csrf': token, 'action': 'disable', 'id': staff_id}))
check('تم التعطيل', 'تم تعطيل الحساب' in html, s)
s, h, _ = st.get('index.php?r=inventory')
check('الطلب التالي للموظف يحوّل إلى الدخول', s == 303 and 'r=login' in (h.get('Location') or ''), s)
_, _, html = st.get('index.php?r=login')
check('مع رسالة إيقاف الحساب', 'تم إيقاف هذا الحساب' in html)
s, h, _ = st2.get('index.php?r=api&op=version', headers={'X-Live': '1'})
check('التحديث التلقائي لجلسة الموظف الأخرى -> 401', s == 401 and h.get('Content-Type', '').startswith('application/json'), s)
s, _, html = Client().login(staff_user, staff_pass)
check('دخول الحساب المعطل: نفس رسالة كلمة المرور الخاطئة', s == 200 and 'اسم المستخدم أو كلمة المرور غير صحيحة' in html, s)
s, h, html = a.follow(a.post('index.php?r=users', {'csrf': token, 'action': 'enable', 'id': staff_id}))
check('إعادة التفعيل', 'تم تفعيل الحساب' in html, s)
st3 = Client()
s, _, _ = st3.login(staff_user, staff_pass)
check('الموظف يدخل بعد التفعيل', s == 303, s)

section('تعيين كلمة مرور جديدة للموظف من المدير')
reset_pass = 'Reset-Pass-' + RUN
s, h, html = a.follow(a.post('index.php?r=users', {'csrf': token, 'action': 'password', 'id': staff_id, 'password': reset_pass, 'password_confirm': reset_pass}))
check('تم التعيين', 'تم تعيين كلمة المرور الجديدة' in html, s)
s, h, _ = st3.get('index.php?r=inventory')
check('جلسة الموظف انتهت', s == 303, s)
s, _, _ = Client().login(staff_user, reset_pass)
check('الدخول بكلمة المرور الجديدة', s == 303, s)

section('سجل المراقبة يحوي إدارة المستخدمين')
_, _, html = a.get('index.php?r=monitor&group=users')
for label in ['تعطيل مستخدم', 'تفعيل مستخدم', 'تعيين كلمة مرور', 'تغيير كلمة المرور', 'إضافة مستخدم']:
    check(f'السجل فيه: {label}', label in html)
_, _, html = a.get('index.php?r=documents')
check('سجل المستندات: عمود «المستخدم» باسم الموظف', '<th scope="col" data-col="user">المستخدم</th>' in html and staff_name in html)
if DB:
    check('قاعدة البيانات: كل أسطر السجل JSON صالح', sql('SELECT COUNT(*) = SUM(details IS NULL OR JSON_VALID(details)) FROM audit_log') == '1')
    check('قاعدة البيانات: عمليات الموظف منسوبة إليه', sql(f"SELECT COUNT(*) FROM audit_log a JOIN users u ON u.id = a.user_id WHERE u.username = '{staff_user}' AND a.action IN ('doc.receipt', 'doc.sale', 'doc.transfer', 'account.password')") == '4')

section('سجلات الأخطاء')
if LOGS:
    for p, start in logs_at_start.items():
        content = ''
        if os.path.exists(p):
            with open(p, encoding='utf-8', errors='replace') as fh:
                fh.seek(start)
                content = fh.read()
        bad = [line for line in content.splitlines() if re.search(r'PHP (Warning|Notice|Deprecated|Fatal|Parse)', line)]
        check(f'لا تحذيرات PHP في {p}', not bad, bad[:3])
else:
    print('  (WOOD_ERROR_LOGS غير محدد: تخطي فحص السجلات)')

print(f'\nHTTP permissions: {PASS} passed, {len(FAILS)} failed')
for f in FAILS:
    print('  - ' + f)
raise SystemExit(1 if FAILS else 0)
