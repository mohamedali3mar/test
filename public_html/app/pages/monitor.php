<?php
defined('APP_ROOT') || exit;

/*
 * المراقبة (للمدير فقط): ملخص اليوم، وسجل كل العمليات (من فعل ماذا ومتى ومن أين) مع التصفية،
 * وسجل الدخول. المناطق data-live تتحدث تلقائيًا عند أي عملية جديدة.
 */

$pdo = db();
if (!users_audit_ready($pdo)) {
    render_migration_needed('المراقبة', 'monitor');
}

$users = users_all($pdo);
$f = audit_filters($_GET, array_map(fn ($u) => (int) $u['id'], $users));
$result = audit_search($pdo, $f, (int) input($_GET, 'page', '1'));
$summary = monitor_summary($pdo);
$logins = monitor_auth_events($pdo, 50);
$query = audit_filter_query($f);
$filtered = count($query) > 1;
$page = $result['page'];

render_header('المراقبة', 'monitor');
?>
<h1>المراقبة</h1>

<section class="section" id="live-monitor-summary" data-live aria-labelledby="today-title">
  <h2 id="today-title">اليوم</h2>
  <dl class="facts monitor-facts">
    <div><dt>العمليات المسجلة اليوم</dt><dd><?= h(fmt_int($summary['operations'])) ?></dd></div>
    <div><dt>فواتير البيع اليوم</dt><dd><?= h(fmt_int($summary['sales_count'])) ?></dd></div>
    <div class="fact-strong">
      <dt>إجمالي المبيعات اليوم</dt>
      <dd>
        <?php if (!$summary['sales']): ?>
          <?= h(fmt_money_currency('0')) ?>
        <?php else: foreach ($summary['sales'] as $s): ?>
          <span class="fact-line"><?= h(fmt_money_currency((string) $s['total'], $s['currency'])) ?></span>
        <?php endforeach; endif; ?>
      </dd>
    </div>
    <div><dt>الإلغاءات اليوم</dt><dd><?= h(fmt_int($summary['cancellations'])) ?></dd></div>
    <div>
      <dt>محاولات دخول فاشلة (آخر <?= h(fmt_int(24)) ?> ساعة)</dt>
      <dd>
        <?php if ($summary['failed_logins'] === null): ?>
          <span class="fact-note">غير متاح حتى يُفعَّل سجل الدخول.</span>
        <?php else: ?>
          <?= h(fmt_int($summary['failed_logins'])) ?>
        <?php endif; ?>
      </dd>
    </div>
    <div>
      <dt>المتصلون الآن</dt>
      <dd>
        <?= h(fmt_int(count($summary['online']))) ?>
        <?php if ($summary['online']): ?>
          <span class="fact-note"><?= h(implode('، ', array_map('user_display_name', $summary['online']))) ?></span>
        <?php endif; ?>
      </dd>
    </div>
  </dl>
  <p class="hint">المبيعات تشمل الفواتير السارية فقط. «متصل الآن» يعني أنه استخدم النظام خلال آخر <?= h(fmt_int(5)) ?> دقائق.</p>
</section>

<form method="get" action="index.php" class="filters" role="search" aria-label="تصفية سجل العمليات">
  <input type="hidden" name="r" value="monitor">
  <div class="field">
    <label for="user">المستخدم</label>
    <select id="user" name="user">
      <option value="">الكل</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int) $u['id'] ?>"<?= $f['user'] === (string) $u['id'] ? ' selected' : '' ?>><?= h(user_display_name($u) . ' (' . $u['username'] . ')') ?></option>
      <?php endforeach; ?>
      <option value="system"<?= $f['user'] === 'system' ? ' selected' : '' ?>>النظام</option>
    </select>
  </div>
  <div class="field">
    <label for="group">نوع العملية</label>
    <select id="group" name="group">
      <option value="">الكل</option>
      <?php foreach (AUDIT_GROUPS as $g => $label): ?>
        <option value="<?= h($g) ?>"<?= $f['group'] === $g ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="from">من تاريخ</label>
    <input type="date" id="from" name="from" value="<?= h($f['from'] ? $f['from']->format('Y-m-d') : '') ?>">
  </div>
  <div class="field">
    <label for="to">إلى تاريخ</label>
    <input type="date" id="to" name="to" value="<?= h($f['to'] ? $f['to']->format('Y-m-d') : '') ?>">
  </div>
  <div class="field">
    <label for="q">بحث في التفاصيل</label>
    <input type="search" id="q" name="q" value="<?= h($f['q']) ?>" maxlength="100" placeholder="عميل، مخزن، نوع، سبب">
  </div>
  <div class="field">
    <label for="doc">رقم المستند</label>
    <input type="text" id="doc" name="doc" inputmode="numeric" maxlength="9" value="<?= h($f['doc'] ? (string) $f['doc'] : '') ?>">
  </div>
  <div class="filter-actions">
    <button type="submit" class="btn">عرض</button>
    <?php if ($filtered): ?><a class="btn btn-quiet" href="<?= h(url('monitor')) ?>">مسح التصفية</a><?php endif; ?>
  </div>
</form>

<?php if ($f['ignored']): ?>
  <div class="alert alert-warning" role="status">تم تجاهل قيم غير صالحة في التصفية: <?= h(implode('، ', $f['ignored'])) ?>.</div>
<?php endif; ?>

