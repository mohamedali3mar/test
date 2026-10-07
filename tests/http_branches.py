#!/usr/bin/env python3
"""
اختبار HTTP للفروع عبر Apache الحقيقي: التثبيت بفرع أول، وإضافة فرع ومخازن، ووارد وبيع وتحويل بين فرعين،
ثم تقييد المدير بفرع واحد مباشرة في قاعدة البيانات والتحقق من النطاق على الخادم:
مستندات الفرع الآخر 404، والتصفية والإجماليات مقيدة، والطلبات المصطنعة لمخازن الفرع الآخر مرفوضة.

يتطلب موقعًا جديدًا غير مثبت وقاعدة بيانات فارغة. الإعدادات من متغيرات البيئة:
  WOOD_BASE        عنوان الموقع (افتراضيًا http://localhost:8080/)
  WOOD_DB          اسم قاعدة البيانات للفحص المباشر عبر mariadb -uroot (افتراضيًا wood_e2e)
  WOOD_USER / WOOD_PASS   حساب المدير الذي يُنشأ عند التثبيت
  WOOD_INSTALL_KEY مفتاح التثبيت في app/config.php
  WOOD_ERROR_LOGS  ملفات سجل الأخطاء مفصولة بفاصلة، تُفحص في النهاية بحثًا عن تحذيرات PHP
التشغيل: python3 -I tests/http_branches.py
"""
import http.cookiejar
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('WOOD_BASE', 'http://localhost:8080/')
if not BASE.endswith('/'):
    BASE += '/'
DB = os.environ.get('WOOD_DB', 'wood_e2e')
ADMIN = (os.environ.get('WOOD_USER', 'admin'), os.environ.get('WOOD_PASS', 'Correct-Horse-9'))
KEY = os.environ.get('WOOD_INSTALL_KEY', 'k7Hq2Zp9Lw4Xv1Nb8Rt5')
ERROR_LOGS = [p for p in os.environ.get('WOOD_ERROR_LOGS', '/var/log/apache2/wood-error.log').split(',') if p]
NO_ACCESS = 'لا تملك صلاحية على هذا المخزن.'
ADMIN_ONLY = 'متاحة فقط لمستخدم يرى كل الفروع'
PASS = 0
FAILS = []


def check(label, ok, detail=''):
    global PASS
    if ok:
        PASS += 1
        print('  PASS  ' + label)
    else:
        FAILS.append(label + (' -> ' + str(detail)[:300] if detail != '' else ''))
        print('  FAIL  ' + label + ('  -> ' + str(detail)[:300] if detail != '' else ''))


def section(t):
    print('\n== ' + t)


