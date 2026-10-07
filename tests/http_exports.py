#!/usr/bin/env python3
"""
اختبارات HTTP لأدوات الجداول والتصدير وملف PDF للفاتورة عبر Apache الحقيقي (مكتبة Python القياسية فقط).

يتحقق من: محتوى ملفات Excel وCSV وتقرير PDF، والأعمدة الظاهرة، والترتيب على الخادم (ومنه كل صفحات السجل)،
والصلاحيات ونطاق الفرع، وحد مرات التصدير، والتسجيل في المراقبة، والحماية (POST، الطلبات من مواقع أخرى،
حقن الصيغ في CSV)، وملف PDF للفاتورة.

البيئة: WOOD_BASE (الافتراضي http://localhost:8080/)، WOOD_DB (الافتراضي wood_e2e)، WOOD_ERROR_LOGS (اختياري).
التشغيل: tests/deploy.sh dist/wood-inventory-*-upload.zip && python3 -I tests/http_exports.py
"""
import csv
import http.cookiejar
import io
import os
import re
import secrets
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import zipfile

BASE = os.environ.get('WOOD_BASE', 'http://localhost:8080/')
if not BASE.endswith('/'):
    BASE += '/'
DB = os.environ.get('WOOD_DB', 'wood_e2e')
LOGS = [p for p in os.environ.get('WOOD_ERROR_LOGS', '/var/log/apache2/wood-error.log').split(',') if p]
ADMIN = ('admin', 'Correct-Horse-9')
KEY = 'k7Hq2Zp9Lw4Xv1Nb8Rt5'
RUN = secrets.token_hex(3)
PASS = 0
FAILS = []
EXPORTS = 0  # عدد ملفات التصدير الناجحة (للمقارنة مع سجل المراقبة)


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


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'
        self.token = ''

    def req(self, path, data=None, headers=None, method=None, raw=False):
        body = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        h = dict(headers or {})
        h['User-Agent'] = self.ua
        r = urllib.request.Request(BASE + path, data=body, headers=h, method=method)
        try:
            resp = self.opener.open(r, timeout=120)
            status, hdrs, content = resp.status, resp.headers, resp.read()
        except urllib.error.HTTPError as e:
            status, hdrs, content = e.code, e.headers, e.read()
        return status, hdrs, (content if raw else content.decode('utf-8', 'replace'))

    def get(self, path, headers=None, raw=False):
        return self.req(path, headers=headers, raw=raw)

    def page(self, path):
        s, _, html = self.get(path)
        m = re.search(r'name="csrf" value="([a-f0-9]+)"', html)
        if m:
            self.token = m.group(1)
        return s, html

    def post(self, path, data):
        return self.req(path, data=dict(data, csrf=self.token))

    def login(self, user, password):
        self.page('index.php?r=login')
        return self.post('index.php?r=login', {'username': user, 'password': password})


def token_of(html):
    m = re.search(r'name="request_token" value="([a-f0-9]{32})"', html)
    return m.group(1) if m else ''


def option_value(html, select_id, label):
    m = re.search(r'<select[^>]*id="' + re.escape(select_id) + r'"[^>]*>(.*?)</select>', html, re.S)
    if not m:
        return ''
    for value, text in re.findall(r'<option value="([^"]*)"[^>]*>([^<]*)</option>', m.group(1)):
        if text.strip() == label:
            return value
    return ''


def receipt(cl, wh, type_id, qty, w='10'):
    _, html = cl.page('index.php?r=receive')
    return cl.post('index.php?r=receive', {
        'warehouse_id': str(wh), 'wood_type_id': str(type_id), 'width': w, 'width_unit': 'cm', 'thickness': '5', 'thickness_unit': 'cm',
        'length': '3', 'length_unit': 'm', 'quantity': str(qty), 'party_name': '', 'reference': '', 'notes': '', 'request_token': token_of(html)})


