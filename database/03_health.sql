-- Read-only. Every violations value must be 0.
SET NAMES utf8mb4;

-- توازن تک‌تک اسناد مالی
SELECT COUNT(*) AS violations FROM (SELECT e.id FROM ledger_entries e LEFT JOIN ledger_lines l ON l.entry_id=e.id GROUP BY e.id HAVING COUNT(l.id)<2 OR COALESCE(SUM(l.debit),0)<>COALESCE(SUM(l.credit),0)) findings;

-- تطبیق موجودی با گردش انبار
SELECT COUNT(*) AS violations FROM products p LEFT JOIN (SELECT product_id,SUM(delta) quantity FROM stock_movements GROUP BY product_id) m ON m.product_id=p.id WHERE p.stock<>COALESCE(m.quantity,0);

-- تطبیق مبلغ کالاها با اقلام فاکتور
SELECT COUNT(*) AS violations FROM orders o LEFT JOIN (SELECT order_id,SUM(line_total) amount,COUNT(*) item_count FROM order_items GROUP BY order_id) i ON i.order_id=o.id WHERE COALESCE(i.item_count,0)=0 OR o.subtotal<>COALESCE(i.amount,0);

-- جمع نهایی سفارش و اجزای مبلغ
SELECT COUNT(*) AS violations FROM orders WHERE total_amount<>subtotal-coupon_amount+shipping_amount+service_amount OR total_amount<0;

-- وجود دریافت و سند برای سفارش پرداخت‌شده
SELECT COUNT(*) AS violations FROM orders o LEFT JOIN (SELECT order_id,COUNT(*) receipts,SUM(amount) amount FROM payments WHERE status='paid' AND method<>'refund' GROUP BY order_id) p ON p.order_id=o.id LEFT JOIN ledger_entries e ON e.order_id=o.id AND e.event_key=CONCAT('paid:',o.id) LEFT JOIN (SELECT entry_id,SUM(debit) debit,SUM(credit) credit FROM ledger_lines GROUP BY entry_id) l ON l.entry_id=e.id WHERE o.payment_status IN ('paid','refunded') AND (COALESCE(p.receipts,0)<>1 OR COALESCE(p.amount,0)<>o.total_amount OR e.id IS NULL OR COALESCE(l.debit,0)<>o.total_amount OR COALESCE(l.credit,0)<>o.total_amount);

-- تطبیق برگشت وجه با سند برگشت
SELECT COUNT(*) AS violations FROM orders o LEFT JOIN (SELECT order_id,COUNT(*) receipts,SUM(amount) amount FROM payments WHERE method='refund' AND status='refunded' GROUP BY order_id) p ON p.order_id=o.id LEFT JOIN ledger_entries e ON e.order_id=o.id AND e.event_key=CONCAT('refund:',o.id) LEFT JOIN (SELECT entry_id,SUM(debit) debit,SUM(credit) credit FROM ledger_lines GROUP BY entry_id) l ON l.entry_id=e.id WHERE o.payment_status='refunded' AND (COALESCE(p.receipts,0)<>1 OR COALESCE(p.amount,0)<>o.total_amount OR e.id IS NULL OR COALESCE(l.debit,0)<>o.total_amount OR COALESCE(l.credit,0)<>o.total_amount);

-- آزادسازی موجودی سفارش لغوشده یا منقضی
SELECT COUNT(*) AS violations FROM orders WHERE status IN ('cancelled','expired') AND stock_released<>1;

-- order_stock_quantities
SELECT COUNT(*) AS violations FROM (SELECT i.order_id,i.product_id,SUM(i.qty) qty FROM order_items i GROUP BY i.order_id,i.product_id) i JOIN orders o ON o.id=i.order_id LEFT JOIN (SELECT order_id,product_id,SUM(CASE WHEN reason='order_reserved' THEN -delta ELSE 0 END) reserved,SUM(CASE WHEN reason IN ('order_cancelled','order_expired','refund_returned') THEN delta ELSE 0 END) released FROM stock_movements WHERE order_id IS NOT NULL GROUP BY order_id,product_id) m ON m.order_id=i.order_id AND m.product_id=i.product_id WHERE COALESCE(m.reserved,0)<>i.qty OR COALESCE(m.released,0)<>i.qty*o.stock_released;

-- coupon_usage
SELECT COUNT(*) AS violations FROM coupons c LEFT JOIN (SELECT coupon_id,COUNT(*) n FROM orders WHERE coupon_id IS NOT NULL AND stock_released=0 GROUP BY coupon_id) o ON o.coupon_id=c.id WHERE c.used_count<>COALESCE(o.n,0);

-- shipment_records
SELECT COUNT(*) AS violations FROM orders o LEFT JOIN order_shipments s ON o.id=s.order_id WHERE (o.status IN ('shipped','delivered') AND s.order_id IS NULL) OR (s.order_id IS NOT NULL AND o.status NOT IN ('shipped','delivered','refunded')) OR (o.status='delivered' AND s.delivered_at IS NULL) OR (o.status='shipped' AND s.delivered_at IS NOT NULL) OR s.delivered_at<s.shipped_at;

-- item_return_stock
SELECT COUNT(*) AS violations FROM order_item_returns r JOIN order_items i ON i.id=r.order_item_id LEFT JOIN stock_movements m ON m.event_key=CONCAT('item-return:',r.id) WHERE (r.item_condition='sellable' AND (m.id IS NULL OR m.delta<>r.quantity OR m.product_id<>i.product_id OR m.order_id IS NULL OR m.order_id<>i.order_id OR m.reason<>'item_returned')) OR (COALESCE(r.item_condition,'')<>'sellable' AND m.id IS NOT NULL) OR (SELECT SUM(q.quantity) FROM order_item_returns q WHERE q.order_item_id=i.id)>i.qty;

-- item_return_finance
SELECT COUNT(*) AS violations FROM order_item_returns r JOIN order_items i ON i.id=r.order_item_id LEFT JOIN payments p ON p.id=r.refund_payment_id LEFT JOIN ledger_entries e ON e.order_id=i.order_id AND e.event_key=CONCAT('item-refund:',r.id) LEFT JOIN (SELECT entry_id,SUM(debit) debit,SUM(credit) credit FROM ledger_lines GROUP BY entry_id) l ON l.entry_id=e.id WHERE (r.refund_payment_id IS NULL AND e.id IS NOT NULL) OR (r.refund_payment_id IS NOT NULL AND (p.id IS NULL OR p.order_id<>i.order_id OR p.status<>'refunded' OR p.method<>'refund' OR r.received_at IS NULL OR p.amount*i.qty>i.line_total*r.quantity OR COALESCE(l.debit,0)<>p.amount OR COALESCE(l.credit,0)<>p.amount));
