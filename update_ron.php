<?php
declare(strict_types=1);

putenv('SYNC_SOURCE_URL=https://b2b.kondela.sk/feed/kuchyna_jedalen_ro.xml');
putenv('SYNC_CACHE_FILE=' . __DIR__ . '/kondela_price_cache_ron.json');
putenv('SYNC_LOG_FILE=' . __DIR__ . '/kondela_price_sync_ron.log');
putenv('SYNC_PRICE_LIST_CODE=price_ron');
putenv('SYNC_DRY_RUN=0');
putenv('SYNC_FORCE_ALL=0');

require __DIR__ . '/update.php';