def sale(cl, wh, item, qty, price, party='مؤسسة البناء'):
    _, html = cl.page('index.php?r=sell')
    return cl.post('index.php?r=sell', {
        'action': 'confirm', 'warehouse_id': str(wh), 'party_name': party, 'notes': '', 'request_token': token_of(html),
        'lines[0][type_id]': '', 'lines[0][item_id]': str(item), 'lines[0][quantity]': str(qty), 'lines[0][price]': str(price)})


def doc_id(resp):
    m = re.search(r'done=(\d+)', resp[1].get('Location') or '')
    return m.group(1) if m else ''


def export(cl, query, raw=True):
    """طلب تصدير؛ يزيد العداد عند النجاح"""
    global EXPORTS
    s, h, body = cl.get('index.php?r=export&' + query, raw=raw)
    if s == 200:
        EXPORTS += 1
    return s, h, body


def csv_rows(body):
    text = body.decode('utf-8')
    return text, list(csv.reader(io.StringIO(text.lstrip('﻿'))))


def pdf_text(body):
    with tempfile.NamedTemporaryFile(suffix='.pdf', delete=False) as f:
        f.write(body)
        path = f.name
    try:
        out = subprocess.run(['pdftotext', '-enc', 'UTF-8', path, '-'], capture_output=True, text=True).stdout
    finally:
        os.unlink(path)
    import unicodedata
    return unicodedata.normalize('NFKC', out)


def log_sizes():
    return {p: (os.path.getsize(p) if os.path.exists(p) else 0) for p in LOGS}


logs_at_start = log_sizes()

# ------------------------------------------------------------------
section('التثبيت والبيانات')
a = Client()
s, html = a.page('install.php')
if s == 200 and 'name="install_key"' in html:
    s, _, html = a.post('install.php', {'install_key': KEY, 'company_name': 'شركة اختبار التصدير', 'warehouse_name': 'المخزن الرئيسي',
                                        'username': ADMIN[0], 'password': ADMIN[1], 'password_confirm': ADMIN[1]})
    check('التثبيت نجح', 'تم التثبيت' in html, s)
s, h, _ = a.login(*ADMIN)
check('دخول المدير', s == 303, s)

zan = 'زان ' + RUN
pine = 'Pine (Finland) ' + RUN
evil = '=1+2 ' + RUN
for name in [zan, pine, evil]:
    a.page('index.php?r=types')
    a.post('index.php?r=types', {'action': 'create', 'name': name})
a.page('index.php?r=branches')
a.post('index.php?r=branches', {'action': 'create', 'name': 'فرع الإسكندرية ' + RUN, 'address': 'طريق الحرية', 'phone': '034871234'})
a.page('index.php?r=warehouses')
alex = sql(f"SELECT id FROM branches WHERE name = 'فرع الإسكندرية {RUN}'")
a.post('index.php?r=warehouses', {'action': 'create', 'name': 'مخزن الإسكندرية ' + RUN, 'branch_id': alex})
_, html = a.page('index.php?r=receive')
t_zan = option_value(html, 'wood_type_id', zan)
t_pine = option_value(html, 'wood_type_id', pine)
t_evil = option_value(html, 'wood_type_id', evil)
wh1 = option_value(html, 'warehouse_id', 'المخزن الرئيسي')
wh_alex = sql(f"SELECT id FROM warehouses WHERE name = 'مخزن الإسكندرية {RUN}'")
check('الأنواع والفرع الثاني ومخزنه', all([t_zan, t_pine, t_evil, wh1, alex, wh_alex]), (t_zan, t_pine, t_evil, wh1, alex, wh_alex))

