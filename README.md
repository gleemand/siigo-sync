## HeadingSimplified integration with Siigo ERP

**Особенности работы с Siigo:**

- https://siigoapi.docs.apiary.io/
- для фильтрации в запросах используется время UTC, в личном кабинете Siigo отображается местное время (для текущего региона)
- cron example:
	- */30 * * * *    /usr/bin/php /var/.../siigo/sync.php stocks >> /var/.../siigo/logs/cron_logs/stocks.log 2>&1
	- 05 * * * *      /usr/bin/php /var/.../siigo/sync.php customers orders >> /var/.../siigo/logs/cron_logs/customers_orders.log 2>&1
	- */7 * * * *     /usr/bin/php /var/.../siigo/sync.php crm_customers crm_orders >> /var/...-/siigo/logs/cron_logs/crm_customers_orders.log 2>&1