<section class="section" id="live-monitor-activity" data-live aria-labelledby="activity-title">
  <h2 id="activity-title">سجل العمليات</h2>
  <?php if (!$result['rows']): ?>
    <p class="empty"><?= $filtered ? 'لا توجد عمليات مطابقة للتصفية.' : 'لا توجد عمليات مسجلة بعد. كل حفظ أو إلغاء أو تعديل يظهر هنا.' ?></p>
  <?php else: ?>
  <p class="summary"><?= h(fmt_int($result['total'])) ?> عملية<?= $filtered ? ' مطابقة' : '' ?>، الأحدث أولًا.</p>
  <div class="table-wrap">
    <table class="audit-table">
      <caption class="visually-hidden">سجل العمليات: الوقت والمستخدم والعملية والتفاصيل والعنوان والجهاز</caption>
      <thead>
        <tr>
          <th scope="col">الوقت</th>
          <th scope="col">المستخدم</th>
          <th scope="col">العملية</th>
          <th scope="col">التفاصيل</th>
          <th scope="col">عنوان IP</th>
          <th scope="col">الجهاز</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($result['rows'] as $a): $device = audit_device_label((string) $a['user_agent']); ?>
        <tr>
          <td data-label="<?= h('الوقت') ?>" class="nowrap"><?= h(fmt_datetime($a['created_at'])) ?></td>
          <td data-label="<?= h('المستخدم') ?>"><?= h(audit_actor_name($a)) ?></td>
          <td data-label="<?= h('العملية') ?>" class="nowrap"><?= h(audit_action_label((string) $a['action'])) ?></td>
          <td data-label="<?= h('التفاصيل') ?>" class="audit-summary">
            <?= h((string) $a['summary']) ?>
            <?php if ($a['entity'] === 'document' && $a['entity_id'] !== null): ?>
              <a href="<?= h(url('document', ['id' => (int) $a['entity_id']])) ?>">عرض المستند</a>
            <?php elseif ($a['entity'] === 'user' && $a['entity_id'] !== null): ?>
              <a href="<?= h(url('users', ['edit' => (int) $a['entity_id']])) ?>">عرض المستخدم</a>
            <?php endif; ?>
          </td>
          <td data-label="<?= h('عنوان IP') ?>" class="nowrap"><?= $a['ip'] !== '' ? h((string) $a['ip']) : '<span class="muted">لا يوجد</span>' ?></td>
          <td data-label="<?= h('الجهاز') ?>" class="audit-device"><?= $device !== '' ? '<span title="' . h((string) $a['user_agent']) . '">' . h($device) . '</span>' : '<span class="muted">لا يوجد</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($result['pages'] > 1): ?>
  <nav class="pager" aria-label="صفحات سجل العمليات">
    <?php if ($page > 1): ?><a class="btn" href="<?= h('index.php?' . http_build_query($query + ['page' => $page - 1])) ?>">السابق</a><?php endif; ?>
    <span>صفحة <?= h(fmt_int($page)) ?> من <?= h(fmt_int($result['pages'])) ?></span>
    <?php if ($page < $result['pages']): ?><a class="btn" href="<?= h('index.php?' . http_build_query($query + ['page' => $page + 1])) ?>">التالي</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>

<section class="section" id="live-monitor-logins" data-live aria-labelledby="logins-title">
  <h2 id="logins-title">سجل الدخول</h2>
  <?php if ($logins === null): ?>
    <p class="empty">سجل الدخول غير متاح بعد. يظهر هنا بعد تحديث قاعدة البيانات من صفحة <a href="<?= h(url('settings')) ?>">الإعدادات</a>.</p>
  <?php elseif (!$logins): ?>
    <p class="empty">لا توجد أحداث دخول مسجلة بعد.</p>
  <?php else: ?>
  <p class="summary">آخر <?= h(fmt_int(count($logins))) ?> حدث، الأحدث أولًا.</p>
  <div class="table-wrap">
    <table>
      <caption class="visually-hidden">آخر أحداث الدخول والخروج</caption>
      <thead>
        <tr>
          <th scope="col">الوقت</th>
          <th scope="col">اسم المستخدم</th>
          <th scope="col">الحدث</th>
          <th scope="col">عنوان IP</th>
          <th scope="col">الجهاز</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($logins as $e): $device = audit_device_label((string) $e['user_agent']); $bad = in_array($e['event'], ['login_fail', 'login_locked'], true); ?>
        <tr>
          <td data-label="<?= h('الوقت') ?>" class="nowrap"><?= h(fmt_datetime($e['created_at'])) ?></td>
          <td data-label="<?= h('اسم المستخدم') ?>"><?= h((string) $e['username']) ?></td>
          <td data-label="<?= h('الحدث') ?>"><?= $bad ? '<span class="status status-failed">' . h(MONITOR_AUTH_EVENT_LABELS[$e['event']]) . '</span>' : h(MONITOR_AUTH_EVENT_LABELS[$e['event']] ?? (string) $e['event']) ?></td>
          <td data-label="<?= h('عنوان IP') ?>" class="nowrap"><?= h((string) $e['ip']) ?></td>
          <td data-label="<?= h('الجهاز') ?>" class="audit-device"><?= $device !== '' ? '<span title="' . h((string) $e['user_agent']) . '">' . h($device) . '</span>' : '<span class="muted">لا يوجد</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php
render_footer();