check('وارد زان عرض 10 (50 قطعة)', receipt(a, wh1, t_zan, 50)[0] == 303)
check('وارد زان عرض 20 (5 قطع)', receipt(a, wh1, t_zan, 5, '20')[0] == 303)
check('وارد صنوبر في الإسكندرية (7 قطع)', receipt(a, wh_alex, t_pine, 7, '30')[0] == 303)
check('وارد نوع يبدأ بعلامة =', receipt(a, wh1, t_evil, 2)[0] == 303)
ok = all(receipt(a, wh1, t_zan, 1)[0] == 303 for _ in range(52))
check('52 واردًا إضافيًا (السجل أكثر من صفحة)', ok)
item_zan10 = sql(f"SELECT id FROM items WHERE wood_type_id = {t_zan} AND width_um = 100000")
item_pine = sql(f"SELECT id FROM items WHERE wood_type_id = {t_pine}")
sale_main = doc_id(sale(a, wh1, item_zan10, 3, 20000))
sale_alex = doc_id(sale(a, wh_alex, item_pine, 2, 2000))
check('فاتورتان (الرئيسي والإسكندرية)', sale_main != '' and sale_alex != '', (sale_main, sale_alex))
docs_total = int(sql('SELECT COUNT(*) FROM documents'))

# ------------------------------------------------------------------
section('صفحة المخزون: شريط الأدوات والترتيب')
s, html = a.page('index.php?r=inventory')
check('شريط أدوات الجدول', 'data-table-tools="inventory"' in html and 'data-table="stock-table"' in html and 'id="stock-table"' in html)
check('أزرار التصدير الثلاثة تعمل بدون JavaScript (نموذج GET)',
      all(f'name="format" value="{f}"' in html for f in ['xlsx', 'csv', 'pdf']) and 'name="r" value="export"' in html)
check('روابط الترتيب في رؤوس الأعمدة', 'href="index.php?r=inventory&amp;sort=qty%3Aasc"' in html)
check('نموذج الترتيب للشاشات الصغيرة', 'class="table-sort"' in html and 'value="qty:desc"' in html)
check('tables.js محمّل عبر r=asset', re.search(r'<script src="index\.php\?r=asset&amp;f=tables\.js&amp;v=[^"]+" defer>', html) is not None)
check('سطر الطباعة يصف التصفية', 'class="print-only print-heading"' in html and 'كل المخازن' in html)
s, h, body = a.get('index.php?r=asset&f=tables.js')
check('tables.js للمستخدم المسجل', s == 200 and 'data-tools-slot' in body and h.get('Content-Type') == 'application/javascript; charset=utf-8', s)
s, _, body = Client().get('assets/js/tables.js')
check('assets/js/tables.js مباشرة مرفوض', s == 403 and 'data-tools-slot' not in body, s)
s, html = a.page(f'index.php?r=inventory&sort=qty:asc&type={t_zan}')
check('الترتيب المختار: aria-sort ونص «تصاعدي»', 'aria-sort="ascending"' in html and 'تصاعدي' in html)
table = html[html.find('id="stock-table"'):]
p5 = table.find('>٥<')
p99 = table.find('>٩٩<')
check('داخل النوع: 5 قطع قبل 99 عند الترتيب التصاعدي بالعدد', 0 < p5 < p99, (p5, p99))
check('الضغط مرة ثانية: تنازلي', 'sort=qty%3Adesc"' in html)
s, html = a.page('index.php?r=inventory&sort=bogus:asc')
check('ترتيب غير معروف يُتجاهل', s == 200 and 'aria-sort' not in html)

# ------------------------------------------------------------------
section('تصدير المخزون')
s, h, body = export(a, 'format=csv&t=inventory')
text, rows = csv_rows(body)
check('CSV: الحالة والنوع', s == 200 and h.get('Content-Type', '').startswith('text/csv'), s)
cd = h.get('Content-Disposition', '')
check('CSV: اسم ملف عربي مع بديل ASCII', cd.startswith('attachment; filename="') and "filename*=UTF-8''" in cd
      and urllib.parse.quote('المخزون') in cd and cd.endswith('.csv'), cd)
