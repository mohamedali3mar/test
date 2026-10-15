-- الترقية 010: تكلفة التقييم الافتتاحي لكل صنف، وتمييز الأصناف التي خُصمت تكلفة رصيدها من الأرباح.
-- آمنة لإعادة التنفيذ إذا انقطعت (IF NOT EXISTS، والتحديثات تكتب نفس القيم مرة أخرى).

-- تكلفة المتر المكعب في آخر تقييم افتتاحي للصنف ووقته. إلغاء وارد بلا تكلفة أو بيع بتكلفة صفر سُجل قبل
-- التقييم يُعكس بهذه التكلفة (وهي القيمة التي أضافها التقييم لقطعه)، وليس بصفر.
ALTER TABLE items ADD COLUMN IF NOT EXISTS opening_cost_per_m3 DECIMAL(24,2) NULL;
ALTER TABLE items ADD COLUMN IF NOT EXISTS opening_valued_at DATETIME NULL;

-- 1 = قيمة الصنف صفر وله رصيد لأن تكلفة قطعه خُصمت بالفعل من الأرباح (إلغاء وارد بعد بيع جزء من الصنف)،
-- وليس رصيدًا سُجل بدون تكلفة. لا يظهر في التقييم الافتتاحي ولا في تحذير الأصناف بدون تكلفة.
ALTER TABLE items ADD COLUMN IF NOT EXISTS cost_written_off TINYINT(1) NOT NULL DEFAULT 0;

-- الأصناف التي قُيّمت قبل هذه الترقية: التكلفة ووقتها من آخر تقييم افتتاحي في سجل المراقبة
UPDATE items i JOIN audit_log a ON a.entity = 'item' AND a.entity_id = i.id AND a.action = 'opening_valuation'
    AND a.id = (SELECT MAX(b.id) FROM audit_log b WHERE b.action = 'opening_valuation' AND b.entity = 'item' AND b.entity_id = i.id)
SET i.opening_cost_per_m3 = JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.cost_per_m3')), i.opening_valued_at = a.created_at
WHERE i.opening_valued_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.cost_per_m3')) REGEXP '^[0-9]+([.][0-9]+)?$';

-- الأصناف التي صُفّرت قيمتها قبل هذه الترقية بإلغاء وارد بعد بيع جزء منه (قيمتها صفر ولها رصيد، ولها فرق تكلفة
-- «أقل من صفر»). يُستبعد الصنف إذا كان له بعد ذلك الفرق وارد بدون تكلفة، أو وارد بدون تكلفة قبله لم يُقيَّم قبل الفرق:
-- رصيده قد يكون فعلًا بدون تكلفة، فيبقى ظاهرًا في التقييم الافتتاحي كما كان.
UPDATE items i SET i.cost_written_off = 1
WHERE i.inventory_value = 0 AND i.cost_written_off = 0
    AND (SELECT COALESCE(SUM(s.qty_on_hand), 0) FROM stock s WHERE s.item_id = i.id) > 0
    AND EXISTS (
        SELECT 1 FROM cost_adjustments ca
        WHERE ca.item_id = i.id AND ca.amount < 0 AND ca.reason LIKE '%أقل من صفر%'
            AND NOT EXISTS (
                SELECT 1 FROM document_lines l JOIN documents d ON d.id = l.document_id
                WHERE l.item_id = i.id AND d.kind = 'in' AND d.status = 'active' AND l.cost_amount = 0
                    AND (d.created_at >= ca.created_at OR i.opening_valued_at IS NULL
                        OR i.opening_valued_at < d.created_at OR i.opening_valued_at > ca.created_at)
            )
    );