def sql(q):
    r = subprocess.run(['mariadb', '-uroot', DB, '-N', '-e', q], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.token = ''

    def req(self, path, data=None, headers=None):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        r = urllib.request.Request(BASE + path, data=body, headers=headers or {})
        try:
            resp = self.opener.open(r, timeout=30)
            return resp.status, resp.headers, resp.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode('utf-8', 'replace')

    def get(self, path, headers=None):
        return self.req(path, headers=headers)

    def post(self, path, data):
        return self.req(path, data=dict(data, csrf=self.token))

    def page(self, path):
        """GET يحدّث رمز CSRF ويعيد (الحالة، الصفحة)"""
        s, _, html = self.get(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
        if m:
            self.token = m.group(1)
        return s, html

    def follow(self, status, headers):
        """يتبع تحويل POST/redirect/GET ويعيد الصفحة الناتجة"""
        if status in (302, 303) and headers.get('Location'):
            return self.get(headers['Location'].replace(BASE, ''))[2]
        return ''


def token_of(html):
    m = re.search(r'name="request_token" value="([a-f0-9]{32})"', html)
    return m.group(1) if m else ''


def select_options(html, select_id):
    m = re.search(r'<select[^>]*id="' + re.escape(select_id) + r'"[^>]*>(.*?)</select>', html, re.S)
    return re.findall(r'<option value="(\d+)"', m.group(1)) if m else []


def receipt(cl, wh, type_id, qty, w='10'):
    _, html = cl.page('index.php?r=receive')
    return cl.post('index.php?r=receive', {
        'warehouse_id': str(wh), 'wood_type_id': str(type_id), 'width': w, 'width_unit': 'cm', 'thickness': '5', 'thickness_unit': 'cm',
        'length': '3', 'length_unit': 'm', 'quantity': str(qty), 'party_name': '', 'reference': '', 'notes': '', 'request_token': token_of(html)})


def sale(cl, wh, item, qty, price):
    _, html = cl.page('index.php?r=sell')
    return cl.post('index.php?r=sell', {
        'action': 'confirm', 'warehouse_id': str(wh), 'party_name': 'عميل', 'notes': '', 'request_token': token_of(html),
        'lines[0][type_id]': '', 'lines[0][item_id]': str(item), 'lines[0][quantity]': str(qty), 'lines[0][price]': str(price)})


def transfer(cl, src, dst, item, qty):
    _, html = cl.page('index.php?r=transfer')
    return cl.post('index.php?r=transfer', {
        'action': 'save', 'warehouse_id': str(src), 'to_warehouse_id': str(dst), 'notes': '', 'request_token': token_of(html),
        'lines[0][type_id]': '', 'lines[0][item_id]': str(item), 'lines[0][quantity]': str(qty)})


def count_docs():
    return int(sql('SELECT COUNT(*) FROM documents'))


log_sizes = {p: (os.path.getsize(p) if os.path.exists(p) else 0) for p in ERROR_LOGS}

# ------------------------------------------------------------------
section('التثبيت بفرع أول')
c = Client()
s, html = c.page('install.php')
check('نموذج التثبيت يطلب اسم أول فرع (الافتراضي «الفرع الرئيسي»)', 'id="branch_name"' in html and 'value="الفرع الرئيسي"' in html, s)
s, _, html = c.post('install.php', {'install_key': KEY, 'company_name': 'شركة الفروع', 'branch_name': 'فرع القاهرة',
                                    'warehouse_name': 'مخزن القاهرة', 'username': ADMIN[0], 'password': ADMIN[1], 'password_confirm': ADMIN[1]})
check('التثبيت نجح', 'تم التثبيت' in html, s)
check('فرع واحد فقط: الفرع الافتراضي أُعيدت تسميته', sql('SELECT GROUP_CONCAT(name) FROM branches') == 'فرع القاهرة')
cairo = sql("SELECT id FROM branches WHERE name = 'فرع القاهرة'")
wh_cairo = sql("SELECT id FROM warehouses WHERE name = 'مخزن القاهرة'")
check('أول مخزن داخل أول فرع', sql(f'SELECT branch_id FROM warehouses WHERE id = {wh_cairo}') == cairo)

a = Client()
a.page('index.php?r=login')
s, h, _ = a.post('index.php?r=login', {'username': ADMIN[0], 'password': ADMIN[1]})
check('تسجيل الدخول', s == 303, s)

# ------------------------------------------------------------------
section('إضافة فرع ومخازن')
s, html = a.page('index.php?r=branches')
check('صفحة الفروع تعمل وفيها نموذج الإضافة', s == 200 and 'إضافة فرع' in html and 'ملخص الفروع' in html, s)
s, h, _ = a.post('index.php?r=branches', {'action': 'create', 'name': 'فرع الإسكندرية', 'address': 'طريق الحرية، الإسكندرية', 'phone': '٠٣ ٤٨٧-١٢٣٤'})
html = a.follow(s, h)
check('إضافة فرع عبر HTTP', s == 303 and 'تمت إضافة الفرع' in html, s)
alex = sql("SELECT id FROM branches WHERE name = 'فرع الإسكندرية'")
check('  الهاتف محفوظ بالأرقام الإنجليزية', sql(f'SELECT phone FROM branches WHERE id = {alex}') == '03 487-1234')
s, _, html = a.post('index.php?r=branches', {'action': 'create', 'name': '  فرع  الإسكندرية ', 'address': '', 'phone': ''})
check('اسم فرع مكرر مرفوض', 'يوجد فرع بنفس الاسم' in html and sql('SELECT COUNT(*) FROM branches') == '2')
s, html = a.page('index.php?r=warehouses')
check('صفحة المخازن فيها عمود الفرع واختيار الفرع', s == 200 and '<th scope="col">الفرع</th>' in html and 'id="new-branch"' in html)
check('  الفرع الافتراضي في نموذج الإضافة هو أول فرع', re.search(r'id="new-branch".*?<option value="(\d+)" selected', html, re.S) is not None)
s, h, _ = a.post('index.php?r=warehouses', {'action': 'create', 'name': 'مخزن الإسكندرية', 'branch_id': alex})
check('إضافة مخزن في فرع الإسكندرية', s == 303 and sql("SELECT branch_id FROM warehouses WHERE name = 'مخزن الإسكندرية'") == alex, s)
wh_alex = sql("SELECT id FROM warehouses WHERE name = 'مخزن الإسكندرية'")
s, h, _ = a.post('index.php?r=warehouses', {'action': 'create', 'name': 'مخزن مؤقت', 'branch_id': cairo})
wh_tmp = sql("SELECT id FROM warehouses WHERE name = 'مخزن مؤقت'")
s, html = a.page(f'index.php?r=warehouses&move={wh_tmp}')
check('نموذج نقل مخزن إلى فرع آخر', 'id="move-branch"' in html and select_options(html, 'move-branch') == [alex])
s, h, _ = a.post(f'index.php?r=warehouses&move={wh_tmp}', {'action': 'move', 'id': wh_tmp, 'branch_id': alex})
html = a.follow(s, h)
check('نقل مخزن إلى فرع الإسكندرية', s == 303 and 'تم نقل المخزن' in html and sql(f'SELECT branch_id FROM warehouses WHERE id = {wh_tmp}') == alex, s)
s, _, html = a.post(f'index.php?r=warehouses&move={wh_tmp}', {'action': 'move', 'id': wh_tmp, 'branch_id': alex})
check('  النقل لنفس الفرع مرفوض برسالة', 'بالفعل' in html)
a.post('index.php?r=warehouses', {'action': 'delete', 'id': wh_tmp})
check('  حذف المخزن المؤقت غير المستخدم', sql(f'SELECT COUNT(*) FROM warehouses WHERE id = {wh_tmp}') == '0')
a.page('index.php?r=types')
a.post('index.php?r=types', {'action': 'create', 'name': 'زان'})
type_id = sql("SELECT id FROM wood_types WHERE name = 'زان'")

# ------------------------------------------------------------------
section('حركات في الفرعين')
s, h, _ = receipt(a, wh_cairo, type_id, 50)
check('وارد في القاهرة', s == 303, s)
s, h, _ = receipt(a, wh_alex, type_id, 30)
check('وارد في الإسكندرية', s == 303, s)
item = sql('SELECT id FROM items LIMIT 1')
s, h, _ = sale(a, wh_alex, item, 4, '2000')
check('بيع من الإسكندرية', s == 303, s)
sale_alex = sql(f"SELECT id FROM documents WHERE kind = 'sale' AND warehouse_id = {wh_alex}")
s, h, _ = sale(a, wh_cairo, item, 2, '1000')
check('بيع من القاهرة', s == 303, s)
sale_cairo = sql(f"SELECT id FROM documents WHERE kind = 'sale' AND warehouse_id = {wh_cairo}")
s, h, _ = transfer(a, wh_cairo, wh_alex, item, 5)
check('تحويل من القاهرة إلى الإسكندرية', s == 303, s)
tr = sql("SELECT id FROM documents WHERE kind = 'transfer'")
check('المستندات تحفظ الفرع وقت الحركة', sql(f'SELECT CONCAT(branch_name, "|", IFNULL(to_branch_name, "")) FROM documents WHERE id = {tr}') == 'فرع القاهرة|فرع الإسكندرية'
      and sql(f'SELECT branch_name FROM documents WHERE id = {sale_alex}') == 'فرع الإسكندرية')
rcv_cairo = sql(f"SELECT id FROM documents WHERE kind = 'in' AND warehouse_id = {wh_cairo}")

s, html = a.page(f'index.php?r=print&id={sale_alex}')
check('الفاتورة المطبوعة تذكر الفرع وعنوانه وهاتفه', 'فرع: فرع الإسكندرية' in html and 'طريق الحرية، الإسكندرية' in html and '٠٣ ٤٨٧-١٢٣٤' in html, s)
s, html = a.page(f'index.php?r=document&id={tr}')
check('تفاصيل التحويل تذكر الفرعين', 'من فرع' in html and 'إلى فرع' in html and 'فرع الإسكندرية' in html, s)
s, html = a.page('index.php?r=documents')
check('سجل المستندات فيه عمود الفرع وتصفية الفرع', '<th scope="col">الفرع</th>' in html and 'id="branch"' in html, s)
check('  التحويل بين الفرعين يظهر «من … إلى …»', 'من فرع القاهرة إلى فرع الإسكندرية' in html)
s, html = a.page(f'index.php?r=documents&branch={alex}')
check('تصفية سجل المستندات بفرع الإسكندرية', f'id={sale_alex}"' in html and f'id={sale_cairo}"' not in html and f'id={tr}"' in html, s)
# القطعة 0.1 × 0.05 × 3 = 0.015 م³: بيع الإسكندرية 4 × 0.015 × 2000 = 120، وبيع القاهرة 2 × 0.015 × 1000 = 30
check('  إجمالي مبيعات الفرع المختار فقط (١٢٠٫٠٠)', 'الإجمالي: <strong>١٢٠٫٠٠ جنيه مصري</strong>' in html)
s, html = a.page('index.php?r=inventory')
check('المخزون لكل الفروع: ملخص الفروع مع مخازنها', 'ملخص الفروع' in html and 'إجمالي كل الفروع' in html and 'مخزن الإسكندرية' in html, s)
s, html = a.page(f'index.php?r=inventory&branch={alex}')
check('تصفية المخزون بفرع: المخازن المعروضة من الفرع فقط', select_options(html, 'warehouse') == [wh_alex] and 'أرصدة الفرع: فرع الإسكندرية' in html)
check('  إجمالي الفرع (٣٠ - ٤ + ٥ = ٣١ قطعة)', 'في ٣١ قطعة' in html, re.findall(r'في [^<]* قطعة', html))
s, html = a.page('index.php?r=branches')
check('ملخص الفروع: مبيعات الشهر لكل فرع', '١ فاتورة، ١٢٠٫٠٠ جنيه مصري' in html and '١ فاتورة، ٣٠٫٠٠ جنيه مصري' in html, s)
check('  والإجمالي العام', '٢ فاتورة، ١٥٠٫٠٠ جنيه مصري' in html)

# ------------------------------------------------------------------
section('المدير مقيد بفرع القاهرة (مباشرة في قاعدة البيانات)')
sql(f"UPDATE users SET branch_id = {cairo} WHERE username = '{ADMIN[0]}'")
for path in [f'document&id={sale_alex}', f'print&id={sale_alex}']:
    s, html = a.page('index.php?r=' + path)
    check(f'مستند فرع آخر 404: {path}', s == 404 and 'فرع الإسكندرية' not in html, s)
for path in [f'document&id={rcv_cairo}', f'print&id={sale_cairo}', f'document&id={tr}']:
    s, _ = a.page('index.php?r=' + path)
    check(f'مستند الفرع ظاهر: {path}', s == 200, s)
s, html = a.page(f'index.php?r=document&id={tr}')
check('التحويل بين الفرعين بلا نموذج إلغاء للمستخدم المقيد', 'name="action" value="cancel"' not in html and 'فرعَي التحويل' in html)
s, _, html = a.post(f'index.php?r=document&id={tr}', {'action': 'cancel', 'confirm': '1', 'reason': ''})
check('  إلغاء مصطنع للتحويل مرفوض', 'أحد طرفيه في فرع آخر' in html and sql(f'SELECT status FROM documents WHERE id = {tr}') == 'active', s)
s, _, html = a.post(f'index.php?r=document&id={sale_alex}', {'action': 'cancel', 'confirm': '1', 'reason': ''})
check('إلغاء مصطنع لمستند الفرع الآخر: 404 ولا تغيير', s == 404 and sql(f'SELECT status FROM documents WHERE id = {sale_alex}') == 'active', s)
s, html = a.page(f'index.php?r=sell&done={sale_alex}')
check('صفحة «تم الحفظ» لا تعرض مستند فرع آخر', 'تم الحفظ' not in html, s)

s, html = a.page('index.php?r=documents')
check('السجل: مستندات القاهرة والتحويل الصادر فقط', f'id={sale_cairo}"' in html and f'id={tr}"' in html and f'id={sale_alex}"' not in html, s)
check('  بلا تصفية فروع للمستخدم المقيد', 'id="branch"' not in html)
check('  إجمالي مبيعات القاهرة فقط (٣٠٫٠٠)', 'الإجمالي: <strong>٣٠٫٠٠ جنيه مصري</strong>' in html and '١٢٠٫٠٠' not in html)
s, html = a.page(f'index.php?r=documents&branch={alex}&warehouse={wh_alex}')
check('  تصفية مصطنعة بفرع آخر ومخزنه لا تكشف شيئًا', f'id={sale_alex}"' not in html and select_options(html, 'warehouse') == [wh_cairo], s)
s, html = a.page(f'index.php?r=inventory&branch={alex}&warehouse={wh_alex}')
check('المخزون: مخازن القاهرة وأرصدتها فقط', s == 200 and select_options(html, 'warehouse') == [wh_cairo] and 'مخزن الإسكندرية' not in html and 'ملخص الفروع' not in html)
check('  الإجمالي ٥٠ - ٢ - ٥ = ٤٣ قطعة', 'في ٤٣ قطعة' in html, re.findall(r'في [^<]* قطعة', html))
s, _, body = a.get('index.php?r=api&op=stock', headers={'X-Live': '1'})
data = json.loads(body)
keys = sorted({k for it in data['items'] for k in it['stock']})
check('JSON الأرصدة: مخازن القاهرة فقط', s == 200 and keys == [wh_cairo], keys)
s, html = a.page('index.php?r=receive')
check('نموذج الوارد: مخازن القاهرة فقط', select_options(html, 'warehouse_id') == [wh_cairo])
s, html = a.page('index.php?r=transfer')
check('نموذج التحويل: المصدر من القاهرة، والمستلم أي فرع', select_options(html, 'warehouse_id') == [wh_cairo]
      and sorted(select_options(html, 'to_warehouse_id')) == sorted([wh_cairo, wh_alex]))

before = count_docs()
qty_alex = sql(f'SELECT qty_on_hand FROM stock WHERE warehouse_id = {wh_alex}')
s, _, html = receipt(a, wh_alex, type_id, 3)
check('وارد مصطنع لمخزن الفرع الآخر مرفوض', s == 200 and NO_ACCESS in html and count_docs() == before, s)
s, _, html = sale(a, wh_alex, item, 1, '100')
check('بيع مصطنع من مخزن الفرع الآخر مرفوض', s == 200 and NO_ACCESS in html and count_docs() == before, s)
_, html = a.page('index.php?r=sell')
s, _, html = a.post('index.php?r=sell', {'action': 'review', 'warehouse_id': wh_alex, 'party_name': '', 'notes': '', 'request_token': token_of(html),
                                         'lines[0][type_id]': '', 'lines[0][item_id]': item, 'lines[0][quantity]': '1', 'lines[0][price]': '100'})
check('  ومراجعة الفاتورة أيضًا', NO_ACCESS in html and 'مراجعة فاتورة البيع' not in html)
s, _, html = transfer(a, wh_alex, wh_cairo, item, 1)
check('تحويل مصطنع من مخزن الفرع الآخر مرفوض', s == 200 and NO_ACCESS in html and count_docs() == before, s)
check('  رصيد الفرع الآخر لم يتغير', sql(f'SELECT qty_on_hand FROM stock WHERE warehouse_id = {wh_alex}') == qty_alex)
s, h, _ = transfer(a, wh_cairo, wh_alex, item, 1)
check('تحويل من القاهرة إلى مخزن الفرع الآخر مسموح', s == 303 and count_docs() == before + 1, s)

s, html = a.page('index.php?r=branches')
check('صفحة الفروع: فرعه فقط وبلا إدارة', s == 200 and 'إضافة فرع' not in html and 'فرع الإسكندرية' not in html and 'فرع القاهرة' in html)
s, _, html = a.post('index.php?r=branches', {'action': 'create', 'name': 'فرع مصطنع', 'address': '', 'phone': ''})
check('  إضافة فرع مصطنعة مرفوضة', ADMIN_ONLY in html and sql('SELECT COUNT(*) FROM branches') == '2', s)
s, _, html = a.post('index.php?r=branches', {'action': 'delete', 'id': alex})
check('  حذف فرع مصطنع مرفوض', ADMIN_ONLY in html and sql('SELECT COUNT(*) FROM branches') == '2', s)
s, html = a.page('index.php?r=warehouses')
check('صفحة المخازن: مخازن فرعه فقط وبلا إدارة', 'مخزن الإسكندرية' not in html and 'id="new-name"' not in html and 'مخزن القاهرة' in html)
s, _, html = a.post('index.php?r=warehouses', {'action': 'create', 'name': 'مخزن مصطنع', 'branch_id': cairo})
check('  إضافة مخزن مصطنعة مرفوضة', ADMIN_ONLY in html and sql("SELECT COUNT(*) FROM warehouses WHERE name = 'مخزن مصطنع'") == '0', s)
s, _, html = a.post('index.php?r=warehouses', {'action': 'move', 'id': wh_alex, 'branch_id': cairo})
check('  نقل مخزن مصطنع مرفوض', ADMIN_ONLY in html and sql(f'SELECT branch_id FROM warehouses WHERE id = {wh_alex}') == alex, s)
s, _, html = a.post('index.php?r=warehouses', {'action': 'rename', 'id': wh_alex, 'name': 'اسم مصطنع'})
check('  تعديل اسم مخزن مصطنع مرفوض', ADMIN_ONLY in html and sql(f'SELECT name FROM warehouses WHERE id = {wh_alex}') == 'مخزن الإسكندرية', s)

sql(f"UPDATE users SET branch_id = NULL WHERE username = '{ADMIN[0]}'")
s, html = a.page(f'index.php?r=document&id={sale_alex}')
check('بعد إزالة التقييد يعود كل شيء ظاهرًا', s == 200)

# ------------------------------------------------------------------
section('سجل الأخطاء')
problems = []
for p in ERROR_LOGS:
    if os.path.exists(p):
        with open(p, encoding='utf-8', errors='replace') as f:
            f.seek(log_sizes.get(p, 0) if os.path.getsize(p) >= log_sizes.get(p, 0) else 0)
            problems += [ln.strip() for ln in f if re.search(r'PHP (Warning|Notice|Deprecated|Fatal|Parse)|\[wood\]', ln)]
check('لا تحذيرات PHP ولا أخطاء في السجل', not problems, problems[:5])

print(f'\nHTTP branches: {PASS} passed, {len(FAILS)} failed')
for f in FAILS:
    print('  - ' + f)
raise SystemExit(1 if FAILS else 0)