check('CSV: يبدأ بـ BOM', body.startswith(b'\xef\xbb\xbf'))
check('CSV: عناوين الأعمدة', rows and rows[0][:7] == ['النوع', 'العرض', 'التخانة', 'الطول', 'العدد المتاح', 'حجم القطعة (م³)', 'الحجم المتاح (م³)'], rows[:1])
check('CSV: عمود التوزيع لأن العرض لكل المخازن', 'التوزيع على المخازن' in rows[0])
zan10 = [r for r in rows if r[0] == zan and r[1] == '10 سم']
check('CSV: زان عرض 10 = 99 قطعة و 1.485 م³ بأرقام إنجليزية', zan10 and zan10[0][4] == '99' and zan10[0][6] == '1.485', zan10)
check('CSV: صف الإجمالي', rows[-1][0] == 'الإجمالي' and rows[-1][4] == str(99 + 5 + 5 + 2), rows[-1])
evil_rows = [r for r in rows if evil in r[0]]
check('CSV: نص يبدأ بـ = محمي من الصيغ', evil_rows and evil_rows[0][0].startswith("'="), evil_rows[:1])

s, _, body = export(a, 'format=csv&t=inventory&cols=type,qty')
_, rows = csv_rows(body)
check('الأعمدة الظاهرة فقط (cols)', s == 200 and rows[0] == ['النوع', 'العدد المتاح'], rows[:1])
s, _, body = export(a, 'format=csv&t=inventory&cols=type,<script>,qty%27')
_, rows = csv_rows(body)
check('مفاتيح أعمدة غير صالحة تُتجاهل', s == 200 and rows[0] == ['النوع'], rows[:1])
s, _, body = export(a, 'format=csv&t=inventory&cols=nothing_here')
_, rows = csv_rows(body)
check('قائمة أعمدة بلا عمود معروف = كل الأعمدة', s == 200 and len(rows[0]) >= 8, rows[:1])
s, _, body = export(a, 'format=csv&t=inventory&sort=qty:asc&type=' + t_zan)
_, rows = csv_rows(body)
check('التصدير بنفس الترتيب والتصفية', s == 200 and [r[4] for r in rows[1:-1]] == ['5', '99'], [r[4] for r in rows[1:]])

