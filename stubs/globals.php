<?php

// phpcs:disable Squiz.PHP.DiscouragedFunctions,NeutronStandard.Constants.DisallowDefine

// ./wp-includes/default-constants.php

define( 'WP_DEBUG', /** @var bool $wp_debug */ $wp_debug = true );
define( 'WP_DEBUG_LOG', /** @var bool $wp_debug_log */ $wp_debug_log = true );

define( 'EMPTY_TRASH_DAYS', /** @var int<0, max> $empty_trash_days */ $empty_trash_days = 30 );

define( 'MINUTE_IN_SECONDS', /** @var 60 $minute_in_seconds */ $minute_in_seconds = 60 );
define( 'HOUR_IN_SECONDS', /** @var 3600 $hour_in_seconds */ $hour_in_seconds = 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', /** @var 86400 $day_in_seconds */ $day_in_seconds = 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', /** @var 604800 $week_in_seconds */ $week_in_seconds = 7 * DAY_IN_SECONDS );
define( 'MONTH_IN_SECONDS', /** @var 2592000 $month_in_seconds */ $month_in_seconds = 30 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', /** @var 31536000 $year_in_seconds */ $year_in_seconds = 365 * DAY_IN_SECONDS );

define( 'KB_IN_BYTES', /** @var 1024 $kb_in_bytes */ $kb_in_bytes = 1024 );
define( 'MB_IN_BYTES', /** @var 1048576 $mb_in_bytes */ $mb_in_bytes = 1024 * KB_IN_BYTES );
define( 'GB_IN_BYTES', /** @var 1073741824 $gb_in_bytes */ $gb_in_bytes = 1024 * MB_IN_BYTES );
define( 'TB_IN_BYTES', /** @var 1099511627776 $tb_in_bytes */ $tb_in_bytes = 1024 * GB_IN_BYTES );

// ./wp-includes/wp-db.php

define( 'OBJECT', /** @var 'OBJECT' $object */ $object = 'OBJECT' );
define( 'OBJECT_K', /** @var 'OBJECT_K' $object_k */ $object_k = 'OBJECT_K' );
define( 'ARRAY_A', /** @var 'ARRAY_A' $array_a */ $array_a = 'ARRAY_A' );
define( 'ARRAY_N', /** @var 'ARRAY_N' $array_n */ $array_n = 'ARRAY_N' );

// ./wp-admin/includes/file.php

define( 'FS_CONNECT_TIMEOUT', /** @var int<0, max> $fs_connect_timeout */ $fs_connect_timeout = 30 );
define( 'FS_TIMEOUT', /** @var int<0, max> $fs_timeout */ $fs_timeout = 30 );
define( 'FS_CHMOD_DIR', /** @var int $fs_chmod_dir */ $fs_chmod_dir = 0755 );
define( 'FS_CHMOD_FILE', /** @var int $fs_chmod_file */ $fs_chmod_file = 0644 );

// ./wp-includes/rewrite.php

define( 'EP_NONE', /** @var 0 $ep_none */ $ep_none = 0 );
define( 'EP_PERMALINK', /** @var 1 $ep_permalink */ $ep_permalink = 1 );
define( 'EP_ATTACHMENT', /** @var 2 $ep_attachment */ $ep_attachment = 2 );
define( 'EP_DATE', /** @var 4 $ep_date */ $ep_date = 4 );
define( 'EP_YEAR', /** @var 8 $ep_year */ $ep_year = 8 );
define( 'EP_MONTH', /** @var 16 $ep_month */ $ep_month = 16 );
define( 'EP_DAY', /** @var 32 $ep_day */ $ep_day = 32 );
define( 'EP_ROOT', /** @var 64 $ep_root */ $ep_root = 64 );
define( 'EP_COMMENTS', /** @var 128 $ep_comments */ $ep_comments = 128 );
define( 'EP_SEARCH', /** @var 256 $ep_search */ $ep_search = 256 );
define( 'EP_CATEGORIES', /** @var 512 $ep_categories */ $ep_categories = 512 );
define( 'EP_TAGS', /** @var 1024 $ep_tags */ $ep_tags = 1024 );
define( 'EP_AUTHORS', /** @var 2048 $ep_authors */ $ep_authors = 2048 );
define( 'EP_PAGES', /** @var 4096 $ep_pages */ $ep_pages = 4096 );
define( 'EP_ALL_ARCHIVES', /** @var int-mask<EP_DATE|EP_YEAR|EP_MONTH|EP_DAY|EP_CATEGORIES|EP_TAGS|EP_AUTHORS> $ep_all_archives */ $ep_all_archives = EP_DATE | EP_YEAR | EP_MONTH | EP_DAY | EP_CATEGORIES | EP_TAGS | EP_AUTHORS );
define( 'EP_ALL', /** @var int-mask<EP_PERMALINK|EP_ATTACHMENT|EP_ROOT|EP_COMMENTS|EP_SEARCH|EP_PAGES|EP_ALL_ARCHIVES> $ep_all */ $ep_all = EP_PERMALINK | EP_ATTACHMENT | EP_ROOT | EP_COMMENTS | EP_SEARCH | EP_PAGES | EP_ALL_ARCHIVES );
