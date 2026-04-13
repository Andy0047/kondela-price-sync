<?php
declare(strict_types=1);

putenv('SYNC_SOURCE_URL=https://b2b.kondela.sk/feed/kuchyna_jedalen.xml');
putenv('SYNC_CACHE_FILE=' . __DIR__ . '/kondela_price_cache_test.json');
putenv('SYNC_LOG_FILE=' . __DIR__ . '/kondela_price_sync_test.log');
putenv('SYNC_PRICE_LIST_CODE=price_test');
putenv('SYNC_DRY_RUN=0');
putenv('SYNC_FORCE_ALL=1');

require __DIR__ . '/update.php';