s, h, body = export(a, 'format=xlsx&t=inventory')
check('Excel: الحالة ونوع الملف', s == 200 and h.get('Content-Type') == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', s)
check('Excel: امتداد .xlsx', h.get('Content-Disposition', '').endswith('.xlsx'))
try:
    z = zipfile.ZipFile(io.BytesIO(body))
    sheet = z.read('xl/worksheets/sheet1.xml').decode('utf-8')
    check('Excel: ورقة من اليمين لليسار', 'rightToLeft="1"' in sheet)
    check('Excel: أرقام حقيقية (99 و 1.485)', '<v>99</v>' in sheet and '<v>1.485</v>' in sheet)
    check('Excel: اسم النوع نصًا', zan in sheet)
except zipfile.BadZipFile:
    check('Excel: ملف ZIP صالح', False)

s, h, body = export(a, 'format=pdf&t=inventory')
check('PDF: الحالة ونوع الملف', s == 200 and h.get('Content-Type') == 'application/pdf' and body.startswith(b'%PDF-'), s)
check('PDF: تحميل كملف', h.get('Content-Disposition', '').startswith('attachment;'))
pt = pdf_text(body)
check('PDF: العنوان والأرقام العربية', 'المخزون' in pt and '٩٩' in pt, pt[:200])

# ------------------------------------------------------------------
section('السجل: الترتيب على الخادم وتصدير كل الصفحات')
s, html = a.page('index.php?r=documents')
check('السجل: صفحتان أو أكثر', 'class="pager no-print"' in html)
check('شريط أدوات السجل', 'data-table-tools="documents"' in html and 'id="documents-table"' in html)
s, html = a.page('index.php?r=documents&sort=qty:desc')
first = re.search(r'<td class="nowrap" data-col="doc"[^>]*><a href="index\.php\?r=document&amp;id=(\d+)"', html)
biggest = sql('SELECT id FROM documents ORDER BY total_qty DESC, id DESC LIMIT 1')
check('الترتيب بالقطع يشمل كل المستندات (الأكبر أولًا)', first is not None and first.group(1) == biggest, (first and first.group(1), biggest))
check('رابط الصفحة التالية يحمل الترتيب', 'sort=qty%3Adesc&amp;page=2' in html)
check('عمود المستخدم غير قابل للترتيب', 'sort=user%3A' not in html)
s, _, body = export(a, 'format=csv&t=documents')
_, rows = csv_rows(body)
check('CSV السجل: كل المستندات وليس الصفحة الأولى فقط', s == 200 and len(rows) - 1 == docs_total, (len(rows) - 1, docs_total))
check('CSV السجل: نوع المستند ورقمه في عمودين', rows[0][:3] == ['نوع المستند', 'الرقم', 'التاريخ'], rows[:1])
check('CSV السجل: عمود الفرع مع أكثر من فرع', 'الفرع' in rows[0])
sale_row = [r for r in rows[1:] if r[0] == 'فاتورة بيع' and r[rows[0].index('القيمة')] == '900.00']
check('CSV السجل: قيمة الفاتورة 900.00 والعملة', sale_row and sale_row[0][rows[0].index('العملة')] == 'جنيه مصري', sale_row[:1])
s, _, body = export(a, 'format=csv&t=documents&kind=sale&cols=doc,amount&sort=amount:asc')
_, rows = csv_rows(body)
check('CSV السجل: التصفية والأعمدة والترتيب', rows[0] == ['نوع المستند', 'الرقم', 'القيمة', 'العملة'] and [r[2] for r in rows[1:]] == ['180.00', '900.00'], rows)
s, h, body = export(a, 'format=xlsx&t=documents')
check('Excel السجل', s == 200 and body[:2] == b'PK', s)
s, h, body = export(a, 'format=pdf&t=documents&kind=sale')
check('تقرير PDF للسجل', s == 200 and body.startswith(b'%PDF-') and 'فاتورة بيع' in pdf_text(body), s)

# ------------------------------------------------------------------
section('التقارير')
s, html = a.page('index.php?r=reports&report=cash_summary')
check('ملخص الخزائن: شريط لكل جدول', all(f'data-table-tools="report.cash_summary{p}"' in html for p in ['', '.1', '.2']))
check('ملخص الخزائن: الجدول الرئيسي قابل للترتيب', 'sort=in%3Aasc' in html)
s, html = a.page('index.php?r=reports&report=profit')
check('الأرباح: بلا روابط ترتيب (بنود قائمة دخل)', s == 200 and 'sort-link' not in html and 'data-table-tools="report.profit"' in html)
s, _, body = export(a, 'format=csv&t=report&report=sales_period&group=month')
_, rows = csv_rows(body)
check('المبيعات حسب الفترة CSV', s == 200 and rows[0][0] == 'الفترة' and any(r[3] == '1080.00' for r in rows[1:]), rows)
s, _, body = export(a, 'format=csv&t=report&report=cash_summary&part=1')
_, rows = csv_rows(body)
check('قسم «الحركة حسب النوع» CSV', s == 200 and rows[0][0] == 'نوع الحركة', rows[:1])
s, _, _ = export(a, 'format=csv&t=report&report=cash_summary&part=9')
check('قسم غير موجود -> 404', s == 404, s)
s, _, _ = export(a, 'format=csv&t=report&report=nope')
check('تقرير غير موجود -> 404', s == 404, s)
s, h, body = export(a, 'format=pdf&t=report&report=profit')
check('الأرباح PDF', s == 200 and 'صافي الربح' in pdf_text(body), s)
s, _, body = export(a, 'format=xlsx&t=report&report=valuation')
check('تقييم المخزون Excel', s == 200 and body[:2] == b'PK', s)

# ------------------------------------------------------------------
section('ملف PDF للفاتورة')
s, html = a.page(f'index.php?r=print&id={sale_main}')
check('زر «تحميل PDF» في صفحة الطباعة', f'href="index.php?r=pdf&amp;id={sale_main}"' in html and 'تحميل PDF' in html)
s, html = a.page(f'index.php?r=document&id={sale_main}')
check('زر «تحميل PDF» في تفاصيل المستند', f'href="index.php?r=pdf&amp;id={sale_main}"' in html)
s, h, body = a.get(f'index.php?r=pdf&id={sale_main}', raw=True)
check('PDF الفاتورة: الحالة والنوع والتحميل', s == 200 and h.get('Content-Type') == 'application/pdf' and h.get('Content-Disposition', '').startswith('attachment;'), s)
pt = pdf_text(body)
check('PDF الفاتورة: العنوان والعميل والقيمة', 'فاتورة بيع' in pt and 'مؤسسة البناء' in pt and '٩٠٠٫٠٠' in pt, pt[:300])
check('PDF الفاتورة: الفرع وطريقة الدفع', 'فرع' in pt and 'طريقة الدفع' in pt)
check('PDF الفاتورة: لا تكلفة ولا ربح', 'التكلفة' not in pt and 'الربح' not in pt)
s, _, _ = a.get('index.php?r=pdf&id=999999')
check('PDF مستند غير موجود -> 404', s == 404, s)

# ------------------------------------------------------------------
section('الموظف المقيد بفرع')
staff_user = 'exp_staff_' + RUN
staff_pass = 'Staff-Pass-' + RUN
a.page('index.php?r=users')
a.post('index.php?r=users', {'action': 'create', 'display_name': 'موظف الإسكندرية', 'username': staff_user, 'role': 'staff',
                             'password': staff_pass, 'password_confirm': staff_pass})
staff_id = sql(f"SELECT id FROM users WHERE username = '{staff_user}'")
a.page(f'index.php?r=users&edit={staff_id}')
a.post('index.php?r=users', {'action': 'update', 'id': staff_id, 'display_name': 'موظف الإسكندرية', 'role': 'staff', 'branch_id': alex})
check('الموظف مقيد بفرع الإسكندرية', sql(f'SELECT branch_id FROM users WHERE id = {staff_id}') == alex)
st = Client()
check('دخول الموظف', st.login(staff_user, staff_pass)[0] == 303)
s, _, body = export(st, 'format=csv&t=inventory')
text, rows = csv_rows(body)
check('مخزون الموظف: صنوبر الإسكندرية فقط', s == 200 and pine in text and zan not in text, rows[:3])
s, _, body = export(st, 'format=csv&t=documents')
_, rows = csv_rows(body)
alex_docs = int(sql(f'SELECT COUNT(*) FROM documents WHERE branch_id = {alex} OR to_branch_id = {alex}'))
check('سجل الموظف: مستندات فرعه فقط', s == 200 and len(rows) - 1 == alex_docs, (len(rows) - 1, alex_docs))
s, _, body = export(st, 'format=csv&t=documents&branch=' + sql('SELECT MIN(id) FROM branches'))
_, rows = csv_rows(body)
check('معامل branch لفرع آخر لا يوسع النطاق', s == 200 and len(rows) - 1 == alex_docs, len(rows) - 1)
for rep in ['sales_period', 'profit', 'valuation', 'cash_summary']:
    s, _, body = st.get(f'index.php?r=export&format=csv&t=report&report={rep}')
    check(f'تقرير {rep} ممنوع على الموظف -> 403', s == 403 and 'ليست لديك صلاحية' in body, s)
s, _, body = export(st, 'format=csv&t=report&report=customer_balances&show=all')
check('أرصدة العملاء مسموحة للموظف (كشوف الحساب)', s == 200, s)
s, _, _ = st.get(f'index.php?r=pdf&id={sale_main}')
check('PDF فاتورة فرع آخر -> 404 للموظف', s == 404, s)
s, _, body = st.get(f'index.php?r=pdf&id={sale_alex}', raw=True)
check('PDF فاتورة فرعه -> 200', s == 200 and body.startswith(b'%PDF-'), s)

# ------------------------------------------------------------------
section('الحماية')
anon = Client()
s, h, _ = anon.get('index.php?r=export&t=inventory&format=csv')
check('بدون دخول -> تحويل للدخول', s == 303 and 'r=login' in (h.get('Location') or ''), s)
s, h, _ = anon.get(f'index.php?r=pdf&id={sale_main}')
check('PDF بدون دخول -> تحويل للدخول', s == 303, s)
s, _, _ = a.req('index.php?r=export&t=inventory&format=csv', data={'x': '1'})
check('POST للتصدير -> 405', s == 405, s)
s, _, body = a.get('index.php?r=export&t=inventory&format=csv', headers={'Sec-Fetch-Site': 'cross-site'})
check('طلب من موقع آخر -> 403', s == 403 and 'صفحات النظام' in body, s)
s, _, body = a.get('index.php?r=export&t=inventory&format=csv', headers={'Sec-Fetch-Site': 'same-origin'})
EXPORTS += 1 if s == 200 else 0
check('نفس الموقع -> 200', s == 200, s)
s, _, body = a.get('index.php?r=export&t=inventory&format=exe')
check('صيغة غير معروفة -> 400', s == 400 and 'صيغة التصدير' in body, s)
s, _, _ = a.get('index.php?r=export&t=users&format=csv')
check('جدول غير معروف -> 404', s == 404, s)
s, _, _ = a.get('index.php?r=export&t[]=inventory&format=csv')
check('t كمصفوفة -> 404', s == 404, s)

# ------------------------------------------------------------------
section('المراقبة')
version_before = sql("SELECT value FROM counters WHERE name = 'data_version'")
s, _, _ = export(a, 'format=csv&t=inventory')
version_after = sql("SELECT value FROM counters WHERE name = 'data_version'")
check('التصدير لا يرفع رقم إصدار البيانات', version_before == version_after, (version_before, version_after))
logged = int(sql("SELECT COUNT(*) FROM audit_log WHERE action = 'data.export'"))
check('كل ملف تصدير مسجل في المراقبة', logged == EXPORTS, (logged, EXPORTS))
summary = sql("SELECT summary FROM audit_log WHERE action = 'data.export' ORDER BY id DESC LIMIT 1")
check('ملخص سطر المراقبة', summary.startswith('تصدير «المخزون» إلى CSV'), summary)
s, html = a.page('index.php?r=monitor&group=export')
check('صفحة المراقبة: «تصدير بيانات» وتصفية «التصدير»', 'تصدير بيانات' in html and '<option value="export" selected>' in html, s)
s, html = a.page('index.php?r=monitor')
check('صفحة المراقبة: لا مفاتيح عمليات إنجليزية', re.search(r'>(voucher|party|branch|cash_box|data)\.[a-z_]+<', html) is None)

# ------------------------------------------------------------------
section('حد مرات التصدير')
c = Client()
c.login(*ADMIN)
statuses = [export(c, 'format=csv&t=inventory&cols=type')[0] for _ in range(21)]
check('20 ملفًا مسموحة في الجلسة', statuses[:20] == [200] * 20, statuses)
s, _, body = c.get('index.php?r=export&format=csv&t=inventory')
check('بعدها 429 برسالة عربية', statuses[20] == 429 and s == 429 and 'حد التصدير' in body, (statuses[20], s))
check('جلسة أخرى غير متأثرة', export(a, 'format=csv&t=inventory&cols=type')[0] == 200)
check('المرفوض بالحد غير مسجل في المراقبة', int(sql("SELECT COUNT(*) FROM audit_log WHERE action = 'data.export'")) == EXPORTS)

# ------------------------------------------------------------------
section('سجل أخطاء PHP')
grown = []
for p, size in logs_at_start.items():
    if os.path.exists(p) and os.path.getsize(p) > size:
        with open(p, encoding='utf-8', errors='replace') as f:
            f.seek(size)
            grown += [ln for ln in f.read().splitlines() if 'PHP' in ln or '[wood]' in ln]
check('لا تحذيرات أو أخطاء PHP جديدة', not grown, grown[:3])

print(f'\nResult: {PASS} passed, {len(FAILS)} failed')
for f in FAILS:
    print('  - ' + f)
raise SystemExit(1 if FAILS else 0)
